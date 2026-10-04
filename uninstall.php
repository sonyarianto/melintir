<?php
// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove generated CSS cache files. Post content (_melintir_data postmeta)
// is intentionally kept so pages never lose content on uninstall.
$upload = wp_upload_dir();
$dir    = trailingslashit( $upload['basedir'] ) . 'melintir/';
foreach ( glob( $dir . 'mel-*.css' ) as $file ) {
	if ( is_file( $file ) ) {
		unlink( $file ); // phpcs:ignore
	}
}
