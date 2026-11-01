<?php
/**
 * Standardized marketing-email footer + unsubscribe headers.
 * Centralize so every campaign/Gmail draft gets identical treatment —
 * 10pt subtle-gray footer, postal address, recipient-specific link.
 * Transactional mail must not call this.
 */
final class MarketingFooter
{
    public const ADDRESS_HTML =
        'DentaTrak<br>PO Box 1<br>Tryon, NC 28782';
    public const ADDRESS_TEXT = "DentaTrak\nPO Box 1\nTryon, NC 28782";

    /** HTML footer block appended at the very bottom of marketing emails. */
    public static function html(string $unsubscribeUrl): string
    {
        $u = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');
        return '<div style="margin-top:32px;padding-top:14px;border-top:1px solid #e5e7eb;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#9ca3af;line-height:1.5;text-align:left;">'
            . self::ADDRESS_HTML . '<br>'
            . '<a href="' . $u . '" style="font-size:10pt;color:#9ca3af;text-decoration:underline;">Unsubscribe</a>'
            . '</div>';
    }

    /** Plain-text equivalent. */
    public static function text(string $unsubscribeUrl): string
    {
        return "\n\n-- \n" . self::ADDRESS_TEXT . "\nUnsubscribe: " . $unsubscribeUrl . "\n";
    }

    /** RFC 8058 one-click headers; the POST endpoint performs the mutation. */
    public static function headers(string $unsubscribeUrl): array
    {
        return [
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
}
