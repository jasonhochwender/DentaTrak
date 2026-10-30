<?php
/**
 * MeditAuthService
 *
 * Medit Link OAuth2 connection service. Implements ONLY the contract
 * documented in the Medit Link OpenAPI guide (stage):
 *
 *   Authorize (browser redirect, ONE-TIME per connection):
 *     GET {authBase}/oauth/authorize
 *         ?client_id={id}&response_type=code&redirect_uri={uri}
 *         &scope=USER GROUP CASE FILE&state={attemptId}
 *     PKCE is NOT documented by the guide (no code_challenge parameter
 *     appears anywhere) - none is sent. `state` round-trips per the
 *     standard OAuth contract and binds the callback to a server-side
 *     attempt row (unpredictable, expiring, single-use).
 *
 *   Callback (browser GET to redirect_uri):
 *     {redirect_uri}?code={code}&state={state}  or ?error=... on decline.
 *
 *   Token exchange (auth host):
 *     POST {authBase}/oauth/token
 *     Authorization: Basic base64(client_id:client_secret)
 *     Content-Type: application/x-www-form-urlencoded
 *     code={code}&state={state}&grant_type=authorization_code
 *         &redirect_uri={uri}&scope={scopes}
 *     (the guide's documented example sends client credentials via the
 *     Basic header - NOT in the JSON/form body.)
 *
 *   Refresh (auth host) - grant_type and refresh_token are documented as
 *   QUERY parameters (not body):
 *     POST {authBase}/oauth/token?grant_type=refresh_token
 *          &refresh_token={rt}
 *     Authorization: Basic base64(client_id:client_secret)
 *
 *   Token response fields (documented):
 *     {"access_token","token_type":"bearer","refresh_token",
 *      "expires_in":10159,"scope":"USER GROUP","refresh_token_exp":1686217896}
 *
 *   Revocation: NO revoke endpoint is documented. Disconnect wipes local
 *   credentials and disables the connection inside the refresh lock;
 *   remote_revoked is reported honestly as false. The abandoned token
 *   ages out at Medit (refresh_token_exp bounds its lifetime).
 *
 * SECURITY:
 *  - Practice-scoped: every attempt and connection binds to practice_id;
 *    the callback additionally requires the live session user+practice to
 *    match the attempt - a foreign session can never complete it.
 *  - Attempts are short-lived and single-use (medit_oauth_attempts).
 *  - Tokens persist only via IntegrationCredentials (AES-256-CBC).
 *  - Client Secret comes from MeditConfig env secrets - never the DB,
 *    browser, logs, or error messages.
 *  - Refresh serialization uses MySQL GET_LOCK so concurrent Cloud Run
 *    instances cannot race a rotation (each response replaces the refresh
 *    token - a lost race would persist a dead one).
 *  - Scopes are the read set only (USER GROUP CASE FILE): no ORDER,
 *    FILE:MANAGE, WEBHOOK:MANAGE - nothing here can mutate Medit data.
 */

require_once __DIR__ . '/../../encryption.php';
require_once __DIR__ . '/../../feature-flags.php';
require_once __DIR__ . '/../MeditConfig.php';
require_once __DIR__ . '/../IntegrationCredentials.php';
require_once __DIR__ . '/../IntegrationManager.php';

class MeditAuthException extends Exception {
    /** @var string safe machine-readable category (never secrets). */
    public $category;
    /** @var bool transient failures may be retried by the caller. */
    public $retryable;

    public function __construct(string $category, string $message, bool $retryable = false) {
        parent::__construct($message);
        $this->category = $category;
        $this->retryable = $retryable;
    }
}

class MeditAuthService {

    public const PROVIDER = 'medit';

    /** Attempts live 15 minutes - plenty for the interactive login. */
    public const ATTEMPT_TTL_SECONDS = 900;

    /** Refresh this far ahead of access-token expiry (clock skew + drift). */
    public const REFRESH_MARGIN_SECONDS = 300;

