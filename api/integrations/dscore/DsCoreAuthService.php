<?php
/**
 * DsCoreAuthService
 *
 * DS Core (Dentsply Sirona / Primescan) OAuth2 connection service.
 * Implements ONLY the contract documented in the DS Core Developer Portal
 * guide + authentication section:
 *
 *   Authorize (browser redirect, ONE-TIME per connection) - REGIONAL host:
 *     GET {authBase}/secureLogin?client_id={id}
 *         &code_challenge={base64url(sha256(verifier))}&redirect_uri={uri}
 *         &state={attemptId}
 *     STATE CORRELATION - UNVERIFIED: `state` does not appear in the
 *     manual's authorize-request example; the callback template in the
 *     portal guide echoes `&state=` back, which is the basis for sending
 *     it. If DS Core does not round-trip state, callbacks fail closed as
 *     'invalid' (no connection, no tokens) - safe, but the flow cannot
 *     complete until live sandbox testing or vendor confirmation proves
 *     the correlation mechanism. Do NOT weaken session/practice/expiry/
 *     single-use checks to work around it.
 *
 *   Callback (browser GET to redirect_uri):
 *     {redirect_uri}?code={code}&iss={issuer}&state={state}&client_id={id}
 *     or ?error=... on decline.
 *
 *   Token exchange (GLOBAL host api.dscore.com, §4.1):
 *     POST {apiBase}/v1beta/auth/token           Content-Type: application/json
 *     {"code","client_id","client_secret","redirect_uri","code_verifier",
 *      "grant_type":"authorization_code"}   (grant_type optional per manual)
 *
 *   Refresh (GLOBAL host, §4.2):
 *     POST {apiBase}/v1beta/auth/token:refresh   Content-Type: application/json
 *     {"refresh_token","client_id","client_secret"}   <- snake_case per
 *     manual; no grant_type.
 *
 *   Revocation (GLOBAL host, §4.3):
 *     POST {apiBase}/v1beta/auth/token:revoke
 *     Content-Type: application/x-www-form-urlencoded
 *     client_id={id}&client_secret={s}&token={refresh}
 *     &token_type_hint=refresh_token
 *
 *   Token response (camelCase fields; expiresIn documented as INTEGER):
 *     {"accessToken","refreshToken","scope","tokenType":"Bearer",
 *      "expiresIn":3599}
 *     (The parser also tolerates a numeric-string expiresIn - defensive
 *     tolerance, not part of the documented contract.)
 *
 * NOT documented by the manual (explicitly not implemented):
 *   - scope selection, response_type overrides, webhook endpoints,
 *     and the PMS business API (orders/scans/patients) - pending the PMS
 *     API documentation.
 *
 * SECURITY:
 *  - Practice-scoped: every attempt and connection binds to practice_id;
 *    the callback additionally requires the live session user+practice to
 *    match the attempt - a foreign session can never complete it.
 *  - Verifier is stored ENCRYPTED (recoverable for the exchange) in
 *    dscore_oauth_attempts; attempts are short-lived and single-use.
 *  - Tokens persist only via IntegrationCredentials (AES-256-CBC).
 *  - Client Secret comes from DsCoreConfig env secrets - never the DB,
 *    browser, logs, or error messages.
 *  - Refresh serialization uses MySQL GET_LOCK so concurrent Cloud Run
 *    instances cannot race a rotation (each response replaces the refresh
 *    token - a lost race would persist a dead one).
 */

require_once __DIR__ . '/../../encryption.php';
require_once __DIR__ . '/../../feature-flags.php';
require_once __DIR__ . '/../DsCoreConfig.php';
require_once __DIR__ . '/../IntegrationCredentials.php';
require_once __DIR__ . '/../IntegrationManager.php';

class DsCoreAuthException extends Exception {
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

class DsCoreAuthService {

    public const PROVIDER = 'ds_core';

