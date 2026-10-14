<?php
/**
 * Adapter registration bootstrap.
 *
 * Central place where concrete PMS adapters are registered with
 * IntegrationManager's factory registry. Any endpoint or CLI tool that may
 * need to resolve an adapter requires this file once. Registration is lazy:
 * the adapter class file is only loaded when a matching provider connection
 * is actually used.
 */

require_once __DIR__ . '/IntegrationManager.php';
require_once __DIR__ . '/adapters/OpenDentalAdapter.php';

IntegrationManager::registerAdapterFactory('open_dental', function (array $connection) {
    // Write capability is scoped to the connection's own explicit opt-in
    // (config_json.outbound.enabled): an adapter built for a connection
    // without it throws writes_disabled before any network call.
    $config = json_decode((string)($connection['config_json'] ?? ''), true);
    $outbound = is_array($config) ? ($config['outbound'] ?? null) : null;
    $writesEnabled = is_array($outbound) && !empty($outbound['enabled']);
    return new OpenDentalAdapter(null, $writesEnabled);
});
