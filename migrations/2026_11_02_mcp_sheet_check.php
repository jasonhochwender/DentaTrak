<?php
/** Isolated sheet-exercise for McpSheetSync: appends a clearly-marked
 * test row, runs preCheck + markSent, verifies, clears it. No email. */
require_once '/var/www/html/api/appConfig.php';
require_once '/var/www/html/api/marketing/ProspectSheetSync.php';
require_once '/var/www/html/api/marketing/McpSheetSync.php';

$sheetId = '1r7G5QaKe69LAU93d0WoWDSXIsWVr0cbDnpjF7z1tARc';
$email = 'mcp-sheet-test-' . bin2hex(random_bytes(4)) . '@test.invalid';
$base = 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId;
$tok = ProspectSheetSync::accessTokenPublic();
try {
    $sync = new McpSheetSync();
    // Test record: Public Email (col P=15) + marker in Practice Name (A)
    $row = array_fill(0, 20, '');
    $row[0] = 'ZZ_MCP_TEST_DELETE';
    $row[15] = $email;
    ProspectSheetSync::httpPublic('POST', $base . '/values/' . rawurlencode('Prospects') . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
        ['values' => [$row]], true, $tok);
    echo "appended test row for $email\n";

    $sync2 = new McpSheetSync(); // fresh instance re-reads sheet
    $pre = $sync2->preCheck($email);
    echo 'preCheck(new): ' . json_encode($pre) . "\n";

    $ok = $sync2->markSent($email, 'mcp-test-campaign', 'testmsg-123');
    echo 'markSent: ' . var_export($ok, true) . "\n";

    $sync3 = new McpSheetSync();
    $p = $sync3->prospectRow($email);
    $h = null;
    // verify status cell + notes carry the message id
    $vals = json_decode(ProspectSheetSync::httpPublic('GET', $base . '/values/' . rawurlencode('Prospects!A' . $p['row'] . ':AZ' . $p['row']), null, false, $tok), true);
    $cells = $vals['values'][0] ?? [];
    echo 'post-write row snippet: ' . json_encode(array_slice($cells, 0, 22)) . "\n";
} finally {
    // Clear the test row (values only; row itself remains as blank).
    if (!empty($p['row'])) ProspectSheetSync::httpPublic('POST', $base . '/values/' . rawurlencode('Prospects!A' . $p['row'] . ':AZ' . $p['row']) . ':clear', new stdClass(), true, $tok);
    echo "test row cleared\n";
}
