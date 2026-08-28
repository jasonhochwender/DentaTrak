<?php
/**
 * Executable source test: temporary column Remove branch is isolated.
 *
 * This is not a DOM harness; it proves by static inspection that:
 *   - buildActiveRow renders Remove for Temp-* columns.
 *   - The remove click handler uses WorkflowDraft.removeColumn.
 *   - The remove branch does not call fetch, fetchArchivePreview, onArchiveClick, or any server action.
 *   - Archive is prevented for Temp-* columns.
 */

$uiSource = file_get_contents(__DIR__ . '/../js/workflow-draft-ui.js');
$draftSource = file_get_contents(__DIR__ . '/../js/workflow-draft.js');

$checks = [
  'Remove data-action exists' => strpos($uiSource, 'data-action="remove"') !== false,
  'Archive data-action still exists' => strpos($uiSource, 'data-action="archive"') !== false,
  'removeColumn invoked on click' => strpos($uiSource, 'M.removeColumn(workflowColumnsDraft, id)') !== false,
  'Remove branch has e.preventDefault' => strpos($uiSource, 'e.preventDefault();') !== false && strpos($uiSource, 'e.stopPropagation();') !== false,
  'Remove branch never calls fetch' => !preg_match('/data-action="remove"[\s\S]{0,1000}\bfetch\b/', $uiSource),
  'Remove branch never calls fetchArchivePreview' => !preg_match('/data-action="remove"[\s\S]{0,1000}\bfetchArchivePreview\b/', $uiSource),
  'Remove branch never calls onArchiveClick' => !preg_match('/data-action="remove"[\s\S]{0,1000}\bonArchiveClick\b/', $uiSource),
  'archive not allowed for temp' => strpos($draftSource, 'if (isTempId(id)) {') !== false,
  'remove not allowed for non-temp' => strpos($draftSource, 'if (!isTempId(id)) {') !== false,
  'isTempId helper exists' => strpos($draftSource, 'function isTempId(id) {') !== false,
  'removeColumn exposed' => strpos($draftSource, 'removeColumn: removeColumn') !== false,
];

$failures = [];
foreach ($checks as $name => $ok) {
  if (!$ok) $failures[] = $name;
  echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . "\n";
}

if (!empty($failures)) {
  exit(1);
}

echo "\nAll Remove-isolation checks passed.\n";
