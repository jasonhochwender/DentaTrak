<?php
/**
 * Typed sheet-load failure so callers can distinguish WHY prospect data
 * is unavailable instead of collapsing every failure into "empty".
 *
 * Codes surfaced to the admin UI (never contain credentials or row data):
 *   auth        - Google token acquisition failed (metadata server / SA)
 *   forbidden   - Sheets API returned 403 (service identity lacks access)
 *   not_found   - spreadsheet/tab missing or returned no values (404/400)
 *   headers     - sheet loaded but required columns are absent
 *   api         - any other Sheets API or transport failure
 *   db          - database failure while annotating prospects (set by caller)
 *   unknown     - unclassified failure
 */
final class SheetSyncException extends RuntimeException
{
    public const AUTH      = 'auth';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const HEADERS   = 'headers';
    public const API       = 'api';
    public const DB        = 'db';
    public const UNKNOWN   = 'unknown';

    public string $errorCode;

    public function __construct(string $code, string $message = '', ?Throwable $previous = null)
    {
        $this->errorCode = $code;
        parent::__construct($message !== '' ? $message : 'sheet sync ' . $code, 0, $previous);
    }
}
