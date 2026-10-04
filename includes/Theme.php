<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme Builder slice 1.
 *
 * `melintir_template` posts hold a Melintir doc (`_melintir_data`, same
 * shape as pages) plus location meta:
 *   _melintir_location: header | footer
 *   _melintir_rule:     entire_site          (only rule in slice 1)
 *
 * Frontend replacement (Theme::register()):
 *   - Block themes: `render_block` swaps core/template-part header/footer.
 *   - Classic themes: NOT replaced in slice 1 (see Theme::render for why);
 *     use the bundled Canvas page template for full-bleed landing pages.
 */
class Theme {

	const CPT      = 'melintir_template';
	const LOC_META = '_melintir_location';
	const RULE_META = '_melintir_rule';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'post_type' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save_meta' ) );
		add_filter( 'render_block', array( __CLASS__, 'swap_template_part' ), 10, 2 );
		add_filter( 'theme_page_templates', array( __CLASS__, 'canvas_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'load_canvas' ) );
	}

	public static function post_type() {
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Theme Templates', 'melintir' ),
					'singular_name' => __( 'Theme Template', 'melintir' ),
					'add_new_item'  => __( 'Add New Template', 'melintir' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'melintir',
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . self::CPT,
			__( 'Edit with Melintir', 'melintir' ),
			__( 'Edit with Melintir', 'melintir' ),
			'edit_posts',
			'melintir-edit-template',
			array( __CLASS__, 'edit_page' )
		);
	}

	/**
	 * Helper screen: pick a template, jump into the Melintir editor for it.
	 * (The editor itself is post-ID generic, so no editor changes needed.)
	 */
	public static function edit_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You cannot edit templates.', 'melintir' ) );
		}
		$templates = get_posts(
			array(
				'post_type'   => self::CPT,
				'numberposts' => 50,
				'post_status' => array( 'publish', 'draft' ),
			)
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Edit Theme Template', 'melintir' ) . '</h1>';
		if ( empty( $templates ) ) {
			echo '<p>' . esc_html__( 'No templates yet. Create a header or footer first.', 'melintir' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $templates as $t ) {
				$loc  = get_post_meta( $t->ID, self::LOC_META, true );
				$url  = admin_url( 'admin.php?page=melintir&post=' . $t->ID );
				echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $t->post_title ) . '</a> (' . esc_html( $loc ? $loc : '—' ) . ')</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	public static function meta_box() {
		add_meta_box(
			'melintir-location',
			__( 'Template Location', 'melintir' ),
			array( __CLASS__, 'meta_box_html' ),
			self::CPT,
			'side'
		);
	}

	public static function meta_box_html( $post ) {
		wp_nonce_field( 'melintir_template_meta', 'melintir_template_nonce' );
		$loc = get_post_meta( $post->ID, self::LOC_META, true );
		echo '<p><label>' . esc_html__( 'Displays as:', 'melintir' ) . '<br />';
		echo '<select name="melintir_location">';
		foreach ( array( '' => __( '— Select —', 'melintir' ), 'header' => __( 'Site Header', 'melintir' ), 'footer' => __( 'Site Footer', 'melintir' ) ) as $v => $label ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $loc, $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__( 'Slice 1 rule: entire site. Per-page/archive conditions come in slice 2.', 'melintir' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=melintir&post=' . $post->ID ) ) . '">' . esc_html__( 'Edit with Melintir', 'melintir' ) . '</a></p>';
	}

	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['melintir_template_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['melintir_template_nonce'] ), 'melintir_template_meta' ) ) { // phpcs:ignore
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$loc = isset( $_POST['melintir_location'] ) ? sanitize_key( $_POST['melintir_location'] ) : ''; // phpcs:ignore
		if ( 'header' !== $loc && 'footer' !== $loc ) {
			$loc = '';
		}
		update_post_meta( $post_id, self::LOC_META, $loc );
		update_post_meta( $post_id, self::RULE_META, 'entire_site' );
	}

	/**
	 * Find the published template for a location (entire_site only).
	 *
	 * @param string $location header|footer
	 * @return int post ID, 0 if none.
	 */
	public static function assigned( $location ) {
		$found = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => 1,
				'meta_query'  => array( // phpcs:ignore
					array( 'key' => self::LOC_META, 'value' => $location ),
				),
				'fields'      => 'ids',
				'no_found_rows' => true,
			)
		);
		return $found ? intval( $found[0] ) : 0;
	}

	/**
	 * Block themes render header/footer as core/template-part blocks.
	 * Swap ours in; classic themes fall through untouched (slice 2).
	 */
	public static function swap_template_part( $content, $block ) {
		if ( ! is_array( $block ) || ( $block['blockName'] ?? '' ) !== 'core/template-part' ) {
			return $content;
		}
		$slug = $block['attrs']['slug'] ?? '';
		$area = $block['attrs']['area'] ?? '';
		$location = '';
		if ( 'header' === $slug || 'header' === $area ) {
			$location = 'header';
		} elseif ( 'footer' === $slug || 'footer' === $area ) {
			$location = 'footer';
		}
		if ( '' === $location || is_admin() ) {
			return $content;
		}
		$id = self::assigned( $location );
		if ( ! $id ) {
			return $content;
		}
		$mel = Renderer::render_page( $id );
		if ( '' === $mel ) {
			return $content;
		}
		return '<!-- melintir:' . esc_attr( $location ) . ' -->' . $mel;
	}

	/**
	 * Canvas page template: no theme header/footer/sidebar, just content.
	 * Registered into the Page Attributes dropdown.
	 */
	public static function canvas_template( $templates ) {
		$templates['melintir-canvas.php'] = __( 'Melintir Canvas', 'melintir' );
		return $templates;
	}

	public static function load_canvas( $template ) {
		if ( is_singular() ) {
			$id = get_the_ID();
			if ( $id && 'melintir-canvas.php' === get_page_template_slug( $id ) ) {
				return MELINTIR_PATH . 'templates/canvas.php';
			}
		}
		return $template;
	}
}