    /** Attempts live 15 minutes - plenty for the interactive login. */
    public const ATTEMPT_TTL_SECONDS = 900;

    /** Refresh this far ahead of access-token expiry (clock skew + drift). */
    public const REFRESH_MARGIN_SECONDS = 300;

    /** Bounded refresh retries for transient failures (exponential backoff). */
    private const REFRESH_MAX_ATTEMPTS = 3;
    private const REFRESH_BACKOFF_BASE_MS = 500;

    /** GET_LOCK timeout (seconds) for cross-instance refresh serialization. */
    private const REFRESH_LOCK_TIMEOUT = 10;

    /** @var callable|null test seam: fn($method,$url,$headers,$body) => ['status'=>int,'headers'=>[],'body'=>string] */
    private $transport;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport;
    }

    // ------------------------------------------------------------------
    // Redirect URI
    // ------------------------------------------------------------------

    /**
     * The single stable callback route. Registered verbatim in the DS Core
     * portal (exact match required; edits require a new Client ID).
     * Host comes from app_base_url (APP_BASE_URL env) - never request input.
     */
    public static function redirectUri(): string {
        global $appConfig;
        $base = rtrim((string)($appConfig['app_base_url'] ?? ''), '/');
        if ($base === '') {
            $base = 'https://dentatrak.com';
        }
        return $base . '/api/dscore-callback.php';
    }

    // ------------------------------------------------------------------
    // Authorization attempt lifecycle
    // ------------------------------------------------------------------

    /**
     * Create a fresh attempt for (practice, user) and return the documented
     * authorize URL. A fresh verifier is generated per attempt; only the
     * ciphertext is persisted.
     *
     * @return array{attempt_id:string, authorize_url:string, expires_at:string}
     * @throws DsCoreAuthException 'server_config_missing' when no Client
     *         ID is configured for the active environment.
     */
    public function beginAttempt(PDO $pdo, int $practiceId, int $userId, ?int $connectionId = null): array {
        // Practice allowlist (DS_CORE_ALLOWED_PRACTICES) is the rollout
        // gate - an empty list permits no practice and no role or plan
        // override can bypass it. Checked before configuration so an
        // unallowlisted practice can never mint an attempt.
        if (!DsCoreConfig::isPracticeAllowed($practiceId)) {
            throw new DsCoreAuthException('practice_not_allowed', 'DS Core access is not enabled for this practice.');
        }
        if (!DsCoreConfig::isConfigured()) {
            throw new DsCoreAuthException('server_config_missing', 'DS Core integration is not configured on this server.');
        }

        $attemptId = self::newUuid();
        $verifier  = self::base64Url(random_bytes(64));
        $challenge = self::base64Url(hash('sha256', $verifier, true));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::ATTEMPT_TTL_SECONDS);

        $encrypted = PIIEncryption::encrypt($verifier);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new DsCoreAuthException('internal', 'Failed to persist the authorization attempt.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO dscore_oauth_attempts
                (id, practice_id, user_id, connection_id, code_verifier_encrypted, expires_at)
            VALUES (:id, :practice_id, :user_id, :connection_id, :verifier, :expires_at)
        ");
        $stmt->execute([
            ':id'            => $attemptId,
            ':practice_id'   => $practiceId,
            ':user_id'       => $userId,
            ':connection_id' => $connectionId,
            ':verifier'      => $encrypted,
            ':expires_at'    => $expiresAt,
        ]);

        // Documented authorize parameters only: client_id, code_challenge,
        // redirect_uri, state. No response_type/scope - not in the manual.
        $url = DsCoreConfig::authBaseUrl() . '/secureLogin'
            . '?client_id='       . rawurlencode(DsCoreConfig::clientId())
            . '&code_challenge='  . rawurlencode($challenge)
            . '&redirect_uri='    . rawurlencode(self::redirectUri())
            . '&state='           . rawurlencode($attemptId);

        return [
            'attempt_id'    => $attemptId,
            'authorize_url' => $url,
            'expires_at'    => $expiresAt,
        ];
    }

