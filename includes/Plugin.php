<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	public static function activate() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'melintir/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	private function hooks() {
		Rest::register();
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'the_content', array( $this, 'the_content' ), 9 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	public function menu() {
		if ( ! Security::can_use_builder() ) {
			return;
		}
		add_menu_page(
			__( 'Melintir', 'melintir' ),
			__( 'Melintir', 'melintir' ),
			'edit_posts',
			'melintir',
			array( $this, 'admin_page' ),
			'dashicons-layout',
			58
		);
		add_submenu_page(
			'melintir',
			__( 'Melintir Settings', 'melintir' ),
			__( 'Settings', 'melintir' ),
			'manage_options',
			'melintir-settings',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage settings.', 'melintir' ) );
		}
		if ( isset( $_POST['melintir_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['melintir_settings_nonce'] ), 'melintir_settings' ) ) { // phpcs:ignore
			update_option( 'melintir_turnstile_sitekey', isset( $_POST['turnstile_sitekey'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_sitekey'] ) ) : '' ); // phpcs:ignore
			update_option( 'melintir_turnstile_secret', isset( $_POST['turnstile_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_secret'] ) ) : '' ); // phpcs:ignore
			$posted_roles = isset( $_POST['melintir_roles'] ) && is_array( $_POST['melintir_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['melintir_roles'] ) ) : array(); // phpcs:ignore
			$known_roles  = function_exists( 'get_editable_roles' ) ? array_keys( get_editable_roles() ) : array();
			update_option( Security::ROLES_OPTION, array_values( array_intersect( $posted_roles, $known_roles ) ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'melintir' ) . '</p></div>';
		}
		$sitekey = get_option( 'melintir_turnstile_sitekey', '' );
		$secret  = get_option( 'melintir_turnstile_secret', '' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Melintir Settings', 'melintir' ) . '</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'melintir_settings', 'melintir_settings_nonce' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Turnstile site key', 'melintir' ) . '</th><td><input type="text" name="turnstile_sitekey" value="' . esc_attr( $sitekey ) . '" class="regular-text" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Turnstile secret key', 'melintir' ) . '</th><td><input type="password" name="turnstile_secret" value="' . esc_attr( $secret ) . '" class="regular-text" autocomplete="new-password" /></td></tr></table>';
		echo '<p class="description">' . esc_html__( 'Cloudflare Turnstile keys. Per-form opt-in lives in the form widget inspector. Empty keys = Turnstile off.', 'melintir' ) . '</p>';
		echo '<h2>' . esc_html__( 'Builder access', 'melintir' ) . '</h2>';
		$all_roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		$saved_roles = get_option( Security::ROLES_OPTION, null );
		if ( ! is_array( $saved_roles ) ) {
			// First run: mirror the historical behavior (everyone with edit_posts).
			$saved_roles = array();
			foreach ( $all_roles as $slug => $details ) {
				if ( ! empty( $details['capabilities']['edit_posts'] ) ) {
					$saved_roles[] = $slug;
				}
			}
		}
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Allowed roles', 'melintir' ) . '</th><td>';
		foreach ( $all_roles as $slug => $details ) {
			$label = isset( $details['name'] ) ? $details['name'] : $slug;
			echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="melintir_roles[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $saved_roles, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</td></tr></table>';
		echo '<p class="description">' . esc_html__( 'Who sees the Melintir menu and can use the builder. Administrators always keep access. Empty selection = anyone with edit_posts (WordPress default).', 'melintir' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	public function admin_page() {
		if ( ! Security::can_use_builder() ) {
			wp_die( esc_html__( 'You are not allowed to use the Melintir builder.', 'melintir' ) );
		}
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore
		if ( $post_id && ! Security::can_edit( $post_id ) ) {
			wp_die( esc_html__( 'You cannot edit this post.', 'melintir' ) );
		}
		// If no post given, list recent posts with edit links.
		if ( ! $post_id ) {
			echo '<div class="wrap"><h1>Melintir</h1><p>';
			esc_html_e( 'Pick a page to edit:', 'melintir' );
			echo '</p><ul>';
			$posts = get_posts(
				array(
					'post_type'   => array( 'page', 'post' ),
					'numberposts' => 10,
				)
			);
			foreach ( $posts as $p ) {
				$url = admin_url( 'admin.php?page=melintir&post=' . $p->ID );
				echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $p->post_title ? $p->post_title : '(#' . $p->ID . ')' ) . '</a></li>';
			}
			echo '</ul><p><a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=page' ) ) . '">';
			esc_html_e( 'Create new page first, then come back here.', 'melintir' );
			echo '</a></p></div>';
			return;
		}

		$this->enqueue_editor( $post_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Melintir Editor', 'melintir' ) . '</h1>';
		echo '<div id="melintir-root" data-post="' . esc_attr( (string) $post_id ) . '"></div></div>';
	}

	private function enqueue_editor( $post_id ) {
		wp_enqueue_media(); // for the image widget's library picker.
		$js     = MELINTIR_URL . 'assets/editor/editor.js';
		// filemtime cache-bust when built locally; fallback to version.
		$js_ver = file_exists( MELINTIR_PATH . 'assets/editor/editor.js' ) ? (string) filemtime( MELINTIR_PATH . 'assets/editor/editor.js' ) : MELINTIR_VERSION;

		// Note: editor CSS is inlined into editor.js by the build (IIFE),
		// so there is no separate editor.css to enqueue.
		wp_enqueue_script( 'melintir-editor', $js, array(), $js_ver, true );
		wp_localize_script(
			'melintir-editor',
			'MelintirData',
			array(
				'postId'  => $post_id,
				'restUrl' => esc_url_raw( rest_url( 'melintir/v1/post/' . $post_id ) ),
				'templatesUrl' => esc_url_raw( rest_url( 'melintir/v1/templates' ) ),
				'patternsUrl' => esc_url_raw( rest_url( 'melintir/v1/patterns' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'wasmUrl' => esc_url_raw( MELINTIR_URL . 'assets/core/melintir-core_bg.wasm' ),
				'wasmJs'  => esc_url_raw( MELINTIR_URL . 'assets/core/melintir-core.js' ),
			)
		);
	}

	public function row_action( $actions, $post ) {
		if ( isset( $post->ID ) && Security::can_edit( $post->ID ) ) {
			$url = admin_url( 'admin.php?page=melintir&post=' . $post->ID );
			$actions['melintir'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Edit with Melintir', 'melintir' ) . '</a>';
		}
		return $actions;
	}

	public function the_content( $content ) {
		if ( ! is_singular() || is_admin() ) {
			return $content;
		}
		$id = get_the_ID();
		if ( ! $id ) {
			return $content;
		}
		$mel = Renderer::render_page( $id );
		if ( '' === $mel ) {
			return $content;
		}
		// v0.1: append builder output after post content (lets you mix Gutenberg + Melintir).
		return $content . "\n" . $mel;
	}

	public function frontend_assets() {
		// Base chrome (tabs, popup) on every frontend page; per-page CSS only where used.
		$css_path = MELINTIR_PATH . 'assets/frontend/frontend.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style( 'melintir-base', MELINTIR_URL . 'assets/frontend/frontend.css', array(), (string) filemtime( $css_path ) );
		}
		$js_path = MELINTIR_PATH . 'assets/frontend/frontend.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script( 'melintir-front', MELINTIR_URL . 'assets/frontend/frontend.js', array(), (string) filemtime( $js_path ), true );
		}
		if ( ! is_singular() ) {
			return;
		}
		$id  = get_the_ID();
		$css = $id ? get_post_meta( $id, MELINTIR_META_CSS, true ) : '';
		if ( is_string( $css ) && '' !== $css ) {
			wp_register_style( 'melintir-front', false, array(), MELINTIR_VERSION );
			wp_enqueue_style( 'melintir-front' );
			wp_add_inline_style( 'melintir-front', $css );
		}
	}
}
