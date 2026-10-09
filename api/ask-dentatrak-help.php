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

    /* --- Product basics: what DentaTrak is (public marketing copy) --- */
    $help[] = "What DentaTrak is:\n" . t('marketing.hero.lead');

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

    $commentsTab = t('cases.comments.title');
    $historyTab = t('cases.history.title');
    $attachmentsLabel = t('cases.attachments_optional');
    $trackingLabel = t('cases.tracking_number');
    $trackLink = t('cases.track_package');
    $help[] = <<<TXT
Comments, attachments, shipping and history on a case:
- Inside an open case, the "{$commentsTab}" tab holds team comments with @mentions that notify the mentioned member.
- The "{$historyTab}" tab shows the case's revision history.
- "{$attachmentsLabel}" in the case form: upload files while creating or editing; open a file to view or download it.
- "{$shippingLabel}" in the case form: "{$carrierLabel}" dropdown plus "{$trackingLabel}"; a "{$trackLink}" link appears next to a saved tracking number.
TXT;

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

    if (isFeatureEnabled('SHOW_PMS_INTEGRATIONS')) {
        $help[] = <<<TXT
Connect an integration such as Open Dental:
1. "{$menu}" > "{$integrations}".
2. Choose the provider and follow Connect / guided setup.
3. Integration syncs are managed from the same section once connected.
TXT;
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
    $help[] = <<<TXT
Notifications and preferences:
- The bell icon in the header opens "{$notifTitle}" with "{$notifAll}" / "{$notifUnread}" tabs and "{$notifMarkAll}". Clicking a notification opens the case it refers to.
- "{$prefsTitle}" opens from the profile menu or the link at the bottom of the notification dropdown; it controls which case events trigger email.
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