    /**
     * Resolve a callback into a connected (or safely failed) connection.
     *
     * $params are the callback query params (code, iss, state, client_id or
     * error). $sessionUserId/$sessionPracticeId MUST come from the live
     * session - never from the request.
     *
     * @return array{result:string, connection_id:?int}
     *   result: 'connected' | 'cancelled' | 'invalid' | 'expired' |
     *           'replay' | 'forbidden' | 'disabled' | 'exchange_failed'
     */
    public function handleCallback(PDO $pdo, array $params, int $sessionUserId, int $sessionPracticeId): array {
        // User hit "Decline" on the DS Core consent screen (or an upstream
        // OAuth error) - nothing was consumed; the pending attempt expires.
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

        $stmt = $pdo->prepare("SELECT * FROM dscore_oauth_attempts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $state]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            return ['result' => 'invalid', 'connection_id' => null];
        }

        // The feature must still be enabled at completion time - a callback
        // arriving after DS_CORE_CONNECT was switched off mid-flow is
        // rejected without consuming the attempt, exchanging the code, or
        // touching the connection.
        if (!isFeatureEnabled('DS_CORE_CONNECT')) {
            return ['result' => 'disabled', 'connection_id' => null];
        }

        // The practice must still be allowlisted at completion time - a
        // practice removed from DS_CORE_ALLOWED_PRACTICES mid-flow cannot
        // finish connecting.
        if (!DsCoreConfig::isPracticeAllowed((int)$attempt['practice_id'])) {
            return ['result' => 'forbidden', 'connection_id' => null];
        }

        // The live session must still belong to the initiating admin in the
        // initiating practice - otherwise a callback URL intercepted or
        // shared out-of-band could attach tokens to the wrong practice.
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
            UPDATE dscore_oauth_attempts SET consumed_at = NOW()
            WHERE id = :id AND consumed_at IS NULL
        ");
        $consume->execute([':id' => $state]);
        if ($consume->rowCount() === 0) {
            return ['result' => 'replay', 'connection_id' => (int)$attempt['connection_id'] ?: null];
        }

        $verifier = PIIEncryption::decrypt((string)$attempt['code_verifier_encrypted']);
        if (!is_string($verifier) || $verifier === '') {
            return ['result' => 'exchange_failed', 'connection_id' => null];
        }

        try {
            $tokens = $this->exchangeCode($code, $verifier);
        } catch (DsCoreAuthException $e) {
            // Failed exchange: attempt is consumed either way - a code can
            // only be redeemed once upstream too, so retry must re-begin.
            $this->markExchangeFailed($pdo, (int)$attempt['practice_id']);
            return ['result' => 'exchange_failed', 'connection_id' => null];
        }

        $connectionId = $this->persistConnection(
            $pdo,
            (int)$attempt['practice_id'],
            (int)$attempt['user_id'],
            (int)($attempt['connection_id'] ?? 0) ?: null,
            $tokens
        );

        return ['result' => 'connected', 'connection_id' => $connectionId];
    }

    // ------------------------------------------------------------------
    // Token exchange / refresh (documented request formats)
    // ------------------------------------------------------------------

    /**
     * POST {apiBase}/v1beta/auth/token  (JSON body, documented field names).
     */
    private function exchangeCode(string $code, string $verifier): array {
        $response = $this->postJson('/v1beta/auth/token', [
            'code'          => $code,
            'client_id'     => DsCoreConfig::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'code_verifier' => $verifier,
            'client_secret' => DsCoreConfig::clientSecret(),
            'grant_type'    => 'authorization_code',
        ]);
        return $this->parseTokenResponse($response);
    }

