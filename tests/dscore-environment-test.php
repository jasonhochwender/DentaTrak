<?php
// DS Core rollout checks: wildcard allowlist, environment separation.
// Pure config-level (no DB) - manipulates process env only.
require_once __DIR__ . '/../api/integrations/DsCoreConfig.php';

$pass = 0; $fail = 0;
function check($ok, $msg) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS' : 'FAIL') . ": $msg\n"; }

// Wildcard allowlist
putenv('DS_CORE_ALLOWED_PRACTICES=*');
check(DsCoreConfig::allowAllPractices() === true, 'wildcard detected');
check(DsCoreConfig::isPracticeAllowed(32), 'wildcard allows 32');
check(DsCoreConfig::isPracticeAllowed(99999), 'wildcard allows arbitrary practice');
check(DsCoreConfig::isPracticeAllowed(0) === false, 'wildcard rejects id 0');

// Enumerated list still works
putenv('DS_CORE_ALLOWED_PRACTICES=32,45');
check(!DsCoreConfig::allowAllPractices(), 'enumerated list not wildcard');
check(DsCoreConfig::isPracticeAllowed(32) && !DsCoreConfig::isPracticeAllowed(46), 'enumerated list honored');

// Unset/empty deny all (fail closed)
putenv('DS_CORE_ALLOWED_PRACTICES=');
check(!DsCoreConfig::isPracticeAllowed(32), 'empty allowlist denies');
putenv('DS_CORE_ALLOWED_PRACTICES');
// lookalikes must not count
foreach (['all','ALL','true','*all'] as $v) {
    putenv("DS_CORE_ALLOWED_PRACTICES=$v");
    check(!DsCoreConfig::isPracticeAllowed(32), "lookalike '$v' denied");
}

// Environment separation
putenv('DS_CORE_ENVIRONMENT=production');
check(DsCoreConfig::environment() === 'production', 'env resolves production');
$prod = ['config_json' => json_encode(['environment' => 'production'])];
$sbx  = ['config_json' => json_encode(['environment' => 'sandbox'])];
$none = ['config_json' => '{}'];
check(DsCoreConfig::connectionMatchesEnvironment($prod) === true, 'prod conn allowed under prod');
check(DsCoreConfig::connectionMatchesEnvironment($sbx) === false, 'sandbox conn blocked under prod');
check(DsCoreConfig::connectionMatchesEnvironment($none) === false, 'missing env treated as sandbox -> blocked under prod');
putenv('DS_CORE_ENVIRONMENT=sandbox');
check(DsCoreConfig::connectionMatchesEnvironment($sbx) === true, 'sandbox conn allowed under sandbox');
check(DsCoreConfig::connectionMatchesEnvironment($prod) === false, 'prod conn blocked under sandbox');
check(DsCoreConfig::connectionMatchesEnvironment(null) === false, 'null connection denied');

// Endpoint separation
putenv('DS_CORE_ENVIRONMENT=sandbox');
check(strpos(DsCoreConfig::authBaseUrl(), 'r2.dscore.com') !== false, 'sandbox auth host is regional r2');
check(DsCoreConfig::apiBaseUrl() === 'https://api.dscore.com', 'token host global in sandbox');
putenv('DS_CORE_ENVIRONMENT=production');
check(DsCoreConfig::apiBaseUrl() === 'https://api.dscore.com', 'token host global in prod too');

// Credential resolution (no values exposed - presence only)
putenv('DS_CORE_PROD_CLIENT_ID=x'); putenv('DS_CORE_PROD_CLIENT_SECRET=y');
putenv('DS_CORE_ENVIRONMENT=production');
check(DsCoreConfig::isConfigured(), 'prod creds resolve');
putenv('DS_CORE_ENVIRONMENT=sandbox');
check(!DsCoreConfig::isConfigured() || getenv('DS_CORE_SANDBOX_CLIENT_ID'), 'sandbox uses sandbox creds');
putenv('DS_CORE_PROD_CLIENT_ID'); putenv('DS_CORE_PROD_CLIENT_SECRET');

echo "--- $pass passed, $fail failed ---\n";
exit($fail > 0 ? 1 : 0);