    /** Bounded refresh retries for transient failures (exponential backoff). */
    private const REFRESH_MAX_ATTEMPTS = 3;
    private const REFRESH_BACKOFF_BASE_MS = 500;

    /** GET_LOCK timeout (seconds) for cross-instance refresh serialization. */
    private const REFRESH_LOCK_TIMEOUT = 10;

    /** Read-only scope set requested at authorize time (documented codes). */
    public const REQUESTED_SCOPES = 'USER GROUP CASE FILE';

    /** @var callable|null test seam: fn($method,$url,$headers,$body) => ['status'=>int,'headers'=>[],'body'=>string] */
    private $transport;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport;
    }

    // ------------------------------------------------------------------
    // Redirect URI
    // ------------------------------------------------------------------

    /**
     * The single stable callback route. Registered verbatim in the Medit
     * developer portal (exact match required). Host comes from
     * app_base_url (APP_BASE_URL env) - never request input.
     */
    public static function redirectUri(): string {
        global $appConfig;
        $base = rtrim((string)($appConfig['app_base_url'] ?? ''), '/');
        if ($base === '') {
            $base = 'https://dentatrak.com';
        }
        return $base . '/api/medit-callback.php';
    }

    // ------------------------------------------------------------------
    // Authorization attempt lifecycle
    // ------------------------------------------------------------------

    /**
     * Create a fresh attempt for (practice, user) and return the documented
     * authorize URL.
     *
     * @return array{attempt_id:string, authorize_url:string, expires_at:string}
     * @throws MeditAuthException 'server_config_missing' when no Client
     *         ID is configured for the active environment.
     */
    public function beginAttempt(PDO $pdo, int $practiceId, int $userId, ?int $connectionId = null): array {
        // Practice allowlist (MEDIT_ALLOWED_PRACTICES) is the rollout
        // gate - an empty list permits no practice and no role or plan
        // override can bypass it. Checked before configuration so an
        // unallowlisted practice can never mint an attempt.
        if (!MeditConfig::isPracticeAllowed($practiceId)) {
            throw new MeditAuthException('practice_not_allowed', 'Medit Link access is not enabled for this practice.');
        }
        if (!MeditConfig::isConfigured()) {
            throw new MeditAuthException('server_config_missing', 'Medit Link integration is not configured on this server.');
        }

        $attemptId = self::newUuid();
        $nonce     = self::base64Url(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::ATTEMPT_TTL_SECONDS);

        $encrypted = PIIEncryption::encrypt($nonce);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new MeditAuthException('internal', 'Failed to persist the authorization attempt.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO medit_oauth_attempts
                (id, practice_id, user_id, connection_id, secret_encrypted, expires_at)
            VALUES (:id, :practice_id, :user_id, :connection_id, :secret, :expires_at)
        ");
        $stmt->execute([
            ':id'            => $attemptId,
            ':practice_id'   => $practiceId,
            ':user_id'       => $userId,
            ':connection_id' => $connectionId,
            ':secret'        => $encrypted,
            ':expires_at'    => $expiresAt,
        ]);

        // Documented authorize parameters only: client_id, response_type,
        // redirect_uri, scope, state. No PKCE - not in the guide.
        $url = MeditConfig::authBaseUrl() . '/oauth/authorize'
            . '?client_id='     . rawurlencode(MeditConfig::clientId())
            . '&response_type=code'
            . '&redirect_uri='  . rawurlencode(self::redirectUri())
            . '&scope='         . rawurlencode(self::REQUESTED_SCOPES)
            . '&state='         . rawurlencode($attemptId);

        return [
            'attempt_id'    => $attemptId,
            'authorize_url' => $url,
            'expires_at'    => $expiresAt,
        ];
    }

    /**
     * Resolve a callback into a connected (or safely failed) connection.
     *
     * $params are the callback query params (code, state or error).
     * $sessionUserId/$sessionPracticeId MUST come from the live session -
     * never from the request.
     *
     * @return array{result:string, connection_id:?int}
     *   result: 'connected' | 'cancelled' | 'invalid' | 'expired' |
     *           'replay' | 'forbidden' | 'disabled' | 'exchange_failed'
     */
    public function handleCallback(PDO $pdo, array $params, int $sessionUserId, int $sessionPracticeId): array {
        // User declined consent (or upstream OAuth error) - nothing was
        // consumed; the pending attempt simply expires.
        if (!empty($params['error'])) {
            return ['result' => 'cancelled', 'connection_id' => null];
        }

        $code  = isset($params['code'])  ? trim((string)$params['code'])  : '';
        $state = isset($params['state']) ? trim((string)$params['state']) : '';
        if ($code === '' || $state === '') {
            return ['result' => 'invalid', 'connection_id' => null];
        }
        if (strlen($code) > 512 || strlen($state) > 36) {
            return ['result' => 'invalid', 'connection_id' => null];
        }

        $stmt = $pdo->prepare("SELECT * FROM medit_oauth_attempts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $state]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            return ['result' => 'invalid', 'connection_id' => null];
        }

        // The feature must still be enabled at completion time - a callback
        // arriving after MEDIT_CONNECT was switched off mid-flow is
        // rejected without consuming the attempt or exchanging the code.
        if (!isFeatureEnabled('MEDIT_CONNECT')) {
            return ['result' => 'disabled', 'connection_id' => null];
        }

        // The practice must still be allowlisted at completion time.
        if (!MeditConfig::isPracticeAllowed((int)$attempt['practice_id'])) {
            return ['result' => 'forbidden', 'connection_id' => null];
        }

        // The live session must still belong to the initiating admin in the
        // initiating practice - otherwise a callback URL shared out-of-band
        // could attach tokens to the wrong practice.
        if ((int)$attempt['user_id'] !== $sessionUserId
            || (int)$attempt['practice_id'] !== $sessionPracticeId) {
            return ['result' => 'forbidden', 'connection_id' => null];
        }

        if (strtotime((string)$attempt['expires_at']) < time()) {
            return ['result' => 'expired', 'connection_id' => (int)$attempt['connection_id'] ?: null];
        }

        // Single-use: only the first callback may consume the attempt. A
        // concurrent or repeated callback loses the conditional UPDATE.
        $consume = $pdo->prepare("
            UPDATE medit_oauth_attempts SET consumed_at = NOW()
            WHERE id = :id AND consumed_at IS NULL
        ");
        $consume->execute([':id' => $state]);
        if ($consume->rowCount() === 0) {
            return ['result' => 'replay', 'connection_id' => (int)$attempt['connection_id'] ?: null];
        }

        try {
            $tokens = $this->exchangeCode($code, $state);
        } catch (MeditAuthException $e) {
            // Failed exchange: attempt is consumed either way - a code can
            // only be redeemed once upstream too, so retry must re-begin.
            $this->markExchangeFailed($pdo, (int)$attempt['practice_id']);
            return ['result' => 'exchange_failed', 'connection_id' => null];
        }

        // Bind the authorized Medit user + group to this practice's
        // connection NOW - the group uuid drives every resource call and
        // is the tenant anchor webhooks resolve against. /v1/me is called
        // with the just-issued token; a failure here fails the whole
        // connection rather than persisting an unbound one.
        try {
            $identity = $this->fetchIdentity($tokens['access_token']);
        } catch (MeditAuthException $e) {
            $this->markExchangeFailed($pdo, (int)$attempt['practice_id']);
            return ['result' => 'exchange_failed', 'connection_id' => null];
        }

        $connectionId = $this->persistConnection(
            $pdo,
            (int)$attempt['practice_id'],
            (int)$attempt['user_id'],
            (int)($attempt['connection_id'] ?? 0) ?: null,
            $tokens,
            $identity
        );

        return ['result' => 'connected', 'connection_id' => $connectionId];
    }

    // ------------------------------------------------------------------
    // Token exchange / refresh (documented request formats)
    // ------------------------------------------------------------------

    /**
     * POST {authBase}/oauth/token - form-urlencoded body, client
     * credentials via Basic header (documented example verbatim).
     */
    private function exchangeCode(string $code, string $state): array {
        $body = http_build_query([
            'code'         => $code,
            'state'        => $state,
            'grant_type'   => 'authorization_code',
            'redirect_uri' => self::redirectUri(),
            'scope'        => self::REQUESTED_SCOPES,
        ], '', '&');
        $response = $this->postForm('/oauth/token', $body);
        return $this->parseTokenResponse($response);
    }

    /**
     * POST {authBase}/oauth/token?grant_type=refresh_token&refresh_token=
     * - the guide documents refresh params as QUERY parameters with an
     * empty body and Basic authorization. Followed verbatim.
     */
    private function refreshTokens(string $refreshToken): array {
        $query = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], '', '&');
        $response = $this->postForm('/oauth/token?' . $query, '');
        return $this->parseTokenResponse($response);
    }

    /**
     * GET {resourceBase}/v1/me?schema=latest - binds the authorized user
     * and their group uuid to the connection. The group uuid is the
     * tenant anchor: resource calls and webhook resolution require it.
     */
    private function fetchIdentity(string $accessToken): array {
        $url = MeditConfig::resourceBaseUrl() . '/v1/me?schema=latest';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $accessToken];
        $response = $this->transport !== null
            ? call_user_func($this->transport, 'GET', $url, $headers, null)
            : self::curlRequest('GET', $url, $headers, null);

        $status = (int)($response['status'] ?? 0);
        $body = json_decode((string)($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new MeditAuthException('identity_failed', 'Medit /v1/me did not return a usable profile.', true);
        }
        $groupUuid = isset($body['group']['uuid']) && is_string($body['group']['uuid'])
            ? substr($body['group']['uuid'], 0, 128) : null;
        if ($groupUuid === null || $groupUuid === '') {
            // A token without a resolvable group can never make a scoped
            // resource call - connect fails rather than stores an
            // unbound credential.
            throw new MeditAuthException('identity_failed', 'Medit profile did not include a group.', false);
        }
        return [
            'group_uuid' => $groupUuid,
            'group_type' => substr((string)($body['group']['type'] ?? ''), 0, 32) ?: null,
            'group_name' => substr((string)($body['group']['name'] ?? ''), 0, 128) ?: null,
        ];
    }

    /**
     * Parse a documented token response: access_token, token_type,
     * refresh_token, expires_in, scope, refresh_token_exp.
     */
    private function parseTokenResponse(array $response): array {
        $status = (int)($response['status'] ?? 0);
        $body   = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($body)) {
            $body = [];
        }

        if ($status < 200 || $status >= 300) {
            $errCode = (string)($body['error'] ?? $body['errorCode'] ?? '');
            // Permanent credential failures -> the caller marks the
            // connection reconnect_required; everything else is retryable.
            $permanent = in_array($errCode, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)
                || $status === 401;
            throw new MeditAuthException(
                $permanent ? 'invalid_credentials' : 'http_' . $status,
                'Medit token endpoint rejected the request (HTTP ' . $status . ').',
                !$permanent
            );
        }

        $accessToken  = $body['access_token']  ?? null;
        $refreshToken = $body['refresh_token'] ?? null;
        $expiresIn    = (int)($body['expires_in'] ?? 0);
        if (!is_string($accessToken) || $accessToken === ''
            || !is_string($refreshToken) || $refreshToken === '' || $expiresIn <= 0) {
            throw new MeditAuthException('unexpected_response', 'Medit token response was missing required fields.', true);
        }

        return [
            'access_token'       => $accessToken,
            'refresh_token'      => $refreshToken,
            'token_type'         => (string)($body['token_type'] ?? 'bearer'),
            'scope'              => (string)($body['scope'] ?? ''),
            'expires_at'         => gmdate('Y-m-d H:i:s', time() + $expiresIn),
            'refresh_token_exp'  => isset($body['refresh_token_exp']) ? (int)$body['refresh_token_exp'] : null,
        ];
    }

    /**
     * Persist exchanged tokens + the bound Medit group on the practice's
     * medit connection. config_json stores NON-SECRET metadata only
     * (environment, expiry, scope, group uuid/type - never tokens).
     */
    private function persistConnection(PDO $pdo, int $practiceId, int $userId, ?int $attemptConnectionId, array $tokens, array $identity): int {
        $connectionId = null;
        if ($attemptConnectionId !== null) {
            $existing = IntegrationManager::getConnectionForPractice($pdo, $practiceId, $attemptConnectionId);
            $connectionId = $existing ? (int)$existing['id'] : null;
        }
        if (empty($connectionId)) {
            $find = $pdo->prepare("
                SELECT id FROM integration_connections
                WHERE practice_id = :pid AND provider = :p LIMIT 1
            ");
            $find->execute([':pid' => $practiceId, ':p' => self::PROVIDER]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            $connectionId = $row ? (int)$row['id'] : null;
        }

        if ($connectionId === null) {
            $ins = $pdo->prepare("
                INSERT INTO integration_connections
                    (practice_id, provider, status, sync_enabled, created_by_user_id)
                VALUES (:pid, :p, 'active', 1, :uid)
            ");
            $ins->execute([':pid' => $practiceId, ':p' => self::PROVIDER, ':uid' => $userId]);
            $connectionId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("
                UPDATE integration_connections
                SET status = 'active', sync_enabled = 1, last_error = NULL
                WHERE id = :id
            ")->execute([':id' => $connectionId]);
        }

        IntegrationCredentials::store($pdo, $connectionId, 'access_token', $tokens['access_token']);
        IntegrationCredentials::store($pdo, $connectionId, 'refresh_token', $tokens['refresh_token']);
        $this->updateConfigJson($pdo, $connectionId, [
            'token_expires_at'  => $tokens['expires_at'],
            'token_scope'       => $tokens['scope'],
            'token_type'        => $tokens['token_type'],
            'refresh_token_exp' => $tokens['refresh_token_exp'],
            'environment'       => MeditConfig::environment(),
            'group_uuid'        => $identity['group_uuid'],
            'group_type'        => $identity['group_type'],
            'group_name'        => $identity['group_name'],
            'authorized_at'     => gmdate('Y-m-d H:i:s'),
            'authorized_by'     => $userId,
        ]);
        return $connectionId;
    }

    private function markExchangeFailed(PDO $pdo, int $practiceId): void {
        $stmt = $pdo->prepare("
            UPDATE integration_connections
            SET status = 'error', last_error = :err
            WHERE practice_id = :pid AND provider = :p AND status <> 'disabled'
        ");
        $stmt->execute([
            ':err' => 'Medit Link authorization could not be completed; start the connection again.',
            ':pid' => $practiceId,
            ':p'   => self::PROVIDER,
        ]);
    }

    // ------------------------------------------------------------------
    // Refresh: proactive + on-demand, serialized across instances
    // ------------------------------------------------------------------

    /**
     * Worker entry point (called from the per-minute queue worker):
     * refresh every connected medit connection whose access token is
     * inside the safety margin - including quiet periods with no scan
     * activity. Returns PHI-free counts only.
     */
    public function refreshDueConnections(PDO $pdo): array {
        $stmt = $pdo->prepare("
            SELECT id, practice_id, config_json, status FROM integration_connections
            WHERE provider = :p AND status IN ('active', 'error')
        ");
        $stmt->execute([':p' => self::PROVIDER]);

        $refreshed = 0;
        $skipped = 0;
        $failed = 0;
        $cutoff = time() + self::REFRESH_MARGIN_SECONDS;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // A practice removed from the allowlist stops receiving token
            // maintenance too - no Medit calls on its behalf at all.
            if (!MeditConfig::isPracticeAllowed((int)$row['practice_id'])) {
                $skipped++;
                continue;
            }
            // Never refresh tokens authorized under a different
            // environment - stage credentials must never reach the
            // production token endpoint (and vice versa).
            if (!MeditConfig::connectionMatchesEnvironment($row)) {
                $skipped++;
                continue;
            }
            $expiresAt = $this->tokenExpiresAt($row);
            if ($expiresAt === null || $expiresAt > $cutoff) {
                $skipped++;
                continue;
            }
            try {
                $this->refreshConnectionToken($pdo, (int)$row['id']);
                $refreshed++;
            } catch (MeditAuthException $e) {
                $failed++;
                error_log('[medit] refresh failed for connection ' . (int)$row['id'] . ': ' . $e->category);
            }
        }
        return ['refreshed' => $refreshed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Serialize refresh across Cloud Run instances via MySQL GET_LOCK, then
     * rotate with bounded retries. The refresh response REPLACES the
     * refresh token - the new one is persisted before the lock releases so
     * a racing instance never stores a dead token.
     *
     * Invalid credentials mark the connection 'reconnect_required' - only
     * an interactive re-authorization repairs it.
     *
     * $force bypasses the "already fresh" double-check: a business API that
     * just 401'd needs a real rotation even when the recorded expiry still
     * looks valid.
     */
    public function refreshConnectionToken(PDO $pdo, int $connectionId, bool $force = false): void {
        $lockName = 'medit_refresh_' . $connectionId;
        $lockStmt = $pdo->prepare("SELECT GET_LOCK(:name, :timeout)");
        $lockStmt->execute([':name' => $lockName, ':timeout' => self::REFRESH_LOCK_TIMEOUT]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new MeditAuthException('lock_timeout', 'Could not acquire the refresh lock.', true);
        }

        try {
            // Re-read under the lock: a concurrent refresh may already have
            // produced fresh tokens (double-check idiom across instances).
            $connection = IntegrationManager::findConnection($pdo, $connectionId);
            if (!$connection || ($connection['provider'] ?? '') !== self::PROVIDER) {
                throw new MeditAuthException('not_found', 'Medit connection not found.');
            }
            if (!MeditConfig::connectionMatchesEnvironment($connection)) {
                throw new MeditAuthException('environment_mismatch', 'Connection belongs to a different Medit environment.', false);
            }
            if (in_array($connection['status'], ['disabled', 'reconnect_required'], true)) {
                return; // intentionally terminal states - nothing to refresh
            }
            $expiresAt = $this->tokenExpiresAt($connection);
            if (!$force && $expiresAt !== null && $expiresAt > time() + self::REFRESH_MARGIN_SECONDS) {
                return; // already fresh - likely a racing instance refreshed
            }

            $refreshToken = IntegrationCredentials::get($pdo, $connectionId, 'refresh_token');
            if ($refreshToken === null || $refreshToken === '') {
                $this->markReconnectRequired($pdo, $connectionId);
                throw new MeditAuthException('invalid_credentials', 'No refresh token stored.');
            }

            $lastError = null;
            for ($attempt = 1; $attempt <= self::REFRESH_MAX_ATTEMPTS; $attempt++) {
                try {
                    $tokens = $this->refreshTokens($refreshToken);
                    IntegrationCredentials::store($pdo, $connectionId, 'access_token', $tokens['access_token']);
                    IntegrationCredentials::store($pdo, $connectionId, 'refresh_token', $tokens['refresh_token']);
                    $this->updateConfigJson($pdo, $connectionId, [
                        'token_expires_at'  => $tokens['expires_at'],
                        'token_scope'       => $tokens['scope'],
                        'refresh_token_exp' => $tokens['refresh_token_exp'],
                    ]);
                    $pdo->prepare("
                        UPDATE integration_connections
                        SET last_error = NULL,
                            status = CASE WHEN status = 'error' THEN 'active' ELSE status END
                        WHERE id = :id
                    ")->execute([':id' => $connectionId]);
                    return;
                } catch (MeditAuthException $e) {
                    $lastError = $e;
                    if ($e->category === 'invalid_credentials') {
                        $this->markReconnectRequired($pdo, $connectionId);
                        throw $e; // permanent - do not retry
                    }
                    if (!$e->retryable || $attempt === self::REFRESH_MAX_ATTEMPTS) {
                        throw $e;
                    }
                    // Bounded backoff for transient failures.
                    usleep(self::REFRESH_BACKOFF_BASE_MS * 1000 * (1 << ($attempt - 1)));
                }
            }
            throw $lastError ?? new MeditAuthException('refresh_failed', 'Refresh failed.', true);
        } finally {
            $release = $pdo->prepare("SELECT RELEASE_LOCK(:name)");
            $release->execute([':name' => $lockName]);
        }
    }

    /**
     * Business-call helper: run $callable($accessToken) once; on a thrown
     * auth failure (HTTP 401 class) force ONE refresh and retry once. Never
     * loops - a second failure propagates.
     *
     * @param callable $fn fn(string $accessToken): mixed; throws
     *                     MeditAuthException('unauthorized',...) on a 401.
     */
    public function withAutoRefresh(PDO $pdo, int $connectionId, callable $fn) {
        $connection = IntegrationManager::findConnection($pdo, $connectionId);
        if (!$connection || ($connection['provider'] ?? '') !== self::PROVIDER) {
            throw new MeditAuthException('not_found', 'Medit connection not found.');
        }
        if (!MeditConfig::connectionMatchesEnvironment($connection)) {
            throw new MeditAuthException('environment_mismatch', 'Connection belongs to a different Medit environment.', false);
        }

        $token = IntegrationCredentials::get($pdo, $connectionId, 'access_token');
        $expiresAt = $this->tokenExpiresAt($connection);
        if ($token === null || ($expiresAt !== null && $expiresAt <= time() + self::REFRESH_MARGIN_SECONDS)) {
            $this->refreshConnectionToken($pdo, $connectionId);
            $token = IntegrationCredentials::get($pdo, $connectionId, 'access_token');
        }
        if ($token === null) {
            $this->markReconnectRequired($pdo, $connectionId);
            throw new MeditAuthException('invalid_credentials', 'No usable access token.');
        }

        try {
            return $fn($token);
        } catch (MeditAuthException $e) {
            if ($e->category !== 'unauthorized') {
                throw $e;
            }
        }

        // One refresh + single retry after a business-API 401.
        $this->refreshConnectionToken($pdo, $connectionId, true);
        $fresh = IntegrationCredentials::get($pdo, $connectionId, 'access_token');
        if ($fresh === null) {
            $this->markReconnectRequired($pdo, $connectionId);
            throw new MeditAuthException('invalid_credentials', 'No usable access token after refresh.');
        }
        return $fn($fresh);
    }

    /**
     * Explicit disconnect (no remote revocation is documented):
     *   1. Acquire the SAME cross-instance refresh lock so an in-flight
     *      rotation cannot persist replacement credentials after we wipe
     *      them. Lock contention fails with 'lock_timeout' rather than
     *      racing.
     *   2. Locally delete both tokens and disable the connection INSIDE
     *      the lock. Local state is authoritative: no further refresh,
     *      poll, or download can run.
     *   3. Report remote_revoked=false honestly - the Medit guide exposes
     *      no token:revoke endpoint, so the abandoned token simply ages
     *      out upstream (bounded by refresh_token_exp).
     *
     * Runs server-side only - independent of any user's DentaTrak logout.
     *
     * @return array{remote_revoked:bool}
     */
    public function disconnect(PDO $pdo, int $connectionId): array {
        $lockName = 'medit_refresh_' . $connectionId;
        $lockStmt = $pdo->prepare("SELECT GET_LOCK(:name, :timeout)");
        $lockStmt->execute([':name' => $lockName, ':timeout' => self::REFRESH_LOCK_TIMEOUT]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new MeditAuthException('lock_timeout', 'Could not acquire the refresh lock.', true);
        }

        try {
            $connection = IntegrationManager::findConnection($pdo, $connectionId);
            if (!$connection || ($connection['provider'] ?? '') !== self::PROVIDER) {
                throw new MeditAuthException('not_found', 'Medit connection not found.');
            }
            if (($connection['status'] ?? '') !== 'disabled') {
                IntegrationCredentials::delete($pdo, $connectionId, 'access_token');
                IntegrationCredentials::delete($pdo, $connectionId, 'refresh_token');
                $this->updateConfigJson($pdo, $connectionId, [
                    'token_expires_at' => null,
                    'token_scope'      => null,
                    'disconnected_at'  => gmdate('Y-m-d H:i:s'),
                ]);
                $pdo->prepare("
                    UPDATE integration_connections
                    SET status = 'disabled', sync_enabled = 0
                    WHERE id = :id
                ")->execute([':id' => $connectionId]);
            }
        } finally {
            $release = $pdo->prepare("SELECT RELEASE_LOCK(:name)");
            $release->execute([':name' => $lockName]);
        }

        // Medit documents no revocation endpoint - reported honestly.
        return ['remote_revoked' => false];
    }

    // ------------------------------------------------------------------
    // Status helpers
    // ------------------------------------------------------------------

    /**
     * Public connection-state projection for the medit provider.
     *   unconfigured       - no connection row
     *   disconnected       - 'disabled'
     *   connected          - 'active' (tokens + future expiry present)
     *   reconnect_required - refresh credentials rejected; needs re-auth
     *   pending/error      - attempt in flight / exchange failed
     */
    public static function connectionState(?array $connection): string {
        if (!$connection) {
            return 'unconfigured';
        }
        $status = (string)($connection['status'] ?? '');
        if ($status === 'disabled') {
            return 'disconnected';
        }
        if ($status === 'reconnect_required') {
            return 'reconnect_required';
        }
        if ($status === 'active') {
            return 'connected';
        }
        return $status === '' ? 'unconfigured' : $status; // pending|error
    }

    private function markReconnectRequired(PDO $pdo, int $connectionId): void {
        $pdo->prepare("
            UPDATE integration_connections
            SET status = 'reconnect_required',
                last_error = 'Medit Link connection expired - reconnect required.'
            WHERE id = :id AND status <> 'disabled'
        ")->execute([':id' => $connectionId]);
    }

    private function tokenExpiresAt(array $connection): ?int {
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        if (!is_array($config)) {
            return null;
        }
        $raw = $config['token_expires_at'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }

    private function updateConfigJson(PDO $pdo, int $connectionId, array $patch): void {
        $stmt = $pdo->prepare("SELECT config_json FROM integration_connections WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $connectionId]);
        $config = json_decode((string)$stmt->fetchColumn(), true);
        if (!is_array($config)) {
            $config = [];
        }
        foreach ($patch as $k => $v) {
            $config[$k] = $v;
        }
        $pdo->prepare("UPDATE integration_connections SET config_json = :cfg WHERE id = :id")
            ->execute([':cfg' => json_encode($config), ':id' => $connectionId]);
    }

    // ------------------------------------------------------------------
    // HTTP transport (injectable for tests - same convention as
    // DsCoreAuthService). Errors are thrown as MeditAuthException with
    // safe categories; bodies are never logged.
    // ------------------------------------------------------------------

    /**
     * Form-encoded POST with the documented Basic client-credentials
     * header. Used for both token exchange and (query-param) refresh.
     */
    private function postForm(string $pathAndQuery, string $encodedBody): array {
        $url = MeditConfig::authBaseUrl() . $pathAndQuery;
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode(MeditConfig::clientId() . ':' . MeditConfig::clientSecret()),
        ];

        if ($this->transport !== null) {
            return call_user_func($this->transport, 'POST', $url, $headers, $encodedBody);
        }
        return self::curlRequest('POST', $url, $headers, $encodedBody);
    }

    private static function curlRequest(string $method, string $url, array $headers, ?string $body = null): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_HTTPHEADER       => $headers,
            CURLOPT_CONNECTTIMEOUT   => 10,
            CURLOPT_TIMEOUT          => 25,
            CURLOPT_NOSIGNAL         => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);

        if ($errno !== 0 || $raw === false) {
            throw new MeditAuthException('network', 'Medit request failed to complete.', true);
        }
        return ['status' => $code, 'headers' => [], 'body' => (string)$raw];
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private static function base64Url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function newUuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
