<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lite form submissions: no integrations in v0.1.
 * Entries are stored as private `melintir_entry` posts (visible under
 * Melintir > Entries) and emailed to the site admin. Spam defense is
 * nonce + honeypot; add a CAPTCHA/Turnstile filter hook later.
 */
class Form {

	public static function register() {
		add_action( 'init', array( __CLASS__, 'post_type' ) );
		add_action( 'admin_post_melintir_submit', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_nopriv_melintir_submit', array( __CLASS__, 'submit' ) );
	}

	public static function post_type() {
		// Note: no custom `capabilities` array. A custom array here broke
		// global `edit_posts` checks in testing (WP 7.1.2), locking admins
		// out of wp-admin list screens. Default post caps + map_meta_cap
		// keep entries manageable by editors while staying safe.
		register_post_type(
			'melintir_entry',
			array(
				'labels'       => array(
					'name'          => __( 'Form Entries', 'melintir' ),
					'singular_name' => __( 'Form Entry', 'melintir' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'melintir',
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function submit() {
		$post_id = isset( $_POST['mel_post'] ) ? absint( $_POST['mel_post'] ) : 0; // phpcs:ignore
		$node_id = isset( $_POST['mel_node'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $_POST['mel_node'] ) : ''; // phpcs:ignore
		$back    = $post_id ? get_permalink( $post_id ) : home_url( '/' );
		$fail    = function () use ( $back, $node_id ) {
			wp_safe_redirect( esc_url_raw( add_query_arg( 'melintir_error', $node_id, $back ) . '#' . $node_id ) );
			exit;
		};

		if ( ! isset( $_POST['melintir_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['melintir_nonce'] ), 'melintir_form' ) ) { // phpcs:ignore
			$fail();
		}
		// Honeypot.
		if ( isset( $_POST['mel_website'] ) && '' !== trim( (string) $_POST['mel_website'] ) ) { // phpcs:ignore
			// Pretend success to bots.
			wp_safe_redirect( esc_url_raw( add_query_arg( 'melintir_sent', $node_id, $back ) . '#' . $node_id ) );
			exit;
		}

		$doc = self::find_form( $post_id, $node_id );
		if ( null === $doc ) {
			$fail();
		}
		list( $fields, $button_ignored, $success_ignored ) = $doc; // phpcs:ignore

		$values = isset( $_POST['mel_f'] ) && is_array( $_POST['mel_f'] ) ? $_POST['mel_f'] : array(); // phpcs:ignore
		$clean  = array();
		foreach ( $fields as $f ) {
			$raw = isset( $values[ $f['name'] ] ) ? $values[ $f['name'] ] : '';
			$raw = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
			if ( $f['required'] && '' === $raw ) {
				$fail();
			}
			if ( 'email' === $f['type'] && '' !== $raw && ! is_email( $raw ) ) {
				$fail();
			}
			if ( 'select' === $f['type'] && '' !== $raw && ! in_array( $raw, $f['options'], true ) ) {
				$fail();
			}
			$clean[ $f['name'] ] = sanitize_text_field( $raw );
		}

		$entry_id = wp_insert_post(
			array(
				'post_type'   => 'melintir_entry',
				'post_status' => 'private',
				'post_title'  => sprintf(
					/* translators: %s: page title */
					__( 'Entry from %s', 'melintir' ),
					$post_id ? get_the_title( $post_id ) : __( '(unknown page)', 'melintir' )
				),
				'meta_input'  => array(
					'_melintir_post_id'  => $post_id,
					'_melintir_node_id'  => $node_id,
					'_melintir_form_data' => wp_json_encode( $clean ),
				),
			)
		);

		if ( $entry_id ) {
			$lines = array();
			foreach ( $clean as $k => $v ) {
				$lines[] = $k . ': ' . $v;
			}
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[Melintir] %s', __( 'New form entry', 'melintir' ) ),
				implode( "\n", $lines )
			);
		}

		wp_safe_redirect( esc_url_raw( add_query_arg( 'melintir_sent', $node_id, $back ) . '#' . $node_id ) );
		exit;
	}

	/**
	 * Locate the form widget's field defs in the saved doc.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @return array|null [fields, buttonText, successMsg]
	 */
	private static function find_form( $post_id, $node_id ) {
		$raw = $post_id ? get_post_meta( $post_id, MELINTIR_META_DATA, true ) : '';
		$doc = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $doc ) || ! isset( $doc['root'] ) ) {
			return null;
		}
		$found = self::walk( $doc['root'], $node_id );
		if ( null === $found || 'form' !== ( $found['widgetType'] ?? '' ) ) {
			return null;
		}
		$sett = $found['settings'] ?? array();
		return array( $sett['fields'] ?? array(), $sett['buttonText'] ?? 'Send', $sett['successMsg'] ?? '' );
	}

	private static function walk( $node, $node_id ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		if ( ( $node['id'] ?? '' ) === $node_id ) {
			return $node;
		}
		foreach ( (array) ( $node['elements'] ?? array() ) as $child ) {
			$found = self::walk( $child, $node_id );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}
}
