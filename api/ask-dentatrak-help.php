<?php
/**
 * Ask DentaTrak - product-help knowledge corpus.
 *
 * Builds a factual, flag-aware description of real DentaTrak functionality
 * for the assistant's planner prompt. Steps reference UI elements through
 * t() so the guidance names the same labels the user actually sees in the
 * active locale. Feature sections gated by feature flags or practice
 * settings are only included when that surface really exists for the
 * current practice - the model must never describe capabilities that are
 * not present.
 *
 * This corpus deliberately documents HOW to perform actions; Ask DentaTrak
 * itself stays read-only.
 */

require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/keyboard-shortcuts.php';

/**
 * Build the help corpus text for the AI planner prompt.
 *
 * @param int $practiceId Active practice (already validated upstream).
 * @return string
 */
function buildAskDentatrakHelpText(int $practiceId): string {
    global $appConfig;

    $menu  = t('settings.settings');                    // profile menu item label
    $users = t('settings.navigation.users');            // "Practice Users & Roles"
    $display = t('settings.navigation.display');        // "Display & Behavior"
    $security = t('settings.navigation.security');      // "Security"
    $integrations = t('settings.navigation.integrations');
    $casesTab = t('navigation.cases');
    $insightsTab = t('navigation.insights');
    $createCase = t('cases.create_new_case');
    $dueDate = t('cases.due_date');
    $apptDate = t('cases.patient_appointment_date');
    $assignedTo = t('cases.assigned_to');
    $addUser = t('settings.users.practice_users.add_user');
    $stages = buildWorkflowStagesPromptText($practiceId);

    // Verified UI labels for the workflow guide below - every step names
    // the control exactly as it appears in the active locale.
    $saveSettings = t('settings.common.save_settings');
    $roleAdmin = t('settings.users.practice_users.admin');
    $roleInsights = t('settings.users.practice_users.insights');
    $roleAssignedOnly = t('settings.users.practice_users.assigned_only');
    $roleLab = t('settings.users.practice_users.lab');
    $roleRemove = t('settings.users.practice_users.remove');
    $labelsTitle = t('settings.users.shared_assignment_labels.title');
    $labelsAdd = t('settings.users.shared_assignment_labels.add');
    $labelsLab = t('settings.users.shared_assignment_labels.lab_checkbox');
    $btnCreateCase = t('cases.create_case');
    $btnSaveAll = t('cases.save_all_changes');
    $viewCaseTitle = t('cases.view_case');
    $editLabel = t('common.edit');
    $moveTo = t('cases.move_to');
    $shippingLabel = t('cases.shipping_optional');
    $viewFull = t('cases.list.view_board');
    $viewCompact = t('cases.list.view_compact');
    $viewList = t('cases.list.view_list');
    $filterSort = t('filters.filters');
    $filterClear = t('filters.clear_filters');
    $filterSearchHint = t('filters.search_placeholder');
    $sortHeading = t('filters.sort_heading');
    $sortAdd = t('filters.add_sort');
    $sortReset = t('filters.reset_sort');
    $caseTypeLabel = t('cases.case_type');
    $reviewStatusLabel = t('filters.review_status');
    $carrierLabel = t('cases.carrier');
    $lateLabel = t('filters.late_cases');
    $dueSoonLabel = t('filters.due_soon');
    $apptRiskLabel = t('filters.appt_risk');
    $atRiskLabel = t('filters.at_risk_only');
    $workflowColsTitle = t('settings.workflow_columns.title');
    $stageNamesTitle = t('settings.display.workflow_stage_names.title');
    $settingsPhoneNote = t('settings.messages.phone_unavailable');
    $practiceInsights = t('insights.practice_insights');
    $labInsights = t('insights.lab_insights');

    $help = [];
    $help[] = "DENTATRAK PRODUCT GUIDE (verified current functionality and approved public product information)";

    /* --- Product basics: what DentaTrak is (public marketing copy plus a
       verified feature summary; this was historically a thin answer) --- */
    $help[] = "What DentaTrak is:\n" . t('marketing.hero.lead')
        . "\nIn practice that means: a Kanban-style case board per practice where each case card carries the patient, dentist, case type, due/appointment dates, assignee, comments, attachments, and shipping/tracking; columns map to the practice's own workflow stages; filters, sorting, list/board views, notifications, Insights reporting, and PMS integrations (such as Open Dental) sit on top of that board."
        . "\nDentaTrak is NOT a practice-management system and does not replace one: it does not do scheduling, charting, claims, or billing of patients. It tracks lab/case work for the practice team and lab partners.";

    /* --- Pricing & plans: all values from the localized public pricing
       section (marketing.pricing) and display_prices in appConfig - never
       invent numbers or features. --- */
    $p = function (string $k) { return t('marketing.pricing.' . $k); };
    $perMonth = $p('per_month'); // "/month"
    $help[] = <<<TXT
Pricing and plans (current public pricing):
- {$p('operate')}: {$p('operate_price_month')}{$perMonth}, {$p('operate_annual')}. {$p('operate_description')} Includes: {$p('operate_features_1')}, {$p('operate_features_attachments')}, {$p('operate_features_2')}, {$p('operate_features_3')}.
- {$p('control')}: {$p('control_price_month')}{$perMonth}, {$p('control_annual')}. {$p('control_description')} Includes: {$p('control_features_1')}, {$p('control_features_2')}, {$p('control_features_3')}, {$p('control_features_4')}, {$p('control_features_5')}, {$p('control_features_6')}.
- {$p('scale')}: {$p('scale_price_month')}{$perMonth}, {$p('scale_annual')}. {$p('scale_description')} Includes: {$p('scale_features_1')}, {$p('scale_features_2')}, {$p('scale_features_3')}. {$p('scale_addon_title')}: {$p('scale_addon_month')} / {$p('scale_addon_year')}.
Trial: {$p('lead')}
{$p('transparency_body')}
TXT;

    /* --- Support & contact: approved public addresses only. The support
       email comes from appConfig/footer string; the security address is
       extracted from the approved HIPAA page copy so there is no second
       hardcoded copy to go stale. --- */
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $securityEmail = 'security@dentatrak.com';
    $hipaaContact = t('marketing.hipaa.contact_body');
    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $hipaaContact, $m)) {
        $securityEmail = $m[0];
    }
    $feedback = t('navigation.feedback');
    $help[] = <<<TXT
