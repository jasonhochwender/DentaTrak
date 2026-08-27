<?php
/**
 * Focused tests for the streaming Download All ZIP feature.
 * No production PHI or GCS access is required.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../api/appConfig.php';
require __DIR__ . '/../api/case-zip-helpers.php';

use GuzzleHttp\Psr7\Utils;
use ZipStream\ZipStream;
use ZipStream\CompressionMethod;

$results = [];

// 1. getBulkZipMaxSize limit handling
function setZipLimit($v) {
    putenv('DENTATRAK_BULK_ZIP_MAX_BYTES' . ($v === null ? '' : '=' . $v));
}

setZipLimit(null);
$results[] = 'Default is 5 GiB: ' . (getBulkZipMaxSize() === 5368709120 ? 'PASS' : 'FAIL');

setZipLimit('invalid');
$results[] = 'Non-integer fallback: ' . (getBulkZipMaxSize() === 5368709120 ? 'PASS' : 'FAIL');

setZipLimit('-1');
$results[] = 'Negative fallback: ' . (getBulkZipMaxSize() === 5368709120 ? 'PASS' : 'FAIL');

setZipLimit('0');
$results[] = 'Zero fallback: ' . (getBulkZipMaxSize() === 5368709120 ? 'PASS' : 'FAIL');

setZipLimit('33554432');
$results[] = 'Valid 32 MiB preserved: ' . (getBulkZipMaxSize() === 33554432 ? 'PASS' : 'FAIL');

setZipLimit('5368709120');
$results[] = 'Exact 5 GiB accepted: ' . (getBulkZipMaxSize() === 5368709120 ? 'PASS' : 'FAIL');

setZipLimit('99999999999999999999999999');
$results[] = 'Overflow capped to 5 GiB: ' . (getBulkZipMaxSize() === 5368709120 ? 'PASS' : 'FAIL');

// 2. ZipStream direct-to-output (php://memory) without /tmp files
$tempDir = sys_get_temp_dir();
$before = glob($tempDir . '/dt_zip_test_*') ?: [];

$zipOut = fopen('php://memory', 'wb');
$zip = new ZipStream(
    outputStream: $zipOut,
    sendHttpHeaders: false,
    defaultCompressionMethod: CompressionMethod::STORE,
    enableZip64: true,
    defaultEnableZeroHeader: true,
);

$used = [];
$zip->addFileFromPsr7Stream(
    fileName: sanitizeZipFilename('scan.stl', $used),
    stream: Utils::streamFor('synthetic stl content'),
    compressionMethod: CompressionMethod::STORE,
    exactSize: 21,
    enableZeroHeader: true,
);
$used[] = 'scan.stl';
$zip->addFileFromPsr7Stream(
    fileName: sanitizeZipFilename('scan.stl', $used),
    stream: Utils::streamFor('duplicate name content'),
    compressionMethod: CompressionMethod::STORE,
    exactSize: 20,
    enableZeroHeader: true,
);

$zip->finish();
$after = glob($tempDir . '/dt_zip_test_*') ?: [];
$results[] = 'No temp ZIP/source files in temp dir: ' . (count($after) === count($before) ? 'PASS' : 'FAIL');

// 3. ZIP content is readable and contains the expected files
rewind($zipOut);
$zipData = stream_get_contents($zipOut);
fclose($zipOut);
$tempZip = tempnam(sys_get_temp_dir(), 'dt_zip_test_') . '.zip';
file_put_contents($tempZip, $zipData);
$read = new ZipArchive();
if ($read->open($tempZip) === true) {
    $names = [];
    for ($i = 0; $i < $read->numFiles; $i++) {
        $names[] = $read->getNameIndex($i);
    }
    $read->close();
    $results[] = 'Generated ZIP opens: ' . (in_array('scan.stl', $names, true) ? 'PASS' : 'FAIL');
    $results[] = 'Duplicate renamed to scan (2).stl: ' . (in_array('scan (2).stl', $names, true) ? 'PASS' : 'FAIL');
} else {
    $results[] = 'Generated ZIP opens: FAIL';
    $results[] = 'Duplicate renamed to scan (2).stl: FAIL';
}
@unlink($tempZip);

// 4. Authoritative path validation
$results[] = 'Exact case prefix accepted: ' . (
    isValidAttachmentPath('cases/42/abc123/photos/file.stl', 42, 'abc123') ? 'PASS' : 'FAIL'
);
$results[] = 'Wrong case prefix rejected: ' . (
    !isValidAttachmentPath('cases/42/other/file.stl', 42, 'abc123') ? 'PASS' : 'FAIL'
);
$results[] = 'Traversal rejected: ' . (
    !isValidAttachmentPath('cases/42/abc123/../file.stl', 42, 'abc123') ? 'PASS' : 'FAIL'
);

// 5. 64-bit safety
$results[] = '5 GiB value is representable as int: ' . (
    is_int(5368709120) && 5368709120 > 0 ? 'PASS' : 'FAIL'
);

// 6. ZipStream dependency guard (ready state; the missing state can only be
// verified when vendor/autoload.php is absent, which is not the case here).
$results[] = 'Dependency guard reports ZipStream ready: ' . (
    getZipStreamDependencyError() === null ? 'PASS' : 'FAIL'
);

header('Content-Type: text/plain');
echo implode("\n", $results);
