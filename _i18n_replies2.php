<?php
// One-off: reply suppression + cohort dashboard strings, all locales.
$T = [
'en-US' => ['dash_camp_verified'=>'Verified sends','dash_camp_cohort'=>'Cohort resp. rate','dash_a_bperm'=>'Permanent bounces','dash_a_btemp'=>'Temporary failures','dash_a_suppressed'=>'Suppressed contacts','reply_cls_bperm'=>'Permanent bounce','reply_cls_btemp'=>'Temporary failure','reply_cls_bamb'=>'Unclear bounce','reply_suppressed_badge'=>'Suppressed','reply_held_badge'=>'On hold pending review','reply_unsub_review'=>'Unsubscribe — needs review'],
'de-DE' => ['dash_camp_verified'=>'Verifizierte Sendungen','dash_camp_cohort'=>'Kohorten-Antwortquote','dash_a_bperm'=>'Permanente Bounces','dash_a_btemp'=>'Temporäre Fehler','dash_a_suppressed'=>'Unterdrückte Kontakte','reply_cls_bperm'=>'Permanenter Bounce','reply_cls_btemp'=>'Temporärer Fehler','reply_cls_bamb'=>'Unklarer Bounce','reply_suppressed_badge'=>'Unterdrückt','reply_held_badge'=>'Zur Prüfung zurückgestellt','reply_unsub_review'=>'Abmeldung — Prüfung nötig'],
'es-ES' => ['dash_camp_verified'=>'Envíos verificados','dash_camp_cohort'=>'Tasa de resp. por cohorte','dash_a_bperm'=>'Rebotes permanentes','dash_a_btemp'=>'Fallos temporales','dash_a_suppressed'=>'Contactos suprimidos','reply_cls_bperm'=>'Rebote permanente','reply_cls_btemp'=>'Fallo temporal','reply_cls_bamb'=>'Rebote incierto','reply_suppressed_badge'=>'Suprimido','reply_held_badge'=>'En espera de revisión','reply_unsub_review'=>'Baja — requiere revisión'],
'fr-CA' => ['dash_camp_verified'=>'Envois vérifiés','dash_camp_cohort'=>'Taux de rép. par cohorte','dash_a_bperm'=>'Rejets permanents','dash_a_btemp'=>'Échecs temporaires','dash_a_suppressed'=>'Contacts supprimés','reply_cls_bperm'=>'Rejet permanent','reply_cls_btemp'=>'Échec temporaire','reply_cls_bamb'=>'Rejet incertain','reply_suppressed_badge'=>'Supprimé','reply_held_badge'=>'En attente de révision','reply_unsub_review'=>'Désabonnement — à examiner'],
'fr-FR' => ['dash_camp_verified'=>'Envois vérifiés','dash_camp_cohort'=>'Taux de rép. par cohorte','dash_a_bperm'=>'Rejets permanents','dash_a_btemp'=>'Échecs temporaires','dash_a_suppressed'=>'Contacts supprimés','reply_cls_bperm'=>'Rejet permanent','reply_cls_btemp'=>'Échec temporaire','reply_cls_bamb'=>'Rejet incertain','reply_suppressed_badge'=>'Supprimé','reply_held_badge'=>'En attente de révision','reply_unsub_review'=>'Désabonnement — à examiner'],
'it-IT' => ['dash_camp_verified'=>'Invii verificati','dash_camp_cohort'=>'Tasso di risp. per coorte','dash_a_bperm'=>'Rimbalzi permanenti','dash_a_btemp'=>'Errori temporanei','dash_a_suppressed'=>'Contatti soppressi','reply_cls_bperm'=>'Rimbalzo permanente','reply_cls_btemp'=>'Errore temporaneo','reply_cls_bamb'=>'Rimbalzo incerto','reply_suppressed_badge'=>'Soppresso','reply_held_badge'=>'In attesa di revisione','reply_unsub_review'=>'Disiscrizione — da esaminare'],
'ko-KR' => ['dash_camp_verified'=>'검증된 발송','dash_camp_cohort'=>'코호트 응답률','dash_a_bperm'=>'영구 반송','dash_a_btemp'=>'일시적 실패','dash_a_suppressed'=>'차단된 연락처','reply_cls_bperm'=>'영구 반송','reply_cls_btemp'=>'일시적 실패','reply_cls_bamb'=>'불분명한 반송','reply_suppressed_badge'=>'차단됨','reply_held_badge'=>'검토 보류 중','reply_unsub_review'=>'수신 거부 — 검토 필요'],
'pt-BR' => ['dash_camp_verified'=>'Envios verificados','dash_camp_cohort'=>'Taxa de resp. por coorte','dash_a_bperm'=>'Rejeições permanentes','dash_a_btemp'=>'Falhas temporárias','dash_a_suppressed'=>'Contatos suprimidos','reply_cls_bperm'=>'Rejeição permanente','reply_cls_btemp'=>'Falha temporária','reply_cls_bamb'=>'Rejeição incerta','reply_suppressed_badge'=>'Suprimido','reply_held_badge'=>'Retido para revisão','reply_unsub_review'=>'Descadastro — requer revisão'],
'ru-RU' => ['dash_camp_verified'=>'Подтверждённые отправки','dash_camp_cohort'=>'Когортная доля ответов','dash_a_bperm'=>'Постоянные отказы','dash_a_btemp'=>'Временные сбои','dash_a_suppressed'=>'Заблокированные контакты','reply_cls_bperm'=>'Постоянный отказ','reply_cls_btemp'=>'Временный сбой','reply_cls_bamb'=>'Неясный отказ','reply_suppressed_badge'=>'Заблокировано','reply_held_badge'=>'На проверке','reply_unsub_review'=>'Отписка — требует проверки'],
];
foreach ($T as $loc => $keys) {
    $f = "locales/$loc.json";
    $json = file_get_contents($f);
    if (!preg_match('/^(\s*)"reply_col_actions":[^\n]*\n/m', $json, $m)) { fwrite(STDERR, "anchor not found in $f\n"); exit(1); }
    $indent = $m[1];
    $block = '';
    foreach ($keys as $k => $v) {
        $block .= $indent . '"' . $k . '": ' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ",\n";
    }
    $json = preg_replace('/^(\s*)"reply_col_actions":[^\n]*\n/m', "$0" . $block, $json, 1);
    file_put_contents($f, $json);
    echo "updated $f\n";
}
