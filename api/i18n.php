<?php
/**
 * Internationalization (i18n) support
 *
 * Provides a single translation source for PHP and a serialized version for JavaScript.
 * en-US is the default and fallback locale. Missing keys are logged in development
 * and fall back to the en-US value or an empty string in production.
 */

global $appConfig;

$activeLocale = isset($appConfig['i18n']['locale']) ? $appConfig['i18n']['locale'] : 'en-US';
$fallbackLocale = isset($appConfig['i18n']['fallback_locale']) ? $appConfig['i18n']['fallback_locale'] : 'en-US';
$devMode = !empty($appConfig['environment']) && in_array($appConfig['environment'], ['development', 'uat'], true);
$translationsCache = [];

/**
 * Load translation data for a locale.
 */
function loadLocale($locale) {
    global $translationsCache;

    if (isset($translationsCache[$locale])) {
        return $translationsCache[$locale];
    }

    $path = dirname(__DIR__) . '/locales/' . $locale . '.json';
    if (!file_exists($path)) {
        $translationsCache[$locale] = [];
        return [];
    }

    $json = file_get_contents($path);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        $translationsCache[$locale] = [];
        return [];
    }

    $translationsCache[$locale] = $data;
    return $data;
}

/**
 * Retrieve a nested translation value by dot-notation key.
 */
function getNestedTranslation($data, $key) {
    if (empty($key)) {
        return null;
    }

    $parts = explode('.', $key);
    foreach ($parts as $part) {
        if (!is_array($data) || !array_key_exists($part, $data)) {
            return null;
        }
        $data = $data[$part];
    }

    return is_string($data) ? $data : null;
}

/**
 * Replace placeholders in a translation string.
 * Placeholders use {name} syntax and are substituted with values from $params.
 */
function interpolateTranslation($value, $params) {
    if (empty($params)) {
        return $value;
    }

    return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($params) {
        $name = $matches[1];
        return isset($params[$name]) ? (string) $params[$name] : $matches[0];
    }, $value);
}

/**
 * Translate a key into the active locale, with fallback to en-US.
 *
 * @param string $key     Dot-notation translation key, e.g. 'cases.assigned_to'.
 * @param array  $params  Optional placeholder values, e.g. ['count' => 5].
 * @return string
 */
function t($key, $params = []) {
    global $activeLocale, $fallbackLocale, $devMode;

    $locales = [$activeLocale, $fallbackLocale];
    $fallbackValue = null;

    foreach ($locales as $locale) {
        $value = getNestedTranslation(loadLocale($locale), $key);
        if ($value !== null) {
            if ($fallbackValue === null) {
                $fallbackValue = $value;
            }
            if ($locale === $activeLocale) {
                return interpolateTranslation($value, $params);
            }
        }
    }

    if ($fallbackValue !== null) {
        return interpolateTranslation($fallbackValue, $params);
    }

    if ($devMode) {
        error_log('[i18n] Missing translation key: ' . $key);
    }

    return '';
}

/**
 * Get the currently active locale.
 */
function getActiveLocale() {
    global $activeLocale;
    return $activeLocale;
}

/**
 * Get the configured fallback locale.
 */
function getFallbackLocale() {
    global $fallbackLocale;
    return $fallbackLocale;
}

/**
 * Build a JSON object of the active-locale translations for the browser.
 */
