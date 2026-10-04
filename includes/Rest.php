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
		register_rest_route(
			'melintir/v1',
			'/block-templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'block_templates' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			'melintir/v1',
			'/patterns/(?P<pid>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'patterns_update' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'patterns_delete' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
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
			return new \WP_Error( 'too_large', __('Document too large', 'melintir'), array( 'status' => 413 ) );
		}

		list( $clean, $errors ) = Security::sanitize_document( $doc );
		if ( null === $clean ) {
			return new \WP_Error( 'invalid', implode( '; ', $errors ), array( 'status' => 400 ) );
		}

		update_post_meta( $id, MELINTIR_META_DATA, wp_json_encode( $clean ) );
		// Canonical doc keeps pattern-refs; CSS is baked from the expansion.
		list( $expanded, ) = Patterns::expand( $clean );
		$css = Renderer::generate_css( $expanded );
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
			return new \WP_Error( 'too_large', __('Document too large', 'melintir'), array( 'status' => 413 ) );
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
			return new \WP_Error( 'not_found', __('Revision not found', 'melintir'), array( 'status' => 404 ) );
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

	/**
	 * Templates available to the Gutenberg block picker: published and draft
	 * theme templates with their locations. Editor users only.
	 */
	public static function block_templates() {
		$posts = get_posts(
			array(
				'post_type'   => Theme::CPT,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => 50,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => $p->ID,
				/* translators: %d = template post ID. */
				'title'    => $p->post_title ? $p->post_title : sprintf( __( 'Template #%d', 'melintir' ), $p->ID ),
				'location' => (string) get_post_meta( $p->ID, Theme::LOC_META, true ),
				'status'   => $p->post_status,
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * FSE bridge: expose the active theme's merged palette + font families
	 * (theme.json theme/user/custom origins, so Site Editor customizations
	 * come along) as Melintir globals. Names are prefixed `theme-` so an
	 * import can never clobber hand-made globals; only hex colors and
	 * sanitizer-safe font stacks are returned.
	 */
	public static function theme_globals() {
		$colors = array();
		$fonts  = array();
		if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$settings = \WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
			if ( is_array( $settings ) ) {
				self::walk_presets( $settings, $colors, $fonts );
			}
		}
		return rest_ensure_response(
			array(
				'colors' => array_slice( $colors, 0, 20, true ),
				'fonts'  => array_slice( $fonts, 0, 20, true ),
			)
		);
	}

	private static function walk_presets( $node, &$colors, &$fonts ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['slug'] ) ) {
			$slug = 'theme-' . sanitize_key( (string) $node['slug'] );
			if ( isset( $node['color'] ) && sanitize_hex_color( (string) $node['color'] ) ) {
				$colors[ $slug ] = (string) $node['color'];
			}
			if ( isset( $node['fontFamily'] ) ) {
				$font = Security::sanitize_font( $node['fontFamily'] );
				if ( '' !== $font ) {
					$fonts[ $slug ] = $font;
				}
			}
			return;
		}
		foreach ( $node as $child ) {
			self::walk_presets( $child, $colors, $fonts );
		}
	}

	/**
	 * Saved patterns. Storage + expansion live in Patterns (slice 2 adds
	 * live links); these callbacks stay thin HTTP adapters.
	 */
	public static function patterns_list() {
		return rest_ensure_response( Patterns::all() );
	}

	public static function patterns_save( \WP_REST_Request $req ) {
		$params = $req->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$result = Patterns::save_new(
			isset( $params['name'] ) ? $params['name'] : '',
			isset( $params['node'] ) ? $params['node'] : null
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function patterns_update( \WP_REST_Request $req ) {
		$params = $req->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$result = Patterns::update(
			$req->get_param( 'pid' ),
			isset( $params['node'] ) ? $params['node'] : null
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function patterns_delete( \WP_REST_Request $req ) {
		$result = Patterns::delete( $req->get_param( 'pid' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function write_css_file( $post_id, $css ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'melintir/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$file = $dir . 'mel-' . intval( $post_id ) . '.css';
		file_put_contents( $file, $css ); // phpcs:ignore
	}
}
