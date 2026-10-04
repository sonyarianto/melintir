<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg bridge: the melintir/template dynamic block embeds a Theme
 * Template inside block-editor / FSE content. The editor script is plain
 * JS with core-only dependencies (no build step); rendering is a PHP
 * callback so frontend output always matches shortcode rendering.
 */
class Blocks {

	public static function register() {
		add_action( 'init', array( __CLASS__, 'block_type' ) );
	}

	public static function block_type() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$js_path = MELINTIR_PATH . 'assets/blocks/template.js';
		if ( ! file_exists( $js_path ) ) {
			return;
		}
		wp_register_script(
			'melintir-template-block',
			MELINTIR_URL . 'assets/blocks/template.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n' ),
			(string) filemtime( $js_path )
		);
		register_block_type(
			'melintir/template',
			array(
				'editor_script'   => 'melintir-template-block',
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => array(
					'templateId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Same guard as the [melintir_template] shortcode: only real
	 * melintir_template posts render, everything else is silent.
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function render( $atts ) {
		$id = isset( $atts['templateId'] ) ? absint( $atts['templateId'] ) : 0;
		if ( ! $id || Theme::CPT !== get_post_type( $id ) ) {
			return '';
		}
		return Renderer::render_page( $id );
	}
}