function getTranslationsJsonForJs() {
    global $activeLocale, $fallbackLocale;

    return json_encode([
        'resolvedLocale' => getResolvedLocale(),
        'fallbackLocale' => $fallbackLocale,
        'supportedLocales' => getSupportedLocales(),
        'devMode' => false,
        'translations' => [
            $activeLocale => loadLocale($activeLocale),
            $fallbackLocale => loadLocale($fallbackLocale),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Format a date for the active locale.
 *
 * @param string|int $date   Date string (Y-m-d) or Unix timestamp.
 * @param string     $style  One of 'short', 'medium', 'long', 'full'.
 * @return string
 */
function formatDate($date, $style = 'short') {
    global $activeLocale;

    $timestamp = is_numeric($date) ? (int) $date : strtotime($date);
    if ($timestamp === false) {
        return (string) $date;
    }

    if (class_exists('IntlDateFormatter')) {
        $formatMap = [
            'short' => IntlDateFormatter::SHORT,
            'medium' => IntlDateFormatter::MEDIUM,
            'long' => IntlDateFormatter::LONG,
            'full' => IntlDateFormatter::FULL,
        ];
        $fmt = new IntlDateFormatter($activeLocale, $formatMap[$style] ?? IntlDateFormatter::SHORT, IntlDateFormatter::NONE);
        return $fmt->format($timestamp);
    }

    // Fallback for environments without the intl extension
    return date('n/j/Y', $timestamp);
}

/**
 * Format a date and time for the active locale.
 *
 * @param string|int $date   Date string or Unix timestamp.
 * @param string     $style  One of 'short', 'medium', 'long', 'full'.
 * @return string
 */
function formatDateTime($date, $style = 'short') {
    global $activeLocale;

    $timestamp = is_numeric($date) ? (int) $date : strtotime($date);
    if ($timestamp === false) {
        return (string) $date;
    }

    // Intl constants must not be referenced until we know the class is available.
    if (class_exists('IntlDateFormatter')) {
        $formatMap = [
            'short'  => [IntlDateFormatter::SHORT, IntlDateFormatter::SHORT],
            'medium' => [IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT],
            'long'   => [IntlDateFormatter::LONG, IntlDateFormatter::SHORT],
            'full'   => [IntlDateFormatter::FULL, IntlDateFormatter::SHORT],
        ];
        $mapped = $formatMap[$style] ?? [IntlDateFormatter::SHORT, IntlDateFormatter::SHORT];
        $dateStyle = $mapped[0];
        $timeStyle = $mapped[1];
        $fmt = new IntlDateFormatter($activeLocale, $dateStyle, $timeStyle);
        return $fmt->format($timestamp);
    }

    // PHP native fallback (current locale is en-US so US-style formatting is fine)
    return date('M j, Y, g:i A', $timestamp);
}

/**
 * Format a number for the active locale.
 *
 * @param float|int $number
 * @param int       $decimals
 * @return string
 */
function formatNumber($number, $decimals = null) {
    global $activeLocale;

    if ($decimals !== null) {
        if (class_exists('NumberFormatter')) {
            $fmt = new NumberFormatter($activeLocale, NumberFormatter::DECIMAL);
            $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            return $fmt->format((float) $number);
        }
        return number_format((float) $number, $decimals);
    }

    if (class_exists('NumberFormatter')) {
        $fmt = new NumberFormatter($activeLocale, NumberFormatter::DECIMAL);
        return $fmt->format((float) $number);
    }

    return number_format((float) $number);
}

/**
 * Format an amount as currency for the active locale.
 *
 * @param float|int $amount
 * @param string    $currency  ISO 4217 currency code, e.g. 'USD'.
 * @return string
 */
function formatCurrency($amount, $currency = 'USD') {
    global $activeLocale;

    if (class_exists('NumberFormatter')) {
        $fmt = new NumberFormatter($activeLocale, NumberFormatter::CURRENCY);
        return $fmt->formatCurrency((float) $amount, $currency);
    }

    return '$' . number_format((float) $amount, 2);
}

/**
 * Return a human-readable relative time string for a date compared to now.
 *
 * @param string|int $date
 * @return string
 */
function formatRelative($date) {
    $timestamp = is_numeric($date) ? (int) $date : strtotime($date);
    if ($timestamp === false) {
        return (string) $date;
    }

    $diff = time() - $timestamp;
    $abs = abs($diff);
    $future = $diff < 0;

    if ($abs < 60) {
        return $future ? t('common.relative.in_a_moment') : t('common.relative.a_moment_ago');
    }
    if ($abs < 3600) {
        $minutes = (int) round($abs / 60);
        return $future ? t('common.relative.in_minutes', ['count' => $minutes]) : t('common.relative.minutes_ago', ['count' => $minutes]);
    }
    if ($abs < 86400) {
        $hours = (int) round($abs / 3600);
        return $future ? t('common.relative.in_hours', ['count' => $hours]) : t('common.relative.hours_ago', ['count' => $hours]);
    }
    if ($abs < 604800) {
        $days = (int) round($abs / 86400);
        return $future ? t('common.relative.in_days', ['count' => $days]) : t('common.relative.days_ago', ['count' => $days]);
    }

    return formatDate($date, 'short');
}

/**
 * Choose a singular or plural translation based on a count.
 *
 * @param int    $count
 * @param string $key     Base key that has '_one' and '_other' variants.
 * @param array  $params
 * @return string
 */
function pluralize($count, $key, $params = []) {
    $suffix = $count === 1 ? '_one' : '_other';
    $fullKey = $key . $suffix;
    $params['count'] = $count;
    return t($fullKey, $params);
}

/**
 * Map a DentaTrak locale to a Stripe-supported Checkout/Portal locale.
 * Returns null when the locale is not supported by Stripe.
 *
 * @param string $locale
 * @return string|null
 */
function getStripeLocale($locale) {
    // Stripe locale values come from the Checkout Session and BillingPortal
    // Session IETF language tag enums. Only return a value we know Stripe
    // explicitly supports; otherwise Stripe falls back to browser/default.
    $map = [
        'en-US' => 'en',
        'en'    => 'en',
        'es'    => 'es',
        'es-ES' => 'es',
        'fr'    => 'fr',
        'fr-FR' => 'fr',
        'de'    => 'de',
        'de-DE' => 'de',
        'pt'    => 'pt',
        'pt-BR' => 'pt-BR',
        'it'    => 'it',
        'it-IT' => 'it',
    ];
    return $map[$locale] ?? null;
}

/**
 * Get a human-readable display name for the active locale.
 *
 * @return string
 */
function getActiveLanguageName() {
    global $activeLocale;

    if (class_exists('Locale')) {
        $name = Locale::getDisplayName($activeLocale, 'en');
        if ($name) {
            return $name;
        }
    }

    // Minimal fallback map for supported/supported-later locales.
    $names = [
        'en-US' => 'English (United States)',
        'en' => 'English',
        'es' => 'Spanish',
        'es-ES' => 'Spanish (Spain)',
        'fr' => 'French',
        'fr-FR' => 'French (France)',
        'de' => 'German',
        'de-DE' => 'German (Germany)',
        'pt' => 'Portuguese',
        'pt-BR' => 'Portuguese (Brazil)',
        'it' => 'Italian',
        'it-IT' => 'Italian (Italy)',
    ];

    return $names[$activeLocale] ?? $activeLocale;
}

/**
 * Case type normalization and display helpers.
 *
 * Stored/legacy values are English strings. These functions map them to
 * stable slugs and return translated labels without changing the stored data.
 */
function getCaseTypeMap() {
    return [
        'Crown'                 => 'crown',
        'Bridge'                => 'bridge',
        'Implant'               => 'implant',
        'Implant Crown'         => 'implant_crown',
        'Implant Surgical Guide'=> 'implant_surgical_guide',
        'AOX'                   => 'aox',
        'Bite Rim'              => 'bite_rim',
        'Denture'               => 'denture',
        'Partial'               => 'partial',
        'Veneer'                => 'veneer',
        'Inlay/Onlay'           => 'inlay_onlay',
        'Orthodontic Appliance' => 'orthodontic_appliance',
        'Mixed'                 => 'mixed',
        'Mixed Case Type'       => 'mixed',
    ];
}

/**
 * Normalize a stored case type value to a stable slug.
 */
function normalizeCaseType($stored) {
    $map = getCaseTypeMap();
    $stored = trim((string) $stored);
    if (isset($map[$stored])) {
        return $map[$stored];
    }
    // Fallback: lowercase, replace non-alphanumeric with underscore
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($stored));
    $slug = trim($slug, '_');
    return $slug ?: 'unknown';
}

/**
 * Get the translated display label for a stored case type.
 */
function getCaseTypeDisplayLabel($stored) {
    $slug = normalizeCaseType($stored);
    $label = t('case_types.' . $slug);
    if ($label !== '') {
        return $label;
    }
    return $stored;
}

/**
 * Build a JSON map of stored value -> slug for client-side use.
 */
function getCaseTypeMapForJs() {
    return getCaseTypeMap();
}

/**
 * Resolve the locale to use for outbound email.
 *
 * Precedence:
 *   1. Explicit locale if provided and supported.
 *   2. Recipient's saved user locale (users.locale).
 *   3. Practice default locale (practices.default_locale).
 *   4. Currently resolved / session locale.
 *   5. en-US.
 *
 * @param int|null    $recipientUserId
 * @param int|null    $practiceId
 * @param string|null $explicitLocale
 * @return string
 */
function resolveEmailLocale($recipientUserId = null, $practiceId = null, $explicitLocale = null) {
    return resolveLocale($explicitLocale, $recipientUserId, $practiceId, true);
}

/**
 * Translate a key for a specific locale without mutating global i18n state.
 *
 * Falls back to en-US if the requested locale is missing the key. Missing keys
 * are logged in development. For security-critical emails, this function never
 * returns an empty string; if even the en-US string is missing, the key is
 * looked up in the English emergency map, and finally the raw key is returned.
 *
 * @param string $locale
 * @param string $key
 * @param array  $params
 * @return string
 */
function tForLocale($locale, $key, $params = []) {
    global $devMode;

    $locale = validateLocale($locale);

    $value = getNestedTranslation(loadLocale($locale), $key);

    if ($value === null && $locale !== 'en-US') {
        $value = getNestedTranslation(loadLocale('en-US'), $key);
    }

    if ($value !== null) {
        return interpolateTranslation($value, $params);
    }

    if ($devMode) {
        error_log('[i18n] Missing translation key for locale ' . $locale . ': ' . $key);
    }

    // Security-critical email fallback: never return empty for email keys
    if (strpos($key, 'email.') === 0) {
        $emergency = getEmailEmergencyTranslation($key);
        if ($emergency !== null) {
            return interpolateTranslation($emergency, $params);
        }
    }

    return $key;
}

/**
 * Return the list of supported locales from application configuration.
 *
 * @return array
 */
function getSupportedLocales() {
    global $appConfig;
    return $appConfig['i18n']['supported_locales'] ?? [
        'en-US' => [
            'name' => 'English (United States)',
            'nativeName' => 'English (United States)',
            'enabled' => true,
        ],
    ];
}

/**
 * Determine whether a locale is supported and enabled.
 *
 * @param string $locale
 * @return bool
 */
function isSupportedLocale($locale) {
    if (empty($locale) || !is_string($locale)) {
        return false;
    }
    $supported = getSupportedLocales();
    $locale = trim($locale);
    return isset($supported[$locale]) && !empty($supported[$locale]['enabled']);
}

/**
 * Determine whether two or more locales are enabled and therefore language
 * selection controls should be shown.
 *
 * @return bool
 */
function hasMultipleEnabledLocales() {
    $supported = getSupportedLocales();
    $enabled = 0;
    foreach ($supported as $code => $meta) {
        if (!empty($meta['enabled'])) {
            $enabled++;
            if ($enabled >= 2) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Normalize and validate a locale, returning the fallback for unsupported values.
 *
 * @param string|null $locale
 * @param string      $fallback
 * @return string
 */
function validateLocale($locale, $fallback = 'en-US') {
    if (empty($locale) || !is_string($locale)) {
        return $fallback;
    }
    $locale = trim($locale);
    // Explicitly reject the practice-default sentinel; it is never a real locale.
    if ($locale === 'use_practice_default') {
        return $fallback;
    }
    return isSupportedLocale($locale) ? $locale : $fallback;
}

/**
 * Resolve a locale using persisted and session sources.
 *
 * Precedence:
 *   1. validated explicit locale
 *   2. user.locale
 *   3. practices.default_locale for the practice
 *   4. session `resolved_locale`
 *   5. en-US
 *
 * @param string|null $explicit
 * @param int|null    $userId
 * @param int|null    $practiceId
 * @param bool        $allowSession
 * @return string
 */
function resolveLocale($explicit = null, $userId = null, $practiceId = null, $allowSession = true) {
    global $pdo;

    // The practice-default sentinel is not a real locale.
    if ($explicit === 'use_practice_default') {
        $explicit = null;
    }

    if (!empty($explicit) && isSupportedLocale($explicit)) {
        return validateLocale($explicit);
    }

    if ($pdo && !empty($userId)) {
        try {
            $stmt = $pdo->prepare("SELECT locale FROM users WHERE id = :id");
            $stmt->execute(['id' => (int)$userId]);
            $userLocale = $stmt->fetchColumn();
            if (!empty($userLocale) && isSupportedLocale($userLocale)) {
                return validateLocale($userLocale);
            }
        } catch (PDOException $e) {
            error_log('[i18n] Error resolving user locale: ' . $e->getMessage());
        }
    }

    if ($pdo && !empty($practiceId)) {
        try {
            $stmt = $pdo->prepare("SELECT default_locale FROM practices WHERE id = :id");
            $stmt->execute(['id' => (int)$practiceId]);
            $practiceLocale = $stmt->fetchColumn();
            if (!empty($practiceLocale) && isSupportedLocale($practiceLocale)) {
                return validateLocale($practiceLocale);
            }
        } catch (PDOException $e) {
            error_log('[i18n] Error resolving practice locale: ' . $e->getMessage());
        }
    }

    if ($allowSession && !empty($_SESSION['resolved_locale']) && isSupportedLocale($_SESSION['resolved_locale']) && $_SESSION['resolved_locale'] !== 'use_practice_default') {
        return validateLocale($_SESSION['resolved_locale']);
    }

    return 'en-US';
}

/**
 * Get the resolved active locale from session or runtime state.
 *
 * @return string
 */
function getResolvedLocale() {
    global $activeLocale;

    if (!empty($_SESSION['resolved_locale'])) {
        return validateLocale($_SESSION['resolved_locale']);
    }
    if (!empty($activeLocale)) {
        return validateLocale($activeLocale);
    }
    return 'en-US';
}

/**
 * Get the HTML lang attribute value for the resolved locale.
 *
 * @return string
 */
function getHtmlLang() {
    return str_replace('_', '-', getResolvedLocale());
}

/**
 * Persist the resolved active locale in session and runtime state.
 *
 * @param string $locale
 * @return void
 */
function setResolvedLocale($locale) {
    global $activeLocale;
    $resolved = validateLocale($locale);
    $activeLocale = $resolved;
    $_SESSION['resolved_locale'] = $resolved;
}

/**
 * Get the configured display name(s) for a locale.
 *
 * @param string $locale
 * @return array ['name' => ..., 'nativeName' => ...]
 */
function getLocaleDisplayName($locale) {
    $supported = getSupportedLocales();
    $locale = trim((string)$locale);
    if (isset($supported[$locale])) {
        return [
            'name' => $supported[$locale]['name'] ?? $locale,
            'nativeName' => $supported[$locale]['nativeName'] ?? $locale,
        ];
    }
    return ['name' => $locale, 'nativeName' => $locale];
}

/**
 * Render a global language selector control.
 *
 * Returns an empty string when fewer than two locales are enabled so the
 * control automatically appears when a second locale is configured.
 *
 * @param string      $saveUrl               API endpoint to POST {language: value} to.
 * @param string      $currentLocale         The currently resolved locale.
 * @param bool        $showUsePracticeDefault Whether to offer "Use practice default".
 * @param string|null $csrfToken             CSRF token for authenticated saves.
 * @return string
 */
function renderLanguageSelector($saveUrl, $currentLocale, $showUsePracticeDefault = false, $csrfToken = null) {
    if (!hasMultipleEnabledLocales()) {
        return '';
    }

    $supported = getSupportedLocales();
    $currentDisplay = getLocaleDisplayName($currentLocale)['nativeName'];
    $selectedId = 'languageSelector_' . substr(md5($saveUrl), 0, 8);
    $csrfAttr = $csrfToken ? ' data-csrf="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '"' : '';

    $items = [];
    if ($showUsePracticeDefault) {
        $items[] = '<button type="button" class="language-selector-item" data-locale="" data-use-practice-default="1" aria-pressed="false">' . htmlspecialchars(t('language_selector.use_practice_default')) . '</button>';
    }
    foreach ($supported as $code => $meta) {
        if (empty($meta['enabled'])) {
            continue;
        }
        $label = htmlspecialchars($meta['nativeName'] ?? $meta['name'] ?? $code);
        $selectedAttr = ($code === $currentLocale) ? ' aria-current="true"' : '';
        $items[] = '<button type="button" class="language-selector-item" data-locale="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"' . $selectedAttr . ' aria-pressed="' . ($code === $currentLocale ? 'true' : 'false') . '">' . $label . '</button>';
    }

    $html = '<div class="language-selector" id="' . $selectedId . '" data-save-url="' . htmlspecialchars($saveUrl, ENT_QUOTES, 'UTF-8') . '"' . $csrfAttr . '>';
    $html .= '<button type="button" class="language-selector-toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . htmlspecialchars(t('language_selector.change_language')) . '">';
    $html .= '<span class="language-selector-current">' . htmlspecialchars($currentDisplay) . '</span>';
    $html .= '<svg class="language-selector-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>';
    $html .= '</button>';
    $html .= '<div class="language-selector-menu" role="menu" aria-label="' . htmlspecialchars(t('language_selector.change_language')) . '">';
    $html .= implode("\n", $items);
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<script>';
    $html .= '(function () {';
    $html .= '  var root = document.getElementById(' . json_encode($selectedId) . ');';
    $html .= '  if (!root) return;';
    $html .= '  var toggle = root.querySelector(".language-selector-toggle");';
    $html .= '  var menu = root.querySelector(".language-selector-menu");';
    $html .= '  var items = root.querySelectorAll(".language-selector-item");';
    $html .= '  if (!toggle || !menu) return;';
    $html .= '  toggle.addEventListener("click", function (e) {';
    $html .= '    e.stopPropagation();';
    $html .= '    var isOpen = menu.classList.toggle("open");';
    $html .= '    toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");';
    $html .= '  });';
    $html .= '  document.addEventListener("click", function () { menu.classList.remove("open"); toggle.setAttribute("aria-expanded", "false"); });';
    $html .= '  menu.addEventListener("click", function (e) { e.stopPropagation(); });';
    $html .= '  items.forEach(function (item) {';
    $html .= '    item.addEventListener("click", function () {';
    $html .= '      var value = item.getAttribute("data-locale");';
    $html .= '      if (item.getAttribute("data-use-practice-default") === "1") { value = "use_practice_default"; }';
    $html .= '      var body = JSON.stringify({ language: value });';
    $html .= '      var headers = { "Content-Type": "application/json" };';
    $html .= '      var csrf = root.getAttribute("data-csrf");';
    $html .= '      if (csrf) headers["X-CSRF-Token"] = csrf;';
    $html .= '      fetch(root.getAttribute("data-save-url"), { method: "POST", headers: headers, body: body })';
    $html .= '        .then(function (r) { return r.json(); })';
    $html .= '        .then(function (data) {';
    $html .= '          if (data && data.success) { window.location.reload(); }';
    $html .= '          else { if (typeof showToast === "function") { showToast((data && data.message) ? data.message : ' . json_encode(t('language_selector.error')) . ', "error"); } }';
    $html .= '        })';
    $html .= '        .catch(function () { if (typeof showToast === "function") { showToast(' . json_encode(t('language_selector.error')) . ', "error"); } });';
    $html .= '    });';
    $html .= '  });';
    $html .= '})();';
    $html .= '</script>';

    return $html;
}

/**
 * Hard-coded English emergency fallback map for security-critical email keys.
 *
 * Used by tForLocale() only when the active and en-US translations are both
 * missing, to guarantee that an email can still be rendered even if the locale
 * files are damaged or absent.
 *
 * @param string $key
 * @return string|null
 */
function getEmailEmergencyTranslation($key) {
    $map = [
        'email.common.greeting_with_name' => 'Hi {name},',
        'email.common.greeting_no_name' => 'Hi there,',
        'email.common.greeting_generic' => 'Hello,',
        'email.common.footer' => 'This is an automated message from {appName}. Please do not reply to this email.',
        'email.common.support_link' => 'If you have any questions, reach out to us at {supportEmail}.',
        'email.common.view_user_guide' => 'View the User Guide',
        'email.common.open_dentatrak' => 'Open {appName}',
        'email.common.copy_link' => 'Or copy and paste this link into your browser:',
        'email.common.thanks' => 'Thanks,',
        'email.common.team_signature' => 'The {appName} Team',
        'email.common.ignore_unsolicited' => "If you didn't request this, you can safely ignore this email.",
        'email.common.ignore_signup' => "If you didn't create an account, you can safely ignore this email.",
        'email.verification.subject' => 'Verify your email - {appName}',
        'email.verification.heading' => 'Verify your email address',
        'email.verification.intro' => 'Thanks for signing up for {appName}! Please verify your email address to complete your registration.',
        'email.verification.code' => 'Your verification code is {code}.',
        'email.verification.expiry' => 'This link will expire in {count} minutes.',
        'email.verification.cta' => 'Verify Email',
        'email.password_setup.subject' => 'Set up your password for {appName}',
        'email.password_setup.heading' => '{appName}',
        'email.password_setup.intro' => 'You requested to set up a password for your {appName} account.',
        'email.password_setup.cta' => 'Set Password',
        'email.password_setup.expiry' => 'This link expires in {count} minutes.',
        'email.password_reset.subject' => 'Password Reset Request - {appName}',
        'email.password_reset.heading' => 'Password Reset Request',
        'email.password_reset.intro' => 'We received a request to reset your password for your {appName} account.',
        'email.password_reset.cta' => 'Reset Password',
        'email.password_reset.expiry' => 'This link will expire in {count} minutes.',
        'email.welcome.subject' => 'Welcome to {appName}',
        'email.welcome.heading' => 'Welcome to {appName}',
        'email.welcome.intro' => 'Welcome to {appName}! Your practice {practiceName} has been set up and is ready to use.',
        'email.welcome.cta' => 'DentaTrak User Guide',
        'email.welcome.footer' => 'If you have any questions, reach out to us at {supportEmail}.',
        'email.practice_invite.subject' => "You've been added to {practiceName} in {appName}",
        'email.practice_invite.heading' => "You've been added to a practice",
        'email.practice_invite.existing_user_intro' => 'If you already have a {appName} account, sign in using the email address that received this message. The {practiceName} dental practice will be available when you sign in.',
        'email.practice_invite.new_user_intro' => "If you don't have a {appName} account yet, create an account using this same email address. Once registration is complete, you'll be able to access the {practiceName} dental practice.",
        'email.practice_invite.open_dentatrak' => 'Open {appName}',
        'email.practice_invite.help' => 'If you have questions or need help, contact us at {supportEmail}.',
        'email.practice_invite.cta' => 'Open {appName}',
        'email.security.subject' => 'Security Notification - {appName}',
        'email.security.heading' => 'Security Notification',
        'email.security.body' => 'A security-related event occurred on your {appName} account. If you did not initiate this change, please contact support immediately.',
        'email.admin_notification.subject' => 'New {appName} User Signup',
        'email.admin_notification.body_html' => '<p>A new user has registered for {appName}.</p><p><strong>Name:</strong> {fullName}<br><strong>Email:</strong> {email}<br><strong>Registration Date/Time:</strong> {timestamp}<br><strong>Practice:</strong> {practiceName}</p>',
        'email.admin_notification.body_text' => "A new user has registered for {appName}.\n\nName: {fullName}\nEmail: {email}\nRegistration Date/Time: {timestamp}\nPractice: {practiceName}",
        'email.signup.subject' => 'New {appName} User Signup',
        'email.signup.body' => 'A new user has signed up for {appName}.',
    ];

    return $map[$key] ?? null;
}
