<?php
/**
 * Plugin bootstrap.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the plugin up and tears it down.
 */
class Plugin {

	/**
	 * Capability required to review and clean up findings.
	 *
	 * Editing other people's posts is the closest existing match: this screen
	 * edits post content across the site. Per-post permission is still checked
	 * again before every individual edit.
	 */
	const CAPABILITY = 'edit_others_posts';

	/**
	 * Option recording whether plugin data should be removed on uninstall.
	 */
	const DELETE_DATA_OPTION = 'broken_image_cleaner_delete_data';

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function boot() {
		Cron::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-cli.php';

			CLI::register();
		}

		if ( is_admin() ) {
			require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-list-table.php';
			require_once BROKEN_IMAGE_CLEANER_DIR . 'includes/class-admin.php';

			Admin::init();
		}
	}

	/**
	 * Create the findings table and default settings.
	 *
	 * @return void
	 */
	public static function activate() {
		Store::install();

		add_option( Scanner::POST_TYPES_OPTION, array( 'post', 'page' ) );
		add_option( Backup::RETENTION_OPTION, Backup::DEFAULT_RETENTION_DAYS );
		add_option( self::DELETE_DATA_OPTION, false );
	}

	/**
	 * Stop all background work. Nothing is deleted here.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::clear_all();
	}
}
