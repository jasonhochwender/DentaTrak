<?php
/**
 * Static regression test: api/get-analytics.php joins cases_cache to the
 * authorized_case_ids temp table, which also has a case_id column. Any
 * unqualified `case_id` in a SELECT list is ambiguous and makes MySQL throw
 * "Column 'case_id' in field list is ambiguous" - an unguarded query then
 * 500s the entire Practice Insights endpoint (production regression).
 *
 * This test needs no database: it scans the endpoint source for SELECT
 * lists feeding FROM ... JOIN authorized_case_ids and fails if a bare
 * case_id column appears unqualified.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$src = file_get_contents(__DIR__ . '/../api/get-analytics.php');
if ($src === false) {
    fwrite(STDERR, "FAIL: cannot read api/get-analytics.php\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

// Extract every SELECT ... FROM ... JOIN authorized_case_ids statement,
// including ones split across heredoc-style double-quoted PHP strings.
// We approximate by scanning for 'authorized_case_ids' and walking backwards
// to the nearest SELECT keyword within the same SQL string literal.
$violations = [];
$offset = 0;
while (($pos = strpos($src, 'authorized_case_ids', $offset)) !== false) {
    // Walk back to the enclosing SQL string start (quote char) then forward
    // to find the SELECT that begins this statement.
    $stmtStart = strrpos(substr($src, 0, $pos), 'SELECT');
    if ($stmtStart === false) {
        $offset = $pos + 1;
        continue;
    }
    // Bound the SELECT list: text between SELECT and FROM.
    $fromPos = strpos($src, 'FROM', $stmtStart);
    if ($fromPos === false || $fromPos > $pos) {
        $offset = $pos + 1;
        continue;
    }
    $selectList = substr($src, $stmtStart + 6, $fromPos - $stmtStart - 6);
    // Only inspect the FIRST select list of a (possibly nested) statement;
    // subqueries repeating the join are scanned on their own iteration.
    // Flag a bare case_id token not preceded by a qualifier (word.char).
    if (preg_match('/(?<![\w.])case_id(?!\s*\()/', $selectList, $m, PREG_OFFSET_CAPTURE)) {
        // Allow 'case_id' inside JOIN/ON? No - selectList ends at first FROM.
        $violations[] = 'SELECT list contains unqualified case_id: "'
            . trim(preg_replace('/\s+/', ' ', $selectList)) . '"';
    }
    $offset = $pos + 1;
}

check(
    'no unqualified case_id in authorized_case_ids join SELECT lists',
    count($violations) === 0,
    implode('; ', array_slice($violations, 0, 3))
);

// Sanity: the file must actually contain the join pattern, otherwise the
// test would pass vacuously if the endpoint were refactored away.
check(
    'endpoint still queries via authorized_case_ids',
    strpos($src, 'INNER JOIN authorized_case_ids') !== false
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
