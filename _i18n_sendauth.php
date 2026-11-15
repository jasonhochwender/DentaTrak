<?php
// One-off: update sending banner/confirm strings + add send-auth keys
// to every non-en-US locale. Mirrors the en-US changes.
$T = [
'de-DE' => [
 'banner_old'=>'Testmodus: E-Mails werden nur an {n} freigegebene interne Empfänger zugestellt. Prospects können nicht angeschrieben werden.',
 'banner_new'=>'Eingeschränkter Versand: E-Mails werden nur an {n} freigegebene Adresse(n) und an Kampagnen zugestellt, die ein Administrator ausdrücklich für den Versand autorisiert hat. Alle anderen Empfänger werden blockiert.',
 'confirm_old'=>'Jetzt genehmigen und senden? Nur freigegebene interne Testempfänger erhalten E-Mails.',
 'confirm_new'=>'Jetzt genehmigen und senden? Nur freigegebene Empfänger und Empfänger versandautorisierter Kampagnen erhalten E-Mails.',
 'badge'=>'Versand autorisiert','auth'=>'Versand autorisieren','revoke'=>'Autorisierung widerrufen',
 'cauth'=>'Diesen Versand für diese Kampagne autorisieren? Dadurch wird die Empfänger-Allowlist NUR für diese Kampagne aufgehoben. Der Versand erfordert weiterhin die Kampagnenfreigabe und Ihre ausdrückliche Sendeaktion; der globale Versandschalter sowie Unterdrückungs- und Berechtigungsprüfungen gelten weiterhin.',
 'crevoke'=>'Versandautorisierung für diese Kampagne widerrufen? Ihre Empfänger unterliegen wieder nur der globalen Allowlist.',
 'done'=>'Versandautorisierung aktualisiert.',
 'note'=>'Die Autorisierung erlaubt den genehmigten Empfängern dieser Kampagne, die Adress-Allowlist zu umgehen. Sie sendet nichts von selbst.',
],
'es-ES' => [
 'banner_old'=>'Modo de prueba: el correo solo se entrega a {n} destinatario(s) interno(s) autorizado(s). No se puede escribir a prospectos.',
 'banner_new'=>'Envío restringido: el correo solo se entrega a {n} dirección(es) autorizada(s) y a las campañas que un administrador autorice explícitamente para el envío. Todos los demás destinatarios quedan bloqueados.',
 'confirm_old'=>'¿Aprobar y enviar ahora? Solo los destinatarios internos de prueba autorizados recibirán correo.',
 'confirm_new'=>'¿Aprobar y enviar ahora? Solo los destinatarios autorizados y los de campañas con envío autorizado recibirán correo.',
 'badge'=>'Envío autorizado','auth'=>'Autorizar envío','revoke'=>'Revocar autorización',
 'cauth'=>'¿Autorizar el envío de esta campaña? Esto levanta la lista de destinatarios permitidos SOLO para esta campaña. El envío sigue requiriendo la aprobación de la campaña y su acción explícita de Enviar; el interruptor global de envío y las comprobaciones de supresión y elegibilidad siguen aplicándose.',
 'crevoke'=>'¿Revocar la autorización de envío de esta campaña? Sus destinatarios volverán a estar limitados a la lista global.',
 'done'=>'Autorización de envío actualizada.',
 'note'=>'Autorizar permite que los destinatarios aprobados de esta campaña omitan la lista de direcciones. No envía nada por sí solo.',
],
'fr-CA' => [
 'banner_old'=>'Mode test : les courriels sont livrés uniquement à {n} destinataire(s) interne(s) autorisé(s). Les prospects ne peuvent pas recevoir de courriel.',
 'banner_new'=>'Envoi restreint : les courriels sont livrés uniquement à {n} adresse(s) autorisée(s) et aux campagnes qu’un administrateur autorise explicitement pour l’envoi. Tous les autres destinataires sont bloqués.',
 'confirm_old'=>'Approuver et envoyer maintenant? Seuls les destinataires internes de test autorisés recevront un courriel.',
 'confirm_new'=>'Approuver et envoyer maintenant? Seuls les destinataires autorisés et ceux des campagnes autorisées pour l’envoi recevront un courriel.',
 'badge'=>'Envoi autorisé','auth'=>'Autoriser l’envoi','revoke'=>'Révoquer l’autorisation',
 'cauth'=>'Autoriser l’envoi de cette campagne? Cela lève la liste des destinataires permis UNIQUEMENT pour cette campagne. L’envoi exige toujours l’approbation de la campagne et votre action Envoyer explicite; l’interrupteur global d’envoi ainsi que les vérifications de suppression et d’éligibilité s’appliquent toujours.',
 'crevoke'=>'Révoquer l’autorisation d’envoi de cette campagne? Ses destinataires seront de nouveau limités à la liste globale.',
 'done'=>'Autorisation d’envoi mise à jour.',
 'note'=>'L’autorisation permet aux destinataires approuvés de cette campagne de contourner la liste d’adresses. Cela n’envoie rien par soi-même.',
],
'fr-FR' => [
 'banner_old'=>'Mode test : les e-mails sont délivrés uniquement à {n} destinataire(s) interne(s) autorisé(s). Les prospects ne peuvent pas recevoir d’e-mail.',
 'banner_new'=>'Envoi restreint : les e-mails sont délivrés uniquement à {n} adresse(s) autorisée(s) et aux campagnes qu’un administrateur autorise explicitement pour l’envoi. Tous les autres destinataires sont bloqués.',
 'confirm_old'=>'Approuver et envoyer maintenant ? Seuls les destinataires internes de test autorisés recevront un e-mail.',
 'confirm_new'=>'Approuver et envoyer maintenant ? Seuls les destinataires autorisés et ceux des campagnes autorisées pour l’envoi recevront un e-mail.',
 'badge'=>'Envoi autorisé','auth'=>'Autoriser l’envoi','revoke'=>'Révoquer l’autorisation',
 'cauth'=>'Autoriser l’envoi de cette campagne ? Cela lève la liste des destinataires permis UNIQUEMENT pour cette campagne. L’envoi exige toujours l’approbation de la campagne et votre action Envoyer explicite ; l’interrupteur global d’envoi ainsi que les vérifications de suppression et d’éligibilité s’appliquent toujours.',
 'crevoke'=>'Révoquer l’autorisation d’envoi de cette campagne ? Ses destinataires seront de nouveau limités à la liste globale.',
 'done'=>'Autorisation d’envoi mise à jour.',
 'note'=>'L’autorisation permet aux destinataires approuvés de cette campagne de contourner la liste d’adresses. Cela n’envoie rien par soi-même.',
],
'it-IT' => [
 'banner_old'=>'Modalità test: le email vengono consegnate solo a {n} destinatari interni autorizzati. Non è possibile scrivere ai prospect.',
 'banner_new'=>'Invio limitato: le email vengono consegnate solo a {n} indirizzo/i autorizzato/i e alle campagne che un amministratore autorizza esplicitamente per l’invio. Tutti gli altri destinatari sono bloccati.',
 'confirm_old'=>'Approvare e inviare ora? Solo i destinatari interni di test autorizzati riceveranno email.',
 'confirm_new'=>'Approvare e inviare ora? Solo i destinatari autorizzati e quelli delle campagne con invio autorizzato riceveranno email.',
 'badge'=>'Invio autorizzato','auth'=>'Autorizza invio','revoke'=>'Revoca autorizzazione',
 'cauth'=>'Autorizzare l’invio di questa campagna? Questo rimuove la lista dei destinatari consentiti SOLO per questa campagna. L’invio richiede comunque l’approvazione della campagna e la tua azione Invia esplicita; l’interruttore globale di invio e i controlli di soppressione ed eleggibilità continuano ad applicarsi.',
 'crevoke'=>'Revocare l’autorizzazione di invio per questa campagna? I suoi destinatari torneranno limitati alla lista globale.',
 'done'=>'Autorizzazione di invio aggiornata.',
 'note'=>'L’autorizzazione consente ai destinatari approvati di questa campagna di bypassare la lista degli indirizzi. Non invia nulla da sola.',
],
'ko-KR' => [
 'banner_old'=>'테스트 모드: 허용 목록에 있는 내부 수신자 {n}명에게만 이메일이 전달됩니다. 잠재 고객에게는 발송할 수 없습니다.',
 'banner_new'=>'제한된 발송: {n}개의 허용 주소와 관리자가 발송을 명시적으로 승인한 캠페인에만 이메일이 전달됩니다. 그 외 모든 수신자는 차단됩니다.',
 'confirm_old'=>'지금 승인하고 발송하시겠습니까? 허용 목록의 내부 테스트 수신자만 이메일을 받습니다.',
 'confirm_new'=>'지금 승인하고 발송하시겠습니까? 허용 목록 수신자와 발송 승인된 캠페인의 수신자만 이메일을 받습니다.',
 'badge'=>'발송 승인됨','auth'=>'발송 승인','revoke'=>'승인 취소',
 'cauth'=>'이 캠페인의 발송을 승인하시겠습니까? 이 캠페인에 한해서만 수신자 허용 목록이 해제됩니다. 발송에는 여전히 캠페인 승인과 명시적인 발송 작업이 필요하며, 전역 발송 스위치와 억제·자격 검사는 계속 적용됩니다.',
 'crevoke'=>'이 캠페인의 발송 승인을 취소하시겠습니까? 수신자는 다시 전역 허용 목록으로 제한됩니다.',
 'done'=>'발송 승인이 업데이트되었습니다.',
 'note'=>'승인하면 이 캠페인의 승인된 수신자가 주소 허용 목록을 우회할 수 있습니다. 그 자체로는 아무것도 발송하지 않습니다.',
],
'pt-BR' => [
 'banner_old'=>'Modo de teste: o e-mail é entregue apenas a {n} destinatário(s) interno(s) autorizado(s). Prospects não podem receber e-mail.',
 'banner_new'=>'Envio restrito: o e-mail é entregue apenas a {n} endereço(s) autorizado(s) e a campanhas que um administrador autorizar explicitamente para envio. Todos os demais destinatários são bloqueados.',
 'confirm_old'=>'Aprovar e enviar agora? Somente destinatários internos de teste autorizados receberão e-mail.',
 'confirm_new'=>'Aprovar e enviar agora? Somente destinatários autorizados e destinatários de campanhas com envio autorizado receberão e-mail.',
 'badge'=>'Envio autorizado','auth'=>'Autorizar envio','revoke'=>'Revogar autorização',
 'cauth'=>'Autorizar o envio desta campanha? Isso remove a lista de destinatários permitidos SOMENTE para esta campanha. O envio ainda exige a aprovação da campanha e sua ação explícita de Enviar; o interruptor global de envio e as verificações de supressão e elegibilidade continuam valendo.',
 'crevoke'=>'Revogar a autorização de envio desta campanha? Seus destinatários voltarão a ser limitados à lista global.',
 'done'=>'Autorização de envio atualizada.',
 'note'=>'Autorizar permite que os destinatários aprovados desta campanha ignorem a lista de endereços. Isso não envia nada por si só.',
],
'ru-RU' => [
 'banner_old'=>'Тестовый режим: письма доставляются только {n} разрешённым внутренним получателям. Потенциальным клиентам письма не отправляются.',
 'banner_new'=>'Ограниченная отправка: письма доставляются только {n} разрешённым адресам и кампаниям, которые администратор явно авторизовал для отправки. Все остальные получатели блокируются.',
 'confirm_old'=>'Утвердить и отправить сейчас? Письма получат только разрешённые внутренние тестовые получатели.',
 'confirm_new'=>'Утвердить и отправить сейчас? Письма получат только разрешённые получатели и получатели кампаний с авторизованной отправкой.',
 'badge'=>'Отправка авторизована','auth'=>'Авторизовать отправку','revoke'=>'Отозвать авторизацию',
 'cauth'=>'Авторизовать отправку этой кампании? Это снимает список разрешённых получателей ТОЛЬКО для этой кампании. Отправка по-прежнему требует утверждения кампании и вашего явного действия «Отправить»; глобальный переключатель отправки и проверки подавления и eligibility продолжают действовать.',
 'crevoke'=>'Отозвать авторизацию отправки этой кампании? Её получатели снова будут ограничены глобальным списком.',
 'done'=>'Авторизация отправки обновлена.',
 'note'=>'Авторизация позволяет утверждённым получателям этой кампании обходить список адресов. Само по себе ничего не отправляет.',
],
];

