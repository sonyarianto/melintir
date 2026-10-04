<?php
// Melintir template takeover canvas: renders another post's Melintir doc
// (Theme::load_product_template sets $GLOBALS['melintir_doc_id']).
// No theme header/footer/sidebar — wp_head/wp_footer still run so styles,
// scripts and the Melintir per-template CSS (frontend_assets) load normally.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$melintir_doc_id = isset( $GLOBALS['melintir_doc_id'] ) ? absint( $GLOBALS['melintir_doc_id'] ) : 0;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'mel-canvas' ); ?>>
<?php wp_body_open(); ?>
<main class="mel-canvas-main">
	<?php
	if ( $melintir_doc_id ) {
		echo '<!-- melintir:template:' . esc_attr( (string) $melintir_doc_id ) . ' -->' . \Melintir\Renderer::render_page( $melintir_doc_id ); // phpcs:ignore
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
