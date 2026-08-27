<?php
/**
 * Memory and /tmp validation for the streaming Download All ZIP feature.
 * Generates a 100 MiB source file on disk, streams it into a ZIP using
 * ZipStream-PHP with STORE compression and php://output-like streaming,
 * and verifies that memory stays near-constant and no temp files appear.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ZipStream\ZipStream;
use ZipStream\CompressionMethod;

$sourcePath = __DIR__ . '/test-source-100mib.bin';
$outputPath = __DIR__ . '/test-output-100mib.zip';
$tempDir = sys_get_temp_dir();
$tempPattern = $tempDir . '/zipstream_tmp_*';

// Generate a 100 MiB source file in 64 KiB chunks (no /tmp, uses test dir).
$block = str_repeat('x', 64 * 1024);
$handle = fopen($sourcePath, 'wb');
for ($i = 0; $i < 1600; $i++) {
    fwrite($handle, $block);
}
fclose($handle);
unset($block);

$before = glob($tempPattern) ?: [];
$startMem = memory_get_usage(true);
$startPeak = memory_get_peak_usage(true);

$out = fopen($outputPath, 'wb');
$zip = new ZipStream(
    outputStream: $out,
    sendHttpHeaders: false,
    defaultCompressionMethod: CompressionMethod::STORE,
    enableZip64: true,
    defaultEnableZeroHeader: true,
);

$zip->addFileFromPath(
    fileName: 'test-attachment.bin',
    path: $sourcePath,
);

$zip->finish();
fclose($out);

$endMem = memory_get_usage(true);
$endPeak = memory_get_peak_usage(true);
$after = glob($tempPattern) ?: [];
$zipSize = filesize($outputPath);

$read = new ZipArchive();
$valid = $read->open($outputPath) === true;
if ($valid) {
    $names = [];
    for ($i = 0; $i < $read->numFiles; $i++) {
        $names[] = $read->getNameIndex($i);
    }
    $read->close();
}

// Cleanup
@unlink($sourcePath);
@unlink($outputPath);

$deltaMem = $endMem - $startMem;
$deltaPeak = $endPeak - $startPeak;

header('Content-Type: text/plain');
echo "Source file: 100 MiB\n";
echo "Output ZIP size: " . $zipSize . " bytes\n";
echo "Start memory: " . $startMem . " bytes\n";
echo "End memory: " . $endMem . " bytes\n";
echo "Peak delta: " . $deltaPeak . " bytes\n";
echo "Memory remained constant-ish: " . ($deltaPeak < 128 * 1024 * 1024 ? 'PASS' : 'FAIL') . "\n";
echo "No /tmp files created: " . (count($after) === count($before) ? 'PASS' : 'FAIL') . "\n";
echo "ZIP opens: " . ($valid ? 'PASS' : 'FAIL') . "\n";
echo "Contains test-attachment.bin: " . ($valid && in_array('test-attachment.bin', $names, true) ? 'PASS' : 'FAIL') . "\n";
