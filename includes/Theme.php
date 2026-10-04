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
 *   _melintir_location: header | footer | popup
 *   _melintir_rule:     entire_site | front_page | blog_home |
 *                       singular_page | singular_post | archive
 *   (per-ID / taxonomy values come in slice 3; rules are ordered by
 *   specificity, first match wins)
 *
 * Frontend replacement (Theme::register()):
 *   - Block themes: `render_block` swaps core/template-part header/footer.
 *   - Classic themes: use [melintir_header] / [melintir_footer] shortcodes
 *     or melintir_header() / melintir_footer() in a child theme. There is
 *     no reliable universal auto-replace for classic header.php, so we
 *     don't pretend (no output-buffer or CSS-hiding hacks).
 * Popups (lite): location `popup` + trigger meta, injected at `wp_footer`,
 * driven by assets/frontend/frontend.js (delay / click / once-per-session).
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
		add_action( 'wp_footer', array( __CLASS__, 'inject_popup' ) );
		add_shortcode( 'melintir_header', array( __CLASS__, 'shortcode_header' ) );
		add_shortcode( 'melintir_footer', array( __CLASS__, 'shortcode_footer' ) );
		add_shortcode( 'melintir_template', array( __CLASS__, 'shortcode_template' ) );
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
		if ( ! Security::can_use_builder() ) {
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
		foreach ( array( '' => __( '— Select —', 'melintir' ), 'header' => __( 'Site Header', 'melintir' ), 'footer' => __( 'Site Footer', 'melintir' ), 'popup' => __( 'Popup', 'melintir' ) ) as $v => $label ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $loc, $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__( 'Rule decides where this template displays. Specific rules beat Entire Site.', 'melintir' ) . '</p>';
		$rule = get_post_meta( $post->ID, self::RULE_META, true );
		if ( '' === $rule ) {
			$rule = 'entire_site';
		}
		echo '<p><label>' . esc_html__( 'Display rule:', 'melintir' ) . '<br /><select name="melintir_rule">';
		foreach ( self::rules() as $v => $label ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $rule, $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		$trigger = get_post_meta( $post->ID, '_melintir_trigger', true );
		if ( ! is_array( $trigger ) ) {
			$trigger = array();
		}
		$mode      = isset( $trigger['mode'] ) ? (string) $trigger['mode'] : 'load';
		$delay     = isset( $trigger['delay'] ) ? intval( $trigger['delay'] ) : 3;
		$selector  = isset( $trigger['selector'] ) ? (string) $trigger['selector'] : '';
		$frequency = isset( $trigger['frequency'] ) ? (string) $trigger['frequency'] : 'always';
		echo '<p><strong>' . esc_html__( 'Popup trigger (popups only):', 'melintir' ) . '</strong><br />';
		echo '<label><input type="radio" name="melintir_trigger_mode" value="load"' . checked( $mode, 'load', false ) . ' /> ' . esc_html__( 'On page load after delay', 'melintir' ) . '</label><br />';
		echo '<label><input type="radio" name="melintir_trigger_mode" value="click"' . checked( $mode, 'click', false ) . ' /> ' . esc_html__( 'On click of elements matching selector', 'melintir' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Delay (seconds):', 'melintir' ) . ' <input type="number" name="melintir_trigger_delay" value="' . esc_attr( (string) $delay ) . '" min="0" max="120" style="width:5em" /></label></p>';
		echo '<p><label>' . esc_html__( 'Click selector (e.g. .open-promo):', 'melintir' ) . '<br /><input type="text" name="melintir_trigger_selector" value="' . esc_attr( $selector ) . '" class="widefat" /></label></p>';
		echo '<p><label>' . esc_html__( 'Frequency:', 'melintir' ) . '<br /><select name="melintir_trigger_frequency">';
		foreach ( array( 'always' => __( 'Every page view', 'melintir' ), 'session' => __( 'Once per session', 'melintir' ) ) as $v => $label ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $frequency, $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
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
		if ( 'header' !== $loc && 'footer' !== $loc && 'popup' !== $loc ) {
			$loc = '';
		}
		update_post_meta( $post_id, self::LOC_META, $loc );
		$rule = isset( $_POST['melintir_rule'] ) ? sanitize_key( $_POST['melintir_rule'] ) : 'entire_site'; // phpcs:ignore
		if ( ! array_key_exists( $rule, self::rules() ) ) {
			$rule = 'entire_site';
		}
		update_post_meta( $post_id, self::RULE_META, $rule );
		$mode = isset( $_POST['melintir_trigger_mode'] ) && 'click' === $_POST['melintir_trigger_mode'] ? 'click' : 'load'; // phpcs:ignore
		$delay = isset( $_POST['melintir_trigger_delay'] ) ? max( 0, min( 120, intval( $_POST['melintir_trigger_delay'] ) ) ) : 3; // phpcs:ignore
		$selector = isset( $_POST['melintir_trigger_selector'] ) ? substr( preg_replace( '/[^a-zA-Z0-9_.#\- \[\]=\"\':]/', '', (string) $_POST['melintir_trigger_selector'] ), 0, 200 ) : ''; // phpcs:ignore
		$frequency = isset( $_POST['melintir_trigger_frequency'] ) && 'session' === $_POST['melintir_trigger_frequency'] ? 'session' : 'always'; // phpcs:ignore
		update_post_meta(
			$post_id,
			'_melintir_trigger',
			array(
				'mode'      => $mode,
				'delay'     => $delay,
				'selector'  => $selector,
				'frequency' => $frequency,
			)
		);
	}

	/**
	 * Available display rules, most specific first. First match wins.
	 *
	 * @return array slug => label
	 */
	public static function rules() {
		return array(
			'front_page'    => __( 'Front Page', 'melintir' ),
			'blog_home'     => __( 'Blog Home', 'melintir' ),
			'singular_page' => __( 'All Pages', 'melintir' ),
			'singular_post' => __( 'All Posts', 'melintir' ),
			'archive'       => __( 'All Archives', 'melintir' ),
			'entire_site'   => __( 'Entire Site', 'melintir' ),
		);
	}

	private static function rule_matches( $rule ) {
		switch ( $rule ) {
			case 'front_page':
				return function_exists( 'is_front_page' ) && is_front_page();
			case 'blog_home':
				return function_exists( 'is_home' ) && is_home();
			case 'singular_page':
				return function_exists( 'is_page' ) && is_page();
			case 'singular_post':
				return function_exists( 'is_single' ) && is_single();
			case 'archive':
				return function_exists( 'is_archive' ) && is_archive();
			case 'entire_site':
				return true;
			default:
				return false;
		}
	}

	/**
	 * Find the published template for a location in the current context.
	 * Missing rule meta means entire_site (slice-1 templates keep working).
	 *
	 * @param string $location header|footer|popup
	 * @return int post ID, 0 if none.
	 */
	public static function assigned( $location ) {
		static $cache = array();
		$ckey = $location . '|' . ( function_exists( 'is_front_page' ) && is_front_page() ? 'f' : '' ) . ( function_exists( 'is_home' ) && is_home() ? 'h' : '' ) . (string) get_queried_object_id();
		if ( isset( $cache[ $ckey ] ) ) {
			return $cache[ $ckey ];
		}
		$found = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => 20,
				'meta_query'  => array( // phpcs:ignore
					array( 'key' => self::LOC_META, 'value' => $location ),
				),
				'fields'      => 'ids',
				'no_found_rows' => true,
			)
		);
		$best       = 0;
		$best_order = PHP_INT_MAX;
		$order      = array_keys( self::rules() );
		foreach ( $found as $id ) {
			$rule = get_post_meta( $id, self::RULE_META, true );
			if ( '' === $rule ) {
				$rule = 'entire_site';
			}
			if ( ! self::rule_matches( $rule ) ) {
				continue;
			}
			$pos = array_search( $rule, $order, true );
			$pos = false === $pos ? PHP_INT_MAX - 1 : $pos;
			if ( $pos < $best_order ) {
				$best_order = $pos;
				$best       = intval( $id );
			}
		}
		$cache[ $ckey ] = $best;
		return $best;
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

	/**
	 * Inject the assigned popup at wp_footer (entire-site rule).
	 * Config travels as data-* attributes; frontend.js owns behavior.
	 */
	public static function inject_popup() {
		if ( is_admin() ) {
			return;
		}
		$id = self::assigned( 'popup' );
		if ( ! $id ) {
			return;
		}
		$mel = Renderer::render_page( $id );
		if ( '' === $mel ) {
			return;
		}
		$trigger = get_post_meta( $id, '_melintir_trigger', true );
		$trigger = is_array( $trigger ) ? $trigger : array();
		$mode      = isset( $trigger['mode'] ) && 'click' === $trigger['mode'] ? 'click' : 'load';
		$delay     = isset( $trigger['delay'] ) ? max( 0, min( 120, intval( $trigger['delay'] ) ) ) : 3;
		$selector  = isset( $trigger['selector'] ) ? (string) $trigger['selector'] : '';
		$frequency = isset( $trigger['frequency'] ) && 'session' === $trigger['frequency'] ? 'session' : 'always';
		echo '<div class="mel-popup" data-popup="' . esc_attr( (string) $id ) . '" data-mode="' . esc_attr( $mode ) . '" data-delay="' . esc_attr( (string) $delay ) . '" data-selector="' . esc_attr( $selector ) . '" data-frequency="' . esc_attr( $frequency ) . '" hidden>';
		echo '<div class="mel-popup-backdrop" data-close></div>';
		echo '<div class="mel-popup-box" role="dialog" aria-modal="true"><button class="mel-popup-x" data-close aria-label="' . esc_attr__( 'Close', 'melintir' ) . '">×</button>' . $mel . '</div>';
		echo '</div>';
	}

	/**
	 * Shortcodes for classic themes (and anywhere else):
	 * [melintir_header], [melintir_footer], [melintir_template id="123"].
	 */
	public static function shortcode_header() {
		return self::location_html( 'header' );
	}

	public static function shortcode_footer() {
		return self::location_html( 'footer' );
	}

	public static function shortcode_template( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'melintir_template' );
		$id   = absint( $atts['id'] );
		if ( ! $id || self::CPT !== get_post_type( $id ) ) {
			return '';
		}
		return Renderer::render_page( $id );
	}

	private static function location_html( $location ) {
		if ( is_admin() ) {
			return '';
		}
		$id = self::assigned( $location );
		if ( ! $id ) {
			return '';
		}
		return '<!-- melintir:' . esc_attr( $location ) . ' -->' . Renderer::render_page( $id );
	}
}
