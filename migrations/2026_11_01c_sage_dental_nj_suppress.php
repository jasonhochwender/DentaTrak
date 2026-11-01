<?php
/**
 * One-off: suppress Sage Dental NJ as an organization (sagedentalnj.com)
 * and annotate the three existing email suppressions with the owner-directed
 * reason. Idempotent (INSERT IGNORE / no-op UPDATE). Does NOT touch
 * mysagedental.com or any other org.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;
$REASON = 'Owner-directed Do Not Contact, November 1, 2026';

$pdo->prepare(
    'INSERT IGNORE INTO marketing_org_suppressions (org_ref,source,reason) VALUES (?,?,?)'
)->execute(['sagedentalnj.com', 'owner_directed_dnc', $REASON]);

$upd = $pdo->prepare(
    'UPDATE marketing_suppressions SET source=?, campaign_ref=? WHERE email_normalized IN (?,?,?)'
);
$upd->execute([
    'owner_directed_dnc',
    $REASON,
    'deborah@archaestheticdental.com',
    'info@sagedentalnj.com',
    'deborah@sagedentalnj.com',
]);

$org = $pdo->prepare('SELECT org_ref,source,reason FROM marketing_org_suppressions WHERE org_ref=?');
$org->execute(['sagedentalnj.com']);
print_r($org->fetch(PDO::FETCH_ASSOC));

$rows = $pdo->prepare('SELECT email_normalized,source,campaign_ref FROM marketing_suppressions WHERE email_normalized IN (?,?,?)');
$rows->execute(['deborah@archaestheticdental.com', 'info@sagedentalnj.com', 'deborah@sagedentalnj.com']);
print_r($rows->fetchAll(PDO::FETCH_ASSOC));

echo "sage dental nj suppression applied\n";
