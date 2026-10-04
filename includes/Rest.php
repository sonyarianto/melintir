<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST: /wp-json/melintir/v1/{post}/{load,save} + /templates
 */
class Rest {

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'melintir/v1',
			'/post/(?P<id>\d+)/load',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'load' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			'melintir/v1',
			'/post/(?P<id>\d+)/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			'melintir/v1',
			'/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'templates' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
	}

	public static function can_edit( \WP_REST_Request $req ) {
		$id = intval( $req->get_param( 'id' ) );
		if ( $id > 0 ) {
			return Security::can_edit( $id );
		}
		return current_user_can( 'edit_posts' );
	}

	public static function load( \WP_REST_Request $req ) {
		$id  = intval( $req->get_param( 'id' ) );
		$raw = get_post_meta( $id, MELINTIR_META_DATA, true );
		$css = get_post_meta( $id, MELINTIR_META_CSS, true );
		return rest_ensure_response(
			array(
				'doc' => $raw ? json_decode( is_string( $raw ) ? $raw : wp_json_encode( $raw ), true ) : null,
				'css' => is_string( $css ) ? $css : '',
			)
		);
	}

	public static function save( \WP_REST_Request $req ) {
		$id  = intval( $req->get_param( 'id' ) );
		$doc = $req->get_json_params();
		// Accept {doc:{...}} or raw {...}.
		if ( isset( $doc['doc'] ) && is_array( $doc['doc'] ) ) {
			$doc = $doc['doc'];
		}
		if ( strlen( wp_json_encode( $doc ) ) > Security::MAX_JSON_BYTES ) {
			return new \WP_Error( 'too_large', 'Document too large', array( 'status' => 413 ) );
		}

		list( $clean, $errors ) = Security::sanitize_document( $doc );
		if ( null === $clean ) {
			return new \WP_Error( 'invalid', implode( '; ', $errors ), array( 'status' => 400 ) );
		}

		update_post_meta( $id, MELINTIR_META_DATA, wp_json_encode( $clean ) );
		$css = Renderer::generate_css( $clean );
		update_post_meta( $id, MELINTIR_META_CSS, $css );
		self::write_css_file( $id, $css );

		return rest_ensure_response(
			array(
				'ok'       => true,
				'warnings' => $errors,
				'css'      => $css,
			)
		);
	}

	public static function templates() {
		$dir   = MELINTIR_PATH . 'templates/';
		$files = glob( $dir . '*.json' );
		$out   = array();
		foreach ( (array) $files as $f ) {
			$json = file_get_contents( $f ); // phpcs:ignore
			$data = json_decode( $json, true );
			if ( is_array( $data ) ) {
				$out[] = array(
					'name' => basename( $f, '.json' ),
					'doc'  => $data,
				);
			}
		}
		return rest_ensure_response( $out );
	}

	private static function write_css_file( $post_id, $css ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'melintir/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$file = $dir . 'mel-' . intval( $post_id ) . '.css';
		file_put_contents( $file, $css ); // phpcs:ignore
	}
}