Support and contact (approved public channels):
- Product support email: {$supportEmail}
- Security, privacy, data-handling, BAA, or vulnerability reports: {$securityEmail} (advise users NOT to include patient health information in security reports).
- In-app "{$feedback}" option in the profile menu for general feedback.
- There is no separate support portal; the channels above are the way to reach DentaTrak.
TXT;

    /* --- Other public product facts --- */
    $localeNames = [];
    foreach (getSupportedLocales() as $code => $meta) {
        if (!empty($meta['enabled'])) {
            $localeNames[] = $meta['nativeName'] ?? $meta['name'] ?? $code;
        }
    }
    $langList = implode(', ', $localeNames);
    $billing = t('navigation.billing');
    $hipaaTitle = t('marketing.seo.hipaa.title');
    $help[] = <<<TXT
Other product facts (public):
- Attachments/storage: "{$p('operate_features_attachments')}" on every plan - no per-practice attachment limit is advertised.
- Interface languages: {$langList}. Users pick a language from the header language selector or "{$menu}".
- Billing & subscription management: profile menu > "{$billing}".
- Security & HIPAA: DentaTrak publishes a "{$hipaaTitle}" page on the public site covering encryption, access controls, audit logging and Business Associate Agreements. Practice admins manage the BAA under "{$menu}" > Practice.
TXT;

    /* --- Keyboard shortcuts: the registry is the complete, verified list.
       The model must answer only from this list and must say a shortcut
       does not exist rather than inventing one. Key combinations are
       platform-specific and never translated. --- */
    $byScope = [];
    foreach (getLocalizedKeyboardShortcuts() as $s) {
        if (empty($s['enabled'])) {
            continue;
        }
        $byScope[$s['scope_label']][] =
            '- Windows/Linux "' . $s['win'] . '", macOS "' . $s['mac'] . '": ' . $s['description'];
    }
    $shortcutText = 'Keyboard shortcuts (complete verified list - do not invent others):';
    foreach ($byScope as $scopeLabel => $lines) {
        $shortcutText .= "\n" . $scopeLabel . ":\n" . implode("\n", $lines);
    }
    $shortcutText .= "\nGlobal shortcuts do not fire while the user is typing into a text field, and standard dialog keys (Esc closes the active dialog, Enter submits forms) follow normal conventions.";
    $help[] = $shortcutText;

    /* --- Settings access model (verified): the Settings menu item only
       exists for practice administrators, and the whole Settings dialog is
       desktop/tablet-only. Every settings answer must carry both facts. --- */
    $settingsNav = implode('", "', [
        t('settings.navigation.practice'), $display, $users,
        $security, t('settings.navigation.data_privacy'), $integrations,
    ]);
    $saveSettings = t('settings.common.save_settings');
    $help[] = <<<TXT
