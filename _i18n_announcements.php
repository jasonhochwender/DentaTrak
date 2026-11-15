<?php
// One-off: add Announcements admin-nav entries + announcements/admin_ann
// locale blocks to every non-en-US locale. en-US is the source of truth;
// these are translations. Splice-insertion preserves file formatting
// (CRLF, escaped slashes, \u escapes) - no full re-encode.

$T = [
'de-DE' => [
 'nav' => 'Ankündigungen',
 'navsub' => 'Geplante Mitteilungen an definierte Zielgruppen, die Benutzern unterhalb der App-Kopfzeile angezeigt werden.',
 'region' => 'Ankündigungen', 'dismiss' => 'Ankündigung schließen',
 'ann' => [
  'new_title'=>'Neue Ankündigung','edit_title'=>'Ankündigung bearbeiten',
  'editor_sub'=>'Details ausfüllen, Vorschau prüfen und dann als Entwurf speichern oder veröffentlichen.',
  'f_title'=>'Titel (optional)','f_title_ph'=>'z. B. Geplante Wartung','f_type'=>'Typ',
  'type_info'=>'Information','type_warning'=>'Warnung','type_critical'=>'Kritisch','type_success'=>'Erfolg',
  'f_message'=>'Nachricht','f_message_ph'=>'Ankündigungstext für Benutzer.',
  'f_audience'=>'Zielgruppe','aud_all'=>'Alle Benutzer','aud_fabg'=>'Nur FABG-Mitglieder',
  'f_timezone'=>'Zeitzone für die Datumsangaben',
  'f_tz_hint'=>'Zeiten werden in UTC gespeichert; die gewählte Zone wird für Eingabe und Anzeige verwendet.',
  'f_start'=>'Anzeige Beginn','f_end'=>'Anzeige Ende',
  'f_enabled'=>'Aktiviert (Benutzer sehen sie im Zeitfenster)','f_published'=>'Veröffentlichen (deaktivieren für Entwurf)',
  'preview_label'=>'Vorschau','preview_placeholder'=>'(Vorschau erscheint hier)',
  'btn_save'=>'Speichern','btn_cancel'=>'Abbrechen','btn_new'=>'+ Neue Ankündigung',
  'btn_edit'=>'Bearbeiten','btn_duplicate'=>'Duplizieren','btn_delete'=>'Löschen',
  'btn_enable'=>'Aktivieren','btn_disable'=>'Deaktivieren','btn_publish'=>'Veröffentlichen','btn_unpublish'=>'Veröffentlichung aufheben',
  'col_announcement'=>'Ankündigung','col_type'=>'Typ','col_audience'=>'Zielgruppe','col_window'=>'Anzeigezeitraum',
  'col_status'=>'Status','col_enabled'=>'Aktiviert','col_actions'=>'Aktionen',
  'empty'=>'Noch keine Ankündigungen.','status_draft'=>'Entwurf','status_scheduled'=>'Geplant',
  'status_active'=>'Aktiv','status_expired'=>'Abgelaufen','status_disabled'=>'Deaktiviert',
  'msg_saved'=>'Gespeichert.','msg_duplicated'=>'Als Entwurf dupliziert.','msg_deleted'=>'Gelöscht.',
  'msg_updated'=>'Aktualisiert.','msg_failed'=>'Anfrage fehlgeschlagen',
  'confirm_delete'=>'Diese Ankündigung löschen? Dies kann nicht rückgängig gemacht werden.',
  'untitled'=>'(ohne Titel)',
 ],
],
'es-ES' => [
 'nav' => 'Anuncios',
 'navsub' => 'Avisos programados dirigidos a audiencias específicas que se muestran a los usuarios debajo del encabezado de la aplicación.',
 'region' => 'Anuncios', 'dismiss' => 'Descartar anuncio',
 'ann' => [
  'new_title'=>'Nuevo anuncio','edit_title'=>'Editar anuncio',
  'editor_sub'=>'Complete los detalles, revise la vista previa y luego guarde como borrador o publique.',
  'f_title'=>'Título (opcional)','f_title_ph'=>'p. ej. Mantenimiento programado','f_type'=>'Tipo',
  'type_info'=>'Información','type_warning'=>'Advertencia','type_critical'=>'Crítico','type_success'=>'Éxito',
  'f_message'=>'Mensaje','f_message_ph'=>'Texto del anuncio que ven los usuarios.',
  'f_audience'=>'Audiencia','aud_all'=>'Todos los usuarios','aud_fabg'=>'Solo miembros de FABG',
  'f_timezone'=>'Zona horaria de las fechas','f_tz_hint'=>'Las horas se guardan en UTC; la zona seleccionada se usa para la entrada y la visualización.',
  'f_start'=>'Inicio de visualización','f_end'=>'Fin de visualización',
  'f_enabled'=>'Activado (los usuarios lo ven durante el período)','f_published'=>'Publicar (desmarque para conservar como borrador)',
  'preview_label'=>'Vista previa','preview_placeholder'=>'(la vista previa aparece aquí)',
  'btn_save'=>'Guardar','btn_cancel'=>'Cancelar','btn_new'=>'+ Nuevo anuncio',
  'btn_edit'=>'Editar','btn_duplicate'=>'Duplicar','btn_delete'=>'Eliminar',
  'btn_enable'=>'Activar','btn_disable'=>'Desactivar','btn_publish'=>'Publicar','btn_unpublish'=>'Despublicar',
  'col_announcement'=>'Anuncio','col_type'=>'Tipo','col_audience'=>'Audiencia','col_window'=>'Período de visualización',
  'col_status'=>'Estado','col_enabled'=>'Activado','col_actions'=>'Acciones',
  'empty'=>'Aún no hay anuncios.','status_draft'=>'Borrador','status_scheduled'=>'Programado',
  'status_active'=>'Activo','status_expired'=>'Expirado','status_disabled'=>'Desactivado',
  'msg_saved'=>'Guardado.','msg_duplicated'=>'Duplicado como borrador.','msg_deleted'=>'Eliminado.',
  'msg_updated'=>'Actualizado.','msg_failed'=>'La solicitud falló',
  'confirm_delete'=>'¿Eliminar este anuncio? No se puede deshacer.',
  'untitled'=>'(sin título)',
 ],
],
'fr-CA' => [
 'nav' => 'Annonces',
 'navsub' => 'Avis planifiés ciblés par audience, affichés aux utilisateurs sous l’en-tête de l’application.',
 'region' => 'Annonces', 'dismiss' => 'Fermer l’annonce',
 'ann' => [
  'new_title'=>'Nouvelle annonce','edit_title'=>'Modifier l’annonce',
  'editor_sub'=>'Remplissez les détails, vérifiez l’aperçu, puis enregistrez comme brouillon ou publiez.',
  'f_title'=>'Titre (facultatif)','f_title_ph'=>'p. ex. Maintenance planifiée','f_type'=>'Type',
  'type_info'=>'Information','type_warning'=>'Avertissement','type_critical'=>'Critique','type_success'=>'Succès',
  'f_message'=>'Message','f_message_ph'=>'Texte de l’annonce affiché aux utilisateurs.',
  'f_audience'=>'Audience','aud_all'=>'Tous les utilisateurs','aud_fabg'=>'Membres FABG uniquement',
  'f_timezone'=>'Fuseau horaire des dates','f_tz_hint'=>'Les heures sont stockées en UTC; le fuseau choisi sert à la saisie et à l’affichage.',
  'f_start'=>'Début d’affichage','f_end'=>'Fin d’affichage',
  'f_enabled'=>'Activée (visible pendant la période)','f_published'=>'Publier (décochez pour garder comme brouillon)',
  'preview_label'=>'Aperçu','preview_placeholder'=>'(l’aperçu apparaît ici)',
  'btn_save'=>'Enregistrer','btn_cancel'=>'Annuler','btn_new'=>'+ Nouvelle annonce',
  'btn_edit'=>'Modifier','btn_duplicate'=>'Dupliquer','btn_delete'=>'Supprimer',
  'btn_enable'=>'Activer','btn_disable'=>'Désactiver','btn_publish'=>'Publier','btn_unpublish'=>'Dépublier',
  'col_announcement'=>'Annonce','col_type'=>'Type','col_audience'=>'Audience','col_window'=>'Période d’affichage',
  'col_status'=>'Statut','col_enabled'=>'Activée','col_actions'=>'Actions',
  'empty'=>'Aucune annonce pour le moment.','status_draft'=>'Brouillon','status_scheduled'=>'Planifiée',
  'status_active'=>'Active','status_expired'=>'Expirée','status_disabled'=>'Désactivée',
  'msg_saved'=>'Enregistré.','msg_duplicated'=>'Dupliquée comme brouillon.','msg_deleted'=>'Supprimée.',
  'msg_updated'=>'Mis à jour.','msg_failed'=>'La requête a échoué',
  'confirm_delete'=>'Supprimer cette annonce? Cette action est irréversible.',
  'untitled'=>'(sans titre)',
 ],
],
'fr-FR' => [
 'nav' => 'Annonces',
 'navsub' => 'Avis planifiés ciblés par audience, affichés aux utilisateurs sous l’en-tête de l’application.',
 'region' => 'Annonces', 'dismiss' => 'Fermer l’annonce',
 'ann' => [
  'new_title'=>'Nouvelle annonce','edit_title'=>'Modifier l’annonce',
  'editor_sub'=>'Remplissez les détails, vérifiez l’aperçu, puis enregistrez comme brouillon ou publiez.',
  'f_title'=>'Titre (facultatif)','f_title_ph'=>'p. ex. Maintenance planifiée','f_type'=>'Type',
  'type_info'=>'Information','type_warning'=>'Avertissement','type_critical'=>'Critique','type_success'=>'Succès',
  'f_message'=>'Message','f_message_ph'=>'Texte de l’annonce affiché aux utilisateurs.',
  'f_audience'=>'Audience','aud_all'=>'Tous les utilisateurs','aud_fabg'=>'Membres FABG uniquement',
  'f_timezone'=>'Fuseau horaire des dates','f_tz_hint'=>'Les heures sont stockées en UTC ; le fuseau choisi sert à la saisie et à l’affichage.',
  'f_start'=>'Début d’affichage','f_end'=>'Fin d’affichage',
  'f_enabled'=>'Activée (visible pendant la période)','f_published'=>'Publier (décocher pour garder comme brouillon)',
  'preview_label'=>'Aperçu','preview_placeholder'=>'(l’aperçu apparaît ici)',
  'btn_save'=>'Enregistrer','btn_cancel'=>'Annuler','btn_new'=>'+ Nouvelle annonce',
  'btn_edit'=>'Modifier','btn_duplicate'=>'Dupliquer','btn_delete'=>'Supprimer',
  'btn_enable'=>'Activer','btn_disable'=>'Désactiver','btn_publish'=>'Publier','btn_unpublish'=>'Dépublier',
  'col_announcement'=>'Annonce','col_type'=>'Type','col_audience'=>'Audience','col_window'=>'Période d’affichage',
  'col_status'=>'Statut','col_enabled'=>'Activée','col_actions'=>'Actions',
  'empty'=>'Aucune annonce pour le moment.','status_draft'=>'Brouillon','status_scheduled'=>'Planifiée',
  'status_active'=>'Active','status_expired'=>'Expirée','status_disabled'=>'Désactivée',
  'msg_saved'=>'Enregistré.','msg_duplicated'=>'Dupliquée comme brouillon.','msg_deleted'=>'Supprimée.',
  'msg_updated'=>'Mis à jour.','msg_failed'=>'La requête a échoué',
  'confirm_delete'=>'Supprimer cette annonce ? Cette action est irréversible.',
  'untitled'=>'(sans titre)',
 ],
],
'it-IT' => [
 'nav' => 'Annunci',
 'navsub' => 'Avvisi programmati con pubblico specifico, mostrati agli utenti sotto l’intestazione dell’app.',
 'region' => 'Annunci', 'dismiss' => 'Chiudi annuncio',
 'ann' => [
  'new_title'=>'Nuovo annuncio','edit_title'=>'Modifica annuncio',
  'editor_sub'=>'Compila i dettagli, controlla l’anteprima, poi salva come bozza o pubblica.',
  'f_title'=>'Titolo (facoltativo)','f_title_ph'=>'es. Manutenzione programmata','f_type'=>'Tipo',
  'type_info'=>'Informazione','type_warning'=>'Avviso','type_critical'=>'Critico','type_success'=>'Successo',
  'f_message'=>'Messaggio','f_message_ph'=>'Testo dell’annuncio mostrato agli utenti.',
  'f_audience'=>'Pubblico','aud_all'=>'Tutti gli utenti','aud_fabg'=>'Solo membri FABG',
  'f_timezone'=>'Fuso orario delle date','f_tz_hint'=>'Gli orari sono salvati in UTC; il fuso selezionato è usato per inserimento e visualizzazione.',
  'f_start'=>'Inizio visualizzazione','f_end'=>'Fine visualizzazione',
  'f_enabled'=>'Abilitato (visibile nella finestra)','f_published'=>'Pubblica (deseleziona per tenere come bozza)',
  'preview_label'=>'Anteprima','preview_placeholder'=>'(l’anteprima appare qui)',
  'btn_save'=>'Salva','btn_cancel'=>'Annulla','btn_new'=>'+ Nuovo annuncio',
  'btn_edit'=>'Modifica','btn_duplicate'=>'Duplica','btn_delete'=>'Elimina',
  'btn_enable'=>'Abilita','btn_disable'=>'Disabilita','btn_publish'=>'Pubblica','btn_unpublish'=>'Annulla pubblicazione',
  'col_announcement'=>'Annuncio','col_type'=>'Tipo','col_audience'=>'Pubblico','col_window'=>'Periodo di visualizzazione',
  'col_status'=>'Stato','col_enabled'=>'Abilitato','col_actions'=>'Azioni',
  'empty'=>'Nessun annuncio.','status_draft'=>'Bozza','status_scheduled'=>'Programmato',
  'status_active'=>'Attivo','status_expired'=>'Scaduto','status_disabled'=>'Disabilitato',
  'msg_saved'=>'Salvato.','msg_duplicated'=>'Duplicato come bozza.','msg_deleted'=>'Eliminato.',
  'msg_updated'=>'Aggiornato.','msg_failed'=>'Richiesta non riuscita',
  'confirm_delete'=>'Eliminare questo annuncio? L’operazione non può essere annullata.',
  'untitled'=>'(senza titolo)',
 ],
],
'ko-KR' => [
 'nav' => '공지사항',
 'navsub' => '대상 사용자를 지정해 앱 헤더 아래에 표시되는 예약 공지입니다.',
 'region' => '공지사항', 'dismiss' => '공지 닫기',
 'ann' => [
  'new_title'=>'새 공지','edit_title'=>'공지 수정',
  'editor_sub'=>'세부 정보를 입력하고 미리 보기를 확인한 후 초안으로 저장하거나 게시하세요.',
  'f_title'=>'제목(선택 사항)','f_title_ph'=>'예: 예정된 유지 관리','f_type'=>'유형',
  'type_info'=>'정보','type_warning'=>'경고','type_critical'=>'중요','type_success'=>'성공',
  'f_message'=>'메시지','f_message_ph'=>'사용자에게 표시되는 공지 텍스트입니다.',
  'f_audience'=>'대상','aud_all'=>'모든 사용자','aud_fabg'=>'FABG 회원 전용',
  'f_timezone'=>'날짜의 시간대','f_tz_hint'=>'시간은 UTC로 저장되며, 선택한 시간대가 입력과 표시에 사용됩니다.',
  'f_start'=>'표시 시작','f_end'=>'표시 종료',
  'f_enabled'=>'활성화(기간 동안 사용자에게 표시)','f_published'=>'게시(초안으로 유지하려면 선택 해제)',
  'preview_label'=>'미리 보기','preview_placeholder'=>'(미리 보기가 여기에 표시됩니다)',
  'btn_save'=>'저장','btn_cancel'=>'취소','btn_new'=>'+ 새 공지',
  'btn_edit'=>'수정','btn_duplicate'=>'복제','btn_delete'=>'삭제',
  'btn_enable'=>'활성화','btn_disable'=>'비활성화','btn_publish'=>'게시','btn_unpublish'=>'게시 취소',
  'col_announcement'=>'공지','col_type'=>'유형','col_audience'=>'대상','col_window'=>'표시 기간',
  'col_status'=>'상태','col_enabled'=>'활성화','col_actions'=>'작업',
  'empty'=>'아직 공지가 없습니다.','status_draft'=>'초안','status_scheduled'=>'예약됨',
  'status_active'=>'활성','status_expired'=>'만료됨','status_disabled'=>'비활성화됨',
  'msg_saved'=>'저장되었습니다.','msg_duplicated'=>'초안으로 복제되었습니다.','msg_deleted'=>'삭제되었습니다.',
  'msg_updated'=>'업데이트되었습니다.','msg_failed'=>'요청이 실패했습니다',
  'confirm_delete'=>'이 공지를 삭제하시겠습니까? 되돌릴 수 없습니다.',
  'untitled'=>'(제목 없음)',
 ],
],
'pt-BR' => [
 'nav' => 'Anúncios',
 'navsub' => 'Avisos agendados direcionados a públicos específicos, exibidos aos usuários abaixo do cabeçalho do aplicativo.',
 'region' => 'Anúncios', 'dismiss' => 'Dispensar anúncio',
 'ann' => [
  'new_title'=>'Novo anúncio','edit_title'=>'Editar anúncio',
  'editor_sub'=>'Preencha os detalhes, confira a prévia e salve como rascunho ou publique.',
  'f_title'=>'Título (opcional)','f_title_ph'=>'ex.: Manutenção programada','f_type'=>'Tipo',
  'type_info'=>'Informação','type_warning'=>'Aviso','type_critical'=>'Crítico','type_success'=>'Sucesso',
  'f_message'=>'Mensagem','f_message_ph'=>'Texto do anúncio exibido aos usuários.',
  'f_audience'=>'Público','aud_all'=>'Todos os usuários','aud_fabg'=>'Apenas membros FABG',
  'f_timezone'=>'Fuso horário das datas','f_tz_hint'=>'Os horários são armazenados em UTC; o fuso selecionado é usado para entrada e exibição.',
  'f_start'=>'Início da exibição','f_end'=>'Fim da exibição',
  'f_enabled'=>'Ativado (visível durante o período)','f_published'=>'Publicar (desmarque para manter como rascunho)',
  'preview_label'=>'Prévia','preview_placeholder'=>'(a prévia aparece aqui)',
  'btn_save'=>'Salvar','btn_cancel'=>'Cancelar','btn_new'=>'+ Novo anúncio',
  'btn_edit'=>'Editar','btn_duplicate'=>'Duplicar','btn_delete'=>'Excluir',
  'btn_enable'=>'Ativar','btn_disable'=>'Desativar','btn_publish'=>'Publicar','btn_unpublish'=>'Despublicar',
  'col_announcement'=>'Anúncio','col_type'=>'Tipo','col_audience'=>'Público','col_window'=>'Período de exibição',
  'col_status'=>'Status','col_enabled'=>'Ativado','col_actions'=>'Ações',
  'empty'=>'Nenhum anúncio ainda.','status_draft'=>'Rascunho','status_scheduled'=>'Agendado',
  'status_active'=>'Ativo','status_expired'=>'Expirado','status_disabled'=>'Desativado',
  'msg_saved'=>'Salvo.','msg_duplicated'=>'Duplicado como rascunho.','msg_deleted'=>'Excluído.',
  'msg_updated'=>'Atualizado.','msg_failed'=>'A solicitação falhou',
  'confirm_delete'=>'Excluir este anúncio? Isso não pode ser desfeito.',
  'untitled'=>'(sem título)',
 ],
],
'ru-RU' => [
 'nav' => 'Объявления',
 'navsub' => 'Запланированные уведомления для выбранных аудиторий, отображаемые пользователям под заголовком приложения.',
 'region' => 'Объявления', 'dismiss' => 'Закрыть объявление',
 'ann' => [
  'new_title'=>'Новое объявление','edit_title'=>'Редактировать объявление',
  'editor_sub'=>'Заполните данные, проверьте предпросмотр, затем сохраните как черновик или опубликуйте.',
  'f_title'=>'Заголовок (необязательно)','f_title_ph'=>'напр. Плановое обслуживание','f_type'=>'Тип',
  'type_info'=>'Информация','type_warning'=>'Предупреждение','type_critical'=>'Критично','type_success'=>'Успех',
  'f_message'=>'Сообщение','f_message_ph'=>'Текст объявления для пользователей.',
  'f_audience'=>'Аудитория','aud_all'=>'Все пользователи','aud_fabg'=>'Только участники FABG',
  'f_timezone'=>'Часовой пояс для дат','f_tz_hint'=>'Время хранится в UTC; выбранный пояс используется для ввода и отображения.',
  'f_start'=>'Начало показа','f_end'=>'Конец показа',
  'f_enabled'=>'Включено (видно в течение периода)','f_published'=>'Опубликовать (снимите флажок, чтобы оставить черновиком)',
  'preview_label'=>'Предпросмотр','preview_placeholder'=>'(предпросмотр появится здесь)',
  'btn_save'=>'Сохранить','btn_cancel'=>'Отмена','btn_new'=>'+ Новое объявление',
  'btn_edit'=>'Изменить','btn_duplicate'=>'Дублировать','btn_delete'=>'Удалить',
  'btn_enable'=>'Включить','btn_disable'=>'Отключить','btn_publish'=>'Опубликовать','btn_unpublish'=>'Снять с публикации',
  'col_announcement'=>'Объявление','col_type'=>'Тип','col_audience'=>'Аудитория','col_window'=>'Период показа',
  'col_status'=>'Статус','col_enabled'=>'Включено','col_actions'=>'Действия',
  'empty'=>'Объявлений пока нет.','status_draft'=>'Черновик','status_scheduled'=>'Запланировано',
  'status_active'=>'Активно','status_expired'=>'Истекло','status_disabled'=>'Отключено',
  'msg_saved'=>'Сохранено.','msg_duplicated'=>'Дублировано как черновик.','msg_deleted'=>'Удалено.',
  'msg_updated'=>'Обновлено.','msg_failed'=>'Запрос не выполнен',
  'confirm_delete'=>'Удалить это объявление? Действие необратимо.',
  'untitled'=>'(без заголовка)',
 ],
],
];

