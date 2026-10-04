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
	 * Resolve {{dynamic_tags}} against the current post + site.
	 * Runs BEFORE escaping/kses at each call site, so resolved values
	 * get the same sanitization as authored text.
	 *
	 * Supported: site_title, site_tagline, post_title, post_date,
	 * post_excerpt, author_name. Unknown tags are left in place.
	 *
	 * @param string $str
	 * @return string
	 */
	public static function dynamic_tags( $str ) {
		if ( ! is_string( $str ) || false === strpos( $str, '{{' ) ) {
			return $str;
		}
		if ( ! preg_match_all( '/\{\{([a-z_]+)\}\}/', $str, $m ) ) {
			return $str;
		}
		$post = get_post();
		foreach ( array_unique( $m[1] ) as $tag ) {
			$value = null;
			switch ( $tag ) {
				case 'site_title':
					$value = get_bloginfo( 'name' );
					break;
				case 'site_tagline':
					$value = get_bloginfo( 'description' );
					break;
				case 'post_title':
					$value = $post ? get_the_title( $post ) : '';
					break;
				case 'post_date':
					$value = $post ? get_the_date( '', $post ) : '';
					break;
				case 'post_excerpt':
					$value = $post ? get_the_excerpt( $post ) : '';
					break;
				case 'author_name':
					$value = $post ? get_the_author_meta( 'display_name', $post->post_author ) : '';
					break;
			}
			if ( null !== $value ) {
				$str = str_replace( '{{' . $tag . '}}', (string) $value, $str );
			}
		}
		return $str;
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
				$text = isset( $sett['text'] ) ? wp_kses_post( self::dynamic_tags( (string) $sett['text'] ) ) : '';
				return '<' . $tag . ' class="mel-heading ' . esc_attr( $cls ) . '">' . $text . '</' . $tag . '>';
			case 'text':
				$html = isset( $sett['html'] ) ? wp_kses_post( self::dynamic_tags( (string) $sett['html'] ) ) : '';
				return '<div class="mel-text ' . esc_attr( $cls ) . '">' . $html . '</div>';
			case 'image':
				$url = isset( $sett['url'] ) ? esc_url( (string) $sett['url'] ) : '';
				$alt = isset( $sett['alt'] ) ? esc_attr( (string) $sett['alt'] ) : '';
				if ( '' === $url ) {
					return '';
				}
				return '<figure class="mel-image ' . esc_attr( $cls ) . '"><img src="' . $url . '" alt="' . $alt . '" loading="lazy" /></figure>';
			case 'button':
				$text = isset( $sett['text'] ) ? esc_html( self::dynamic_tags( (string) $sett['text'] ) ) : '';
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
				$title = isset( $sett['title'] ) ? esc_html( self::dynamic_tags( (string) $sett['title'] ) ) : '';
				$desc  = isset( $sett['desc'] ) ? wp_kses_post( self::dynamic_tags( (string) $sett['desc'] ) ) : '';
				$icon  = isset( $sett['icon'] ) ? esc_attr( (string) $sett['icon'] ) : 'star';
				return '<div class="mel-iconbox ' . esc_attr( $cls ) . '"><span class="mel-icon" data-icon="' . $icon . '"></span><h3>' . $title . '</h3><div>' . $desc . '</div></div>';
			case 'tabs':
				$tabs = isset( $sett['tabs'] ) && is_array( $sett['tabs'] ) ? $sett['tabs'] : array();
				$out  = '<div class="mel-tabs ' . esc_attr( $cls ) . '" data-tabs>';
				$out .= '<div class="mel-tabs-nav" role="tablist">';
				foreach ( array_values( $tabs ) as $i => $t ) {
					$title = isset( $t['title'] ) ? esc_html( self::dynamic_tags( (string) $t['title'] ) ) : 'Tab';
					$out  .= '<button role="tab" data-tab="' . esc_attr( (string) $i ) . '">' . $title . '</button>';
				}
				$out .= '</div><div class="mel-tabs-panels">';
				foreach ( array_values( $tabs ) as $i => $t ) {
					$content = isset( $t['content'] ) ? wp_kses_post( self::dynamic_tags( (string) $t['content'] ) ) : '';
					$hidden  = 0 === $i ? '' : ' hidden';
					$out    .= '<div role="tabpanel" data-panel="' . esc_attr( (string) $i ) . '"' . $hidden . '>' . $content . '</div>';
				}
				return $out . '</div></div>';
			case 'form':
				return self::render_form( $id, $cls, $sett );
		case 'loop':
			return self::render_loop( $cls, $sett );
		case 'products':
			return self::render_products( $cls, $sett );
		case 'product-title':
			return self::render_product_title( $cls, $sett );
		case 'product-price':
			return self::render_product_price( $cls );
		case 'product-cart':
			return self::render_product_cart( $cls );
		case 'product-rating':
			return self::render_product_rating( $cls );
		case 'product-image':
			return self::render_product_image( $cls, $sett );
		case 'product-excerpt':
			return self::render_product_excerpt( $cls );
		case 'menu-cart':
			return self::render_menu_cart( $cls, $sett );
		case 'woo-cart':
			return self::render_woo_shortcode( $cls, 'cart' );
		case 'woo-checkout':
			return self::render_woo_shortcode( $cls, 'checkout' );
			case 'accordion':
				$items = isset( $sett['items'] ) && is_array( $sett['items'] ) ? $sett['items'] : array();
				$out   = '<div class="mel-accordion ' . esc_attr( $cls ) . '">';
				foreach ( array_values( $items ) as $i => $it ) {
					$title   = isset( $it['title'] ) ? esc_html( self::dynamic_tags( (string) $it['title'] ) ) : '';
					$content = isset( $it['content'] ) ? wp_kses_post( self::dynamic_tags( (string) $it['content'] ) ) : '';
					$open    = 0 === $i ? ' open' : '';
					$out    .= '<details class="mel-acc-item"' . $open . '><summary>' . $title . '</summary><div>' . $content . '</div></details>';
				}
				return $out . '</div>';
			case 'gallery':
				$images = isset( $sett['images'] ) && is_array( $sett['images'] ) ? $sett['images'] : array();
				$gcols  = isset( $sett['columns'] ) ? max( 1, min( 6, intval( $sett['columns'] ) ) ) : 3;
				$out    = '<div class="mel-gallery mel-gcols-' . $gcols . ' ' . esc_attr( $cls ) . '">';
				foreach ( $images as $im ) {
					$url = isset( $im['url'] ) ? esc_url( (string) $im['url'] ) : '';
					$alt = isset( $im['alt'] ) ? esc_attr( (string) $im['alt'] ) : '';
					if ( '' === $url ) {
						continue;
					}
					$out .= '<figure class="mel-gimg"><img src="' . $url . '" alt="' . $alt . '" loading="lazy" /></figure>';
				}
				return $out . '</div>';
			case 'counter':
				$num = isset( $sett['number'] ) ? intval( $sett['number'] ) : 0;
				$pre = isset( $sett['prefix'] ) ? esc_html( self::dynamic_tags( (string) $sett['prefix'] ) ) : '';
				$suf = isset( $sett['suffix'] ) ? esc_html( self::dynamic_tags( (string) $sett['suffix'] ) ) : '';
				return '<div class="mel-counter ' . esc_attr( $cls ) . '">' . $pre . '<span data-count="' . $num . '">0</span>' . $suf . '</div>';
			case 'testimonial':
				$quote  = isset( $sett['quote'] ) ? wp_kses_post( self::dynamic_tags( (string) $sett['quote'] ) ) : '';
				$name   = isset( $sett['name'] ) ? esc_html( self::dynamic_tags( (string) $sett['name'] ) ) : '';
				$role   = isset( $sett['role'] ) ? esc_html( self::dynamic_tags( (string) $sett['role'] ) ) : '';
				$avatar = isset( $sett['avatar'] ) ? esc_url( (string) $sett['avatar'] ) : '';
				$out    = '<figure class="mel-testimonial ' . esc_attr( $cls ) . '"><blockquote>' . $quote . '</blockquote><figcaption>';
				if ( '' !== $avatar ) {
					$out .= '<img class="mel-tavatar" src="' . $avatar . '" alt="" loading="lazy" />';
				}
				$out .= '<span class="mel-tname">' . $name . '</span>';
				if ( '' !== $role ) {
					$out .= '<span class="mel-trole">' . $role . '</span>';
				}
				return $out . '</figcaption></figure>';
			case 'nav':
				return self::render_nav( $id, $cls, $sett );
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
		$out .= '<button type="submit" class="mel-btn">' . esc_html( $btn ) . '</button>';
		// Cloudflare Turnstile widget (only when the form opts in and keys exist).
		$sitekey = get_option( 'melintir_turnstile_sitekey', '' );
		if ( ! empty( $sett['turnstile'] ) && '' !== $sitekey ) {
			$out .= '<div class="cf-turnstile" data-sitekey="' . esc_attr( $sitekey ) . '"></div>';
			$out .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
		}
		return $out . '</form>';
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
		$css .= self::globals_css( $doc );
		$css .= ".mel-loop{display:grid;gap:16px}.mel-cols-1{grid-template-columns:1fr}.mel-cols-2{grid-template-columns:repeat(2,1fr)}.mel-cols-3{grid-template-columns:repeat(3,1fr)}.mel-cols-4{grid-template-columns:repeat(4,1fr)}@media(max-width:767px){.mel-loop{grid-template-columns:1fr}}\n";
		$css .= ".mel-card{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff}.mel-card-img img{width:100%;height:auto;display:block}.mel-card-title{font-size:18px;margin:12px 12px 4px}.mel-card-ex{font-size:14px;color:#475569;margin:0 12px 12px}\n";
		$css .= ".mel-badge{display:inline-block;background:#dc2626;color:#fff;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px;margin:8px 12px 0}.mel-price{font-size:16px;font-weight:700;margin:4px 12px}.mel-price del{color:#94a3b8;font-weight:400;margin-right:6px}.mel-price ins{text-decoration:none;background:none}.mel-stars{margin:0 12px;font-size:14px;color:#f59e0b;letter-spacing:2px}.mel-addcart{display:inline-block;background:#2563eb;color:#fff;font-weight:600;padding:8px 16px;border-radius:8px;text-decoration:none;margin:8px 12px 12px}\n";
		$css .= ".mel-ptitle{font-size:32px;margin:0 0 8px}.mel-pexcerpt{color:#475569;margin:8px 0}.mel-pimg img{width:100%;height:auto;display:block;border-radius:8px}.mel-pthumbs{display:flex;gap:8px;margin-top:8px}.mel-pthumbs img{width:72px;height:auto;border-radius:6px}\n";
		$css .= ".mel-menucart{display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:inherit;font-weight:600}.mel-cart-icon{font-size:20px}.mel-cart-count{display:inline-block;min-width:20px;text-align:center;background:#2563eb;color:#fff;font-size:12px;font-weight:700;border-radius:999px;padding:1px 6px}.mel-cart-total{font-size:14px;color:#475569}\n";
		$css .= ".mel-gallery{display:grid;gap:12px}.mel-gcols-1{grid-template-columns:1fr}.mel-gcols-2{grid-template-columns:repeat(2,1fr)}.mel-gcols-3{grid-template-columns:repeat(3,1fr)}.mel-gcols-4{grid-template-columns:repeat(4,1fr)}.mel-gcols-5{grid-template-columns:repeat(5,1fr)}.mel-gcols-6{grid-template-columns:repeat(6,1fr)}@media(max-width:767px){.mel-gallery{grid-template-columns:repeat(2,1fr)}}\n";
		$css .= ".mel-gimg img{width:100%;height:auto;display:block;border-radius:8px}.mel-acc-item{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px}.mel-acc-item summary{cursor:pointer;padding:12px 16px;font-weight:600}.mel-acc-item summary+div{padding:0 16px 12px}.mel-counter{font-size:40px;font-weight:800}.mel-testimonial{border-left:4px solid #2563eb;padding:8px 16px;margin:0}.mel-testimonial blockquote{margin:0 0 8px;font-style:italic}.mel-tavatar{width:40px;height:40px;border-radius:50%;vertical-align:middle;margin-right:8px}.mel-tname{font-weight:700}.mel-trole{color:#64748b;margin-left:8px}\n";
		$css .= ".mel-nav-list{display:flex;gap:16px;list-style:none;margin:0;padding:0}.mel-nav-vertical .mel-nav-list{flex-direction:column}.mel-nav-list a{text-decoration:none;color:inherit}.mel-nav-sub{list-style:none;margin:4px 0 0 12px;padding:0}.mel-nav-check{display:none}.mel-nav-burger{display:none;cursor:pointer;font-size:24px}@media(max-width:767px){.mel-has-toggle .mel-nav-burger{display:block}.mel-has-toggle .mel-nav-list{display:none;flex-direction:column}.mel-has-toggle .mel-nav-check:checked+.mel-nav-burger+.mel-nav-list{display:flex}}\n";
		$css .= self::node_css( $doc['root'] );
		// Cap to avoid runaway postmeta.
		if ( strlen( $css ) > 100 * 1024 ) {
			$css = substr( $css, 0, 100 * 1024 );
		}
		return $css;
	}

	/**
	 * :root variables from the global palette. Names/values re-validated
	 * here so pre-existing or hand-edited postmeta can't inject rules.
	 *
	 * @param array $doc
	 * @return string
	 */
	private static function globals_css( $doc ) {
		$colors = isset( $doc['globals']['colors'] ) && is_array( $doc['globals']['colors'] ) ? $doc['globals']['colors'] : array();
		$decls  = '';
		foreach ( array_slice( $colors, 0, 20 ) as $name => $value ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name || ! sanitize_hex_color( (string) $value ) ) {
				continue;
			}
			$decls .= '--mel-' . $name . ':' . (string) $value . ';';
		}
		$fonts = isset( $doc['globals']['fonts'] ) && is_array( $doc['globals']['fonts'] ) ? $doc['globals']['fonts'] : array();
		foreach ( array_slice( $fonts, 0, 20 ) as $name => $stack ) {
			$name  = sanitize_key( (string) $name );
			$stack = Security::sanitize_font( $stack );
			if ( '' === $name || '' === $stack ) {
				continue;
			}
			// Curated keys resolve to their stacks; custom stacks pass through.
			if ( isset( Security::FONT_STACKS[ $stack ] ) ) {
				$stack = Security::FONT_STACKS[ $stack ];
			}
			$decls .= '--mel-font-' . $name . ':' . $stack . ';';
		}
		return '' !== $decls ? ':root{' . $decls . "}\n" : '';
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
				$bg = Security::sanitize_color( $l['bg'] );
				if ( '' !== $bg ) {
					$d .= 'background:' . $bg . ';';
				}
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
				$tc = Security::sanitize_color( $t['color'] );
				if ( '' !== $tc ) {
					$d .= 'color:' . $tc . ';';
				}
			}
			if ( isset( $t['family'] ) ) {
				$tf = Security::sanitize_font( $t['family'] );
				if ( '' !== $tf ) {
					if ( isset( Security::FONT_STACKS[ $tf ] ) ) {
						$tf = Security::FONT_STACKS[ $tf ];
					}
					$d .= 'font-family:' . $tf . ';';
				}
			}
		}
		if ( isset( $style['customCss'] ) && is_string( $style['customCss'] ) ) {
			// Already sanitized on save; scoped to this node's selector by the caller.
			$d .= rtrim( trim( $style['customCss'] ), ';' ) . ';';
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

	/**
	 * Product grid (Woo lite). Server-rendered from real store data so the
	 * visitor gets SEO-friendly HTML with zero extra JS; degrades to a
	 * notice when WooCommerce is inactive. Variable products get their
	 * price range + a view-product button automatically.
	 *
	 * @param string $cls
	 * @param array  $sett
	 * @return string
	 */
	private static function render_products( $cls, $sett ) {
		if ( ! function_exists( 'wc_get_product' ) || ! post_type_exists( 'product' ) ) {
			return '<p class="mel-loop-empty ' . esc_attr( $cls ) . '">' . esc_html__( 'Install and activate WooCommerce to display products.', 'melintir' ) . '</p>';
		}
		$count = isset( $sett['count'] ) ? max( 1, min( 20, intval( $sett['count'] ) ) ) : 8;
		$cols  = isset( $sett['columns'] ) ? max( 1, min( 4, intval( $sett['columns'] ) ) ) : 4;
		$order = ( isset( $sett['order'] ) && 'ASC' === strtoupper( (string) $sett['order'] ) ) ? 'ASC' : 'DESC';
		$args  = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'order'          => $order,
			'no_found_rows'  => true,
		);
		switch ( isset( $sett['orderBy'] ) ? (string) $sett['orderBy'] : 'date' ) {
			case 'price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price'; // phpcs:ignore
				break;
			case 'rating':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_average_rating'; // phpcs:ignore
				break;
			case 'popularity':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = 'total_sales'; // phpcs:ignore
				break;
			case 'title':
				$args['orderby'] = 'title';
				break;
			default:
				$args['orderby'] = 'date';
		}
		$cat = isset( $sett['category'] ) ? absint( $sett['category'] ) : 0;
		if ( $cat > 0 ) {
			$args['tax_query'] = array( // phpcs:ignore
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array( $cat ),
				),
			);
		}
		$q   = new \WP_Query( $args );
		$out = '<div class="mel-loop mel-cols-' . $cols . ' ' . esc_attr( $cls ) . '">';
		if ( ! $q->have_posts() ) {
			$out .= '<p class="mel-loop-empty">' . esc_html__( 'No products found.', 'melintir' ) . '</p>';
		}
		while ( $q->have_posts() ) {
			$q->the_post();
			$product = wc_get_product( get_the_ID() );
			if ( ! $product ) {
				continue;
			}
			$out .= '<article class="mel-card">';
			if ( ! empty( $sett['showImage'] ) ) {
				$img = get_the_post_thumbnail( get_the_ID(), 'woocommerce_thumbnail' );
				if ( '' === $img && function_exists( 'wc_placeholder_img' ) ) {
					$img = wc_placeholder_img( 'woocommerce_thumbnail' );
				}
				$out .= '<a class="mel-card-img" href="' . esc_url( get_permalink() ) . '">' . $img . '</a>';
			}
			if ( ! empty( $sett['showBadge'] ) && $product->is_on_sale() ) {
				$out .= '<span class="mel-badge">' . esc_html__( 'Sale!', 'melintir' ) . '</span>';
			}
			if ( ! empty( $sett['showTitle'] ) ) {
				$out .= '<h3 class="mel-card-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
			}
			if ( ! empty( $sett['showRating'] ) && function_exists( 'wc_get_rating_html' ) ) {
				$stars = wc_get_rating_html( $product->get_average_rating(), $product->get_rating_count() );
				if ( '' !== $stars ) {
					$out .= '<div class="mel-stars">' . $stars . '</div>';
				}
			}
			if ( ! empty( $sett['showPrice'] ) ) {
				$out .= '<div class="mel-price">' . wp_kses_post( $product->get_price_html() ) . '</div>';
			}
			if ( ! empty( $sett['showCart'] ) && $product->is_purchasable() && $product->is_in_stock() ) {
				if ( 'simple' === $product->get_type() && $product->supports( 'ajax_add_to_cart' ) ) {
					$out .= '<a href="' . esc_url( $product->add_to_cart_url() ) . '" data-quantity="1" class="mel-addcart add_to_cart_button ajax_add_to_cart" data-product_id="' . esc_attr( (string) get_the_ID() ) . '" data-product_sku="' . esc_attr( $product->get_sku() ) . '">' . esc_html( $product->add_to_cart_text() ) . '</a>';
				} else {
					$out .= '<a href="' . esc_url( $product->add_to_cart_url() ) . '" class="mel-addcart">' . esc_html( $product->add_to_cart_text() ) . '</a>';
				}
			}
			$out .= '</article>';
		}
		wp_reset_postdata();
		return $out . '</div>';
	}

	/**
	 * The product the product-* widgets describe: single-product pages with
	 * WooCommerce active only. Anywhere else they render nothing (the editor
	 * shows its own placeholders), so templates can never leak wrong data.
	 *
	 * @return \WC_Product|null
	 */
	private static function current_product() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		if ( ! ( function_exists( 'is_product' ) && is_product() ) ) {
			return null;
		}
		$product = wc_get_product( get_the_ID() );
		return $product ? $product : null;
	}

	private static function render_product_title( $cls, $sett ) {
		$product = self::current_product();
		if ( ! $product ) {
			return '';
		}
		$tag = isset( $sett['tag'] ) ? (string) $sett['tag'] : 'h1';
		if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' ), true ) ) {
			$tag = 'h1';
		}
		return '<' . $tag . ' class="mel-ptitle ' . esc_attr( $cls ) . '">' . esc_html( $product->get_name() ) . '</' . $tag . '>';
	}

	private static function render_product_price( $cls ) {
		$product = self::current_product();
		if ( ! $product ) {
			return '';
		}
		return '<div class="mel-price ' . esc_attr( $cls ) . '">' . wp_kses_post( $product->get_price_html() ) . '</div>';
	}

	private static function render_product_rating( $cls ) {
		$product = self::current_product();
		if ( ! $product || ! function_exists( 'wc_get_rating_html' ) ) {
			return '';
		}
		$stars = wc_get_rating_html( $product->get_average_rating(), $product->get_rating_count() );
		if ( '' === $stars ) {
			return '';
		}
		return '<div class="mel-stars ' . esc_attr( $cls ) . '">' . $stars . '</div>';
	}

	private static function render_product_cart( $cls ) {
		$product = self::current_product();
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return '';
		}
		// Variable products get Woo's own variation form (attribute dropdowns,
		// quantity, variation JSON), so Woo's scripts enhance it natively and
		// new Woo versions keep working without Melintir changes.
		if ( 'variable' === $product->get_type() && function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
			$GLOBALS['product'] = $product;
			ob_start();
			woocommerce_template_single_add_to_cart();
			$form = ob_get_clean();
			return '<div class="' . esc_attr( $cls ) . '">' . $form . '</div>';
		}
		if ( 'simple' === $product->get_type() && $product->supports( 'ajax_add_to_cart' ) ) {
			return '<a href="' . esc_url( $product->add_to_cart_url() ) . '" data-quantity="1" class="mel-addcart add_to_cart_button ajax_add_to_cart ' . esc_attr( $cls ) . '" data-product_id="' . esc_attr( (string) $product->get_id() ) . '" data-product_sku="' . esc_attr( $product->get_sku() ) . '">' . esc_html( $product->add_to_cart_text() ) . '</a>';
		}
		return '<a href="' . esc_url( $product->add_to_cart_url() ) . '" class="mel-addcart ' . esc_attr( $cls ) . '">' . esc_html( $product->add_to_cart_text() ) . '</a>';
	}

	private static function render_product_image( $cls, $sett ) {
		$product = self::current_product();
		if ( ! $product ) {
			return '';
		}
		$pid = $product->get_id();
		$main = get_the_post_thumbnail( $pid, 'woocommerce_single' );
		if ( '' === $main && function_exists( 'wc_placeholder_img' ) ) {
			$main = wc_placeholder_img( 'woocommerce_single' );
		}
		$out = '<div class="mel-pimg ' . esc_attr( $cls ) . '">' . $main;
		if ( ! empty( $sett['showThumbs'] ) ) {
			$thumbs = '';
			foreach ( $product->get_gallery_image_ids() as $att_id ) {
				$thumbs .= wp_get_attachment_image( $att_id, 'woocommerce_gallery_thumbnail' );
			}
			if ( '' !== $thumbs ) {
				$out .= '<div class="mel-pthumbs">' . $thumbs . '</div>';
			}
		}
		return $out . '</div>';
	}

	private static function render_product_excerpt( $cls ) {
		$product = self::current_product();
		if ( ! $product ) {
			return '';
		}
		$excerpt = $product->get_short_description();
		if ( '' === trim( (string) $excerpt ) ) {
			return '';
		}
		return '<div class="mel-pexcerpt ' . esc_attr( $cls ) . '">' . wp_kses_post( $excerpt ) . '</div>';
	}

	/**
	 * Header cart link (Woo lite). Shows item count + total when WooCommerce
	 * is active with a cart in this request; otherwise a plain cart link.
	 * Live fragment refresh (count without reload) needs Woo's own
	 * cart-fragments script, which Woo enqueues on shop pages itself.
	 *
	 * @param string $cls
	 * @param array  $sett
	 * @return string
	 */
	private static function render_menu_cart( $cls, $sett ) {
		$url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '#';
		$out = '<a class="mel-menucart ' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">';
		$out .= '<span class="mel-cart-icon" aria-hidden="true">🛒</span>';
		if ( ! empty( $sett['showCount'] ) && function_exists( 'WC' ) && WC()->cart ) {
			$out .= ' <span class="mel-cart-count">' . esc_html( (string) WC()->cart->get_cart_contents_count() ) . '</span>';
		}
		if ( ! empty( $sett['showTotal'] ) && function_exists( 'WC' ) && WC()->cart ) {
			$out .= ' <span class="mel-cart-total">' . wp_kses_post( WC()->cart->get_cart_total() ) . '</span>';
		}
		return $out . '</a>';
	}

	/**
	 * Cart / checkout embeds (Woo lite). Renders Woo's own shortcodes inside
	 * a Melintir layout, so the full purchase path can live in the builder.
	 * Fixed shortcode per widget (no arbitrary shortcode execution); Woo's
	 * markup and styles apply untouched.
	 *
	 * @param string $cls
	 * @param string $page cart|checkout
	 * @return string
	 */
	private static function render_woo_shortcode( $cls, $page ) {
		if ( ! function_exists( 'WC' ) || ! post_type_exists( 'product' ) ) {
			return '<p class="mel-loop-empty ' . esc_attr( $cls ) . '">' . esc_html__( 'Install and activate WooCommerce to display this block.', 'melintir' ) . '</p>';
		}
		$tag = 'checkout' === $page ? '[woocommerce_checkout]' : '[woocommerce_cart]';
		return '<div class="' . esc_attr( $cls ) . '">' . do_shortcode( $tag ) . '</div>';
	}

	/**
	 * WP menu as horizontal/vertical nav with a CSS-only mobile toggle
	 * (checkbox hack — no JS required).
	 *
	 * @param string $id
	 * @param string $cls
	 * @param array  $sett
	 * @return string
	 */
	private static function render_nav( $id, $cls, $sett ) {
		$layout = ( isset( $sett['layout'] ) && 'vertical' === $sett['layout'] ) ? 'vertical' : 'horizontal';
		$menu_id = isset( $sett['menu'] ) ? absint( $sett['menu'] ) : 0;
		$toggle  = ! empty( $sett['showToggle'] );
		$out     = '<nav class="mel-nav mel-nav-' . $layout . ( $toggle ? ' mel-has-toggle' : '' ) . ' ' . esc_attr( $cls ) . '">';
		if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
			return $out . '<span class="mel-nav-empty">' . esc_html__( 'Select a menu', 'melintir' ) . '</span></nav>';
		}
		$items = wp_get_nav_menu_items( $menu_id );
		if ( ! $items ) {
			return $out . '<span class="mel-nav-empty">' . esc_html__( 'Menu is empty', 'melintir' ) . '</span></nav>';
		}
		if ( ! empty( $sett['showToggle'] ) ) {
			$out .= '<input type="checkbox" id="meln-' . esc_attr( $id ) . '" class="mel-nav-check" />';
			$out .= '<label class="mel-nav-burger" for="meln-' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Menu', 'melintir' ) . '">☰</label>';
		}
		$out .= '<ul class="mel-nav-list">' . self::nav_items( $items, 0, 0 ) . '</ul></nav>';
		return $out;
	}

	private static function nav_items( $items, $parent, $depth ) {
		if ( $depth > 3 ) {
			return '';
		}
		$out = '';
		foreach ( (array) $items as $it ) {
			if ( intval( $it->menu_item_parent ) !== intval( $parent ) ) {
				continue;
			}
			$out .= '<li><a href="' . esc_url( $it->url ) . '">' . esc_html( $it->title ) . '</a>';
			$sub  = self::nav_items( $items, $it->ID, $depth + 1 );
			if ( '' !== $sub ) {
				$out .= '<ul class="mel-nav-sub">' . $sub . '</ul>';
			}
			$out .= '</li>';
		}
		return $out;
	}
}
