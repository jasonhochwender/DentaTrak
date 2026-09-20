<?php
/**
 * IntegrationCredentials
 *
 * Reusable encrypted credential store for PMS integrations, backed by the
 * integration_credentials table and PIIEncryption (AES-256-CBC, env-provided
 * ENCRYPTION_KEY). There is intentionally NO plaintext column in the table:
 * every value that reaches the database is ciphertext.
 *
 * SECURITY CONTRACT:
 *  - Decrypted credentials exist in memory only, server-side, for as long as
 *    an adapter needs them. They are never serialized into connection
 *    arrays, API responses, logs, or exception messages.
 *  - FAIL CLOSED: PIIEncryption silently falls back to a randomly generated
 *    key when ENCRYPTION_KEY is unset, which would produce ciphertext that
 *    can never be decrypted in a later request. store()/get*() therefore
 *    verify a configured key exists BEFORE touching PIIEncryption.
 *  - Exception messages never include the credential key or value.
 */

require_once __DIR__ . '/../encryption.php';

class IntegrationCredentials {

    /**
     * Verify an encryption key is actually configured. PIIEncryption would
     * otherwise auto-generate an ephemeral key and log a warning, producing
     * undecryptable credentials.
     */
    private static function assertEncryptionKeyConfigured(): void {
        $key = $_ENV['ENCRYPTION_KEY'] ?? $_SERVER['ENCRYPTION_KEY'] ?? null;
        if ($key === null && defined('ENCRYPTION_KEY')) {
            $key = ENCRYPTION_KEY;
        }
        if ($key === null || $key === '') {
            throw new RuntimeException('Integration credential storage is unavailable: encryption key is not configured.');
        }
    }

    /**
     * Encrypt and store (upsert) one credential for a connection.
     * $plaintext is encrypted before the INSERT; nothing else reaches the DB.
     */
    public static function store(PDO $pdo, int $connectionId, string $credentialKey, string $plaintext): void {
        self::assertEncryptionKeyConfigured();
        if ($credentialKey === '') {
            throw new InvalidArgumentException('Credential key must not be empty.');
        }

        $encrypted = PIIEncryption::encrypt($plaintext);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new RuntimeException('Credential encryption failed.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO integration_credentials (connection_id, credential_key, credential_value_encrypted)
            VALUES (:connection_id, :credential_key, :credential_value)
            ON DUPLICATE KEY UPDATE credential_value_encrypted = VALUES(credential_value_encrypted)
        ");
        $stmt->execute([
            ':connection_id'    => $connectionId,
            ':credential_key'   => $credentialKey,
            ':credential_value' => $encrypted,
        ]);
    }

    /**
     * Fetch and decrypt a single credential. Returns null when absent.
     * Server-side use only - the return value is plaintext.
     */
    public static function get(PDO $pdo, int $connectionId, string $credentialKey): ?string {
        $stmt = $pdo->prepare("
            SELECT credential_value_encrypted
            FROM integration_credentials
            WHERE connection_id = :connection_id AND credential_key = :credential_key
            LIMIT 1
        ");
        $stmt->execute([':connection_id' => $connectionId, ':credential_key' => $credentialKey]);
        $encrypted = $stmt->fetchColumn();

        if ($encrypted === false || $encrypted === null || $encrypted === '') {
            return null;
        }

        self::assertEncryptionKeyConfigured();
        $plaintext = PIIEncryption::decrypt($encrypted);
        return is_string($plaintext) ? $plaintext : null;
    }

    /**
     * Fetch and decrypt ALL credentials for a connection as
     * credential_key => plaintext. Server-side use only (adapters, workers);
     * never return this map from an HTTP endpoint.
     */
    public static function getAll(PDO $pdo, int $connectionId): array {
        $stmt = $pdo->prepare("
            SELECT credential_key, credential_value_encrypted
            FROM integration_credentials
            WHERE connection_id = :connection_id
        ");
        $stmt->execute([':connection_id' => $connectionId]);

        $credentials = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            self::assertEncryptionKeyConfigured();
            $plaintext = PIIEncryption::decrypt($row['credential_value_encrypted']);
            if (is_string($plaintext)) {
                $credentials[$row['credential_key']] = $plaintext;
            }
        }
        return $credentials;
    }

    /**
     * True when a given credential exists. Does not decrypt.
     */
    public static function has(PDO $pdo, int $connectionId, string $credentialKey): bool {
        $stmt = $pdo->prepare("
            SELECT 1 FROM integration_credentials
            WHERE connection_id = :connection_id AND credential_key = :credential_key
            LIMIT 1
        ");
        $stmt->execute([':connection_id' => $connectionId, ':credential_key' => $credentialKey]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * List the credential_key names stored for a connection (keys only -
     * never values). Safe for status projections so the UI can show
     * "Developer key: Configured" without ever seeing a secret.
     *
     * @return string[]
     */
    public static function listKeys(PDO $pdo, int $connectionId): array {
        $stmt = $pdo->prepare("
            SELECT credential_key FROM integration_credentials
            WHERE connection_id = :connection_id
            ORDER BY credential_key ASC
        ");
        $stmt->execute([':connection_id' => $connectionId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Number of credentials stored for a connection. Safe for status
     * projections ("credentials configured: yes/no") - reveals existence,
     * never values.
     */
    public static function countForConnection(PDO $pdo, int $connectionId): int {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM integration_credentials WHERE connection_id = :connection_id
        ");
        $stmt->execute([':connection_id' => $connectionId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete a single credential. Whole-connection deletion normally happens
     * through the ON DELETE CASCADE on integration_credentials.connection_id.
     */
    public static function delete(PDO $pdo, int $connectionId, string $credentialKey): void {
        $stmt = $pdo->prepare("
            DELETE FROM integration_credentials
            WHERE connection_id = :connection_id AND credential_key = :credential_key
        ");
        $stmt->execute([':connection_id' => $connectionId, ':credential_key' => $credentialKey]);
    }
}
