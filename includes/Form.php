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
		add_action( 'admin_post_melintir_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'export_button' ) );
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
		list( $fields, $button_ignored, $success_ignored, $form_opts ) = array_pad( $doc, 4, array() ); // phpcs:ignore

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

		// Turnstile (only when the form opts in AND global keys exist).
		if ( ! empty( $form_opts['turnstile'] ) && ! self::verify_turnstile() ) {
			$fail();
		}

		/**
		 * Custom spam/abuse check.
		 *
		 * @param true|\WP_Error $ok     Return WP_Error to reject the submission.
		 * @param array          $clean  Sanitized field values.
		 * @param int            $post_id
		 * @param string         $node_id
		 */
		$spam_check = apply_filters( 'melintir_form_spam_check', true, $clean, $post_id, $node_id );
		if ( is_wp_error( $spam_check ) ) {
			$fail();
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
			$to = ( is_array( $form_opts ) && ! empty( $form_opts['to'] ) && is_email( $form_opts['to'] ) ) ? $form_opts['to'] : get_option( 'admin_email' );
			wp_mail(
				$to,
				sprintf( '[Melintir] %s', __( 'New form entry', 'melintir' ) ),
				implode( "\n", $lines )
			);
		}

		wp_safe_redirect( esc_url_raw( add_query_arg( 'melintir_sent', $node_id, $back ) . '#' . $node_id ) );
		exit;
	}

	/**
	 * Verify a Cloudflare Turnstile token. Returns true when Turnstile is
	 * not configured (per-form opt-in requires global keys to enforce).
	 *
	 * @return bool
	 */
	private static function verify_turnstile() {
		$secret = get_option( 'melintir_turnstile_secret', '' );
		if ( '' === $secret ) {
			return true;
		}
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : ''; // phpcs:ignore
		if ( '' === $token ) {
			return false;
		}
		$resp = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	/**
	 * CSV download of all entries. Capability + nonce gated.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'edit_posts' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'melintir_export_csv' ) ) { // phpcs:ignore
			wp_die( esc_html__( 'You cannot export entries.', 'melintir' ), 403 );
		}
		$entries = get_posts(
			array(
				'post_type'   => 'melintir_entry',
				'post_status' => 'private',
				'numberposts' => 1000,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=melintir-entries-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		fputcsv( $out, array( 'id', 'date', 'page_id', 'node_id', 'data' ) );
		foreach ( $entries as $e ) {
			fputcsv(
				$out,
				array(
					$e->ID,
					$e->post_date,
					get_post_meta( $e->ID, '_melintir_post_id', true ),
					get_post_meta( $e->ID, '_melintir_node_id', true ),
					get_post_meta( $e->ID, '_melintir_form_data', true ),
				)
			);
		}
		exit;
	}

	/**
	 * Export button on the Entries list screen.
	 */
	public static function export_button() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-melintir_entry' !== $screen->id ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=melintir_export_csv' ), 'melintir_export_csv' );
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'melintir' ) . '</a>';
	}

	/**
	 * Locate the form widget's field defs in the saved doc.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @return array|null [fields, buttonText, successMsg, opts(to, turnstile)]
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
		return array(
			$sett['fields'] ?? array(),
			$sett['buttonText'] ?? 'Send',
			$sett['successMsg'] ?? '',
			array(
				'to'        => $sett['to'] ?? '',
				'turnstile' => ! empty( $sett['turnstile'] ),
			),
		);
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
