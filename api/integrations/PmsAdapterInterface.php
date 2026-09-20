<?php
/**
 * PmsAdapterInterface
 *
 * Contract every PMS (practice-management system) adapter implements.
 * IntegrationManager resolves the adapter for a connection's `provider`
 * string; all provider-specific knowledge (endpoints, auth scheme, record
 * shape) lives behind this interface so nothing Open Dental-specific leaks
 * into the rest of the application.
 *
 * Phase 1 scope is read-only PMS -> DentaTrak synchronization of lab cases.
 * This interface is intentionally small; do not add write-back, scheduling,
 * or admin methods here until a real integration needs them.
 */

interface PmsAdapterInterface {

    /**
     * Stable provider key stored on integration_connections.provider
     * (e.g. 'open_dental'). Lowercase snake_case; never localized.
     */
    public function getProvider(): string;

    /**
     * Validate that the supplied credentials and config can reach and
     * authenticate against the external system. Called when a connection is
     * first configured; MUST NOT mutate remote state.
     *
     * @param array $credentials Decrypted credential map (credential_key => plaintext)
     * @param array $config      Decoded non-secret config_json
     * @return array {
     *   success: bool,
     *   message: string,                 // human-readable, PHI-free, credential-free
     *   external_account_id: ?string     // provider-side account/site id, if discoverable
     * }
     */
    public function testConnection(array $credentials, array $config): array;

    /**
     * Fetch external lab cases eligible for synchronization.
     *
     * @param array       $credentials Decrypted credential map
     * @param array       $config      Decoded non-secret config_json
     * @param string|null $cursor      Watermark from the previous successful
     *                                 run (connection.last_success_at window),
     *                                 null for initial/backfill runs
     * @param int         $limit       Max records to return this call
     * @return array {
     *   cases: CanonicalCase[],        // normalized, ready for SyncEngine
     *   next_cursor: ?string,          // watermark to persist on success
     *   has_more: bool                 // true if caller should page again
     * }
     */
    public function fetchCases(array $credentials, array $config, ?string $cursor, int $limit): array;

    /**
     * Convert one provider-specific raw record into a CanonicalCase.
     * Kept separate from fetchCases() so normalization is unit-testable
     * without a network. MUST NOT throw on missing optional fields;
     * CanonicalCase tolerates absent data.
     */
    public function normalizeCase(array $rawRecord): CanonicalCase;
}
