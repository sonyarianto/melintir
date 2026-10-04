<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central capability + sanitization policy.
 * WASM validates client-side for speed; PHP re-validates for security.
 */
class Security {

	const ALLOWED_WIDGETS = array(
		'heading', 'text', 'image', 'button',
		'video', 'divider', 'spacer', 'icon-box', 'tabs', 'form', 'loop',
		'accordion', 'gallery', 'counter', 'testimonial',
	);

	const MAX_NODES = 1000;
	const MAX_DEPTH = 6;
	const MAX_JSON_BYTES = 500 * 1024;

	/**
	 * @param int $post_id
	 * @return bool
	 */
	public static function can_edit( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object ) {
			return false;
		}
		if ( ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Validate + sanitize decoded document. Returns [clean_doc, errors].
	 *
	 * @param mixed $doc
	 * @return array
	 */
	public static function sanitize_document( $doc ) {
		$errors = array();

		if ( ! is_array( $doc ) ) {
			return array( null, array( 'document must be an object' ) );
		}
		if ( ! isset( $doc['root'] ) || ! is_array( $doc['root'] ) ) {
			return array( null, array( 'missing root node' ) );
		}

		$count = array( 'n' => 0 );
		$clean_root = self::sanitize_node( $doc['root'], 0, $count, $errors );
		if ( $count['n'] > self::MAX_NODES ) {
			$errors[] = 'too many nodes (max ' . self::MAX_NODES . ')';
		}
		if ( ! empty( $errors ) && null === $clean_root ) {
			return array( null, $errors );
		}

		$globals = isset( $doc['globals'] ) && is_array( $doc['globals'] ) ? $doc['globals'] : array();
		$clean = array(
			'version' => '0.1.0',
			'root'    => $clean_root,
			'globals' => array(
				'colors'      => self::sanitize_color_map( isset( $globals['colors'] ) ? $globals['colors'] : array() ),
				'breakpoints' => array(
					'tablet' => 1024,
					'mobile' => 767,
				),
			),
		);

		return array( $clean, $errors );
	}

	/**
	 * @param mixed $node
	 * @param int $depth
	 * @param array $count
	 * @param array $errors
	 * @return array|null
	 */
	private static function sanitize_node( $node, $depth, &$count, &$errors ) {
		$count['n']++;

		if ( $depth > self::MAX_DEPTH ) {
			$errors[] = 'max depth exceeded';
			return null;
		}
		if ( ! is_array( $node ) ) {
			$errors[] = 'invalid node';
			return null;
		}

		$el_type = isset( $node['elType'] ) ? sanitize_key( $node['elType'] ) : '';
		if ( 'container' !== $el_type && 'widget' !== $el_type ) {
			$errors[] = 'invalid elType';
			return null;
		}

		$id = isset( $node['id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $node['id'] ) : '';
		if ( '' === $id ) {
			$id = wp_generate_password( 8, false, false );
		}
		$id = substr( $id, 0, 32 );

		$widget_type = '';
		if ( 'widget' === $el_type ) {
			$widget_type = isset( $node['widgetType'] ) ? sanitize_key( $node['widgetType'] ) : '';
			if ( ! in_array( $widget_type, self::ALLOWED_WIDGETS, true ) ) {
				$errors[] = 'disallowed widget: ' . $widget_type;
				return null;
			}
		}

		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$settings = self::sanitize_settings( $widget_type, $settings );

		$style = isset( $node['style'] ) && is_array( $node['style'] ) ? $node['style'] : array();
		$style = self::sanitize_style( $style );

		$elements = array();
		if ( 'container' === $el_type && isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( array_slice( $node['elements'], 0, self::MAX_NODES ) as $child ) {
				$clean_child = self::sanitize_node( $child, $depth + 1, $count, $errors );
				if ( null !== $clean_child ) {
					$elements[] = $clean_child;
				}
			}
		}

		$out = array(
			'id'       => $id,
			'elType'   => $el_type,
			'settings' => $settings,
			'style'    => $style,
			'elements' => $elements,
		);
		if ( 'widget' === $el_type ) {
			$out['widgetType'] = $widget_type;
		}
		return $out;
	}

	/**
	 * Per-widget allow-list. Text-ish fields go through wp_kses_post.
	 */
	private static function sanitize_settings( $widget_type, $settings ) {
		$out = array();
		switch ( $widget_type ) {
			case 'heading':
				$out['text'] = isset( $settings['text'] ) ? wp_kses_post( (string) $settings['text'] ) : '';
				$tag = isset( $settings['tag'] ) ? strtolower( (string) $settings['tag'] ) : 'h2';
				$out['tag'] = in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' ), true ) ? $tag : 'h2';
				break;
			case 'text':
				$out['html'] = isset( $settings['html'] ) ? wp_kses_post( (string) $settings['html'] ) : '';
				break;
			case 'image':
				$out['url'] = isset( $settings['url'] ) ? esc_url_raw( (string) $settings['url'] ) : '';
				$out['alt'] = isset( $settings['alt'] ) ? sanitize_text_field( (string) $settings['alt'] ) : '';
				$out['id']  = isset( $settings['id'] ) ? absint( $settings['id'] ) : 0;
				break;
			case 'button':
				$out['text'] = isset( $settings['text'] ) ? sanitize_text_field( (string) $settings['text'] ) : '';
				$out['url']  = isset( $settings['url'] ) ? esc_url_raw( (string) $settings['url'] ) : '#';
				break;
			case 'video':
				$out['url'] = isset( $settings['url'] ) ? esc_url_raw( (string) $settings['url'] ) : '';
				break;
			case 'icon-box':
				$out['title'] = isset( $settings['title'] ) ? sanitize_text_field( (string) $settings['title'] ) : '';
				$out['desc']  = isset( $settings['desc'] ) ? wp_kses_post( (string) $settings['desc'] ) : '';
				$out['icon']  = isset( $settings['icon'] ) ? sanitize_text_field( (string) $settings['icon'] ) : 'star';
				break;
			case 'tabs':
				$tabs = isset( $settings['tabs'] ) && is_array( $settings['tabs'] ) ? array_slice( $settings['tabs'], 0, 10 ) : array();
				$clean_tabs = array();
				foreach ( $tabs as $t ) {
					if ( ! is_array( $t ) ) {
						continue;
					}
					$clean_tabs[] = array(
						'title'   => isset( $t['title'] ) ? sanitize_text_field( (string) $t['title'] ) : '',
						'content' => isset( $t['content'] ) ? wp_kses_post( (string) $t['content'] ) : '',
					);
				}
				$out['tabs'] = $clean_tabs;
				break;
			case 'form':
				$fields = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? array_slice( $settings['fields'], 0, 20 ) : array();
				$clean_fields = array();
				foreach ( $fields as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
					if ( ! in_array( $type, array( 'text', 'email', 'textarea', 'select' ), true ) ) {
						$type = 'text';
					}
					$options = array();
					if ( 'select' === $type && isset( $f['options'] ) && is_array( $f['options'] ) ) {
						foreach ( array_slice( $f['options'], 0, 20 ) as $o ) {
							$o = sanitize_text_field( (string) $o );
							if ( '' !== $o ) {
								$options[] = $o;
							}
						}
					}
					$name = isset( $f['name'] ) ? sanitize_key( (string) $f['name'] ) : '';
					if ( '' === $name ) {
						$name = 'field_' . count( $clean_fields );
					}
					$clean_fields[] = array(
						'label'    => isset( $f['label'] ) ? sanitize_text_field( (string) $f['label'] ) : $name,
						'name'     => $name,
						'type'     => $type,
						'required' => ! empty( $f['required'] ),
						'options'  => $options,
					);
				}
				$out['fields']     = $clean_fields;
				$out['buttonText'] = isset( $settings['buttonText'] ) ? sanitize_text_field( (string) $settings['buttonText'] ) : 'Send';
				$out['successMsg'] = isset( $settings['successMsg'] ) ? sanitize_text_field( (string) $settings['successMsg'] ) : 'Thanks! We got your message.';
				break;
			case 'loop':
				$type = isset( $settings['postType'] ) ? sanitize_key( (string) $settings['postType'] ) : 'post';
				$pto  = get_post_type_object( $type );
				if ( ! $pto || empty( $pto->public ) ) {
					$type = 'post';
				}
				$order = isset( $settings['order'] ) ? strtoupper( (string) $settings['order'] ) : 'DESC';
				if ( 'ASC' !== $order && 'DESC' !== $order ) {
					$order = 'DESC';
				}
				$orderby = isset( $settings['orderBy'] ) ? sanitize_key( (string) $settings['orderBy'] ) : 'date';
				if ( ! in_array( $orderby, array( 'date', 'title', 'rand' ), true ) ) {
					$orderby = 'date';
				}
				$out['postType']     = $type;
				$out['postsPerPage'] = max( 1, min( 20, isset( $settings['postsPerPage'] ) ? intval( $settings['postsPerPage'] ) : 6 ) );
				$out['columns']      = max( 1, min( 4, isset( $settings['columns'] ) ? intval( $settings['columns'] ) : 3 ) );
				$out['order']        = $order;
				$out['orderBy']      = $orderby;
				$out['showImage']    = ! isset( $settings['showImage'] ) || ! empty( $settings['showImage'] );
				$out['showTitle']    = ! isset( $settings['showTitle'] ) || ! empty( $settings['showTitle'] );
				$out['showExcerpt']  = ! isset( $settings['showExcerpt'] ) || ! empty( $settings['showExcerpt'] );
				break;
			case 'accordion':
				$items = isset( $settings['items'] ) && is_array( $settings['items'] ) ? array_slice( $settings['items'], 0, 20 ) : array();
				$clean_items = array();
				foreach ( $items as $it ) {
					if ( ! is_array( $it ) ) {
						continue;
					}
					$clean_items[] = array(
						'title'   => isset( $it['title'] ) ? sanitize_text_field( (string) $it['title'] ) : '',
						'content' => isset( $it['content'] ) ? wp_kses_post( (string) $it['content'] ) : '',
					);
				}
				$out['items'] = $clean_items;
				break;
			case 'gallery':
				$images = isset( $settings['images'] ) && is_array( $settings['images'] ) ? array_slice( $settings['images'], 0, 30 ) : array();
				$clean_images = array();
				foreach ( $images as $im ) {
					if ( ! is_array( $im ) ) {
						continue;
					}
					$url = isset( $im['url'] ) ? esc_url_raw( (string) $im['url'] ) : '';
					if ( '' === $url ) {
						continue;
					}
					$clean_images[] = array(
						'url' => $url,
						'alt' => isset( $im['alt'] ) ? sanitize_text_field( (string) $im['alt'] ) : '',
						'id'  => isset( $im['id'] ) ? absint( $im['id'] ) : 0,
					);
				}
				$out['images']  = $clean_images;
				$out['columns'] = max( 1, min( 6, isset( $settings['columns'] ) ? intval( $settings['columns'] ) : 3 ) );
				break;
			case 'counter':
				$out['number'] = isset( $settings['number'] ) ? max( 0, min( 1000000000, intval( $settings['number'] ) ) ) : 0;
				$out['prefix'] = isset( $settings['prefix'] ) ? sanitize_text_field( (string) $settings['prefix'] ) : '';
				$out['suffix'] = isset( $settings['suffix'] ) ? sanitize_text_field( (string) $settings['suffix'] ) : '';
				break;
			case 'testimonial':
				$out['quote'] = isset( $settings['quote'] ) ? wp_kses_post( (string) $settings['quote'] ) : '';
				$out['name']  = isset( $settings['name'] ) ? sanitize_text_field( (string) $settings['name'] ) : '';
				$out['role']  = isset( $settings['role'] ) ? sanitize_text_field( (string) $settings['role'] ) : '';
				$out['avatar'] = isset( $settings['avatar'] ) ? esc_url_raw( (string) $settings['avatar'] ) : '';
				break;
			default:
				// divider, spacer: no user HTML.
				break;
		}
		return $out;
	}

	private static function sanitize_style( $style ) {
		$out = array();
		// Keep it small on purpose for v0.1. Numbers clamped to sane ranges.
		if ( isset( $style['layout'] ) && is_array( $style['layout'] ) ) {
			$layout = array();
			if ( isset( $style['layout']['direction'] ) ) {
				$d = (string) $style['layout']['direction'];
				if ( 'row' === $d || 'column' === $d ) {
					$layout['direction'] = $d;
				}
			}
			foreach ( array( 'gap', 'padding', 'radius' ) as $k ) {
				if ( isset( $style['layout'][ $k ] ) ) {
					$layout[ $k ] = max( 0, min( 200, intval( $style['layout'][ $k ] ) ) );
				}
			}
			foreach ( array( 'justify', 'align', 'bg' ) as $k ) {
				if ( isset( $style['layout'][ $k ] ) ) {
					$layout[ $k ] = sanitize_text_field( (string) $style['layout'][ $k ] );
				}
			}
			if ( ! empty( $layout ) ) {
				$out['layout'] = $layout;
			}
		}
		if ( isset( $style['typo'] ) && is_array( $style['typo'] ) ) {
			$typo = array();
			if ( isset( $style['typo']['size'] ) ) {
				$typo['size'] = max( 10, min( 120, intval( $style['typo']['size'] ) ) );
			}
			if ( isset( $style['typo']['weight'] ) ) {
				$typo['weight'] = max( 100, min( 900, intval( $style['typo']['weight'] ) ) );
			}
			if ( isset( $style['typo']['color'] ) ) {
				$typo['color'] = sanitize_hex_color( (string) $style['typo']['color'] ) ? (string) $style['typo']['color'] : '#0f172a';
			}
			if ( ! empty( $typo ) ) {
				$out['typo'] = $typo;
			}
		}
		foreach ( array( 'tablet', 'mobile' ) as $bp ) {
			if ( isset( $style[ $bp ] ) && is_array( $style[ $bp ] ) ) {
				$out[ $bp ] = self::sanitize_style( $style[ $bp ] );
			}
			if ( isset( $style['responsive'][ $bp ] ) && is_array( $style['responsive'][ $bp ] ) ) {
				if ( ! isset( $out['responsive'] ) ) {
					$out['responsive'] = array();
				}
				$out['responsive'][ $bp ] = self::sanitize_style( $style['responsive'][ $bp ] );
			}
		}
		return $out;
	}

	private static function sanitize_color_map( $colors ) {
		if ( ! is_array( $colors ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $colors, 0, 20 ) as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( sanitize_hex_color( (string) $v ) ) {
				$out[ $k ] = (string) $v;
			}
		}
		return $out;
	}
}
