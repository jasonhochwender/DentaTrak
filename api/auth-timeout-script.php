<?php
/**
 * Shared session-timeout script loader for authenticated HTML pages.
 *
 * Include this once just before </body> on any page that requires an
 * authenticated user. It ensures the i18n translation payload and the
 * session-timeout handler are available.
 */

require_once __DIR__ . '/i18n.php';
?>
<script>
    window.__i18n = <?php echo getTranslationsJsonForJs(); ?>;
</script>
<script src="js/i18n.js?v=20260820"></script>
<script src="js/session-timeout.js?v=2026090401" defer></script>