Settings (who and where):
- "{$menu}" appears in the profile menu ONLY for practice administrators; non-admin users do not see it and cannot change practice settings.
- Settings is desktop/tablet only. On a phone the app shows "{$settingsPhoneNote}" equivalent - tell phone users to continue on a larger screen.
- The Settings dialog has sections: "{$settingsNav}", saved with one "{$saveSettings}" button at the bottom.
TXT;

    $help[] = <<<TXT
Add a user / team member (practice admin, desktop or tablet):
1. Open the profile menu (top right) and choose "{$menu}".
2. Go to "{$users}".
3. Type the person's email address into the email field.
4. Click "{$addUser}" (or press Enter) - the person appears as a new row in the Practice Users table.
5. In their row, set the access checkboxes: "{$roleAdmin}" (manage practice settings and users), "{$roleInsights}" (view Insights analytics), "{$roleAssignedOnly}" (only sees cases assigned to them), "{$roleLab}" (lab participant; only shown when Lab Insights is enabled).
6. Click "{$saveSettings}". Nothing is saved - and no invitation goes out - until "{$saveSettings}" is clicked. On save, the person is added to the practice and receives an invitation email.
Important order: the email is entered FIRST, "{$addUser}" is clicked SECOND, roles are set THIRD, and "{$saveSettings}" LAST. Clicking "{$addUser}" alone does not invite anyone.
TXT;

    $help[] = <<<TXT
Change a user's role or remove a user (practice admin):
1. "{$menu}" > "{$users}". The Practice Users table lists every member with role checkboxes.
2. Tick or untick "{$roleAdmin}", "{$roleInsights}", "{$roleAssignedOnly}", or "{$roleLab}" in their row, or click "{$roleRemove}" to remove them.
3. Click "{$saveSettings}" - role edits are staged in the table and only take effect on save.
TXT;

    $help[] = <<<TXT
Add a lab or shared assignment label (practice admin):
1. "{$menu}" > "{$users}" > "{$labelsTitle}".
2. Type the label name in the input, tick "{$labelsLab}" if it represents a lab partner (only shown when Lab Insights is enabled), and click "{$labelsAdd}".
3. Click "{$saveSettings}". Labels then appear alongside people in the "{$assignedTo}" dropdown on every case and on board cards; they are not user accounts.
TXT;

    $help[] = <<<TXT
Create a case:
1. On the "{$casesTab}" tab, click "+ {$createCase}".
2. Fill in patient name, dentist, case type, "{$dueDate}", optional "{$apptDate}", "{$assignedTo}", notes and attachments. The Status dropdown defaults to the first workflow stage but a different stage can be selected before saving (for cases already underway).
3. Click "{$btnCreateCase}". The card appears in the chosen board column.
TXT;

    $help[] = <<<TXT