    /**
     * POST {apiBase}/v1beta/auth/token:refresh with the documented
     * snake_case body (§4.2): refresh_token + client_id + client_secret.
     * No grant_type is sent - the manual does not include it here.
     */
    private function refreshTokens(string $refreshToken): array {
        $response = $this->postJson('/v1beta/auth/token:refresh', [
            'refresh_token' => $refreshToken,
            'client_id'     => DsCoreConfig::clientId(),
            'client_secret' => DsCoreConfig::clientSecret(),
        ]);
        return $this->parseTokenResponse($response);
    }

    /**
     * POST {apiBase}/v1beta/auth/token:revoke (§4.3) - form-urlencoded,
     * NOT JSON: client_id, client_secret, token, token_type_hint.
     * Returns true on a 2xx (token revoked / already invalid upstream).
     */
    private function revokeToken(string $refreshToken): bool {
        $body = http_build_query([
            'client_id'        => DsCoreConfig::clientId(),
            'client_secret'    => DsCoreConfig::clientSecret(),
            'token'            => $refreshToken,
            'token_type_hint'  => 'refresh_token',
        ], '', '&');
        $response = $this->postForm('/v1beta/auth/token:revoke', $body);
        return $this->is2xx($response);
    }

    /**
     * Parse a documented token response: accessToken, refreshToken, scope,
     * tokenType, expiresIn (string seconds).
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
            throw new DsCoreAuthException(
                $permanent ? 'invalid_credentials' : 'http_' . $status,
                'DS Core token endpoint rejected the request (HTTP ' . $status . ').',
                !$permanent
            );
        }

        $accessToken  = $body['accessToken']  ?? null;
        $refreshToken = $body['refreshToken'] ?? null;
        // expiresIn is documented as an INTEGER; the cast additionally
        // tolerates a numeric string as defensive parsing only.
        $expiresIn    = (int)($body['expiresIn'] ?? 0);
        if (!is_string($accessToken) || $accessToken === ''
            || !is_string($refreshToken) || $refreshToken === '' || $expiresIn <= 0) {
            throw new DsCoreAuthException('unexpected_response', 'DS Core token response was missing required fields.', true);
        }

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => (string)($body['tokenType'] ?? 'Bearer'),
            'scope'         => (string)($body['scope'] ?? ''),
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + $expiresIn),
        ];
    }

    /**
     * Persist exchanged tokens on the practice's ds_core connection.
     * Creates the connection row on first connect; a completed attempt
     * always lands on 'active' with a fresh expiry timestamp in config_json
     * (non-secret metadata only).
     */
    private function persistConnection(PDO $pdo, int $practiceId, int $userId, ?int $attemptConnectionId, array $tokens): int {
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
                SET status = 'active', last_error = NULL
                WHERE id = :id
            ")->execute([':id' => $connectionId]);
        }

