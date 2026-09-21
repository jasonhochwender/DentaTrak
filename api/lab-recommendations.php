<?php
/**
 * Smart Recommendations builder for Lab Insights (V1).
 *
 * Deterministic, rule-based recommendations derived exclusively from the
 * already-computed `performance` payload produced by
 * api/lab-performance-metrics.php (computeLabPerformanceMetrics). No SQL,
 * no session, no metric recalculation — the metrics service remains the
 * single source of truth for every number referenced here.
 *
 * Principles:
 *  - Respect backend sufficiency/coverage signals (`sufficient`,
 *    `dueDateCoveragePct`, `meta.minSampleSize`) instead of inventing
 *    new thresholds.
 *  - Never imply causation: comparisons are observations, not verdicts.
 *  - Remakes are not lab failures by default; lab-attributed remakes are
 *    reported as their own metric, never as a "failure rate".
 *  - Regression/revision data is never used here (remake metrics come only
 *    from structured case_remake_events via the payload).
 *  - Positive and neutral observations are surfaced alongside issues.
 *
 * Output: a sorted list of structured recommendation objects (see
 * labRec() for the shape). The frontend renders them verbatim via
 * i18n templates keyed on `type` + `params`; nothing here produces
 * user-facing prose so the same objects stay reusable (e.g. Practice
 * Insights) without parsing strings.
 */

// ── Thresholds (centralized, documented) ─────────────────────────────────
// Minimum sample size comes from the payload (meta.minSampleSize); these
// are only the *change/magnitude* thresholds that decide whether a real,
// sufficiently-sampled observation is worth surfacing.
const REC_TURNAROUND_SHIFT_DAYS   = 1.0;   // vs previous period
const REC_TURNAROUND_VS_PRACTICE  = 1.5;   // days vs practice average
const REC_ONTIME_SHIFT_PTS        = 10.0;  // percentage points vs prev period
const REC_ONTIME_VS_OTHERS_PTS    = 10.0;  // pts vs same case type at other labs
const REC_REMAKE_SHIFT_PTS        = 5.0;   // remake-rate pts vs prev period
const REC_CASETYPE_TURNAROUND_D   = 1.5;   // days vs other labs
const REC_CASETYPE_REMAKE_PTS     = 5.0;   // type remake rate vs lab overall
const REC_MIN_REMAKE_EVENTS       = 3;     // for concentration claims
const REC_CONCENTRATION_SHARE     = 0.5;   // 50%+ of remakes in one bucket
const REC_LAB_ATTR_RATE_PCT       = 5.0;   // lab-attributed rate worth noting
const REC_DUE_COVERAGE_MIN_PCT    = 50.0;  // suppress on-time recs below this

/**
 * Build one structured recommendation object.
 * params carries every value the i18n template needs (already formatted
 * to one-decimal precision where applicable).
 */
function labRec($type, $severity, $scope, $labKey, $labName, $caseType,
                $metric, $current, $comparison, $sampleSize, $magnitude, array $params) {
    return [
        'id'              => $type . ':' . $scope . ':' . ($labKey ?? '') . ':' . ($caseType ?? ''),
        'type'            => $type,
        'severity'        => $severity,        // attention | watch | improvement | info
        'scope'           => $scope,           // 'lab' | 'practice'
        'labKey'          => $labKey,
        'labName'         => $labName,
        'caseType'        => $caseType,
        'metric'          => $metric,
        'currentValue'    => $current,
        'comparisonValue' => $comparison,
        'sampleSize'      => $sampleSize,
        '_magnitude'      => $magnitude,       // sort hint; stripped on output
        'params'          => $params,
    ];
}

/**
 * Build sorted, de-duplicated recommendations from a performance payload.
 * Returns ['items' => [...], 'visible' => int]. Items are ordered by
 * severity then magnitude; 'priority' is the 1-based rank.
 */
