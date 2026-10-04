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
		add_menu_page(
			__( 'Melintir', 'melintir' ),
			__( 'Melintir', 'melintir' ),
			'edit_posts',
			'melintir',
			array( $this, 'admin_page' ),
			'dashicons-layout',
			58
		);
	}

	public function admin_page() {
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
		$js_path = MELINTIR_PATH . 'assets/frontend/frontend.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script( 'melintir-front', MELINTIR_URL . 'assets/frontend/frontend.js', array(), (string) filemtime( $js_path ), true );
		}
	}
}
