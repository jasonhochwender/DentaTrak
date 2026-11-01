<?php
/**
 * RFC 8058 List-Unsubscribe one-click handler.
 * Only POST mutates — this endpoint suppresses immediately.
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/marketing/SuppressionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
if (($_POST['List-Unsubscribe'] ?? '') !== 'One-Click') { http_response_code(400); exit('Bad Request'); }

$svc = new SuppressionService($appConfig['db']());
$email = $svc->resolveToken((string) ($_REQUEST['t'] ?? ''));
if ($email) $svc->suppress($email, 'email_unsubscribe_one_click');
http_response_code(200); echo 'OK';
