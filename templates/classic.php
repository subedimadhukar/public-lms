<?php
/**
 * Classic theme wrapper for the plugin's block templates.
 *
 * Loaded by Templates\ClassicTemplates through `template_include` when the
 * active theme is not a block theme. Prints the theme's header and footer
 * around the same blocks the block template renders.
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div id="primary" class="content-area publiclms-classic">
	<?php
	echo publiclms()->container()->get( \PublicLMS\Templates\ClassicTemplates::class )->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered block markup; every block escapes its own output.
	?>
</div>
<?php
get_footer();
