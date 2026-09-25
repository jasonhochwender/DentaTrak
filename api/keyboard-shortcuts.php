<?php
/**
 * Central registry of intentional keyboard shortcuts in DentaTrak.
 *
 * Single source of truth for:
 * - the Ask DentaTrak grounded help corpus (so it never guesses shortcuts)
 * - the browser UI (window.dtKeyboardShortcuts in main.php powers menu hints)
 *
 * Entries only cover real, verified key handlers. Incidental key behavior
 * (Enter submits a form, arrow-key navigation inside dropdown menus, mention
 * autocomplete) is standard form/accessibility behavior, not a shortcut.
 */
require_once __DIR__ . '/feature-flags.php';

if (!function_exists('getKeyboardShortcuts')) {

/**
 * @return array<int, array{id:string,scope:string,win:string,mac:string,
 *                           description_key:string,enabled:bool}>
 */
function getKeyboardShortcuts() {
    return array(
        array(
            'id' => 'open_settings',
            'scope' => 'global',
            'win' => 'Ctrl + ,',
            'mac' => '⌘ + ,',
            'description_key' => 'shortcuts.open_settings',
            'enabled' => true,
        ),
        array(
            'id' => 'create_case',
            'scope' => 'global',
            'win' => 'Ctrl + K',
            'mac' => '⌘ + K',
            'description_key' => 'shortcuts.create_case',
            'enabled' => true,
        ),
        array(
            'id' => 'view_archived_cases',
            'scope' => 'global',
            'win' => 'Ctrl + Shift + A',
            'mac' => '⌘ + Shift + A',
            'description_key' => 'shortcuts.view_archived_cases',
            'enabled' => true,
        ),
        array(
            'id' => 'open_feedback',
            'scope' => 'global',
            'win' => 'Ctrl + Shift + F',
            'mac' => '⌘ + Shift + F',
            'description_key' => 'shortcuts.open_feedback',
            'enabled' => true,
        ),
        array(
            'id' => 'open_ask_dentatrak',
            'scope' => 'global',
            'win' => 'Ctrl + /',
            'mac' => '⌘ + /',
            'description_key' => 'shortcuts.open_ask_dentatrak',
            'enabled' => isFeatureEnabled('SHOW_AI_CHAT'),
        ),
        array(
            'id' => 'close_dialog',
            'scope' => 'global',
            'win' => 'Esc',
            'mac' => 'Esc',
            'description_key' => 'shortcuts.close_dialog',
            'enabled' => true,
        ),
        array(
            'id' => 'viewer_navigate',
            'scope' => 'attachment_viewer',
            'win' => '← / →',
            'mac' => '← / →',
            'description_key' => 'shortcuts.viewer_navigate',
            'enabled' => true,
        ),
    );
}

/**
 * Registry resolved for the current locale/user: adds localized `scope_label`
 * and `description`, keeps key combos untranslated.
 */
function getLocalizedKeyboardShortcuts() {
    $scopes = array(
        'global' => 'shortcuts.scope_global',
        'attachment_viewer' => 'shortcuts.scope_attachment_viewer',
    );
    $resolved = array();
    foreach (getKeyboardShortcuts() as $s) {
        $s['scope_label'] = isset($scopes[$s['scope']]) ? t($scopes[$s['scope']]) : $s['scope'];
        $s['description'] = t($s['description_key']);
        $resolved[] = $s;
    }
    return $resolved;
}

}
