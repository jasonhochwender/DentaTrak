<?php
/**
 * Attention-queue classifier regression test (no database).
 *
 * Exercises api/case-attention.php::classifyCaseAttentionRisk() against the
 * same thresholds/flags the Case Health classification uses, plus the
 * attention-queue tier/primary-reason priority rules.
 */

require_once __DIR__ . '/../api/case-attention.php';

$pass = 0;
$fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $label\n"; }
    else { $fail++; echo "FAIL $label\n"; }
}

$day = function ($offset) { return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days')); };
$mk = function (array $over) use ($day) {
    return array_merge([
        'dueDate' => null,
        'patientAppointmentDate' => null,
        'labAckStatus' => null,
        'status' => 'Designed',
    ], $over);
};

$prefs = [
    'past_due_days' => 3,
    'coming_due_days' => 5,
    'appointment_risk_days' => 4,
    'highlight_past_due' => 1,
    'highlight_coming_due' => 1,
    'highlight_appointment_risk' => 1,
];
$terminal = 'Delivered';

// 1. Plain overdue (past the 3-day threshold)
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(-10)]), $prefs, $terminal);
check('late case flagged', $r['late'] === true && $r['attention'] === true);
check('late tier 3 / primary late', $r['tier'] === 3 && $r['primaryReason'] === 'late');
check('late reasons', $r['reasons'] === ['late']);
check('daysUntilDue negative', $r['daysUntilDue'] === -10);

// 2. Overdue + appointment inside risk window -> tier 1
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(-10), 'patientAppointmentDate' => $day(2)]), $prefs, $terminal);
check('late+appt tier 1', $r['tier'] === 1 && $r['primaryReason'] === 'late_appt');
check('late+appt reasons both', in_array('late', $r['reasons'], true) && in_array('appt_risk', $r['reasons'], true));

// 3. Appointment risk only (not overdue, not due soon)
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(20), 'patientAppointmentDate' => $day(2)]), $prefs, $terminal);
check('appt-only tier 2', $r['tier'] === 2 && $r['primaryReason'] === 'appt_risk' && $r['late'] === false && $r['dueSoon'] === false);

// 4. Appointment already missed still counts as risk
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(20), 'patientAppointmentDate' => $day(-1)]), $prefs, $terminal);
check('missed appt still appt_risk', $r['apptRisk'] === true && $r['tier'] === 2);

// 5. Due soon only
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(3)]), $prefs, $terminal);
check('due-soon tier 4', $r['tier'] === 4 && $r['primaryReason'] === 'due_soon' && $r['dueSoon'] === true);

// 6. Due date beyond coming-due window -> on track
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(10)]), $prefs, $terminal);
check('beyond window not attention', $r['attention'] === false && $r['tier'] === null);

// 7. Awaiting lab ack only -> tier 5
$r = classifyCaseAttentionRisk($mk(['labAckStatus' => 'awaiting']), $prefs, $terminal);
check('awaiting ack tier 5', $r['tier'] === 5 && $r['primaryReason'] === 'awaiting_ack');
$r = classifyCaseAttentionRisk($mk(['labAckStatus' => 'awaiting_lab_review']), $prefs, $terminal);
check('awaiting_lab_review also ack', $r['awaitingAck'] === true && $r['tier'] === 5);
$r = classifyCaseAttentionRisk($mk(['labAckStatus' => 'needs_info']), $prefs, $terminal);
check('needs_info is not awaiting', $r['awaitingAck'] === false);
$r = classifyCaseAttentionRisk($mk(['labAckStatus' => 'confirmed']), $prefs, $terminal);
check('confirmed is not awaiting', $r['awaitingAck'] === false);

// 8. Overlapping: due soon + ack -> due soon wins (tier 4)
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(2), 'labAckStatus' => 'awaiting']), $prefs, $terminal);
check('due_soon+ack tier 4', $r['tier'] === 4 && in_array('awaiting_ack', $r['reasons'], true));

// 9. Highlight flags disabled -> risk classes suppressed
$noHighlight = array_merge($prefs, ['highlight_past_due' => 0, 'highlight_coming_due' => 0, 'highlight_appointment_risk' => 0]);
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(-10), 'patientAppointmentDate' => $day(1)]), $noHighlight, $terminal);
check('highlights off suppresses risks', $r['late'] === false && $r['apptRisk'] === false && $r['dueSoon'] === false);

// 10. Terminal status never attention
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(-10), 'status' => 'Delivered']), $prefs, $terminal);
check('terminal excluded', $r['attention'] === false);

// 11. Missing dates handled gracefully
$r = classifyCaseAttentionRisk($mk([]), $prefs, $terminal);
check('empty case not attention', $r['attention'] === false && $r['daysUntilDue'] === null);

// 12. Boundary: exactly at thresholds
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(-3)]), $prefs, $terminal); // -3 <= -3 late
check('late boundary inclusive', $r['late'] === true);
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(-2)]), $prefs, $terminal); // -2 > -3 not late, <0 not due soon
check('inside grace not attention', $r['attention'] === false);
$r = classifyCaseAttentionRisk($mk(['dueDate' => $day(5)]), $prefs, $terminal); // 5 <= 5 due soon
check('due-soon boundary inclusive', $r['dueSoon'] === true);
$r = classifyCaseAttentionRisk($mk(['patientAppointmentDate' => $day(4)]), $prefs, $terminal); // 4 <= 4 appt risk
check('appt boundary inclusive', $r['apptRisk'] === true);

// 13. Priority order sanity: appt-only outranks plain late (tier 2 < 3)
$rAppt = classifyCaseAttentionRisk($mk(['dueDate' => $day(20), 'patientAppointmentDate' => $day(1)]), $prefs, $terminal);
$rLate = classifyCaseAttentionRisk($mk(['dueDate' => $day(-10)]), $prefs, $terminal);
check('appt tier outranks late tier', $rAppt['tier'] < $rLate['tier']);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
