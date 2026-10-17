<?php
/**
 * ScannerDownloadException
 *
 * Failure signal for ScannerConnectorInterface::downloadFile().
 *
 * retryable = true  -> transient failure (network/timeout/5xx/vendor busy);
 *                      the pipeline parks the file as 'failed' with
 *                      error_code 'download_failed' and a user retry may
 *                      re-attempt it (bounded by MAX_DOWNLOAD_ATTEMPTS).
 * retryable = false -> permanent failure (gone/forbidden/corrupt); the file
 *                      is parked 'failed' with no further automatic work.
 *
 * The message must be a safe category string - never vendor payloads, URLs,
 * credentials, or patient data.
 */

class ScannerDownloadException extends RuntimeException {

    private bool $retryable;
    private string $errorCode;

    public function __construct(string $message = '', bool $retryable = true, string $errorCode = 'download_failed', ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
        $this->errorCode = $errorCode;
    }

    public function isRetryable(): bool {
        return $this->retryable;
    }

    /** Safe error category for storage/display - never free-form text. */
    public function getErrorCode(): string {
        return $this->errorCode;
    }
}