function buildLabRecommendations(array $perf) {
    $min   = (int)($perf['meta']['minSampleSize'] ?? 5);
    $labs  = $perf['labs'] ?? [];
    $prac  = $perf['practice'] ?? [];
    $recs  = [];   // id => rec (dedup by construction)

    $put = function (array $r) use (&$recs) {
        // Keep the stronger magnitude if an id somehow repeats.
        if (!isset($recs[$r['id']]) || $r['_magnitude'] > $recs[$r['id']]['_magnitude']) {
            $recs[$r['id']] = $r;
        }
    };

    $lateLabs = [];

    foreach ($labs as $lab) {
        $labKey  = $lab['labKey'] ?? '';
        $labName = $lab['name'] ?? $labKey;
        $prev    = $lab['benchmarks']['previousPeriod'] ?? null;
        $vs      = $lab['benchmarks']['vsPractice'] ?? [];
        $ctBench = $lab['benchmarks']['caseTypes'] ?? [];
        $turn    = $lab['turnaround'] ?? [];
        $onTime  = $lab['onTime'] ?? [];
        $rm      = $lab['remakes'] ?? [];
        $wl      = $lab['workload'] ?? [];
        $vol     = $lab['volume'] ?? [];
        $p       = ['lab' => $labName];

        // ── 1. Turnaround vs previous period ─────────────────────────────
        // Requires: current n >= min AND previous window had >= min cases
        // (best available proxy for a meaningful prior sample).
        $pd = $prev['avgTurnaroundDays'] ?? null;
        if ($pd && $pd['delta'] !== null
            && ($turn['n'] ?? 0) >= $min
            && ($prev['volumeUniqueCases']['previous'] ?? 0) >= $min) {
            $d = $pd['delta'];
            if ($d >= REC_TURNAROUND_SHIFT_DAYS) {
                $put(labRec('turnaround_slower_period', 'attention', 'lab', $labKey, $labName, null,
                    'avgTurnaroundDays', $pd['current'], $pd['previous'], $turn['n'], $d,
                    $p + ['cur' => $pd['current'], 'prev' => $pd['previous'], 'delta' => $d]));
            } elseif ($d <= -REC_TURNAROUND_SHIFT_DAYS) {
                $put(labRec('turnaround_faster_period', 'improvement', 'lab', $labKey, $labName, null,
                    'avgTurnaroundDays', $pd['current'], $pd['previous'], $turn['n'], abs($d),
                    $p + ['cur' => $pd['current'], 'prev' => $pd['previous'], 'delta' => abs($d)]));
            }
        }

        // ── 2. Turnaround vs practice average ────────────────────────────
        $vd = $vs['avgTurnaroundDays'] ?? null;
        if ($vd && ($vd['sufficient'] ?? false) && $vd['delta'] !== null) {
            $d = $vd['delta'];
            if ($d >= REC_TURNAROUND_VS_PRACTICE) {
                $put(labRec('turnaround_slower_practice', 'watch', 'lab', $labKey, $labName, null,
                    'avgTurnaroundDays', $vd['lab'], $vd['practice'], $turn['n'], $d,
                    $p + ['cur' => $vd['lab'], 'prev' => $vd['practice'], 'delta' => $d]));
            } elseif ($d <= -REC_TURNAROUND_VS_PRACTICE) {
                $put(labRec('turnaround_faster_practice', 'improvement', 'lab', $labKey, $labName, null,
                    'avgTurnaroundDays', $vd['lab'], $vd['practice'], $turn['n'], abs($d),
                    $p + ['cur' => $vd['lab'], 'prev' => $vd['practice'], 'delta' => abs($d)]));
            }
        }

        // ── 3. On-time vs previous period — suppressed when due-date
        //        coverage is weak (the rate could not be representative) ──
        $od = $prev['onTimePct'] ?? null;
        $dueCov = $onTime['dueDateCoveragePct'] ?? null;
        if ($od && $od['delta'] !== null
            && ($onTime['n'] ?? 0) >= $min
            && ($prev['volumeUniqueCases']['previous'] ?? 0) >= $min
            && $dueCov !== null && $dueCov >= REC_DUE_COVERAGE_MIN_PCT) {
            $d = $od['delta'];
            if ($d <= -REC_ONTIME_SHIFT_PTS) {
                $put(labRec('ontime_dropped', 'attention', 'lab', $labKey, $labName, null,
                    'onTimePct', $od['current'], $od['previous'], $onTime['n'], abs($d),
                    $p + ['cur' => $od['current'], 'prev' => $od['previous'], 'delta' => abs($d)]));
            } elseif ($d >= REC_ONTIME_SHIFT_PTS) {
                $put(labRec('ontime_improved', 'improvement', 'lab', $labKey, $labName, null,
                    'onTimePct', $od['current'], $od['previous'], $onTime['n'], $d,
                    $p + ['cur' => $od['current'], 'prev' => $od['previous'], 'delta' => $d]));
            }
        }

        // ── 4. Remake rate vs previous period ────────────────────────────
        $rd = $prev['remakeRatePct'] ?? null;
        if ($rd && $rd['delta'] !== null && ($rm['rateSufficient'] ?? false)) {
            $d = $rd['delta'];
            if ($d >= REC_REMAKE_SHIFT_PTS) {
                $put(labRec('remake_rate_up', 'attention', 'lab', $labKey, $labName, null,
                    'remakeRatePct', $rd['current'], $rd['previous'], $rm['rateDenominator'] ?? null, $d,
                    $p + ['cur' => $rd['current'], 'prev' => $rd['previous'], 'delta' => $d]));
            } elseif ($d <= -REC_REMAKE_SHIFT_PTS) {
                $put(labRec('remake_rate_down', 'improvement', 'lab', $labKey, $labName, null,
                    'remakeRatePct', $rd['current'], $rd['previous'], $rm['rateDenominator'] ?? null, abs($d),
                    $p + ['cur' => $rd['current'], 'prev' => $rd['previous'], 'delta' => abs($d)]));
            }
        }

        // ── 5. Lab-attributed remakes (share first, then rate) ───────────
        $total   = (int)($rm['total'] ?? 0);
        $attrs   = $rm['attributions'] ?? [];
        $labRel  = (int)($attrs['lab_related'] ?? 0);
        if ($total >= REC_MIN_REMAKE_EVENTS && $labRel / $total >= REC_CONCENTRATION_SHARE) {
            $put(labRec('lab_attr_share', 'watch', 'lab', $labKey, $labName, null,
                'labAttributedRemakes', $labRel, $total, $rm['rateDenominator'] ?? null,
                $labRel / $total,
                $p + ['count' => $labRel, 'total' => $total]));
        } elseif (($rm['rateSufficient'] ?? false)
            && ($rm['labAttributedRatePct'] ?? null) !== null
            && $rm['labAttributedRatePct'] >= REC_LAB_ATTR_RATE_PCT) {
            $put(labRec('lab_attr_rate', 'watch', 'lab', $labKey, $labName, null,
                'labAttributedRatePct', $rm['labAttributedRatePct'], null, $rm['rateDenominator'] ?? null,
                $rm['labAttributedRatePct'],
                $p + ['pct' => $rm['labAttributedRatePct']]));
        }

        // ── 6. Remake-reason concentration ───────────────────────────────
        if ($total >= REC_MIN_REMAKE_EVENTS) {
            $reasons = $rm['reasons'] ?? [];
            if (!empty($reasons)) {
                arsort($reasons);
                $topCode  = array_key_first($reasons);
                $topCount = (int)$reasons[$topCode];
                if ($topCount >= REC_MIN_REMAKE_EVENTS
                    && $topCount / $total >= REC_CONCENTRATION_SHARE) {
                    $put(labRec('remake_reason_top', 'info', 'lab', $labKey, $labName, null,
                        'remakeReasons', $topCount, $total, $total, $topCount / $total,
                        $p + ['count' => $topCount, 'total' => $total,
                              'reasonCode' => $topCode]));
                }
            }
        }

        // ── 7. Remakes not attributed to the lab ─────────────────────────
        //     (defensible, useful: keeps users from auto-blaming the lab)
        if ($total >= REC_MIN_REMAKE_EVENTS) {
            $nonLab = $total - $labRel;
            if ($nonLab / $total > REC_CONCENTRATION_SHARE) {
                // Report the dominant non-lab category when one exists.
                $nonLabAttrs = array_diff_key($attrs, ['lab_related' => 1]);
                arsort($nonLabAttrs);
                $domCode  = !empty($nonLabAttrs) ? array_key_first($nonLabAttrs) : null;
                $domCount = $domCode !== null ? (int)$nonLabAttrs[$domCode] : 0;
                if ($domCode !== null && $domCount >= REC_MIN_REMAKE_EVENTS
                    && $domCount / $total >= REC_CONCENTRATION_SHARE) {
                    $put(labRec('remake_attr_category', 'info', 'lab', $labKey, $labName, null,
                        'remakeAttributions', $domCount, $total, $total, $domCount / $total,
                        $p + ['count' => $domCount, 'total' => $total,
                              'attrCode' => $domCode]));
                } else {
                    $put(labRec('remake_attr_not_lab', 'info', 'lab', $labKey, $labName, null,
                        'remakeAttributions', $nonLab, $total, $total, $nonLab / $total,
                        $p + ['count' => $nonLab, 'total' => $total]));
                }
            }
        }

        // ── 8a. Case-type turnaround vs other labs ───────────────────────
        foreach ($ctBench as $type => $b) {
            $tb = $b['avgTurnaroundDays'] ?? null;
            if ($tb && $tb['delta'] !== null) {
                $d = $tb['delta'];
                if ($d >= REC_CASETYPE_TURNAROUND_D) {
                    $put(labRec('case_type_slower', 'watch', 'lab', $labKey, $labName, $type,
                        'avgTurnaroundDays', $tb['lab'], $tb['otherLabs'], null, $d,
                        $p + ['type' => $type, 'delta' => $d, 'cur' => $tb['lab'], 'prev' => $tb['otherLabs']]));
                } elseif ($d <= -REC_CASETYPE_TURNAROUND_D) {
                    $put(labRec('case_type_faster', 'improvement', 'lab', $labKey, $labName, $type,
                        'avgTurnaroundDays', $tb['lab'], $tb['otherLabs'], null, abs($d),
                        $p + ['type' => $type, 'delta' => abs($d), 'cur' => $tb['lab'], 'prev' => $tb['otherLabs']]));
                }
            }
            // 8b. Case-type on-time vs other labs
            $ob = $b['onTimePct'] ?? null;
            if ($ob && $ob['delta'] !== null && $ob['delta'] <= -REC_ONTIME_VS_OTHERS_PTS) {
                $put(labRec('ontime_below_others', 'watch', 'lab', $labKey, $labName, $type,
                    'onTimePct', $ob['lab'], $ob['otherLabs'], null, abs($ob['delta']),
                    $p + ['type' => $type, 'cur' => $ob['lab'], 'prev' => $ob['otherLabs'], 'delta' => abs($ob['delta'])]));
            }
        }

        // ── 8c. Case-type remake rate vs lab's own overall rate ──────────
        $overallRate = $rm['remakeRatePct'] ?? null;
        if ($overallRate !== null && ($rm['rateSufficient'] ?? false)) {
            foreach (($lab['caseTypes'] ?? []) as $type => $td) {
                $tRate = $td['remakeRatePct'] ?? null;
                if ($tRate === null || !($td['sufficient'] ?? false)) { continue; }
                $d = $tRate - $overallRate;
                if ($d >= REC_CASETYPE_REMAKE_PTS) {
                    $put(labRec('case_type_remake_high', 'watch', 'lab', $labKey, $labName, $type,
                        'remakeRatePct', $tRate, $overallRate, $td['uniqueCases'], $d,
                        $p + ['type' => $type, 'pct' => $tRate, 'overall' => $overallRate, 'delta' => $d]));
                }
            }
        }

        // ── 9. Multiple-remake cases ─────────────────────────────────────
        $multi = (int)($rm['multiRemakeCases'] ?? 0);
        if ($multi >= 1) {
            $put(labRec('multi_remake', 'watch', 'lab', $labKey, $labName, null,
                'multiRemakeCases', $multi, null, $rm['casesWithRemakes'] ?? null, $multi,
                $p + ['count' => $multi]));
        }

        // ── 10. Current late workload ────────────────────────────────────
        $late = (int)($wl['late'] ?? 0);
        if ($late >= 1) { $lateLabs[$labKey] = ['name' => $labName, 'late' => $late]; }

        // ── 11. No remakes (positive/neutral) ────────────────────────────
        //     Per-lab variant suppressed later if the whole practice had none.
        if ($total === 0 && ($vol['uniqueCases'] ?? 0) >= $min) {
            $put(labRec('no_remakes_lab', 'improvement', 'lab', $labKey, $labName, null,
                'remakeRatePct', 0, null, $vol['uniqueCases'], 0.01,
                $p));
        }
    }

    // ── Practice-level rules ─────────────────────────────────────────────
    $pracVol  = $prac['volume'] ?? [];
    $pracRm   = $prac['remakes'] ?? [];
    $pracName = null;

    // Late workload: one practice rollup when 2+ labs are late; otherwise a
    // single per-lab note. Never both (dedup).
    if (count($lateLabs) >= 2) {
        $lateTotal = array_sum(array_column($lateLabs, 'late'));
        $put(labRec('late_workload_practice', 'attention', 'practice', null, $pracName, null,
            'workloadLate', $lateTotal, null, null, $lateTotal,
            ['count' => $lateTotal, 'labs' => count($lateLabs)]));
    } elseif (count($lateLabs) === 1) {
        $lk = array_key_first($lateLabs);
        $put(labRec('late_workload_lab', 'attention', 'lab', $lk, $lateLabs[$lk]['name'], null,
            'workloadLate', $lateLabs[$lk]['late'], null, null, $lateLabs[$lk]['late'],
            ['lab' => $lateLabs[$lk]['name'], 'count' => $lateLabs[$lk]['late']]));
    }

    // Dedup: "no remakes" is redundant when the same lab already has a
    // remake-rate-improvement item (the improvement message carries the 0%).
    foreach ($labs as $lab) {
        $lk = $lab['labKey'] ?? '';
        if (isset($recs['remake_rate_down:lab:' . $lk . ':'])
            && isset($recs['no_remakes_lab:lab:' . $lk . ':'])) {
            unset($recs['no_remakes_lab:lab:' . $lk . ':']);
        }
    }

    // Practice-wide "no remakes" replaces the per-lab variants.
    if ((int)($pracRm['total'] ?? 0) === 0 && ($pracVol['uniqueCases'] ?? 0) >= $min) {
        foreach (array_keys($recs) as $id) {
            if (strpos($id, 'no_remakes_lab:') === 0) { unset($recs[$id]); }
        }
        $put(labRec('no_remakes_practice', 'improvement', 'practice', null, $pracName, null,
            'remakeRatePct', 0, null, $pracVol['uniqueCases'], 0.02, []));
    }

    // ── Sort: severity rank, then magnitude desc, then lab name ──────────
    $rank = ['attention' => 0, 'watch' => 1, 'improvement' => 2, 'info' => 3];
    $items = array_values($recs);
    usort($items, function ($a, $b) use ($rank) {
        $ra = $rank[$a['severity']] ?? 9;
        $rb = $rank[$b['severity']] ?? 9;
        if ($ra !== $rb) { return $ra - $rb; }
        if ($a['_magnitude'] !== $b['_magnitude']) {
            return $b['_magnitude'] <=> $a['_magnitude'];
        }
        return strcmp((string)($a['labName'] ?? ''), (string)($b['labName'] ?? ''));
    });

    $out = [];
    foreach ($items as $i => $r) {
        unset($r['_magnitude']);
        $r['priority'] = $i + 1;
        $out[] = $r;
    }

    return ['items' => $out, 'visible' => 5, 'total' => count($out)];
}