$j = function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); };

foreach ($T as $loc => $tr) {
    $f = "locales/$loc.json";
    $s = file_get_contents($f);
    if ($s === false) { echo "READ FAIL $f\n"; continue; }
    $orig = $s;

    // --- 1) admin_nav: insert two keys before the block's closing brace ---
    if (!preg_match('/^(\s*)"admin_nav":\s*\{/m', $s, $m, PREG_OFFSET_CAPTURE)) {
        echo "NO admin_nav $loc\n"; continue;
    }
    $navStart = $m[0][1];
    // find the closing "    }," at the same indent as "admin_nav"
    $indent = $m[1][0];
    $closeRe = '/^' . preg_quote($indent, '/') . '\},\s*$/m';
    if (!preg_match($closeRe, $s, $cm, PREG_OFFSET_CAPTURE, $navStart)) {
        echo "NO admin_nav close $loc\n"; continue;
    }
    $closePos = $cm[0][1];
    // ensure the preceding key line ends with a comma
    $trimmedLen = strlen(rtrim(substr($s, 0, $closePos)));
    if (substr($s, $trimmedLen - 1, 1) !== ',') {
        $s = substr($s, 0, $trimmedLen) . ',' . substr($s, $trimmedLen);
        $closePos += 1;
    }
    $keyIndent = $indent . '    ';
    $insert = $keyIndent . '"announcements": ' . $j($tr['nav']) . ",\n"
            . $keyIndent . '"announcements_subtitle": ' . $j($tr['navsub']) . "\n";
    $s = substr($s, 0, $closePos) . $insert . substr($s, $closePos);

    // --- 2) top-level announcements + admin_ann blocks before "admin_overview" ---
    if (!preg_match('/^    "admin_overview":\s*\{/m', $s)) {
        echo "NO admin_overview anchor $loc\n"; continue;
    }
    $annKeys = '';
    foreach ($tr['ann'] as $k => $v) {
        $annKeys .= '        "' . $k . '": ' . $j($v) . ",\n";
    }
    $annKeys = rtrim($annKeys, ",\n") . "\n";
    $blocks =
        "    \"announcements\": {\n" .
        "        \"region_label\": " . $j($tr['region']) . ",\n" .
        "        \"dismiss\": " . $j($tr['dismiss']) . "\n" .
        "    },\n" .
        "    \"admin_ann\": {\n" . $annKeys . "    },\n";
    $s = preg_replace('/^    "admin_overview":\s*\{/m', $blocks . '    "admin_overview": {', $s, 1);

    if ($s === $orig) { echo "NOCHANGE $loc\n"; continue; }
    json_decode($s);
    if (json_last_error() !== JSON_ERROR_NONE) { echo "JSON INVALID $loc: " . json_last_error_msg() . "\n"; continue; }
    file_put_contents($f, $s);
    echo "OK $loc\n";
}
