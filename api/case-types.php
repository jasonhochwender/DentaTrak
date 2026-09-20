<?php
/**
 * Canonical DentaTrak case-type definitions.
 *
 * SINGLE SOURCE OF TRUTH for which case_type values exist. Every consumer
 * (Create Case form, filters, server-side validation, analytics, demo
 * data, tests) should derive its list from here instead of maintaining a
 * private copy - the previous hardcoded lists disagreed (filters offered
 * 'Implant' while the create form only ever stored 'Implant Crown' /
 * 'Implant Surgical Guide', which exact-match filters could never find).
 *
 * THREE TIERS OF VALUES:
 *  - CANONICAL: creatable today via the Create Case form / trusted import.
 *  - LEGACY:    no longer creatable, but may exist in stored data. They
 *               stay valid, displayable, and filterable so existing cases
 *               never break (labels resolve through i18n's case_type map).
 *  - UNKNOWN:   anything else in storage. Display/filter code falls back
 *               to the raw value (see getCaseTypeDisplayLabel in i18n.php).
 *
 * Do NOT rename existing stored case_type values; canonicalization here
 * changes only what the UI offers and what validation accepts.
 */

require_once __DIR__ . '/i18n.php';

/** Imported PMS cases land here until a user classifies them. */
const CASE_TYPE_NEEDS_CLASSIFICATION = 'Needs Classification';

/**
 * Ordered canonical (creatable) case types.
 * Order is the user-facing dropdown order.
 */
function getCanonicalCaseTypes(): array {
    return [
        'Crown',
        'Bridge',
        'Implant Crown',
        'Implant Surgical Guide',
        'AOX',
        'Bite Rim',
        'Denture',
        'Partial',
        'Veneer',
        'Inlay/Onlay',
        'Orthodontic Appliance',
        CASE_TYPE_NEEDS_CLASSIFICATION,
    ];
}

/**
 * Stored values that predate the canonical list and remain valid/displayable.
 * 'Mixed' is the demo-data generator's category, not a create-form type.
 */
function getLegacyCaseTypes(): array {
    return [
        'Implant',
        'Mixed',
        'Mixed Case Type',
    ];
}

/** All recognized stored values: canonical first, then legacy. */
function getAllKnownCaseTypes(): array {
    return array_merge(getCanonicalCaseTypes(), getLegacyCaseTypes());
}

/** True when $type is a recognized (canonical or legacy) case type. */
function isValidCaseType(string $type): bool {
    return in_array($type, getAllKnownCaseTypes(), true);
}

/** True when $type is creatable through the current create/import paths. */
function isCanonicalCaseType(string $type): bool {
    return in_array($type, getCanonicalCaseTypes(), true);
}

/**
 * Case types offered by case_type FILTERS. Includes canonical values plus
 * legacy values that may still exist in stored data, so a stored 'Implant'
 * row remains reachable by an exact-match filter.
 */
function getFilterableCaseTypes(): array {
    return getAllKnownCaseTypes();
}

/**
 * Case-type-specific required clinical fields. Shared by create-case.php
 * and update-case.php so create/edit validation can never diverge.
 * Needs Classification intentionally has NO entry: it must not gate on
 * type-specific clinical data.
 */
function getCaseTypeClinicalFields(): array {
    return [
        'Crown' => ['toothNumber'],
        'Bridge' => ['abutmentTeeth', 'ponticTeeth'],
        'Implant Crown' => ['implantToothNumber', 'abutmentType', 'implantSystem', 'platformSize', 'scanBodyUsed'],
        'Implant Surgical Guide' => ['implantSites'],
        'Denture' => ['dentureJaw', 'dentureType', 'gingivalShade'],
        'Partial' => ['partialJaw', 'teethToReplace', 'partialMaterial', 'partialGingivalShade'],
    ];
}

/**
 * Case types for which the Material field is required/visible in the
 * create form. Includes the legacy 'Implant' so any old stored value still
 * behaves sensibly if it ever round-trips through the form.
 */
function getCaseTypesRequiringMaterial(): array {
    return ['Crown', 'Bridge', 'Implant', 'Implant Crown', 'Implant Surgical Guide', 'AOX', 'Veneer', 'Inlay/Onlay'];
}

/**
 * Render <option> elements for a case-type <select>, ordered per the
 * canonical list. Labels resolve through t('case_types.<slug>') via the
 * i18n map; unknown slugs fall back to the raw value so nothing renders
 * blank.
 *
 * @param array|null $types   Defaults to getCanonicalCaseTypes().
 * @param string     $selected Currently-selected stored value.
 */
function renderCaseTypeOptions(?array $types = null, string $selected = ''): string {
    $types = $types ?? getCanonicalCaseTypes();
    $html = '';
    foreach ($types as $type) {
        $slug = normalizeCaseType($type);
        $label = t('case_types.' . $slug);
        if ($label === '') {
            $label = $type;
        }
        $sel = ($type === $selected) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($type, ENT_QUOTES) . '"' . $sel . '>'
               . htmlspecialchars($label) . "</option>\n";
    }
    return $html;
}
