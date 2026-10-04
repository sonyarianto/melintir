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
		register_rest_route(
			'melintir/v1',
			'/patterns',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'patterns_list' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			'melintir/v1',
			'/patterns',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'patterns_save' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			'melintir/v1',
			'/patterns/(?P<pid>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'patterns_delete' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
	}

	public static function can_edit( \WP_REST_Request $req ) {
		$id = intval( $req->get_param( 'id' ) );
		if ( $id > 0 ) {
			return Security::can_edit( $id );
		}
		return Security::can_use_builder();
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

	const PATTERNS_OPTION = 'melintir_patterns';
	const MAX_PATTERNS  = 50;

	/**
	 * Saved patterns (Global widgets slice 1: unsynced copy library).
	 * A pattern is one sanitized node subtree; insert expands it as a
	 * fresh-ID copy, so no renderer changes and no sync invalidation.
	 * Patterns persist in an option (kept on uninstall, like page data).
	 */
	public static function patterns_list() {
		$all = get_option( self::PATTERNS_OPTION, array() );
		return rest_ensure_response( is_array( $all ) ? array_values( $all ) : array() );
	}

	public static function patterns_save( \WP_REST_Request $req ) {
		$params = $req->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$name   = isset( $params['name'] ) ? substr( sanitize_text_field( (string) $params['name'] ), 0, 60 ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'invalid', 'Pattern name is required', array( 'status' => 400 ) );
		}
		if ( ! isset( $params['node'] ) || ! is_array( $params['node'] ) ) {
			return new \WP_Error( 'invalid', 'Pattern node is required', array( 'status' => 400 ) );
		}
		list( $clean, $errors ) = Security::sanitize_pattern_node( $params['node'] );
		if ( null === $clean ) {
			return new \WP_Error( 'invalid', implode( '; ', $errors ), array( 'status' => 400 ) );
		}
		$all = get_option( self::PATTERNS_OPTION, array() );
		$all = is_array( $all ) ? array_values( $all ) : array();
		$ids = array();
		foreach ( $all as $p ) {
			if ( isset( $p['id'] ) ) {
				$ids[ (string) $p['id'] ] = true;
			}
		}
		$pid = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$candidate = 'p' . wp_generate_password( 7, false, false );
			if ( ! isset( $ids[ $candidate ] ) ) {
				$pid = $candidate;
				break;
			}
		}
		if ( '' === $pid ) {
			return new \WP_Error( 'full', 'Could not allocate a pattern ID', array( 'status' => 500 ) );
		}
		array_unshift(
			$all,
			array(
				'id'   => $pid,
				'name' => $name,
				'node' => $clean,
				'ts'   => time(),
			)
		);
		update_option( self::PATTERNS_OPTION, array_slice( $all, 0, self::MAX_PATTERNS ) );
		return rest_ensure_response( array( 'ok' => true, 'id' => $pid, 'warnings' => $errors ) );
	}

	public static function patterns_delete( \WP_REST_Request $req ) {
		$pid = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $req->get_param( 'pid' ) );
		$all = get_option( self::PATTERNS_OPTION, array() );
		$all = is_array( $all ) ? array_values( $all ) : array();
		$kept = array();
		foreach ( $all as $p ) {
			if ( ! isset( $p['id'] ) || (string) $p['id'] !== $pid ) {
				$kept[] = $p;
			}
		}
		update_option( self::PATTERNS_OPTION, $kept );
		return rest_ensure_response( array( 'ok' => true ) );
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
