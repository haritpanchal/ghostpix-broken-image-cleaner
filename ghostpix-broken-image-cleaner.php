<?php
/**
 * Plugin Name:       Ghostpix Broken Image Cleaner
 * Plugin URI:        https://github.com/haritpanchal/ghostpix-broken-image-cleaner
 * Description:       Finds images in post content whose files are missing from the uploads folder, and removes them safely — after review, with one-click undo.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Harit Panchal
 * Author URI:        https://profiles.wordpress.org/haritpanchal/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ghostpix-broken-image-cleaner
 *
 * @package BrokenImageCleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BROKEN_IMAGE_CLEANER_VERSION', '1.0.0' );
define( 'BROKEN_IMAGE_CLEANER_FILE', __FILE__ );
define( 'BROKEN_IMAGE_CLEANER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BROKEN_IMAGE_CLEANER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Whether the current environment meets the plugin's minimum requirements.
 *
 * Checked before anything else loads so that an unsupported environment gets a
 * notice rather than a fatal error.
 *
 * @return bool
 */
function broken_image_cleaner_requirements_met() {
	return version_compare( PHP_VERSION, '7.4', '>=' )
		&& version_compare( get_bloginfo( 'version' ), '5.6', '>=' );
}

/**
 * Show an admin notice when the environment is too old to run the plugin.
 *
 * @return void
 */
function broken_image_cleaner_requirements_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Ghostpix Broken Image Cleaner requires PHP 7.4 or later and WordPress 5.6 or later. The plugin has not been loaded.', 'ghostpix-broken-image-cleaner' )
	);
}

if ( ! broken_image_cleaner_requirements_met() ) {
	add_action( 'admin_notices', 'broken_image_cleaner_requirements_notice' );
	return;
}

require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-store.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-extractor.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-resolver.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-rewriter.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-backup.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-scanner.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-cron.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-remover.php';
require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'BrokenImageCleaner\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BrokenImageCleaner\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'BrokenImageCleaner\\Plugin', 'boot' ) );
