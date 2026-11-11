<?php
/**
 * Standardized marketing-email footer + unsubscribe headers.
 *
 * Approved design: centered, small-font footer applied automatically to
 * EVERY marketing message at send time (MarketingMailer appends it; it is
 * never stored in campaign content). Transactional mail must not call
 * this.
 *
 * Contents: DentaTrak logo linked to https://dentatrak.com, resource
 * links, Privacy Policy, Contact Us, copyright, postal address, and the
 * recipient-specific unsubscribe link.
 */
final class MarketingFooter
{
    public const SITE        = 'https://dentatrak.com';
    public const LOGO_URL    = 'https://dentatrak.com/images/main.png';
    public const RESOURCES   = 'https://dentatrak.com/resources';
    public const PRIVACY     = 'https://dentatrak.com/privacy';
    public const CONTACT     = 'mailto:support@dentatrak.com';
    public const ADDRESS_HTML =
        'DentaTrak<br>PO Box 1<br>Tryon, NC 28782';
    public const ADDRESS_TEXT = "DentaTrak\nPO Box 1\nTryon, NC 28782";

    private static function link(string $url, string $label): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" style="color:#6b7280;text-decoration:underline;">' . $label . '</a>';
    }

    /** HTML footer block appended at the very bottom of marketing emails. */
    public static function html(string $unsubscribeUrl): string
    {
        $u = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');
        $year = gmdate('Y');
        $sep = '<span style="color:#d1d5db;">&nbsp;&middot;&nbsp;</span>';
        return '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#9ca3af;line-height:1.6;text-align:center;">'
            . '<a href="' . self::SITE . '" style="text-decoration:none;">'
            . '<img src="' . self::LOGO_URL . '" alt="DentaTrak" width="110" '
            . 'style="display:block;margin:0 auto 10px;max-width:110px;height:auto;" /></a>'
            . self::link(self::RESOURCES, 'Resources') . $sep
            . self::link(self::PRIVACY, 'Privacy Policy') . $sep
            . self::link(self::CONTACT, 'Contact Us') . '<br>'
            . '&copy; ' . $year . ' DentaTrak. All rights reserved.<br>'
            . self::ADDRESS_HTML . '<br>'
            . '<a href="' . $u . '" style="font-size:10pt;color:#9ca3af;text-decoration:underline;">Unsubscribe</a>'
            . '</div>';
    }

    /** Plain-text equivalent. */
    public static function text(string $unsubscribeUrl): string
    {
        $year = gmdate('Y');
        return "\n\n-- \nDentaTrak\n"
            . "Resources: " . self::RESOURCES . "\n"
            . "Privacy Policy: " . self::PRIVACY . "\n"
            . "Contact Us: " . self::CONTACT . "\n"
            . "(c) " . $year . " DentaTrak. All rights reserved.\n"
            . self::ADDRESS_TEXT . "\n"
            . "Unsubscribe: " . $unsubscribeUrl . "\n";
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
