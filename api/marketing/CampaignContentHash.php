<?php
/**
 * Content hash binding campaign approval to exact content.
 *
 * Covers the campaign identity (name/kind - both appear on sent mail and
 * sheet write-backs), every recipient row's membership + rendered content,
 * and resolved thread references. Any add/remove/edit changes it.
 *
 * Shared by the send/approve path (api/marketing-admin.php) and read-only
 * verification tooling (scripts/marketing-inspect.php) so both compute the
 * identical digest.
 */
require_once __DIR__ . '/MarketingSignature.php';
require_once __DIR__ . '/MarketingFooter.php';

final class CampaignContentHash
{
    public static function compute(PDO $pdo, string $campaignId): string
    {
        $c = $pdo->prepare("SELECT name,kind FROM marketing_campaigns WHERE id=?");
        $c->execute([$campaignId]);
        $meta = $c->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'kind' => ''];
        $rows = $pdo->prepare("SELECT id,contact_id,sheet_row,prospect_email,subject,body_html,thread_status,gmail_thread_id,thread_rfc_message_id,thread_references,thread_subject,thread_date FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
        $rows->execute([$campaignId]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        // For 'initial' campaigns gmail_thread_id is a send artifact: the
        // first send creates the Gmail thread and mo_mark_recipient_sent
        // stores its id. It is not approved content, so normalize it or the
        // hash would mismatch after the first batch and stall multi-batch
        // sends. Threaded kinds keep the resolved thread id in the hash.
        if (($meta['kind'] ?? '') === 'initial') {
            foreach ($rows as &$row) { $row['gmail_thread_id'] = null; }
            unset($row);
        }
        // Material delivery content is part of what was approved: the sender
        // signature config and the managed footer template. Dynamic
        // unsubscribe tokens are per-send and intentionally excluded.
        $env = [
            MarketingSignature::fingerprint(MarketingSignature::load($pdo)),
            hash('sha256', MarketingFooter::html('__preview__')),
        ];
        return hash('sha256', json_encode([$meta['name'], $meta['kind'], $rows, $env]));
    }
}
