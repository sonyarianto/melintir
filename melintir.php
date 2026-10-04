<?php
/**
 * Plugin Name: Melintir
 * Plugin URI: https://github.com/melintir/melintir
 * Description: Open-source page builder with Rust/WASM brain. Container-only, fast frontend (<30KB JS).
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Melintir
 * License: GPLv2 or later
 * Text Domain: melintir
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MELINTIR_VERSION', '0.1.0' );
define( 'MELINTIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'MELINTIR_URL', plugin_dir_url( __FILE__ ) );
define( 'MELINTIR_META_DATA', '_melintir_data' );
define( 'MELINTIR_META_CSS', '_melintir_css' );

require_once MELINTIR_PATH . 'includes/Security.php';
require_once MELINTIR_PATH . 'includes/Renderer.php';
require_once MELINTIR_PATH . 'includes/Migrator.php';
require_once MELINTIR_PATH . 'includes/Rest.php';
require_once MELINTIR_PATH . 'includes/Plugin.php';

register_activation_hook( __FILE__, array( 'Melintir\\Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'Melintir\\Plugin', 'instance' ) );
