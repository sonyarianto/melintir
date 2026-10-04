<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP mirror of core/css.rs generate_css().
 * Selector convention: .mel-{node_id}
 * Must stay in sync with Rust + editor/src/cssFallback.ts
 */
class Renderer {

	/**
	 * Full page HTML for the_content filter.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function render_page( $post_id ) {
		$doc_json = get_post_meta( $post_id, MELINTIR_META_DATA, true );
		if ( empty( $doc_json ) ) {
			return '';
		}
		$doc = is_string( $doc_json ) ? json_decode( $doc_json, true ) : $doc_json;
		if ( ! is_array( $doc ) || ! isset( $doc['root'] ) ) {
			return '';
		}
		$html = self::render_node( $doc['root'] );
		if ( '' === $html ) {
			return '';
		}
		return '<div class="mel-page" data-melintir="' . esc_attr( $post_id ) . '">' . $html . '</div>';
	}

	/**
	 * @param array $node
	 * @return string
	 */
	public static function render_node( $node ) {
		if ( ! is_array( $node ) || ! isset( $node['id'], $node['elType'] ) ) {
			return '';
		}
		$id   = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $node['id'] );
		$cls  = 'mel-' . $id;
		$el   = isset( $node['elType'] ) ? (string) $node['elType'] : '';
		$sett = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();

