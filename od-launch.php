<?php
// Open Dental toolbar launch endpoint.
//
// Configured in Open Dental as a Custom Bridge (Setup -> Program Links):
//   Path of file to open:            https://<host>/od-launch.php
//   Optional command line arguments: ?pat=[PatNum]
//
// This endpoint intentionally stays thin: it validates the incoming patient
// hint and routes to the right authenticated surface - login.php when a
// sign-in is needed (the od_pat hint is preserved through login, 2FA, and
// the practice chooser via sessionStorage, exactly like notification deep
// links) or main.php directly. The PatNum is a lookup hint ONLY - it is
// never treated as authorization; api/od-patient-cases.php enforces session
// auth and practice isolation before any patient data is touched.

require_once __DIR__ . '/api/session.php';

$pat = '';
foreach (['pat', 'patnum', 'PatNum'] as $key) {
    if (isset($_GET[$key]) && preg_match('/^\d{1,20}$/', (string)$_GET[$key])) {
        $pat = (string)$_GET[$key];
        break;
    }
}

if (empty($_SESSION['db_user_id'])) {
    $target = 'login.php';
    if ($pat !== '') {
        $target .= '?od_pat=' . urlencode($pat);
    }
} else {
    $target = 'main.php';
    if ($pat !== '') {
        $target .= '?od_pat=' . urlencode($pat);
    }
}

header('Location: ' . $target, true, 302);
exit;
