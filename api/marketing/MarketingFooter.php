<?php
/**
 * Standardized marketing-email footer + unsubscribe headers.
 *
 * Approved design: centered, small-font footer applied automatically to
 * EVERY marketing message at send time (MarketingMailer appends it; it is
 * never stored in campaign content). Transactional mail must not call
 * this.
 *
 * Contents, in order: resource links, Privacy Policy, Contact Us,
 * copyright, postal address, the Standard DentaTrak logo linked to
 * https://dentatrak.com, and the recipient-specific unsubscribe link.
 */
require_once __DIR__ . '/../brand-assets.php';

final class MarketingFooter
{
    public const SITE        = 'https://dentatrak.com';
    public const RESOURCES   = 'https://dentatrak.com/resources';
    public const PRIVACY     = 'https://dentatrak.com/privacy';
    public const CONTACT     = 'mailto:support@dentatrak.com';
    /** Existing public resource articles surfaced in the footer. */
    public const ARTICLES = [
        'Dental Lab Case Tracking' => 'https://dentatrak.com/dental-lab-case-tracking',
        'Case Tracking Checklist'  => 'https://dentatrak.com/dental-case-tracking-checklist',
    ];
    public const COPYRIGHT_HOLDER = 'DentaTrak LLC';
    public const LOGO_WIDTH  = 150;
    public const LOGO_HEIGHT = 50;
    public const ADDRESS_HTML =
        'DentaTrak<br>PO Box 1<br>Tryon, NC 28782';
    public const ADDRESS_TEXT = "DentaTrak\nPO Box 1\nTryon, NC 28782";

    /** Public URL of the Standard Logo (email clients need an absolute URL). */
    public static function logoUrl(): string
    {
        return brandAssetPublicUrl('logo_standard');
    }

    private static function link(string $url, string $label): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" style="color:#6b7280;text-decoration:underline;white-space:nowrap;">' . $label . '</a>';
    }

    /** HTML footer block appended at the very bottom of marketing emails. */
    public static function html(string $unsubscribeUrl): string
    {
        $u = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');
        $year = gmdate('Y');
        $sep = ' <span style="color:#d1d5db;">&middot;</span> ';
        // Two balanced rows: resources first, then privacy/contact.
        $row1 = [];
        foreach (self::ARTICLES as $label => $url) {
            $row1[] = self::link($url, $label);
        }
        $row1[] = self::link(self::RESOURCES, 'All Resources');
        $row2 = implode($sep, [
            self::link(self::PRIVACY, 'Privacy Policy'),
            self::link(self::CONTACT, 'Contact Us'),
        ]);
        return '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#9ca3af;line-height:1.6;text-align:center;">'
            . implode($sep, $row1)
            . '<br><span style="display:inline-block;height:6px;"></span><br>' . $row2 . '<br>'
            . '&copy; ' . $year . ' ' . self::COPYRIGHT_HOLDER . '. All rights reserved.<br>'
            . self::ADDRESS_HTML . '<br>'
            . '<a href="' . self::SITE . '" style="text-decoration:none;">'
            . '<img src="' . htmlspecialchars(self::logoUrl(), ENT_QUOTES, 'UTF-8') . '" alt="DentaTrak" '
            . 'width="' . self::LOGO_WIDTH . '" height="' . self::LOGO_HEIGHT . '" '
            . 'style="display:block;margin:12px auto;width:' . self::LOGO_WIDTH . 'px;max-width:100%;height:auto;border:0;" /></a>'
            . 'If you no longer wish to receive these emails, you can '
            . '<a href="' . $u . '" style="font-size:10pt;color:#9ca3af;text-decoration:underline;">unsubscribe here</a>.'
            . '</div>';
    }

    /** Plain-text equivalent. */
    public static function text(string $unsubscribeUrl): string
    {
        $year = gmdate('Y');
        $out = "\n\n-- \n";
        foreach (self::ARTICLES as $label => $url) {
            $out .= $label . ': ' . $url . "\n";
        }
        return $out
            . "All Resources: " . self::RESOURCES . "\n"
            . "Privacy Policy: " . self::PRIVACY . "\n"
            . "Contact Us: " . self::CONTACT . "\n"
            . "(c) " . $year . ' ' . self::COPYRIGHT_HOLDER . ". All rights reserved.\n"
            . self::ADDRESS_TEXT . "\n"
            . self::SITE . "\n"
            . "If you no longer wish to receive these emails, you can unsubscribe here: " . $unsubscribeUrl . "\n";
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
