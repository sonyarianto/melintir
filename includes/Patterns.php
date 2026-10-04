<?php
namespace Melintir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saved patterns, slice 2: live links (bake on save).
 *
 * A `pattern-ref` widget stores only {patternId}. The canonical document
 * always keeps the ref; expansion happens at every consumption point
 * (page save for CSS, render_page for HTML, editor preview). Editing a
 * pattern re-bakes all using pages via propagate(), so one save updates
 * everywhere with zero renderer changes and zero extra frontend queries.
 *
 * Safety: expansion is cycle-guarded (visited stack), node-budgeted, and
 * id-suffixed deterministically (2nd+ embed of one pattern gets -2, -3…).
 * Deleting a pattern that still has usages is refused.
 */
class Patterns {

	const OPTION = 'melintir_patterns';
	const MAX    = 50;
	const BUDGET = 2000;

	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? array_values( $all ) : array();
	}

	public static function map() {
		$map = array();
		foreach ( self::all() as $p ) {
			if ( isset( $p['id'], $p['node'] ) && is_array( $p['node'] ) ) {
				$map[ (string) $p['id'] ] = $p['node'];
			}
		}
		return $map;
	}

	private static function write( $all ) {
		update_option( self::OPTION, array_slice( array_values( $all ), 0, self::MAX ) );
	}

	public static function save_new( $name, $node ) {
		$name = substr( sanitize_text_field( (string) $name ), 0, 60 );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid', __('Pattern name is required', 'melintir'), array( 'status' => 400 ) );
		}
		if ( ! is_array( $node ) ) {
			return new \WP_Error( 'invalid', __('Pattern node is required', 'melintir'), array( 'status' => 400 ) );
		}
		list( $clean, $errors ) = Security::sanitize_pattern_node( $node );
		if ( null === $clean ) {
			return new \WP_Error( 'invalid', implode( '; ', $errors ), array( 'status' => 400 ) );
		}
		$all = self::all();
		$ids = array();
		foreach ( $all as $p ) {
			if ( isset( $p['id'] ) ) {
				$ids[ (string) $p['id'] ] = true;
			}
		}
		$pid = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$candidate = 'p' . wp_generate_password( 7, false, false );
			if ( ! isset( $ids[ $candidate ] ) ) {
				$pid = $candidate;
				break;
			}
		}
		if ( '' === $pid ) {
			return new \WP_Error( 'full', __('Could not allocate a pattern ID', 'melintir'), array( 'status' => 500 ) );
		}
		array_unshift(
			$all,
			array(
				'id'   => $pid,
				'name' => $name,
				'node' => $clean,
				'ts'   => time(),
			)
		);
		self::write( $all );
		return array( 'ok' => true, 'id' => $pid, 'warnings' => $errors );
	}

	/**
	 * Replace a pattern's content, then re-bake every using page.
	 * A cycle introduced by the new content is rejected before anything
	 * is stored.
	 *
	 * @param string $pid
	 * @param mixed  $node
	 * @return array|\WP_Error
	 */
	public static function update( $pid, $node ) {
		$pid = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $pid );
		if ( ! is_array( $node ) ) {
			return new \WP_Error( 'invalid', __('Pattern node is required', 'melintir'), array( 'status' => 400 ) );
		}
		list( $clean, $errors ) = Security::sanitize_pattern_node( $node );
		if ( null === $clean ) {
			return new \WP_Error( 'invalid', implode( '; ', $errors ), array( 'status' => 400 ) );
		}
		$all   = self::all();
		$found = false;
		foreach ( $all as &$p ) {
			if ( isset( $p['id'] ) && (string) $p['id'] === $pid ) {
				$found = true;
				break;
			}
		}
		unset( $p );
		if ( ! $found ) {
			return new \WP_Error( 'not_found', __('Pattern not found', 'melintir'), array( 'status' => 404 ) );
		}
		// Dry-run expansion against the new content: cycles die here.
		$map         = self::map();
		$map[ $pid ] = $clean;
		$probe       = array(
			'root' => array(
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => array(),
				'style'    => array(),
				'elements' => array(
					array(
						'id'         => 'probe',
						'elType'     => 'widget',
						'widgetType' => 'pattern-ref',
						'settings'   => array( 'patternId' => $pid ),
						'style'      => array(),
						'elements'   => array(),
					),
				),
			),
		);
		list( , $probe_errors ) = self::expand_with_map( $probe, $map );
		foreach ( $probe_errors as $e ) {
			if ( false !== strpos( $e, 'cycle' ) ) {
				return new \WP_Error( 'invalid', 'Refused: ' . $e, array( 'status' => 400 ) );
			}
		}
		foreach ( $all as &$p ) {
			if ( isset( $p['id'] ) && (string) $p['id'] === $pid ) {
				$p['node'] = $clean;
				$p['ts']   = time();
				break;
			}
		}
		unset( $p );
		self::write( $all );
		$report = self::propagate( $pid );
		return array( 'ok' => true, 'id' => $pid, 'warnings' => $errors, 'propagated' => $report );
	}

	/**
	 * @param string $pid
	 * @return true|\WP_Error refused while usages exist.
	 */
	public static function delete( $pid ) {
		$pid = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $pid );
		$usages = self::find_usages( $pid );
		if ( ! empty( $usages ) ) {
			return new \WP_Error(
				'in_use',
				/* translators: %d = number of pages using the pattern. */
				sprintf( __( 'Pattern is used by %d page(s). Unlink them first.', 'melintir' ), count( $usages ) ),
				array( 'status' => 409, 'pages' => array_slice( $usages, 0, 20 ) )
			);
		}
		$kept = array();
		foreach ( self::all() as $p ) {
			if ( ! isset( $p['id'] ) || (string) $p['id'] !== $pid ) {
				$kept[] = $p;
			}
		}
		self::write( $kept );
		return true;
	}

	/**
	 * Post IDs whose canonical doc references $pid.
	 *
	 * @param string $pid
	 * @return int[]
	 */
	public static function find_usages( $pid ) {
		global $wpdb;
		$pid = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $pid );
		if ( '' === $pid ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( '"patternId":"' . $pid . '"' ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value LIKE %s", // phpcs:ignore
				MELINTIR_META_DATA,
				$like
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Re-bake CSS caches of using pages (canonical docs keep their refs).
	 *
	 * @param string $pid
	 * @return array updated/failed/total/pending
	 */
	public static function propagate( $pid, $limit = 50 ) {
		$usages  = self::find_usages( $pid );
		$batch   = array_slice( $usages, 0, $limit );
		$updated = array();
		$failed  = array();
		foreach ( $batch as $id ) {
			$raw = get_post_meta( $id, MELINTIR_META_DATA, true );
			$doc = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			if ( ! is_array( $doc ) || ! isset( $doc['root'] ) ) {
				$failed[ $id ] = 'unreadable document';
				continue;
			}
			list( $expanded, ) = self::expand( $doc );
			$css = Renderer::generate_css( $expanded );
			update_post_meta( $id, MELINTIR_META_CSS, $css );
			Rest::write_css_file( $id, $css );
			$updated[] = $id;
		}
		return array(
			'updated' => $updated,
			'failed'  => $failed,
			'total'   => count( $usages ),
			'pending' => max( 0, count( $usages ) - count( $batch ) ),
		);
	}

	/**
	 * Expand pattern-refs using the stored library.
	 *
	 * @param mixed $doc
	 * @return array [expanded_doc, errors]
	 */
	public static function expand( $doc ) {
		return self::expand_with_map( $doc, self::map() );
	}

	public static function expand_with_map( $doc, $map ) {
		$errors = array();
		if ( ! is_array( $doc ) || ! isset( $doc['root'] ) || ! is_array( $doc['root'] ) ) {
			return array( $doc, array( 'missing root node' ) );
		}
		$counts = array();
		$used   = array();
		self::collect_ids( $doc['root'], $used );
		$budget = array( 'n' => 0 );
		$root   = self::expand_node( $doc['root'], $map, array(), $counts, $used, $budget, $errors );
		$doc['root'] = is_array( $root ) ? $root : $doc['root'];
		return array( $doc, $errors );
	}

	private static function collect_ids( $node, &$used ) {
		if ( ! is_array( $node ) || ! isset( $node['id'] ) ) {
			return;
		}
		$used[ (string) $node['id'] ] = true;
		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $c ) {
				self::collect_ids( $c, $used );
			}
		}
	}

	private static function count_nodes( $node ) {
		$n = 1;
		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $c ) {
				$n += self::count_nodes( $c );
			}
		}
		return $n;
	}

	private static function suffix_ids( $node, $suffix ) {
		if ( '' === $suffix ) {
			return $node;
		}
		$node['id'] = (string) $node['id'] . $suffix;
		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $i => $c ) {
				$node['elements'][ $i ] = self::suffix_ids( $c, $suffix );
			}
		}
		return $node;
	}

	/**
	 * @return array|null expanded node, null when the ref is broken (dropped).
	 */
	private static function expand_node( $node, $map, $stack, &$counts, &$used, &$budget, &$errors ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		$is_ref = 'widget' === ( $node['elType'] ?? '' )
			&& 'pattern-ref' === ( $node['widgetType'] ?? '' )
			&& isset( $node['settings']['patternId'] );
		if ( $is_ref ) {
			$pid = (string) $node['settings']['patternId'];
			if ( ! isset( $map[ $pid ] ) ) {
				$errors[] = 'unknown pattern: ' . $pid;
				return null;
			}
			if ( in_array( $pid, $stack, true ) ) {
				$errors[] = 'cycle detected at pattern: ' . $pid;
				return null;
			}
			$counts[ $pid ] = isset( $counts[ $pid ] ) ? $counts[ $pid ] + 1 : 1;
			$suffix = $counts[ $pid ] > 1 ? '-' . $counts[ $pid ] : '';
			$copy   = self::suffix_ids( $map[ $pid ], $suffix );
			// Deterministic de-collision against ids already emitted.
			$copy = self::dedupe_ids( $copy, $used );
			$budget['n'] += self::count_nodes( $copy );
			if ( $budget['n'] > self::BUDGET ) {
				$errors[] = 'pattern expansion budget exceeded';
				return null;
			}
			$stack[] = $pid;
			// Re-enter expand_node (not just children): the replacement itself
			// may be another ref, which is exactly how cycles form.
			return self::expand_node( $copy, $map, $stack, $counts, $used, $budget, $errors );
		}
		return self::expand_children( $node, $map, $stack, $counts, $used, $budget, $errors );
	}

	private static function expand_children( $node, $map, $stack, &$counts, &$used, &$budget, &$errors ) {
		if ( ! isset( $node['elements'] ) || ! is_array( $node['elements'] ) ) {
			return $node;
		}
		$elements = array();
		foreach ( $node['elements'] as $c ) {
			$expanded = self::expand_node( $c, $map, $stack, $counts, $used, $budget, $errors );
			if ( null !== $expanded ) {
				$elements[] = $expanded;
			}
		}
		$node['elements'] = $elements;
		return $node;
	}

	private static function dedupe_ids( $node, &$used ) {
		if ( isset( $node['id'] ) ) {
			$id = (string) $node['id'];
			$i  = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = (string) $node['id'] . '-' . $i;
				$i++;
			}
			$node['id'] = $id;
			$used[ $id ] = true;
		}
		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $i => $c ) {
				$node['elements'][ $i ] = self::dedupe_ids( $c, $used );
			}
		}
		return $node;
	}
}