        IntegrationCredentials::store($pdo, $connectionId, 'access_token', $tokens['access_token']);
        IntegrationCredentials::store($pdo, $connectionId, 'refresh_token', $tokens['refresh_token']);
        $this->updateConfigJson($pdo, $connectionId, [
            'token_expires_at'  => $tokens['expires_at'],
            'token_scope'       => $tokens['scope'],
            'token_type'        => $tokens['token_type'],
            'environment'       => DsCoreConfig::environment(),
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
            ':err' => 'DS Core authorization could not be completed; start the connection again.',
            ':pid' => $practiceId,
            ':p'   => self::PROVIDER,
        ]);
    }

    // ------------------------------------------------------------------
    // Refresh: proactive + on-demand, serialized across instances
    // ------------------------------------------------------------------

    /**
     * Worker entry point (called from the per-minute queue worker):
     * refresh every connected ds_core connection whose access token is
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
            // maintenance too - no DS Core calls on its behalf at all.
            if (!DsCoreConfig::isPracticeAllowed((int)$row['practice_id'])) {
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
            } catch (DsCoreAuthException $e) {
                $failed++;
                error_log('[dscore] refresh failed for connection ' . (int)$row['id'] . ': ' . $e->category);
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
     * Invalid credentials ('invalid_credentials') mark the connection
     * 'reconnect_required' - only an interactive re-authorization repairs it.
     *
     * $force bypasses the "already fresh" double-check: a business API that
     * just 401'd needs a real rotation even when the recorded expiry still
     * looks valid (server-side revocation, clock skew, scope loss).
     */
    public function refreshConnectionToken(PDO $pdo, int $connectionId, bool $force = false): void {
        $lockName = 'dscore_refresh_' . $connectionId;
        $lockStmt = $pdo->prepare("SELECT GET_LOCK(:name, :timeout)");
        $lockStmt->execute([':name' => $lockName, ':timeout' => self::REFRESH_LOCK_TIMEOUT]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new DsCoreAuthException('lock_timeout', 'Could not acquire the refresh lock.', true);
        }

        try {
            // Re-read under the lock: a concurrent refresh may already have
            // produced fresh tokens (double-check idiom across instances).
            $connection = IntegrationManager::findConnection($pdo, $connectionId);
            if (!$connection || ($connection['provider'] ?? '') !== self::PROVIDER) {
                throw new DsCoreAuthException('not_found', 'DS Core connection not found.');
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
                throw new DsCoreAuthException('invalid_credentials', 'No refresh token stored.');
            }

            $lastError = null;
            for ($attempt = 1; $attempt <= self::REFRESH_MAX_ATTEMPTS; $attempt++) {
                try {
                    $tokens = $this->refreshTokens($refreshToken);
                    IntegrationCredentials::store($pdo, $connectionId, 'access_token', $tokens['access_token']);
                    IntegrationCredentials::store($pdo, $connectionId, 'refresh_token', $tokens['refresh_token']);
                    $this->updateConfigJson($pdo, $connectionId, [
                        'token_expires_at' => $tokens['expires_at'],
                        'token_scope'      => $tokens['scope'],
                    ]);
                    $pdo->prepare("
                        UPDATE integration_connections
                        SET last_error = NULL,
                            status = CASE WHEN status = 'error' THEN 'active' ELSE status END
                        WHERE id = :id
                    ")->execute([':id' => $connectionId]);
                    return;
                } catch (DsCoreAuthException $e) {
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
            throw $lastError ?? new DsCoreAuthException('refresh_failed', 'Refresh failed.', true);
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
     *                     DsCoreAuthException('unauthorized',...) on a 401.
     */
    public function withAutoRefresh(PDO $pdo, int $connectionId, callable $fn) {
        $connection = IntegrationManager::findConnection($pdo, $connectionId);
        if (!$connection || ($connection['provider'] ?? '') !== self::PROVIDER) {
            throw new DsCoreAuthException('not_found', 'DS Core connection not found.');
        }

        $token = IntegrationCredentials::get($pdo, $connectionId, 'access_token');
        $expiresAt = $this->tokenExpiresAt($connection);
        if ($token === null || ($expiresAt !== null && $expiresAt <= time() + self::REFRESH_MARGIN_SECONDS)) {
            $this->refreshConnectionToken($pdo, $connectionId);
            $token = IntegrationCredentials::get($pdo, $connectionId, 'access_token');
        }
        if ($token === null) {
            $this->markReconnectRequired($pdo, $connectionId);
            throw new DsCoreAuthException('invalid_credentials', 'No usable access token.');
        }

        try {
            return $fn($token);
        } catch (DsCoreAuthException $e) {
            if ($e->category !== 'unauthorized') {
                throw $e;
            }
        }

        // One refresh + single retry after a business-API 401. Forced: the
        // stored expiry may still look fresh while the token was rejected,
        // so the normal double-check must not skip the rotation.
        $this->refreshConnectionToken($pdo, $connectionId, true);
        $fresh = IntegrationCredentials::get($pdo, $connectionId, 'access_token');
        if ($fresh === null) {
            $this->markReconnectRequired($pdo, $connectionId);
            throw new DsCoreAuthException('invalid_credentials', 'No usable access token after refresh.');
        }
        return $fn($fresh);
    }

    /**
     * Explicit disconnect (§4.3 revocation supported):
     *   1. Acquire the SAME cross-instance refresh lock so an in-flight
     *      rotation cannot persist replacement credentials after we wipe
     *      them - and we cannot wipe credentials a rotation is mid-write
     *      on. Lock contention fails with 'lock_timeout' rather than
     *      racing.
     *   2. Capture the refresh token, then locally delete both tokens and
     *      disable the connection INSIDE the lock. Local state is
     *      authoritative: no further refresh or business call can run.
     *   3. After the lock releases, attempt remote revocation of the
     *      captured refresh token via token:revoke. A failed revocation
     *      does NOT un-disconnect the practice and is reported honestly
     *      as remote_revoked=false (the abandoned token ages out at
     *      DS Core).
     *
     * Runs server-side only - independent of any user's DentaTrak logout.
     *
     * @return array{remote_revoked:bool}
     */
    public function disconnect(PDO $pdo, int $connectionId): array {
        $lockName = 'dscore_refresh_' . $connectionId;
        $lockStmt = $pdo->prepare("SELECT GET_LOCK(:name, :timeout)");
        $lockStmt->execute([':name' => $lockName, ':timeout' => self::REFRESH_LOCK_TIMEOUT]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new DsCoreAuthException('lock_timeout', 'Could not acquire the refresh lock.', true);
        }

        $refreshToken = null;
        try {
            $connection = IntegrationManager::findConnection($pdo, $connectionId);
            if (!$connection || ($connection['provider'] ?? '') !== self::PROVIDER) {
                throw new DsCoreAuthException('not_found', 'DS Core connection not found.');
            }
            if (($connection['status'] ?? '') !== 'disabled') {
                $refreshToken = IntegrationCredentials::get($pdo, $connectionId, 'refresh_token');
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

        // Remote revocation is best-effort AFTER local state is dead. A
        // disabled connection can never refresh, so nothing here can race.
        $remoteRevoked = false;
        if (is_string($refreshToken) && $refreshToken !== '') {
            try {
                $remoteRevoked = $this->revokeToken($refreshToken);
            } catch (DsCoreAuthException $e) {
                error_log('[dscore] remote revocation failed for connection ' . $connectionId . ': ' . $e->category);
            }
        }
        return ['remote_revoked' => $remoteRevoked];
    }

    // ------------------------------------------------------------------
    // Status helpers
    // ------------------------------------------------------------------

    /**
     * Public connection-state projection for the ds_core provider.
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
                last_error = 'DS Core connection expired - reconnect required.'
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
    // OpenDentalPortalClient). Errors are thrown as DsCoreAuthException
    // with safe categories; bodies are never logged.
    // ------------------------------------------------------------------

    private function postJson(string $path, array $payload): array {
        $url = DsCoreConfig::apiBaseUrl() . $path;
        $body = json_encode($payload);
        $headers = ['Content-Type: application/json'];

        if ($this->transport !== null) {
            return call_user_func($this->transport, 'POST', $url, $headers, $body);
        }
        return self::curlRequest('POST', $url, $headers, $body);
    }

    /** Form-encoded POST (used by the documented revocation endpoint). */
    private function postForm(string $path, string $encodedBody): array {
        $url = DsCoreConfig::apiBaseUrl() . $path;
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        if ($this->transport !== null) {
            return call_user_func($this->transport, 'POST', $url, $headers, $encodedBody);
        }
        return self::curlRequest('POST', $url, $headers, $encodedBody);
    }

    private function is2xx(array $response): bool {
        $status = (int)($response['status'] ?? 0);
        return $status >= 200 && $status < 300;
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
            throw new DsCoreAuthException('network', 'DS Core request failed to complete.', true);
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
