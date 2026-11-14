<?php
/**
 * Shared sanitizer for admin-authored marketing message HTML.
 *
 * Used by the Marketing Outreach endpoint (save_message / generate output)
 * and the MCP campaign tools so both apply identical rules: strip scripts,
 * styles, event handlers, and javascript: URLs. Signature and footer are
 * composed later by MarketingMailer::composeParts - never stored here.
 */
final class MessageHtml
{
    public static function sanitize(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i', '$1=$2#$2', $html);
        return trim((string)$html);
    }
}
