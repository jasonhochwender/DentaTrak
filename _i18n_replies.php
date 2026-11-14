<?php
// One-off: add reply-tracking + dashboard strings to all locales.
$T = [
'en-US' => [
 'tab_replies'=>'Replies',
 'reply_filter_new'=>'New replies','reply_filter_all'=>'All replies','reply_filter_reviewed'=>'Reviewed','reply_filter_dismissed'=>'Dismissed','reply_filter_allclass'=>'All types',
 'reply_cls_human'=>'Human reply','reply_cls_auto'=>'Auto-reply','reply_cls_bounce'=>'Delivery failure','reply_cls_unsub'=>'Unsubscribe request','reply_cls_uncertain'=>'Uncertain',
 'reply_sync_now'=>'Sync now','reply_sync_ok'=>'Last sync:','reply_sync_err'=>'Sync error','reply_sync_auth'=>'Gmail authorization required — reconnect the marketing Gmail account.','reply_sync_done'=>'Sync complete','reply_sync_running'=>'Syncing…',
 'reply_empty'=>'No replies detected yet. Replies are checked automatically every 15 minutes.','reply_open_gmail'=>'Open in Gmail','reply_mark_reviewed'=>'Mark reviewed','reply_dismiss'=>'Dismiss',
 'reply_ambiguous'=>'Ambiguous match','reply_unmatched'=>'Not matched to a prospect',
 'reply_via_thread'=>'Matched by Gmail thread','reply_via_rfc'=>'Matched by reply headers','reply_via_email'=>'Matched by sender address',
 'reply_reviewed_ok'=>'Reply updated','reply_view_prospect'=>'Prospect',
 'reply_col_date'=>'Received','reply_col_from'=>'From','reply_col_subject'=>'Subject','reply_col_class'=>'Type','reply_col_campaign'=>'Campaign','reply_col_status'=>'Status','reply_col_actions'=>'Actions',
 'dash_leg_replies'=>'Human replies','dash_a_replies'=>'Replies to review','dash_a_bounces'=>'Delivery failures','dash_a_unsub'=>'Unsubscribe requests','dash_a_auto'=>'Auto-replies',
 'dash_rate_na'=>'n/a','dash_camp_replies'=>'Replies',
 'dash_defs'=>"Definitions: 'Verified sends' are confirmed Gmail deliveries recorded in the send ledger — not imported spreadsheet statuses. 'Human replies' are inbound Gmail messages classified as genuine responses and matched to a prospect; response rate = human replies ÷ verified sends in the same period. Auto-replies, bounces, and unsubscribe requests are tracked separately and never counted as responses. 'Awaiting response' requires evidence of prior outreach. Campaign recipient rows are not sends until a campaign is sent.",
],
'de-DE' => [
 'tab_replies'=>'Antworten',
 'reply_filter_new'=>'Neue Antworten','reply_filter_all'=>'Alle Antworten','reply_filter_reviewed'=>'Gesehen','reply_filter_dismissed'=>'Verworfen','reply_filter_allclass'=>'Alle Typen',
 'reply_cls_human'=>'Menschliche Antwort','reply_cls_auto'=>'Automatische Antwort','reply_cls_bounce'=>'Zustellfehler','reply_cls_unsub'=>'Abmeldeanfrage','reply_cls_uncertain'=>'Unklar',
 'reply_sync_now'=>'Jetzt synchronisieren','reply_sync_ok'=>'Letzte Synchronisierung:','reply_sync_err'=>'Synchronisierungsfehler','reply_sync_auth'=>'Gmail-Autorisierung erforderlich — Marketing-Gmail-Konto erneut verbinden.','reply_sync_done'=>'Synchronisierung abgeschlossen','reply_sync_running'=>'Synchronisiere…',
 'reply_empty'=>'Noch keine Antworten erkannt. Antworten werden automatisch alle 15 Minuten geprüft.','reply_open_gmail'=>'In Gmail öffnen','reply_mark_reviewed'=>'Als gesehen markieren','reply_dismiss'=>'Verwerfen',
 'reply_ambiguous'=>'Mehrdeutige Zuordnung','reply_unmatched'=>'Keinem Interessenten zugeordnet',
 'reply_via_thread'=>'Über Gmail-Thread zugeordnet','reply_via_rfc'=>'Über Antwort-Header zugeordnet','reply_via_email'=>'Über Absenderadresse zugeordnet',
 'reply_reviewed_ok'=>'Antwort aktualisiert','reply_view_prospect'=>'Interessent',
 'reply_col_date'=>'Empfangen','reply_col_from'=>'Von','reply_col_subject'=>'Betreff','reply_col_class'=>'Typ','reply_col_campaign'=>'Kampagne','reply_col_status'=>'Status','reply_col_actions'=>'Aktionen',
 'dash_leg_replies'=>'Menschliche Antworten','dash_a_replies'=>'Zu prüfende Antworten','dash_a_bounces'=>'Zustellfehler','dash_a_unsub'=>'Abmeldeanfragen','dash_a_auto'=>'Auto-Antworten',
 'dash_rate_na'=>'k. A.','dash_camp_replies'=>'Antworten',
 'dash_defs'=>"Definitionen: „Verifizierte Sendungen“ sind bestätigte Gmail-Zustellungen im Sendungsprotokoll — keine importierten Tabellenstatus. „Menschliche Antworten“ sind eingehende Gmail-Nachrichten, die als echte Reaktionen eingestuft und einem Interessenten zugeordnet wurden; Antwortquote = menschliche Antworten ÷ verifizierte Sendungen im selben Zeitraum. Auto-Antworten, Zustellfehler und Abmeldungen werden separat erfasst und nie als Antworten gezählt. „Wartet auf Antwort“ erfordert einen Beleg für frühere Ansprache. Empfängerzeilen sind erst nach dem Versand einer Kampagne Sendungen.",
],
'es-ES' => [
 'tab_replies'=>'Respuestas',
 'reply_filter_new'=>'Respuestas nuevas','reply_filter_all'=>'Todas las respuestas','reply_filter_reviewed'=>'Revisadas','reply_filter_dismissed'=>'Descartadas','reply_filter_allclass'=>'Todos los tipos',
 'reply_cls_human'=>'Respuesta humana','reply_cls_auto'=>'Respuesta automática','reply_cls_bounce'=>'Fallo de entrega','reply_cls_unsub'=>'Solicitud de baja','reply_cls_uncertain'=>'Incierta',
 'reply_sync_now'=>'Sincronizar ahora','reply_sync_ok'=>'Última sincronización:','reply_sync_err'=>'Error de sincronización','reply_sync_auth'=>'Se requiere autorización de Gmail — vuelve a conectar la cuenta de Gmail de marketing.','reply_sync_done'=>'Sincronización completada','reply_sync_running'=>'Sincronizando…',
 'reply_empty'=>'Aún no se han detectado respuestas. Se comprueban automáticamente cada 15 minutos.','reply_open_gmail'=>'Abrir en Gmail','reply_mark_reviewed'=>'Marcar como revisada','reply_dismiss'=>'Descartar',
 'reply_ambiguous'=>'Coincidencia ambigua','reply_unmatched'=>'Sin coincidencia con un prospecto',
 'reply_via_thread'=>'Coincidencia por hilo de Gmail','reply_via_rfc'=>'Coincidencia por cabeceras de respuesta','reply_via_email'=>'Coincidencia por dirección del remitente',
 'reply_reviewed_ok'=>'Respuesta actualizada','reply_view_prospect'=>'Prospecto',
 'reply_col_date'=>'Recibida','reply_col_from'=>'De','reply_col_subject'=>'Asunto','reply_col_class'=>'Tipo','reply_col_campaign'=>'Campaña','reply_col_status'=>'Estado','reply_col_actions'=>'Acciones',
 'dash_leg_replies'=>'Respuestas humanas','dash_a_replies'=>'Respuestas por revisar','dash_a_bounces'=>'Fallos de entrega','dash_a_unsub'=>'Solicitudes de baja','dash_a_auto'=>'Respuestas automáticas',
 'dash_rate_na'=>'n/d','dash_camp_replies'=>'Respuestas',
 'dash_defs'=>"Definiciones: «Envíos verificados» son entregas de Gmail confirmadas registradas en el libro de envíos, no estados importados de la hoja de cálculo. «Respuestas humanas» son mensajes entrantes de Gmail clasificados como respuestas genuinas y asociados a un prospecto; la tasa de respuesta = respuestas humanas ÷ envíos verificados del mismo período. Las respuestas automáticas, los fallos y las bajas se registran por separado y nunca cuentan como respuestas. «Esperando respuesta» requiere evidencia de contacto previo. Las filas de destinatarios no son envíos hasta que se envía una campaña.",
],
'fr-CA' => [
 'tab_replies'=>'Réponses',
 'reply_filter_new'=>'Nouvelles réponses','reply_filter_all'=>'Toutes les réponses','reply_filter_reviewed'=>'Examinées','reply_filter_dismissed'=>'Ignorées','reply_filter_allclass'=>'Tous les types',
 'reply_cls_human'=>'Réponse humaine','reply_cls_auto'=>'Réponse automatique','reply_cls_bounce'=>'Échec de livraison','reply_cls_unsub'=>'Demande de désabonnement','reply_cls_uncertain'=>'Incertaine',
 'reply_sync_now'=>'Synchroniser','reply_sync_ok'=>'Dernière synchro :','reply_sync_err'=>'Erreur de synchro','reply_sync_auth'=>'Autorisation Gmail requise — reconnectez le compte Gmail marketing.','reply_sync_done'=>'Synchronisation terminée','reply_sync_running'=>'Synchronisation…',
 'reply_empty'=>'Aucune réponse détectée pour le moment. Les réponses sont vérifiées automatiquement toutes les 15 minutes.','reply_open_gmail'=>'Ouvrir dans Gmail','reply_mark_reviewed'=>'Marquer comme examinée','reply_dismiss'=>'Ignorer',
 'reply_ambiguous'=>'Correspondance ambiguë','reply_unmatched'=>'Aucun prospect associé',
 'reply_via_thread'=>'Associée par fil Gmail','reply_via_rfc'=>'Associée par en-têtes de réponse','reply_via_email'=>'Associée par adresse de l-expéditeur',
 'reply_reviewed_ok'=>'Réponse mise à jour','reply_view_prospect'=>'Prospect',
 'reply_col_date'=>'Reçue','reply_col_from'=>'De','reply_col_subject'=>'Objet','reply_col_class'=>'Type','reply_col_campaign'=>'Campagne','reply_col_status'=>'Statut','reply_col_actions'=>'Actions',
 'dash_leg_replies'=>'Réponses humaines','dash_a_replies'=>'Réponses à examiner','dash_a_bounces'=>'Échecs de livraison','dash_a_unsub'=>'Demandes de désabonnement','dash_a_auto'=>'Réponses auto',
 'dash_rate_na'=>'s.o.','dash_camp_replies'=>'Réponses',
 'dash_defs'=>"Définitions : « Envois vérifiés » sont les livraisons Gmail confirmées consignées au registre d'envoi — pas les statuts importés du chiffrier. « Réponses humaines » sont des messages Gmail entrants classés comme réponses authentiques et associés à un prospect ; le taux de réponse = réponses humaines ÷ envois vérifiés sur la même période. Les réponses auto, les échecs et les désabonnements sont suivis séparément et ne comptent jamais comme réponses. « En attente de réponse » exige une preuve de contact antérieur. Les lignes de destinataires ne sont des envois qu'une fois la campagne envoyée.",
],
'fr-FR' => [
 'tab_replies'=>'Réponses',
 'reply_filter_new'=>'Nouvelles réponses','reply_filter_all'=>'Toutes les réponses','reply_filter_reviewed'=>'Examinées','reply_filter_dismissed'=>'Ignorées','reply_filter_allclass'=>'Tous les types',
 'reply_cls_human'=>'Réponse humaine','reply_cls_auto'=>'Réponse automatique','reply_cls_bounce'=>'Échec de livraison','reply_cls_unsub'=>'Demande de désabonnement','reply_cls_uncertain'=>'Incertaine',
 'reply_sync_now'=>'Synchroniser','reply_sync_ok'=>'Dernière synchro :','reply_sync_err'=>'Erreur de synchro','reply_sync_auth'=>'Autorisation Gmail requise — reconnectez le compte Gmail marketing.','reply_sync_done'=>'Synchronisation terminée','reply_sync_running'=>'Synchronisation…',
 'reply_empty'=>'Aucune réponse détectée pour le moment. Les réponses sont vérifiées automatiquement toutes les 15 minutes.','reply_open_gmail'=>'Ouvrir dans Gmail','reply_mark_reviewed'=>'Marquer comme examinée','reply_dismiss'=>'Ignorer',
 'reply_ambiguous'=>'Correspondance ambiguë','reply_unmatched'=>'Aucun prospect associé',
 'reply_via_thread'=>'Associée par fil Gmail','reply_via_rfc'=>'Associée par en-têtes de réponse','reply_via_email'=>'Associée par adresse de l-expéditeur',
 'reply_reviewed_ok'=>'Réponse mise à jour','reply_view_prospect'=>'Prospect',
 'reply_col_date'=>'Reçue','reply_col_from'=>'De','reply_col_subject'=>'Objet','reply_col_class'=>'Type','reply_col_campaign'=>'Campagne','reply_col_status'=>'Statut','reply_col_actions'=>'Actions',
 'dash_leg_replies'=>'Réponses humaines','dash_a_replies'=>'Réponses à examiner','dash_a_bounces'=>'Échecs de livraison','dash_a_unsub'=>'Demandes de désabonnement','dash_a_auto'=>'Réponses auto',
 'dash_rate_na'=>'s.o.','dash_camp_replies'=>'Réponses',
 'dash_defs'=>"Définitions : « Envois vérifiés » sont les livraisons Gmail confirmées consignées au registre d'envoi — pas les statuts importés du chiffrier. « Réponses humaines » sont des messages Gmail entrants classés comme réponses authentiques et associés à un prospect ; le taux de réponse = réponses humaines ÷ envois vérifiés sur la même période. Les réponses auto, les échecs et les désabonnements sont suivis séparément et ne comptent jamais comme réponses. « En attente de réponse » exige une preuve de contact antérieur. Les lignes de destinataires ne sont des envois qu'une fois la campagne envoyée.",
],
'it-IT' => [
 'tab_replies'=>'Risposte',
 'reply_filter_new'=>'Nuove risposte','reply_filter_all'=>'Tutte le risposte','reply_filter_reviewed'=>'Esaminate','reply_filter_dismissed'=>'Ignorate','reply_filter_allclass'=>'Tutti i tipi',
 'reply_cls_human'=>'Risposta umana','reply_cls_auto'=>'Risposta automatica','reply_cls_bounce'=>'Consegna non riuscita','reply_cls_unsub'=>'Richiesta di cancellazione','reply_cls_uncertain'=>'Incerta',
 'reply_sync_now'=>'Sincronizza ora','reply_sync_ok'=>'Ultima sincronizzazione:','reply_sync_err'=>'Errore di sincronizzazione','reply_sync_auth'=>'Autorizzazione Gmail richiesta — ricollega l-account Gmail di marketing.','reply_sync_done'=>'Sincronizzazione completata','reply_sync_running'=>'Sincronizzazione…',
 'reply_empty'=>'Nessuna risposta rilevata. Le risposte vengono controllate automaticamente ogni 15 minuti.','reply_open_gmail'=>'Apri in Gmail','reply_mark_reviewed'=>'Segna come esaminata','reply_dismiss'=>'Ignora',
 'reply_ambiguous'=>'Corrispondenza ambigua','reply_unmatched'=>'Nessun prospect associato',
 'reply_via_thread'=>'Abbinata tramite thread Gmail','reply_via_rfc'=>'Abbinata tramite intestazioni di risposta','reply_via_email'=>'Abbinata tramite indirizzo del mittente',
 'reply_reviewed_ok'=>'Risposta aggiornata','reply_view_prospect'=>'Prospect',
 'reply_col_date'=>'Ricevuta','reply_col_from'=>'Da','reply_col_subject'=>'Oggetto','reply_col_class'=>'Tipo','reply_col_campaign'=>'Campagna','reply_col_status'=>'Stato','reply_col_actions'=>'Azioni',
 'dash_leg_replies'=>'Risposte umane','dash_a_replies'=>'Risposte da esaminare','dash_a_bounces'=>'Consegne non riuscite','dash_a_unsub'=>'Richieste di cancellazione','dash_a_auto'=>'Risposte automatiche',
 'dash_rate_na'=>'n/d','dash_camp_replies'=>'Risposte',
 'dash_defs'=>"Definizioni: «Invii verificati» sono le consegne Gmail confermate registrate nel registro di invio — non gli stati importati dal foglio di calcolo. «Risposte umane» sono messaggi Gmail in arrivo classificati come risposte genuine e associati a un prospect; il tasso di risposta = risposte umane ÷ invii verificati nello stesso periodo. Risposte automatiche, errori di consegna e cancellazioni sono tracciati separatamente e non contano mai come risposte. «In attesa di risposta» richiede prova di contatto precedente. Le righe dei destinatari non sono invii finché la campagna non viene inviata.",
],
'ko-KR' => [
 'tab_replies'=>'답장',
 'reply_filter_new'=>'새 답장','reply_filter_all'=>'모든 답장','reply_filter_reviewed'=>'검토됨','reply_filter_dismissed'=>'무시됨','reply_filter_allclass'=>'모든 유형',
 'reply_cls_human'=>'실제 답장','reply_cls_auto'=>'자동 답장','reply_cls_bounce'=>'전달 실패','reply_cls_unsub'=>'수신 거부 요청','reply_cls_uncertain'=>'불확실',
 'reply_sync_now'=>'지금 동기화','reply_sync_ok'=>'마지막 동기화:','reply_sync_err'=>'동기화 오류','reply_sync_auth'=>'Gmail 인증이 필요합니다 — 마케팅 Gmail 계정을 다시 연결하세요.','reply_sync_done'=>'동기화 완료','reply_sync_running'=>'동기화 중…',
 'reply_empty'=>'아직 감지된 답장이 없습니다. 답장은 15분마다 자동으로 확인됩니다.','reply_open_gmail'=>'Gmail에서 열기','reply_mark_reviewed'=>'검토됨으로 표시','reply_dismiss'=>'무시',
 'reply_ambiguous'=>'모호한 일치','reply_unmatched'=>'잠재고객과 연결되지 않음',
 'reply_via_thread'=>'Gmail 스레드로 일치','reply_via_rfc'=>'답장 헤더로 일치','reply_via_email'=>'발신자 주소로 일치',
 'reply_reviewed_ok'=>'답장이 업데이트됨','reply_view_prospect'=>'잠재고객',
 'reply_col_date'=>'수신','reply_col_from'=>'보낸 사람','reply_col_subject'=>'제목','reply_col_class'=>'유형','reply_col_campaign'=>'캠페인','reply_col_status'=>'상태','reply_col_actions'=>'작업',
 'dash_leg_replies'=>'실제 답장','dash_a_replies'=>'검토할 답장','dash_a_bounces'=>'전달 실패','dash_a_unsub'=>'수신 거부 요청','dash_a_auto'=>'자동 답장',
 'dash_rate_na'=>'해당 없음','dash_camp_replies'=>'답장',
 'dash_defs'=>"정의: ‘검증된 발송’은 발송 원장에 기록된 Gmail 전달 확인 건으로, 가져온 스프레드시트 상태가 아닙니다. ‘실제 답장’은 진짜 응답으로 분류되어 잠재고객과 연결된 Gmail 수신 메시지이며, 응답률 = 실제 답장 ÷ 같은 기간의 검증된 발송입니다. 자동 답장, 반송, 수신 거부는 별도로 추적되며 응답으로 계산되지 않습니다. ‘응답 대기’는 이전 연락 증거가 필요합니다. 캠페인 수신자 행은 캠페인이 발송되기 전까지 발송이 아닙니다.",
],
'pt-BR' => [
 'tab_replies'=>'Respostas',
 'reply_filter_new'=>'Respostas novas','reply_filter_all'=>'Todas as respostas','reply_filter_reviewed'=>'Revisadas','reply_filter_dismissed'=>'Descartadas','reply_filter_allclass'=>'Todos os tipos',
 'reply_cls_human'=>'Resposta humana','reply_cls_auto'=>'Resposta automática','reply_cls_bounce'=>'Falha de entrega','reply_cls_unsub'=>'Pedido de descadastro','reply_cls_uncertain'=>'Incerta',
 'reply_sync_now'=>'Sincronizar agora','reply_sync_ok'=>'Última sincronização:','reply_sync_err'=>'Erro de sincronização','reply_sync_auth'=>'Autorização do Gmail necessária — reconecte a conta de Gmail de marketing.','reply_sync_done'=>'Sincronização concluída','reply_sync_running'=>'Sincronizando…',
 'reply_empty'=>'Nenhuma resposta detectada ainda. As respostas são verificadas automaticamente a cada 15 minutos.','reply_open_gmail'=>'Abrir no Gmail','reply_mark_reviewed'=>'Marcar como revisada','reply_dismiss'=>'Descartar',
 'reply_ambiguous'=>'Correspondência ambígua','reply_unmatched'=>'Sem correspondência com um prospect',
 'reply_via_thread'=>'Correspondida por thread do Gmail','reply_via_rfc'=>'Correspondida por cabeçalhos de resposta','reply_via_email'=>'Correspondida pelo endereço do remetente',
 'reply_reviewed_ok'=>'Resposta atualizada','reply_view_prospect'=>'Prospect',
 'reply_col_date'=>'Recebida','reply_col_from'=>'De','reply_col_subject'=>'Assunto','reply_col_class'=>'Tipo','reply_col_campaign'=>'Campanha','reply_col_status'=>'Status','reply_col_actions'=>'Ações',
 'dash_leg_replies'=>'Respostas humanas','dash_a_replies'=>'Respostas para revisar','dash_a_bounces'=>'Falhas de entrega','dash_a_unsub'=>'Pedidos de descadastro','dash_a_auto'=>'Respostas automáticas',
 'dash_rate_na'=>'n/d','dash_camp_replies'=>'Respostas',
 'dash_defs'=>"Definições: «Envios verificados» são entregas do Gmail confirmadas registradas no livro de envios — não status importados da planilha. «Respostas humanas» são mensagens do Gmail recebidas classificadas como respostas genuínas e associadas a um prospect; a taxa de resposta = respostas humanas ÷ envios verificados no mesmo período. Respostas automáticas, falhas e descadastros são rastreados separadamente e nunca contam como respostas. «Aguardando resposta» exige evidência de contato anterior. Linhas de destinatários não são envios até a campanha ser enviada.",
],
'ru-RU' => [
 'tab_replies'=>'Ответы',
 'reply_filter_new'=>'Новые ответы','reply_filter_all'=>'Все ответы','reply_filter_reviewed'=>'Просмотренные','reply_filter_dismissed'=>'Отклонённые','reply_filter_allclass'=>'Все типы',
 'reply_cls_human'=>'Ответ человека','reply_cls_auto'=>'Автоответ','reply_cls_bounce'=>'Ошибка доставки','reply_cls_unsub'=>'Запрос на отписку','reply_cls_uncertain'=>'Неопределённый',
 'reply_sync_now'=>'Синхронизировать','reply_sync_ok'=>'Последняя синхронизация:','reply_sync_err'=>'Ошибка синхронизации','reply_sync_auth'=>'Требуется авторизация Gmail — переподключите маркетинговый аккаунт Gmail.','reply_sync_done'=>'Синхронизация завершена','reply_sync_running'=>'Синхронизация…',
 'reply_empty'=>'Ответов пока не обнаружено. Ответы проверяются автоматически каждые 15 минут.','reply_open_gmail'=>'Открыть в Gmail','reply_mark_reviewed'=>'Отметить просмотренным','reply_dismiss'=>'Отклонить',
 'reply_ambiguous'=>'Неоднозначное совпадение','reply_unmatched'=>'Не сопоставлено с потенциальным клиентом',
 'reply_via_thread'=>'Сопоставлено по треду Gmail','reply_via_rfc'=>'Сопоставлено по заголовкам ответа','reply_via_email'=>'Сопоставлено по адресу отправителя',
 'reply_reviewed_ok'=>'Ответ обновлён','reply_view_prospect'=>'Клиент',
 'reply_col_date'=>'Получен','reply_col_from'=>'От','reply_col_subject'=>'Тема','reply_col_class'=>'Тип','reply_col_campaign'=>'Кампания','reply_col_status'=>'Статус','reply_col_actions'=>'Действия',
 'dash_leg_replies'=>'Ответы людей','dash_a_replies'=>'Ответы для просмотра','dash_a_bounces'=>'Ошибки доставки','dash_a_unsub'=>'Запросы на отписку','dash_a_auto'=>'Автоответы',
 'dash_rate_na'=>'н/д','dash_camp_replies'=>'Ответы',
 'dash_defs'=>"Определения: «Подтверждённые отправки» — подтверждённые Gmail доставки, записанные в журнале отправок, а не импортированные статусы таблицы. «Ответы людей» — входящие сообщения Gmail, классифицированные как genuine responses и сопоставленные с потенциальным клиентом; доля ответов = ответы людей ÷ подтверждённые отправки за тот же период. Автоответы, ошибки доставки и отписки отслеживаются отдельно и никогда не считаются ответами. «Ожидает ответа» требует доказательств предыдущего контакта. Строки получателей не являются отправками, пока кампания не отправлена.",
],
];

foreach ($T as $loc => $keys) {
    $f = "locales/$loc.json";
    $json = file_get_contents($f);
    // Insert block after the "tab_followups" line inside marketing_admin.
    $block = '';
    foreach ($keys as $k => $v) {
        $block .= '        "' . $k . '": ' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ",\n";
    }
    // Remove trailing comma on last inserted line, then keep comma since more keys follow? tab_followups is followed by tab_templates, so trailing comma is fine — but our block ends with comma and next original line is tab_templates. OK.
    if (!preg_match('/^(\s*)"tab_followups":[^\n]*\n/m', $json, $m)) { fwrite(STDERR, "tab_followups not found in $f\n"); exit(1); }
    $indent = $m[1];
    $block = '';
    foreach ($keys as $k => $v) {
        // Every inserted key keeps a trailing comma: tab_templates follows.
        $block .= $indent . '"' . $k . '": ' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ",\n";
    }
    $json = preg_replace('/^(\s*)"tab_followups":[^\n]*\n/m', "$0" . $block, $json, 1);
    file_put_contents($f, $json);
    echo "updated $f\n";
}
