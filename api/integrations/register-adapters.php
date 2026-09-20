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
    return new OpenDentalAdapter();
});