Open and edit a case:
1. Desktop: double-click the card, or open the card's case-actions menu (the three-dot button on the card) and choose "{$editLabel}". Phone: tap the card. List view: open the case from the row's actions.
2. Update "{$dueDate}", status, "{$assignedTo}", or any other field - including the "{$shippingLabel}" section (carrier + tracking number).
3. Click "{$btnSaveAll}". The board, filters and Insights metrics update automatically.
Cases a user may not edit (or archived cases) open read-only as "{$viewCaseTitle}".
TXT;

    $help[] = <<<TXT
Case statuses / workflow:
Cases move across a Kanban board with the practice's columns: {$stages}.
- Desktop: drag a card between columns, or change Status inside the case form. Moving a case BACKWARD may prompt to record a remake.
- Phone: cards cannot be dragged - use the case-actions menu's "{$moveTo}" dropdown instead; columns are navigated with the arrows and column dropdown at the bottom of the board.
- Admins customize the workflow itself in "{$menu}" > "{$display}": "{$workflowColsTitle}" adds, renames, reorders and archives columns (the first and last are required; 2-10 active columns), and "{$stageNamesTitle}" changes stage display labels.
TXT;

    $help[] = <<<TXT
Case views, filters and sorting:
- The "{$casesTab}" toolbar switches between "{$viewFull}" (full cards), "{$viewCompact}" (smaller cards) and "{$viewList}" (a sortable table of all cases).
- The "{$filterSort}" button opens the filter bar: a search box ("{$filterSearchHint}"), "{$caseTypeLabel}", "{$assignedTo}", "{$reviewStatusLabel}", "{$carrierLabel}" dropdowns, and "{$lateLabel}", "{$dueSoonLabel}", "{$apptRiskLabel}" / "{$atRiskLabel}" checkboxes. "{$filterClear}" resets them.
- The same bar has a "{$sortHeading}" section that orders cards within each column ("{$sortAdd}", "{$sortReset}").
TXT;

    $caseActions = t('cases.actions_menu');
    $printLabel = t('common.print');
    $archiveLabel = t('common.archive');
    $recordRemake = t('remakes.record_remake');
    $help[] = <<<TXT
