<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor -> Melintir converter (v0.1: content widgets only).
 *
 * Elementor stores a page as `_elementor_data`: a JSON array of
 * section > column > widget nodes. We map that onto our container model:
 *   section -> container (row) / column -> container (column) / widget -> widget
 *
 * Out of scope for v0.1 (counted as skipped, never fatal): inner-section,
 * column widths (% splits become equal columns), motion effects, custom CSS,
 * third-party widgets, responsive overrides, dynamic tags.
 */
class Migrator {

	/** Elementor widget => Melintir widget. */
	const WIDGET_MAP = array(
		'heading'      => 'heading',
		'text-editor'  => 'text',
		'image'        => 'image',
		'button'       => 'button',
		'video'        => 'video',
		'divider'      => 'divider',
		'spacer'       => 'spacer',
		'icon-box'     => 'icon-box',
		'tabs'         => 'tabs',
	);

	/**
	 * @param mixed $elementor_data Decoded `_elementor_data` (array of nodes).
	 * @return array {doc, stats, warnings}
	 */
	public static function convert( $elementor_data ) {
		$stats = array(
			'sections' => 0,
			'columns'  => 0,
			'widgets'  => 0,
			'mapped'   => 0,
			'skipped'  => 0,
		);
		$warnings  = array();
		$elements  = array();

		foreach ( (array) $elementor_data as $node ) {
			$converted = self::convert_node( $node, $stats, $warnings );
			if ( null !== $converted ) {
				$elements[] = $converted;
			}
		}

		$doc = array(
			'version' => '0.1.0',
			'root'    => array(
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => array(),
				'style'    => array( 'layout' => array( 'direction' => 'column', 'gap' => 20 ) ),
				'elements' => $elements,
			),
			'globals' => array(
				'colors'      => array(),
				'breakpoints' => array( 'tablet' => 1024, 'mobile' => 767 ),
			),
		);

		return array( 'doc' => $doc, 'stats' => $stats, 'warnings' => array_values( array_unique( $warnings ) ) );
	}

	/**
	 * @param mixed $node
	 * @param array $stats
	 * @param array $warnings
	 * @return array|null
	 */
	private static function convert_node( $node, &$stats, &$warnings ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		$el_type = isset( $node['elType'] ) ? (string) $node['elType'] : '';
		$id      = isset( $node['id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $node['id'] ) : '';
		if ( '' === $id ) {
			$id = wp_generate_password( 8, false, false );
		}

		if ( 'section' === $el_type ) {
			$stats['sections']++;
			return array(
				'id'       => $id,
				'elType'   => 'container',
				'settings' => array(),
				'style'    => array( 'layout' => array( 'direction' => 'row', 'gap' => 16 ) ),
				'elements' => self::convert_children( $node, $stats, $warnings ),
			);
		}

		if ( 'column' === $el_type ) {
			$stats['columns']++;
			if ( isset( $node['settings']['_column_size'] ) ) {
				$warnings[] = 'column widths are not preserved in v0.1 (columns become equal)';
			}
			return array(
				'id'       => $id,
				'elType'   => 'container',
				'settings' => array(),
				'style'    => array( 'layout' => array( 'direction' => 'column', 'gap' => 12 ) ),
				'elements' => self::convert_children( $node, $stats, $warnings ),
			);
		}

		if ( 'widget' === $el_type ) {
			$stats['widgets']++;
			$from = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';
			if ( ! isset( self::WIDGET_MAP[ $from ] ) ) {
				$stats['skipped']++;
				$warnings[] = 'skipped unsupported widget: ' . $from;
				return null;
			}
			$stats['mapped']++;
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			return array(
				'id'         => $id,
				'elType'     => 'widget',
				'widgetType' => self::WIDGET_MAP[ $from ],
				'settings'   => self::convert_settings( $from, $settings, $warnings ),
				'style'      => self::convert_style( $settings, $warnings ),
				'elements'   => array(),
			);
		}

		// inner-section and anything else.
		$stats['skipped']++;
		$warnings[] = 'skipped unsupported element: ' . ( '' !== $el_type ? $el_type : 'unknown' );
		return null;
	}

	private static function convert_children( $node, &$stats, &$warnings ) {
		$out = array();
		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				$converted = self::convert_node( $child, $stats, $warnings );
				if ( null !== $converted ) {
					$out[] = $converted;
				}
			}
		}
		return $out;
	}

	private static function str( $settings, $key, $default = '' ) {
		if ( ! isset( $settings[ $key ] ) ) {
			return $default;
		}
		$v = $settings[ $key ];
		if ( is_array( $v ) && isset( $v['url'] ) ) {
			$v = $v['url'];
		}
		return is_string( $v ) || is_numeric( $v ) ? (string) $v : $default;
	}

