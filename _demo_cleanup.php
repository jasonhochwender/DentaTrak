<?php
$c = file_get_contents('index.php');

// 1) session stamp (column 0 in file)
$c = str_replace("// Server-side render timestamp for the demo-request timing check:\n// api/demo-request.php rejects submissions that arrive implausibly fast.\n\$_SESSION['demo_form_rendered_at'] = time();\n", '', $c);

// 2) orphaned demo-modal JS: everything between the 'use strict' opener and
//    the insights IIFE that follows it
$end = strpos($c, "var snapshot = document.querySelector('#insights");
if ($end === false) { echo "insights sentinel missing\n"; exit(1); }
$end = strrpos(substr($c, 0, $end), '(function() {');
$use = strrpos(substr($c, 0, $end), "'use strict';");
if ($use === false || $end === false || $end <= $use) { echo 'bounds miss u=' . var_export($use, true) . ' e=' . var_export($end, true) . "\n"; exit(1); }
$mid = substr($c, $use, $end - $use);
echo 'removing ' . strlen($mid) . " chars; showStatus=" . (strpos($mid, 'showStatus') !== false ? 'y' : 'n') . "; openTriggers=" . (strpos($mid, 'openTriggers') !== false ? 'y' : 'n') . "\n";
$c = substr($c, 0, $use) . substr($c, $end);
file_put_contents('index.php', $c);
echo "done\n";