Case actions menu and printing:
Every case card has a "{$caseActions}" menu (the three-dot button). Its actions are "{$editLabel}", "{$printLabel}", "{$recordRemake}", and "{$archiveLabel}" - plus a "{$moveTo}" dropdown on phones. "{$printLabel}" prints the case details. The same menu exists on rows in "{$viewList}" view.
TXT;

    $attachmentsLabel = t('cases.attachments_optional');
    $trackingLabel = t('cases.tracking_number');
    $trackLink = t('cases.track_package');

    // Comments and revision-history tabs are feature-flagged surfaces; only
    // describe the ones this deployment actually shows.
    $caseDetailLines = [];
    if (isFeatureEnabled('SHOW_COMMENTS')) {
        $commentsTab = t('cases.comments.title');
        $caseDetailLines[] = "- Inside an open case, the \"{$commentsTab}\" tab holds team comments with @mentions that notify the mentioned member.";
    }
    if (isFeatureEnabled('SHOW_REVISION_HISTORY')) {
        $historyTab = t('cases.history.title');
        $caseDetailLines[] = "- The \"{$historyTab}\" tab shows the case's revision history.";
    }
    // Supported attachment types come from the upload allowlist in
    // appConfig - the single source the upload endpoint enforces.
    $exts = $appConfig['gcs']['allowed_extensions'] ?? [];
    $modelExts = array_values(array_intersect($exts, ['stl', 'obj', 'ply', 'dcm']));
    $imageExts = array_values(array_intersect($exts, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg']));
    $docExts   = array_values(array_intersect($exts, ['pdf', 'zip']));
    $fmtParts = [];
    if ($modelExts) $fmtParts[] = '3D models/scans (' . implode(', ', $modelExts) . ')';
    if ($imageExts) $fmtParts[] = 'images (' . implode(', ', $imageExts) . ')';
    if ($docExts)   $fmtParts[] = 'documents (' . implode(', ', $docExts) . ')';
    $fmtText = $fmtParts ? implode('; ', $fmtParts) : 'the configured allowed file types';
    $caseDetailLines[] = "- \"{$attachmentsLabel}\" in the case form: upload files while creating or editing. Supported types: {$fmtText}. Images open in an in-app preview viewer; other files (including STL scan files) download through each file's action. If an upload is rejected, the file type or size is outside the allowed set.";
    $caseDetailLines[] = "- \"{$shippingLabel}\" in the case form: \"{$carrierLabel}\" dropdown plus \"{$trackingLabel}\"; a \"{$trackLink}\" link appears next to a saved tracking number.";
    $help[] = "Comments, attachments, shipping and history on a case:\n" . implode("\n", $caseDetailLines);

    if (isFeatureEnabled('SHOW_CASE_DOWNLOAD_ALL')) {
        $downloadAll = t('attachments.download_all');
        $help[] = <<<TXT
Download all files for a case:
1. Open the case.
2. In the Attachments area, click "{$downloadAll}" to get a ZIP of every eligible file.
Individual files can also be downloaded one at a time with each file's Download action.
TXT;
    }

    $help[] = <<<TXT
Two-factor authentication (your own account):
1. "{$menu}" > "{$security}".
2. Under Two-Factor Authentication, click Enable and scan the QR code with an authenticator app.
3. Enter the 6-digit code to confirm.
Practice admins can require 2FA for the whole practice in "{$security}" as well.
TXT;

    if (isCaseReviewTrackingEnabled($practiceId)) {
        $help[] = <<<TXT
"Needs Review" status:
The practice has Case Review Tracking enabled (set under "{$menu}" > "{$display}"). Cases can be flagged Needs Review and then Mark Reviewed from the case card or case view - it is a review flag, not a workflow stage.
TXT;
    }

    $help[] = <<<TXT
Appointment Risk:
When enabled under "{$menu}" > "{$display}", cases whose "{$dueDate}" is close to (or past) the "{$apptDate}" are highlighted At Risk so the team can act before the appointment. "At Risk only" in the filter panel shows them.
TXT;

    $pastDueLabel = t('settings.display.past_due.label');
    $comingDueLabel = t('settings.display.coming_due.label');
    $autoArchiveLabel = t('settings.display.auto_archive.label');
    $help[] = <<<TXT
Board highlighting and auto-archive (Display & Behavior):
- "{$pastDueLabel}" and "{$comingDueLabel}" tint board cards when a case is past due or approaching its due date; admins set the day thresholds.
- "{$autoArchiveLabel}" moves cases in the final column out of the board after the configured number of days (0 = never). Archived cases are never deleted - they stay restorable on the Archived Cases screen.
TXT;

    if (isFeatureEnabled('SHOW_GOOGLE_DRIVE_BACKUP')) {
        $gdLabel = t('settings.display.google_drive.label');
        $help[] = <<<TXT
Google Drive backup (available in this deployment):
- "{$menu}" > "{$display}" > "{$gdLabel}" backs up every practice case (details and attached files) to a shared Google Drive folder kept in sync as cases change. Requirements: a Google sign-in account, Google Workspace with a signed BAA (PHI is copied), only new cases created after enabling are backed up, and disabling preserves existing backup files.
TXT;
    }

    if (isFeatureEnabled('SHOW_PMS_INTEGRATIONS')) {
        $help[] = buildAskDentatrakOpenDentalHelp($menu, $integrations, $casesTab, $caseActions);
    }

    $viewArchived = t('archive.view_archived_button');
    $restoreLabel = t('archive.actions.restore');
    $deletePerm = t('archive.actions.delete_permanently');
    $help[] = <<<TXT
Archive, restore and delete cases:
1. Archive: open the case's "{$caseActions}" menu and choose "{$archiveLabel}", then confirm. The card leaves the board.
2. Restore or review: click "{$viewArchived}" on the "{$casesTab}" toolbar to open the Archived Cases screen, where cases can be searched, filtered, viewed, printed, and "{$restoreLabel}"d back onto the board.
3. "{$deletePerm}" on the Archived Cases screen removes a case forever - this cannot be undone.
TXT;

    $help[] = <<<TXT
Remakes:
"{$recordRemake}" in a case's "{$caseActions}" menu records a remake with a reason and attribution. Moving a case backward in the workflow may also prompt to record one. Remake counts and trends are analyzed in Insights; the assistant can report recorded remake counts and remake rates.
TXT;

    $help[] = <<<TXT
Insights:
"{$insightsTab}" (visible only to users whose "{$roleInsights}" permission is enabled in "{$users}") contains "{$practiceInsights}" (volume, turnaround, workload, Recommendations) and "{$labInsights}" (lab comparison and performance). Deep analysis questions belong there.
TXT;

    $notifTitle = t('notifications.title');
    $notifAll = t('notifications.all');
    $notifUnread = t('notifications.unread');
    $notifMarkAll = t('notifications.mark_all_read');
    $prefsTitle = t('preferences.title');
    // The event list is generated from the same preference keys the UI
    // renders, so the corpus names exactly the toggles the user sees.
    $prefEvents = [];
    foreach (['case_created', 'assignment_changed', 'file_added', 'file_deleted',
              'notes_changed', 'status_changed', 'due_date_changed',
              'appointment_date_changed', 'case_details_changed', 'mention',
              'comment_added'] as $ev) {
        $prefEvents[] = t('preferences.events.' . $ev);
    }
    $masterLabel = t('preferences.master_label');
    $help[] = <<<TXT
Notifications and preferences:
- The bell icon in the header opens "{$notifTitle}" with "{$notifAll}" / "{$notifUnread}" tabs and "{$notifMarkAll}". Clicking a notification opens the case it refers to.
- "{$prefsTitle}" opens from the profile menu or the link at the bottom of the notification dropdown. Each toggle controls one kind of email: {$prefEvents[0]}; {$prefEvents[1]}; {$prefEvents[2]}; {$prefEvents[3]}; {$prefEvents[4]}; {$prefEvents[5]}; {$prefEvents[6]}; {$prefEvents[7]}; {$prefEvents[8]}; {$prefEvents[9]}; {$prefEvents[10]}. A master switch ("{$masterLabel}") turns all email notifications off while preserving the individual choices.
TXT;

    /* --- Lab collaboration & case visibility (who can see what) --- */
    $labelsSeeNote = t('settings.users.practice_users.assigned_only');
    $help[] = <<<TXT
Labs, shared assignment labels, and who can see a case:
- Labs are not separate accounts in the case workflow - a lab is represented by a shared assignment label (created in "{$menu}" > "{$users}" > "{$labelsTitle}") or by a lab-flagged team member. Assigning a case to a lab label hands it to that lab on the board.
- A member with "{$labelsSeeNote}" enabled sees ONLY cases assigned to them (by their email) or to a label they are a recipient of - lab partners are typically set up this way so they see just their own work, never the whole practice.
- Everyone else sees all non-archived practice cases. Ask DentaTrak itself answers data questions only within the asking user's own visibility - a limited member never gets numbers or lists for cases they cannot see.
TXT;

    /* --- Multiple practices / account --- */
    $help[] = <<<TXT
Multiple practices and accounts:
- A user invited to more than one practice switches between them with the practice-name switcher in the header (next to the logo); every practice's cases, settings, users, and billing are completely separate.
- One person can belong to several practices with a different role in each. Invitations are per-practice and per-email.
TXT;

    $dataPrivacyNav = t('settings.navigation.data_privacy');
    $exportBtn = t('settings.data_privacy.export.button');
    $practiceNav = t('settings.navigation.practice');
    $help[] = <<<TXT
Account, security and data:
- "{$menu}" > "{$security}": change password (password accounts), enable two-factor authentication, and - for admins - require 2FA practice-wide.
- "{$menu}" > "{$dataPrivacyNav}": "{$exportBtn}" requests a full practice data export delivered as a download link by email (links expire after 7 days).
- "{$menu}" > "{$practiceNav}": practice display name and logo.
TXT;

    /* --- Password & sign-in --- */
    $forgotLabel = t('auth.login.forgot_password');
    $help[] = <<<TXT
Password and sign-in:
- Forgotten password: use the "{$forgotLabel}" link on the sign-in page to receive a reset email; it is not changed inside the app.
- Signed-in password accounts change their password under "{$menu}" > "{$security}". Accounts that sign in with Google manage the password on the Google account instead.
TXT;

    /* --- Theme / appearance --- */
    $themeLabel = t('settings.display.theme.label');
    $themeSys = t('settings.display.theme.system');
    $themeLight = t('settings.display.theme.light');
    $themeDark = t('settings.display.theme.dark');
    $help[] = <<<TXT
Appearance (theme):
- "{$menu}" > "{$display}" > "{$themeLabel}": choose "{$themeSys}" (follow the device), "{$themeLight}", or "{$themeDark}". The choice is per-practice setting saved with "{$saveSettings}".
TXT;

    $takeTour = t('navigation.take_tour');
    $userGuide = t('navigation.user_guide');
    $help[] = <<<TXT
Language and tour:
The language selector in the header changes the interface language instantly ({$langList}). The profile menu also offers "{$takeTour}" and "{$userGuide}".
TXT;

    /* --- Supported routes the assistant may reference in answers. These
       are the only in-app deep links; do not invent others. --- */
    $help[] = <<<TXT
Linkable screens (only these deep links exist):
- A specific case: main.php?case=<case_id> (opens the case view; access is still enforced server-side).
- Insights: #insights, #insights/practice, #insights/labs.
- Billing modal: ?billing=1.
TXT;

    return implode("\n\n", $help);
}

/**
 * Open Dental integration knowledge for the planner prompt.
 *
 * Built from the same t() keys the Settings > Integrations card renders
 * (setup.*, toolbar.*, outbound.*, import.*, meta.*, subscription.*,
 * messages.*) plus od_send.* for the case action, so the instructions can
 * never drift from what the UI actually says. Only the behavioral facts
 * that are NOT user-facing strings (approval ordering, overwrite policy,
 * version minimum, scope limits) are stated inline - they mirror the
 * enforced code paths in api/integrations.php, IntegrationOutbound and
 * OpenDentalAdapter.
 *
 * Only called when SHOW_PMS_INTEGRATIONS is enabled.
 */
function buildAskDentatrakOpenDentalHelp(string $menu, string $integrations, string $casesTab, string $caseActions): string {
    global $appConfig;
    $i = function (string $k) { return t('settings.integrations.' . $k); };
    $provider = $i('providers.open_dental.name');
    $planNote = t('api.integrations.plan_required');

    $out = [];
    $out[] = <<<TXT
{$provider} integration (all under "{$menu}" > "{$integrations}"):
What it is: {$i('providers.open_dental.description')} Access {$planNote} - every practice on an active or trialing plan can connect; the card shows a lock notice when the practice is not subscribed.
TXT;

    /* --- Connect / setup flow, from the rendered step copy --- */
    $out[] = <<<TXT
Connect a {$provider} practice (practice administrator):
1. "{$menu}" > "{$integrations}" > {$provider} card > "{$i('connect')}" - "{$i('setup.step1_title')}": {$i('setup.step1_desc')} {$i('setup.key_once_note')}
2. "{$i('setup.step2_title')}": {$i('setup.step2_a')} {$i('setup.step2_b')} {$i('setup.step2_c')}
3. "{$i('setup.step3_title')}": {$i('setup.step3_desc')}
4. "{$i('setup.step4_title')}": {$i('setup.step4_desc')}
A connection key may be regenerated later ("{$i('setup.regenerate_key')}"), which replaces the key in Open Dental too.
TXT;

    /* --- Inbound sync (Open Dental -> DentaTrak) --- */
    $out[] = <<<TXT
Inbound sync ({$provider} -> DentaTrak):
- Once connected, "{$i('subscription.enable')}" turns on automatic lab case updates: new Open Dental lab cases are imported and Open Dental-managed fields (patient, provider, appointment, due date, instructions) stay in sync. DentaTrak-only details - comments, attachments, custom workflow columns, assignees, carrier/tracking, notes made in DentaTrak - are never touched by inbound sync.
- "{$i('import.button')}" backfills existing lab cases for a chosen look-back window ({$i('import.scope_30')} / {$i('import.scope_90')} / {$i('import.scope_180')} / {$i('import.scope_365')}). {$i('import.confirm_message')}
- Where Open Dental supports it, DentaTrak also watches for lab cases deleted in Open Dental. Sync status (last event, last import, imported count, issues) is shown on the integration card.
TXT;

    /* --- Toolbar button + patient launch --- */
    $launchUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/od-launch.php?pat=[PatNum]';
    $out[] = <<<TXT
{$provider} toolbar button (open a patient's cases from Open Dental):
{$i('toolbar.intro')}
1. {$i('toolbar.step1')}
2. {$i('toolbar.step_enabled')}
3. {$i('toolbar.step2_intro')} {$i('toolbar.field_description')}: DentaTrak; {$i('toolbar.field_path')}: {$launchUrl}; {$i('toolbar.field_args')}: {$i('toolbar.field_args_blank')}; {$i('toolbar.field_button')}: DentaTrak.
4. {$i('toolbar.step3')}
5. {$i('toolbar.step4')}
6. {$i('toolbar.step5')}
{$i('toolbar.how_it_works')} The patient number is only a lookup hint - the user still signs in normally and only sees cases they are authorized for. Troubleshooting: {$i('toolbar.trouble_1')} {$i('toolbar.trouble_2')} {$i('toolbar.trouble_3')}
TXT;

    /* --- Two-way sync (DentaTrak -> Open Dental writes) --- */
    $sendMenuItem = t('od_send.menu_item');
    $out[] = <<<TXT
Two-way sync (DentaTrak -> {$provider}; practice administrator opt-in):
- {$i('outbound.description')}
- Requirements to enable: an active connection with inbound updates on, an office timezone ("{$i('outbound.timezone_label')}"), and the "All Others" API permission on the connection key in Open Dental (Setup > Advanced Setup > API). DentaTrak verifies the connection and that permission when "{$i('outbound.enable')}" is clicked and refuses with guidance if the permission is missing ("{$i('outbound.missing_permission_help')}"). Lab case writes require Open Dental 24.4.19 or later.
- Approved write mappings: {$i('outbound.map_sent')} {$i('outbound.map_received')} {$i('outbound.map_note')} Delivered is intentionally never written back.
- {$i('outbound.create_note')} Open Dental LabCases are created through the "{$caseActions}" menu action "{$sendMenuItem}" - the sender confirms the matching Open Dental patient, provider, and laboratory; DentaTrak never auto-creates or auto-duplicates records.
- Overwrite and conflict policy: existing Open Dental dates are never overwritten; when Open Dental already holds a different value the update is parked as a conflict for review instead of clobbering it. The card shows sync health ("{$i('outbound.health_synced')}", "{$i('outbound.health_pending')}", "{$i('outbound.health_failed')}", "{$i('outbound.health_conflicts')}", "{$i('outbound.health_last_sync')}") and "{$i('outbound.retry_failed')}" requeues failed updates.
- "{$i('outbound.disable')}" turns write-back off while inbound updates keep flowing; "{$i('outbound.disable_confirm_message')}"
- Outbound writes are lifecycle progress only: comments, attachments, tooth shade, material, carrier/tracking, assignee and custom workflow internals are never sent to Open Dental.
TXT;

    return implode("\n\n", $out);
}
