<?php
/**
 * Shared session-timeout script loader for authenticated HTML pages.
 *
 * Include this once just before </body> on any page that requires an
 * authenticated user. It ensures the i18n translation payload and the
 * session-timeout handler are available.
 */

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/asset-url.php';
?>
<script>
    window.__i18n = <?php echo getTranslationsJsonForJs(); ?>;
</script>
<script src="<?= appAssetUrl('js/i18n.js') ?>"></script>
<script src="<?= appAssetUrl('js/session-timeout.js') ?>" defer></script>
