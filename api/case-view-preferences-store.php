<?php

function normalizeCaseViewPreferences($value, bool $strict = false): array {
    $defaults = [
        'patientSearch' => '', 'filterCaseType' => '', 'filterAssignedTo' => '',
        'filterReviewStatus' => '', 'filterCarrier' => '',
        'filterLateCases' => false, 'filterDueSoon' => false,
        'filterApptRisk' => false, 'filterAtRisk' => false,
    ];
    if (!is_array($value)) {
        if ($strict) throw new InvalidArgumentException('Invalid preferences');
        $value = [];
    }
    $filters = $value['filters'] ?? [];
    $sort = $value['sort'] ?? [];
    if (!is_array($filters) || !is_array($sort) || ($strict && count($sort) > 3)) {
        if ($strict) throw new InvalidArgumentException('Invalid filters or sort');
        $filters = is_array($filters) ? $filters : [];
        $sort = is_array($sort) ? $sort : [];
    }
    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $filters)) continue;
        $v = $filters[$key];
        if (is_bool($default) ? !is_bool($v) : (!is_string($v) || strlen($v) > 500)) {
            if ($strict) throw new InvalidArgumentException('Invalid filter value');
            continue;
        }
        $defaults[$key] = is_string($v) ? trim($v) : $v;
    }
    $allowedValues = [
        'filterCaseType' => ['', 'Crown', 'Bridge', 'Implant', 'AOX', 'Bite Rim', 'Denture', 'Partial', 'Veneer', 'Inlay/Onlay', 'Orthodontic Appliance'],
        'filterReviewStatus' => ['', 'reviewed', 'needs_review'],
        'filterCarrier' => ['', 'UPS', 'FedEx', 'USPS', 'DHL', 'Other'],
    ];
    foreach ($allowedValues as $key => $allowed) {
        if (!in_array($defaults[$key], $allowed, true)) {
            if ($strict) throw new InvalidArgumentException('Unknown filter value');
            $defaults[$key] = '';
        }
    }
    if ($defaults['filterLateCases']) $defaults['filterDueSoon'] = false;
    $criteria = [];
    $seen = [];
    foreach ($sort as $criterion) {
        $field = is_array($criterion) ? ($criterion['field'] ?? null) : null;
        $direction = is_array($criterion) ? ($criterion['direction'] ?? null) : null;
        if (!is_string($field) || !in_array($field, ['patient', 'type', 'status', 'assigned', 'due', 'appointment', 'dentist', 'updated', 'review'], true)
            || !in_array($direction, ['asc', 'desc'], true) || isset($seen[$field]) || count($criteria) >= 3) {
            if ($strict) throw new InvalidArgumentException('Invalid or duplicate sort criterion');
            continue;
        }
        $seen[$field] = true;
        $criteria[] = ['field' => $field, 'direction' => $direction];
    }
    return ['filters' => $defaults, 'sort' => $criteria];
}

function loadCaseViewPreferences(PDO $pdo, int $userId, int $practiceId): array {
    $stmt = $pdo->prepare('SELECT case_view_preferences FROM practice_users WHERE user_id = ? AND practice_id = ?');
    $stmt->execute([$userId, $practiceId]);
    return normalizeCaseViewPreferences(json_decode($stmt->fetchColumn() ?: '{}', true));
}
