<?php
/**
 * Shared case-attention classification.
 *
 * Single source of truth for "which active cases need attention" used by:
 *   - api/get-analytics.php  (Practice Insights Attention Required queue)
 *   - api/list-cases.php     (attention_only=true board filter / View All)
 *
 * The risk booleans deliberately mirror the mutually-exclusive Case Health
 * classification in get-analytics.php (same practice thresholds, same
 * highlight_* feature flags) so queue counts reconcile with the KPI cards.
 * Unlike Case Health, attention categories may overlap on a single case;
 * `tier`/`primaryReason` pick the most urgent one for display.
 */

if (!function_exists('dentatrakAttentionDayDiff')) {
    /**
     * Whole-calendar-day difference (target - today) in the server timezone.
     * Mirrors getCalendarDayDiff() in get-analytics.php / list-cases.php.
     */
    function dentatrakAttentionDayDiff($dateString) {
        if (empty($dateString)) {
            return null;
        }
        try {
            $due = new DateTimeImmutable($dateString, new DateTimeZone(date_default_timezone_get()));
            $due = $due->setTime(0, 0, 0);
            $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
            $today = $today->setTime(0, 0, 0);
            return (int) round(($due->getTimestamp() - $today->getTimestamp()) / 86400);
        } catch (Throwable $e) {
            return null;
        }
    }
}

/**
 * Classify one case for the attention queue.
 *
 * @param array $case Normalized fields (callers map snake_case/camelCase):
 *   - dueDate               string|null
 *   - patientAppointmentDate string|null
 *   - labAckStatus          string|null  ('awaiting'|'awaiting_lab_review'|...)
 *   - status                string       current workflow stage id
 * @param array $prefs Practice display settings:
 *   past_due_days, coming_due_days, appointment_risk_days,
 *   highlight_past_due, highlight_coming_due, highlight_appointment_risk
 * @param string|null $terminalStatusId Cases already in the terminal stage
 *   are never attention items (defensive; callers normally pre-filter).
 *
 * @return array{
 *   late: bool, apptRisk: bool, dueSoon: bool, awaitingAck: bool,
 *   attention: bool, tier: int|null, primaryReason: string|null,
 *   reasons: string[], daysUntilDue: int|null, daysUntilAppt: int|null
 * }
 */
function classifyCaseAttentionRisk(array $case, array $prefs, $terminalStatusId = null): array {
    $status = $case['status'] ?? '';
    if ($terminalStatusId !== null && $status === $terminalStatusId) {
        return [
            'late' => false, 'apptRisk' => false, 'dueSoon' => false,
            'awaitingAck' => false, 'attention' => false,
            'tier' => null, 'primaryReason' => null, 'reasons' => [],
            'daysUntilDue' => null, 'daysUntilAppt' => null,
        ];
    }

    $pastDueDays = (int)($prefs['past_due_days'] ?? 0);
    $comingDueDays = (int)($prefs['coming_due_days'] ?? 0);
    $appointmentRiskDays = (int)($prefs['appointment_risk_days'] ?? 0);

    $daysUntilDue = dentatrakAttentionDayDiff($case['dueDate'] ?? null);
    $daysUntilAppt = dentatrakAttentionDayDiff($case['patientAppointmentDate'] ?? null);

    // Same gates as the Case Health classification: a disabled highlight
    // means the practice does not treat that condition as a risk state.
    $late = !empty($prefs['highlight_past_due'])
        && $daysUntilDue !== null && $daysUntilDue <= -$pastDueDays;

    $apptRisk = !empty($prefs['highlight_appointment_risk'])
        && !empty($case['patientAppointmentDate'])
        && $daysUntilAppt !== null && $daysUntilAppt <= $appointmentRiskDays;

    $dueSoon = !empty($prefs['highlight_coming_due'])
        && $daysUntilDue !== null && $daysUntilDue >= 0 && $daysUntilDue <= $comingDueDays;

    // Lab acknowledgment: only an OPEN acknowledgment counts. Matches the
    // board's ack_awaiting_only filter ('awaiting' + 'awaiting_lab_review').
    $awaitingAck = in_array($case['labAckStatus'] ?? null, ['awaiting', 'awaiting_lab_review'], true);

    $reasons = [];
    if ($late) $reasons[] = 'late';
    if ($apptRisk) $reasons[] = 'appt_risk';
    if ($dueSoon) $reasons[] = 'due_soon';
    if ($awaitingAck) $reasons[] = 'awaiting_ack';

    // Priority order per product spec:
    //   1 overdue + appointment risk, 2 appointment risk, 3 overdue,
    //   4 due soon, 5 awaiting lab acknowledgment.
    $tier = null;
    $primaryReason = null;
    if ($late && $apptRisk) { $tier = 1; $primaryReason = 'late_appt'; }
    elseif ($apptRisk) { $tier = 2; $primaryReason = 'appt_risk'; }
    elseif ($late) { $tier = 3; $primaryReason = 'late'; }
    elseif ($dueSoon) { $tier = 4; $primaryReason = 'due_soon'; }
    elseif ($awaitingAck) { $tier = 5; $primaryReason = 'awaiting_ack'; }

    return [
        'late' => $late,
        'apptRisk' => $apptRisk,
        'dueSoon' => $dueSoon,
        'awaitingAck' => $awaitingAck,
        'attention' => $tier !== null,
        'tier' => $tier,
        'primaryReason' => $primaryReason,
        'reasons' => $reasons,
        'daysUntilDue' => $daysUntilDue,
        'daysUntilAppt' => $daysUntilAppt,
    ];
}