	private static function convert_settings( $from, $settings, &$warnings ) {
		switch ( $from ) {
			case 'heading':
				$tag = strtolower( self::str( $settings, 'header_size', 'h2' ) );
				return array(
					'text' => self::str( $settings, 'title', '' ),
					'tag'  => in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' ), true ) ? $tag : 'h2',
				);
			case 'text-editor':
				return array( 'html' => self::str( $settings, 'editor', '' ) );
			case 'image':
				$img = isset( $settings['image'] ) && is_array( $settings['image'] ) ? $settings['image'] : array();
				return array(
					'url' => isset( $img['url'] ) ? (string) $img['url'] : self::str( $settings, 'url', '' ),
					'alt' => isset( $settings['image_alt'] ) ? (string) $settings['image_alt'] : '',
					'id'  => isset( $img['id'] ) ? absint( $img['id'] ) : 0,
				);
			case 'button':
				return array(
					'text' => self::str( $settings, 'text', '' ),
					'url'  => self::str( $settings, 'link', '#' ) === '#' ? '#' : self::str( $settings, 'link', '#' ),
				);
			case 'video':
				foreach ( array( 'youtube_url', 'vimeo_url', 'dailymotion_url', 'hosted_url', 'link' ) as $k ) {
					$u = self::str( $settings, $k, '' );
					if ( '' !== $u ) {
						return array( 'url' => $u );
					}
				}
				return array( 'url' => '' );
			case 'icon-box':
				return array(
					'title' => self::str( $settings, 'title_text', '' ),
					'desc'  => self::str( $settings, 'description_text', '' ),
					'icon'  => self::str( $settings, 'selected_icon', 'star' ) === 'star' ? self::str( $settings, 'icon', 'star' ) : self::str( $settings, 'selected_icon', 'star' ),
				);
			case 'tabs':
				$tabs = array();
				if ( isset( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
					foreach ( array_slice( $settings['tabs'], 0, 10 ) as $t ) {
						if ( ! is_array( $t ) ) {
							continue;
						}
						$tabs[] = array(
							'title'   => isset( $t['tab_title'] ) ? (string) $t['tab_title'] : '',
							'content' => isset( $t['tab_content'] ) ? (string) $t['tab_content'] : '',
						);
					}
				}
				return array( 'tabs' => $tabs );
			default:
				return array();
		}
	}

	private static function convert_style( $settings, &$warnings ) {
		$style = array();
		// Typography size: Elementor uses {size, unit} arrays.
		if ( isset( $settings['typography_font_size'] ) && is_array( $settings['typography_font_size'] ) && isset( $settings['typography_font_size']['size'] ) ) {
			$style['typo'] = array( 'size' => max( 10, min( 120, intval( $settings['typography_font_size']['size'] ) ) ) );
		}
		if ( isset( $settings['title_color'] ) && is_string( $settings['title_color'] ) && '' !== $settings['title_color'] ) {
			if ( ! isset( $style['typo'] ) ) {
				$style['typo'] = array();
			}
			$style['typo']['color'] = (string) $settings['title_color'];
		}
		if ( ! empty( $settings['custom_css'] ) ) {
			$warnings[] = 'custom CSS is not migrated in v0.1';
		}
		return $style;
	}

	/**
	 * Migrate one post: read `_elementor_data`, convert, persist via the
	 * same sanitize+CSS path as the editor save.
	 *
	 * @param int  $post_id
	 * @param bool $dry_run
	 * @return array|\WP_Error
	 */
	public static function migrate_post( $post_id, $dry_run = false ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return new \WP_Error( 'no_elementor', __('No Elementor data on this post', 'melintir'), array( 'status' => 404 ) );
		}
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_elementor', __('Elementor data is not valid JSON', 'melintir'), array( 'status' => 400 ) );
		}
		$result = self::convert( $data );
		if ( $dry_run ) {
			return array(
				'dry_run'  => true,
				'stats'    => $result['stats'],
				'warnings' => $result['warnings'],
			);
		}
		list( $clean, $errors ) = Security::sanitize_document( $result['doc'] );
		if ( null === $clean ) {
			return new \WP_Error( 'invalid', implode( '; ', $errors ), array( 'status' => 400 ) );
		}
		update_post_meta( $post_id, MELINTIR_META_DATA, wp_json_encode( $clean ) );
		$css = Renderer::generate_css( $clean );
		update_post_meta( $post_id, MELINTIR_META_CSS, $css );
		return array(
			'ok'       => true,
			'stats'    => $result['stats'],
			'warnings' => array_merge( $result['warnings'], $errors ),
			'css'      => $css,
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'melintir migrate',
		function ( $args, $assoc_args ) {
			$id = isset( $args[0] ) ? absint( $args[0] ) : 0;
			if ( ! $id ) {
				\WP_CLI::error( 'Usage: wp melintir migrate <post-id> [--dry-run]' );
			}
			$result = Migrator::migrate_post( $id, isset( $assoc_args['dry-run'] ) );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::success( 'Stats: ' . wp_json_encode( $result['stats'] ) );
			foreach ( $result['warnings'] as $w ) {
				\WP_CLI::warning( $w );
			}
		}
	);
}