foreach ($T as $loc => $tr) {
    $f = "locales/$loc.json";
    $s = file_get_contents($f);
    if ($s === false) { echo "READ FAIL $f\n"; continue; }
    $orig = $s;
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // 1) banner
    $old = '"sending_allowlist_banner": ' . $j($tr['banner_old']);
    if (strpos($s, $old) === false) { echo "BANNER OLD NOT FOUND $loc\n"; continue; }
    $s = str_replace($old, '"sending_allowlist_banner": ' . $j($tr['banner_new']), $s);
    // 2) confirm_send_test
    $old = '"confirm_send_test": ' . $j($tr['confirm_old']);
    if (strpos($s, $old) === false) { echo "CONFIRM OLD NOT FOUND $loc\n"; }
    else $s = str_replace($old, '"confirm_send_test": ' . $j($tr['confirm_new']), $s);
    // 3) new keys after confirm_stop_send line
    $anchor = preg_quote('"confirm_stop_send":', '/');
    if (!preg_match('/^(\s*)"confirm_stop_send":\s*"(?:[^"\\\\]|\\\\.)*",\s*$/m', $s, $m)) { echo "ANCHOR NOT FOUND $loc\n"; continue; }
    $ind = $m[1];
    $newKeys =
        $ind . '"send_authorized_badge": ' . $j($tr['badge']) . ",\n" .
        $ind . '"authorize_sending": ' . $j($tr['auth']) . ",\n" .
        $ind . '"revoke_send_auth": ' . $j($tr['revoke']) . ",\n" .
        $ind . '"confirm_authorize_send": ' . $j($tr['cauth']) . ",\n" .
        $ind . '"confirm_revoke_send_auth": ' . $j($tr['crevoke']) . ",\n" .
        $ind . '"send_auth_done": ' . $j($tr['done']) . ",\n" .
        $ind . '"send_auth_note": ' . $j($tr['note']) . ",\n";
    $s = preg_replace('/^(\s*)"confirm_stop_send":\s*"(?:[^"\\\\]|\\\\.)*",\s*$/m',
        "\$0\n" . $newKeys, $s, 1);
    if ($s === $orig) { echo "NOCHANGE $loc\n"; continue; }
    json_decode($s);
    if (json_last_error() !== JSON_ERROR_NONE) { echo "JSON INVALID $loc: " . json_last_error_msg() . "\n"; continue; }
    file_put_contents($f, $s);
    echo "OK $loc\n";
}