		if ( 'container' === $el ) {
			$inner = '';
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				foreach ( $node['elements'] as $child ) {
					$inner .= self::render_node( $child );
				}
			}
			return '<div class="mel-container ' . esc_attr( $cls ) . '">' . $inner . '</div>';
		}

		$type = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';
		switch ( $type ) {
			case 'heading':
				$tag  = isset( $sett['tag'] ) ? (string) $sett['tag'] : 'h2';
				if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' ), true ) ) {
					$tag = 'h2';
				}
				$text = isset( $sett['text'] ) ? wp_kses_post( (string) $sett['text'] ) : '';
				return '<' . $tag . ' class="mel-heading ' . esc_attr( $cls ) . '">' . $text . '</' . $tag . '>';
			case 'text':
				$html = isset( $sett['html'] ) ? wp_kses_post( (string) $sett['html'] ) : '';
				return '<div class="mel-text ' . esc_attr( $cls ) . '">' . $html . '</div>';
			case 'image':
				$url = isset( $sett['url'] ) ? esc_url( (string) $sett['url'] ) : '';
				$alt = isset( $sett['alt'] ) ? esc_attr( (string) $sett['alt'] ) : '';
				if ( '' === $url ) {
					return '';
				}
				return '<figure class="mel-image ' . esc_attr( $cls ) . '"><img src="' . $url . '" alt="' . $alt . '" loading="lazy" /></figure>';
			case 'button':
				$text = isset( $sett['text'] ) ? esc_html( (string) $sett['text'] ) : '';
				$url  = isset( $sett['url'] ) ? esc_url( (string) $sett['url'] ) : '#';
				return '<div class="mel-btn-wrap ' . esc_attr( $cls ) . '"><a class="mel-btn" href="' . $url . '">' . $text . '</a></div>';
			case 'video':
				$url = isset( $sett['url'] ) ? esc_url( (string) $sett['url'] ) : '';
				if ( '' === $url ) {
					return '';
				}
				// oEmbed for youtube/vimeo, plain video tag otherwise.
				$embed = wp_oembed_get( $url );
				if ( $embed ) {
					return '<div class="mel-video ' . esc_attr( $cls ) . '">' . $embed . '</div>';
				}
				return '<div class="mel-video ' . esc_attr( $cls ) . '"><video src="' . $url . '" controlspreload="none"></video></div>';
			case 'divider':
				return '<hr class="mel-divider ' . esc_attr( $cls ) . '" />';
			case 'spacer':
				return '<div class="mel-spacer ' . esc_attr( $cls ) . '" aria-hidden="true"></div>';
			case 'icon-box':
				$title = isset( $sett['title'] ) ? esc_html( (string) $sett['title'] ) : '';
				$desc  = isset( $sett['desc'] ) ? wp_kses_post( (string) $sett['desc'] ) : '';
				$icon  = isset( $sett['icon'] ) ? esc_attr( (string) $sett['icon'] ) : 'star';
				return '<div class="mel-iconbox ' . esc_attr( $cls ) . '"><span class="mel-icon" data-icon="' . $icon . '"></span><h3>' . $title . '</h3><div>' . $desc . '</div></div>';
			case 'tabs':
				$tabs = isset( $sett['tabs'] ) && is_array( $sett['tabs'] ) ? $sett['tabs'] : array();
				$out  = '<div class="mel-tabs ' . esc_attr( $cls ) . '" data-tabs>';
				$out .= '<div class="mel-tabs-nav" role="tablist">';
				foreach ( array_values( $tabs ) as $i => $t ) {
					$title = isset( $t['title'] ) ? esc_html( (string) $t['title'] ) : 'Tab';
					$out  .= '<button role="tab" data-tab="' . esc_attr( (string) $i ) . '">' . $title . '</button>';
				}
				$out .= '</div><div class="mel-tabs-panels">';
				foreach ( array_values( $tabs ) as $i => $t ) {
					$content = isset( $t['content'] ) ? wp_kses_post( (string) $t['content'] ) : '';
					$hidden  = 0 === $i ? '' : ' hidden';
					$out    .= '<div role="tabpanel" data-panel="' . esc_attr( (string) $i ) . '"' . $hidden . '>' . $content . '</div>';
				}
				return $out . '</div></div>';
			case 'form':
				return self::render_form( $id, $cls, $sett );
			case 'loop':
				return self::render_loop( $cls, $sett );
			default:
				return '';
		}
	}

	/**
	 * Plain-POST form (no JS required): posts to admin-post.php, handler
	 * in Form.php validates, stores an entry, redirects back with ?melintir_sent.
	 *
	 * @param string $id
	 * @param string $cls
	 * @param array  $sett
	 * @return string
	 */
	private static function render_form( $id, $cls, $sett ) {
		$fields  = isset( $sett['fields'] ) && is_array( $sett['fields'] ) ? $sett['fields'] : array();
		$btn     = isset( $sett['buttonText'] ) && '' !== $sett['buttonText'] ? $sett['buttonText'] : 'Send';
		$success = isset( $sett['successMsg'] ) ? $sett['successMsg'] : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		if ( isset( $_GET['melintir_sent'] ) && $id === preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $_GET['melintir_sent'] ) ) {
			return '<div class="mel-form-sent ' . esc_attr( $cls ) . '">' . esc_html( $success ) . '</div>';
		}

		$out  = '<form class="mel-form ' . esc_attr( $cls ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="melintir_submit" />';
		$out .= '<input type="hidden" name="mel_node" value="' . esc_attr( $id ) . '" />';
		$out .= '<input type="hidden" name="mel_post" value="' . esc_attr( (string) get_the_ID() ) . '" />';
		$out .= wp_nonce_field( 'melintir_form', 'melintir_nonce', true, false );
		// Honeypot: humans never fill this.
		$out .= '<input type="text" name="mel_website" value="" style="display:none" tabindex="-1" autocomplete="off" />';
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['name'], $f['type'] ) ) {
				continue;
			}
			$name     = esc_attr( (string) $f['name'] );
			$label    = isset( $f['label'] ) ? esc_html( (string) $f['label'] ) : $name;
			$req      = ! empty( $f['required'] ) ? ' required' : '';
			$req_mark = ! empty( $f['required'] ) ? ' *' : '';
			$out     .= '<label class="mel-field"><span>' . $label . $req_mark . '</span>';
			switch ( $f['type'] ) {
				case 'textarea':
					$out .= '<textarea name="mel_f[' . $name . ']"' . $req . '></textarea>';
					break;
				case 'select':
					$out .= '<select name="mel_f[' . $name . ']"' . $req . '>';
					foreach ( (array) ( isset( $f['options'] ) ? $f['options'] : array() ) as $o ) {
						$out .= '<option>' . esc_html( (string) $o ) . '</option>';
					}
					$out .= '</select>';
					break;
				case 'email':
					$out .= '<input type="email" name="mel_f[' . $name . ']"' . $req . ' />';
					break;
				default:
					$out .= '<input type="text" name="mel_f[' . $name . ']"' . $req . ' />';
					break;
			}
			$out .= '</label>';
		}
		$out .= '<button type="submit" class="mel-btn">' . esc_html( $btn ) . '</button></form>';
		return $out;
	}

	/**
	 * Generate CSS for a sanitized doc. Keep in sync with core/css.rs.
	 *
	 * @param array $doc
	 * @return string
	 */
	public static function generate_css( $doc ) {
		if ( ! is_array( $doc ) || ! isset( $doc['root'] ) ) {
			return '';
		}
		$css = ".mel-page{box-sizing:border-box}.mel-container{display:flex;flex-direction:column}\n";
		$css .= ".mel-loop{display:grid;gap:16px}.mel-cols-1{grid-template-columns:1fr}.mel-cols-2{grid-template-columns:repeat(2,1fr)}.mel-cols-3{grid-template-columns:repeat(3,1fr)}.mel-cols-4{grid-template-columns:repeat(4,1fr)}@media(max-width:767px){.mel-loop{grid-template-columns:1fr}}\n";
		$css .= ".mel-card{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff}.mel-card-img img{width:100%;height:auto;display:block}.mel-card-title{font-size:18px;margin:12px 12px 4px}.mel-card-ex{font-size:14px;color:#475569;margin:0 12px 12px}\n";
		$css .= self::node_css( $doc['root'] );
		// Cap to avoid runaway postmeta.
		if ( strlen( $css ) > 100 * 1024 ) {
			$css = substr( $css, 0, 100 * 1024 );
		}
		return $css;
	}

	private static function node_css( $node ) {
		if ( ! is_array( $node ) || ! isset( $node['id'] ) ) {
			return '';
		}
		$id  = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $node['id'] );
		$sel = '.mel-' . $id;
		$css = '';

		$style = isset( $node['style'] ) && is_array( $node['style'] ) ? $node['style'] : array();
		$decl  = self::style_decls( $style );
		if ( '' !== $decl ) {
			$css .= $sel . '{' . $decl . "}\n";
		}
		foreach ( array( 'tablet' => 1024, 'mobile' => 767 ) as $bp => $max ) {
			$bp_style = null;
			if ( isset( $style[ $bp ] ) && is_array( $style[ $bp ] ) ) {
				$bp_style = $style[ $bp ];
			} elseif ( isset( $style['responsive'][ $bp ] ) && is_array( $style['responsive'][ $bp ] ) ) {
				$bp_style = $style['responsive'][ $bp ];
			}
			if ( $bp_style ) {
				$bp_decl = self::style_decls( $bp_style );
				if ( '' !== $bp_decl ) {
					$css .= '@media(max-width:' . $max . 'px){' . $sel . '{' . $bp_decl . "}}\n";
				}
			}
		}

		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				$css .= self::node_css( $child );
			}
		}
		return $css;
	}

	private static function style_decls( $style ) {
		$d = '';
		if ( isset( $style['layout'] ) && is_array( $style['layout'] ) ) {
			$l = $style['layout'];
			if ( isset( $l['direction'] ) && ( 'row' === $l['direction'] || 'column' === $l['direction'] ) ) {
				$d .= 'flex-direction:' . $l['direction'] . ';';
			}
			if ( isset( $l['gap'] ) ) {
				$d .= 'gap:' . intval( $l['gap'] ) . 'px;';
			}
			if ( isset( $l['justify'] ) ) {
				$d .= 'justify-content:' . sanitize_text_field( (string) $l['justify'] ) . ';';
			}
			if ( isset( $l['align'] ) ) {
				$d .= 'align-items:' . sanitize_text_field( (string) $l['align'] ) . ';';
			}
			if ( isset( $l['bg'] ) ) {
				$d .= 'background:' . sanitize_text_field( (string) $l['bg'] ) . ';';
			}
			if ( isset( $l['padding'] ) ) {
				$d .= 'padding:' . intval( $l['padding'] ) . 'px;';
			}
			if ( isset( $l['radius'] ) ) {
				$d .= 'border-radius:' . intval( $l['radius'] ) . 'px;';
			}
		}
		if ( isset( $style['typo'] ) && is_array( $style['typo'] ) ) {
			$t = $style['typo'];
			if ( isset( $t['size'] ) ) {
				$d .= 'font-size:' . intval( $t['size'] ) . 'px;';
			}
			if ( isset( $t['weight'] ) ) {
				$d .= 'font-weight:' . intval( $t['weight'] ) . ';';
			}
			if ( isset( $t['color'] ) ) {
				$d .= 'color:' . sanitize_text_field( (string) $t['color'] ) . ';';
			}
		}
		return $d;
	}

	/**
	 * Post grid. Query is capped and sanitized upstream; output escaped here.
	 *
	 * @param string $cls
	 * @param array  $sett
	 * @return string
	 */
	private static function render_loop( $cls, $sett ) {
		$q = new \WP_Query(
			array(
				'post_type'      => isset( $sett['postType'] ) ? (string) $sett['postType'] : 'post',
				'posts_per_page' => isset( $sett['postsPerPage'] ) ? intval( $sett['postsPerPage'] ) : 6,
				'orderby'        => isset( $sett['orderBy'] ) ? (string) $sett['orderBy'] : 'date',
				'order'          => isset( $sett['order'] ) ? (string) $sett['order'] : 'DESC',
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			)
		);
		$cols = isset( $sett['columns'] ) ? max( 1, min( 4, intval( $sett['columns'] ) ) ) : 3;
		$out  = '<div class="mel-loop mel-cols-' . $cols . ' ' . esc_attr( $cls ) . '">';
		if ( ! $q->have_posts() ) {
			$out .= '<p class="mel-loop-empty">' . esc_html__( 'No posts found.', 'melintir' ) . '</p>';
		}
		while ( $q->have_posts() ) {
			$q->the_post();
			$out .= '<article class="mel-card">';
			if ( ! empty( $sett['showImage'] ) && has_post_thumbnail() ) {
				$out .= '<a class="mel-card-img" href="' . esc_url( get_permalink() ) . '">' . get_the_post_thumbnail( get_the_ID(), 'medium' ) . '</a>';
			}
			if ( ! empty( $sett['showTitle'] ) ) {
				$out .= '<h3 class="mel-card-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
			}
			if ( ! empty( $sett['showExcerpt'] ) ) {
				$out .= '<div class="mel-card-ex">' . esc_html( wp_trim_words( get_the_excerpt(), 20 ) ) . '</div>';
			}
			$out .= '</article>';
		}
		wp_reset_postdata();
		return $out . '</div>';
	}
}
