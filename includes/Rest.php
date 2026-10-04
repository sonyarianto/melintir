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
		register_rest_route(
			'melintir/v1',
			'/post/(?P<id>\d+)/migrate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'migrate' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			'melintir/v1',
			'/post/(?P<id>\d+)/autosave',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'autosave' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			'melintir/v1',
			'/post/(?P<id>\d+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'history' ),
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

	public static function migrate( \WP_REST_Request $req ) {
		$id      = intval( $req->get_param( 'id' ) );
		$params  = $req->get_json_params();
		$dry_run = is_array( $params ) && ! empty( $params['dry_run'] );
		$result  = Migrator::migrate_post( $id, $dry_run );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Write an autosave snapshot + rotate the revision ring (max 5).
	 * Never touches the published `_melintir_data`.
	 */
	public static function autosave( \WP_REST_Request $req ) {
		$id  = intval( $req->get_param( 'id' ) );
		$doc = $req->get_json_params();
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
		$ts = time();
		update_post_meta( $id, '_melintir_autosave', wp_json_encode( array( 'ts' => $ts, 'doc' => $clean ) ) );

		$revs = get_post_meta( $id, '_melintir_revisions', true );
		$revs = is_array( $revs ) ? $revs : array();
		array_unshift( $revs, array( 'ts' => $ts, 'doc' => $clean ) );
		update_post_meta( $id, '_melintir_revisions', array_slice( $revs, 0, 5 ) );

		return rest_ensure_response( array( 'ok' => true, 'ts' => $ts, 'warnings' => $errors ) );
	}

	/**
	 * Read autosave + revision timestamps. ?rev=<ts> returns that full doc.
	 */
	public static function history( \WP_REST_Request $req ) {
		$id  = intval( $req->get_param( 'id' ) );
		$rev = $req->get_param( 'rev' );
		$revs = get_post_meta( $id, '_melintir_revisions', true );
		$revs = is_array( $revs ) ? $revs : array();
		if ( null !== $rev ) {
			foreach ( $revs as $r ) {
				if ( isset( $r['ts'] ) && (string) $r['ts'] === (string) $rev ) {
					return rest_ensure_response( array( 'doc' => $r['doc'], 'ts' => $r['ts'] ) );
				}
			}
			$auto = get_post_meta( $id, '_melintir_autosave', true );
			$auto = is_array( $auto ) ? $auto : ( is_string( $auto ) ? json_decode( $auto, true ) : null );
			if ( is_array( $auto ) && isset( $auto['ts'] ) && (string) $auto['ts'] === (string) $rev && isset( $auto['doc'] ) ) {
				return rest_ensure_response( array( 'doc' => $auto['doc'], 'ts' => $auto['ts'] ) );
			}
			return new \WP_Error( 'not_found', 'Revision not found', array( 'status' => 404 ) );
		}
		$auto = get_post_meta( $id, '_melintir_autosave', true );
		$auto = is_array( $auto ) ? $auto : ( is_string( $auto ) ? json_decode( $auto, true ) : null );
		$list = array();
		foreach ( $revs as $r ) {
			if ( isset( $r['ts'] ) ) {
				$list[] = array( 'ts' => $r['ts'] );
			}
		}
		return rest_ensure_response(
			array(
				'autosave'  => ( is_array( $auto ) && isset( $auto['ts'] ) ) ? array( 'ts' => $auto['ts'] ) : null,
				'revisions' => $list,
			)
		);
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
