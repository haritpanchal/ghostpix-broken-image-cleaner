<?php
/**
 * Removes the plugin's data when it is deleted.
 *
 * Scan results and settings always go. The saved copies used for undo are only
 * deleted if the user asked for that in the settings, because they are the one
 * piece of data whose loss cannot be undone.
 *
 * @package BrokenImageCleaner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-backup.php';

$broken_image_cleaner_delete_data = (bool) get_option( 'broken_image_cleaner_delete_data' );

BrokenImageCleaner\Store::drop_table();

delete_option( 'broken_image_cleaner_scan_state' );
delete_option( 'broken_image_cleaner_scan_lock' );
delete_option( 'broken_image_cleaner_post_types' );
delete_option( 'broken_image_cleaner_retention_days' );
delete_option( 'broken_image_cleaner_delete_data' );
delete_option( 'broken_image_cleaner_db_version' );

if ( $broken_image_cleaner_delete_data ) {
	BrokenImageCleaner\Backup::delete_all();
}
