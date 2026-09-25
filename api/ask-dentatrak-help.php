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

    $help[] = <<<TXT
Add a user / team member:
1. Open the profile menu (top right) and choose "{$menu}".
2. Go to "{$users}".
3. Click "{$addUser}" and enter the person's email.
4. Choose their access: Admin (manage practice), Insights (view analytics), Assigned Only (only see their own cases), or Lab (lab participant).
Note: only Practice Administrators can change practice settings and users.
TXT;

    $help[] = <<<TXT
Add a lab or shared assignment label:
1. "{$menu}" > "{$users}" > Shared Assignment Labels.
2. Add the label and enable its Lab designation if it represents a lab partner.
3. Labels can then be selected in the "{$assignedTo}" field on any case.
TXT;

    $help[] = <<<TXT
Create a case:
1. On the "{$casesTab}" tab, click "+ {$createCase}".
2. Fill in patient name, dentist, case type, "{$dueDate}", optional "{$apptDate}", "{$assignedTo}", notes and attachments.
3. Save to create the card on the case board.
TXT;

    $help[] = <<<TXT
Change a due date / edit a case:
1. Click the case card on the "{$casesTab}" board (or use the case actions menu) to open it.
2. Update "{$dueDate}" or any other field.
3. Save. The card and any Insights metrics update automatically.
TXT;

    $help[] = <<<TXT
Case statuses / workflow:
Cases move across a Kanban board with the practice's columns: {$stages}.
Drag a card between columns to change its status, or update the status inside the case. Admins can rename stage labels in "{$menu}" > "{$display}".
TXT;

    $help[] = <<<TXT
Filter and find cases:
- Search box on the "{$casesTab}" tab finds cases by patient or dentist name.
- Filter panel supports Assigned To, Case Type, late/overdue only, At Risk only, and Review Status.
- "{$menu}" is not needed for filtering - filters live on the Cases toolbar.
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

    $help[] = <<<TXT
Archive and history:
Completed cases can be archived from the case actions menu. The "{$casesTab}" toolbar's View Archived option shows archived cases.
TXT;

    $help[] = <<<TXT
Remakes:
A remake is recorded from the case actions menu with a reason and attribution. Remake counts and trends are analyzed in Insights; the assistant can report simple remake counts from recorded remake events.
TXT;

    $help[] = <<<TXT
Insights:
"{$insightsTab}" contains Practice Insights (volume, turnaround, workload, Smart Recommendations) and Lab Insights (lab comparison and performance). Deep analysis questions belong there.
TXT;

    $help[] = <<<TXT
Preferences, notifications and language:
Notification preferences live under the profile menu > Preferences. The language selector in the header changes the interface language.
TXT;

    return implode("\n\n", $help);
}
