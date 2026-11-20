<?php
/**
 * WP-CLI scan, list and remove, including a dry run that must not write.
 *
 * The fixture uses its own post type, so the scan does not walk the rest of
 * the site. Removal is by finding ID, so other findings already in the table
 * are not edited.
 *
 *     wp eval-file wp-content/plugins/ghostpix-broken-image-cleaner/tests/test-cli.php
 *
 * @package BrokenImageCleaner
 */

use BrokenImageCleaner\Backup;
use BrokenImageCleaner\CLI;
use BrokenImageCleaner\Cron;
use BrokenImageCleaner\Scanner;
use BrokenImageCleaner\Store;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This script must be run through WP-CLI.' );
}

$passed = 0;
$failed = 0;

/**
 * Report a single check.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
$check = function ( $label, $expected, $actual ) use ( &$passed, &$failed ) {
	if ( $expected === $actual ) {
		++$passed;
		WP_CLI::log( "  PASS  {$label}" );

		return;
	}

	++$failed;
	WP_CLI::log( "  FAIL  {$label}" );
	WP_CLI::log( '        expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '        actual:   ' . var_export( $actual, true ) );
};

$previous_types = get_option( Scanner::POST_TYPES_OPTION, null );
$previous_state = get_option( Scanner::STATE_OPTION, null );
$post_ids       = array();

$pixel   = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
$uploads = wp_get_upload_dir();
$dir     = $uploads['basedir'] . '/ghostpix-cli-fixture';
$url     = $uploads['baseurl'] . '/ghostpix-cli-fixture';

wp_mkdir_p( $dir );
file_put_contents( $dir . '/fine.jpg', $pixel );
file_put_contents( $dir . '/photo.jpg', $pixel );

if ( file_exists( $dir . '/gone.jpg' ) ) {
	unlink( $dir . '/gone.jpg' );
}

if ( file_exists( $dir . '/photo-150x150.jpg' ) ) {
	unlink( $dir . '/photo-150x150.jpg' );
}

register_post_type( 'bic_cli', array( 'public' => true ) );
update_option( Scanner::POST_TYPES_OPTION, array( 'bic_cli' ) );

register_shutdown_function(
	function () use ( &$post_ids, &$previous_types, &$previous_state, $dir ) {
		global $wpdb;

		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
			$wpdb->delete( Store::table_name(), array( 'post_id' => $post_id ), array( '%d' ) );
		}

		if ( null === $previous_types ) {
			delete_option( Scanner::POST_TYPES_OPTION );
		} else {
			update_option( Scanner::POST_TYPES_OPTION, $previous_types );
		}

		if ( null === $previous_state ) {
			delete_option( Scanner::STATE_OPTION );
		} else {
			update_option( Scanner::STATE_OPTION, $previous_state, false );
		}

		if ( is_array( $previous_state ) && ! empty( $previous_state['running'] ) ) {
			Cron::schedule_batch();
		} else {
			Cron::clear_batch();
		}

		foreach ( array( 'fine.jpg', 'photo.jpg', 'gone.jpg', 'photo-150x150.jpg' ) as $file ) {
			if ( file_exists( $dir . '/' . $file ) ) {
				unlink( $dir . '/' . $file );
			}
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}

		unregister_post_type( 'bic_cli' );
	}
);

wp_set_current_user( 1 );
Store::install();

$content = '<p>Keep this.</p>'
	. '<img src="' . $url . '/gone.jpg" alt="gone" />'
	. '<img src="' . $url . '/fine.jpg" alt="fine" />'
	. '<img src="' . $url . '/photo-150x150.jpg" alt="thumb" />';

$post_id = wp_insert_post(
	array(
		'post_type'    => 'bic_cli',
		'post_status'  => 'publish',
		'post_title'   => 'Ghostpix CLI fixture',
		'post_content' => $content,
	),
	true
);

if ( is_wp_error( $post_id ) ) {
	WP_CLI::error( 'Could not create the fixture post.' );
}

$post_ids[] = (int) $post_id;

$empty_id = wp_insert_post(
	array(
		'post_type'    => 'bic_cli',
		'post_status'  => 'publish',
		'post_title'   => 'Ghostpix CLI fixture with a healthy image',
		'post_content' => '<img src="' . $url . '/fine.jpg" alt="only fine" />',
	),
	true
);

if ( is_wp_error( $empty_id ) ) {
	WP_CLI::error( 'Could not create the second fixture post.' );
}

$post_ids[] = (int) $empty_id;

$cli = new CLI();

WP_CLI::log( 'Scan' );
$cli->scan( array(), array( 'restart' => true ) );

$state = Scanner::state();

$check( 'the scan is finished', false, Scanner::is_running() );
$check( 'both fixture posts were checked', 2, (int) $state['scanned'] );

global $wpdb;

$fixture_rows = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT id, post_id, image_url, reason, status FROM ' . Store::table_name() . ' WHERE post_id IN ( %d, %d )',
		$post_id,
		$empty_id
	)
);

$reasons = array();

foreach ( $fixture_rows as $row ) {
	$reasons[ basename( $row->image_url ) ] = $row->reason;
}

ksort( $reasons );

$check(
	'the missing file and the missing generated size are recorded',
	array(
		'gone.jpg'         => Store::REASON_FILE_MISSING,
		'photo-150x150.jpg' => Store::REASON_SIZE_MISSING,
	),
	$reasons
);

$gone_id = 0;
$size_id = 0;

foreach ( $fixture_rows as $row ) {
	if ( (int) $row->post_id !== (int) $post_id ) {
		continue;
	}

	if ( Store::REASON_FILE_MISSING === $row->reason ) {
		$gone_id = (int) $row->id;
	}

	if ( Store::REASON_SIZE_MISSING === $row->reason ) {
		$size_id = (int) $row->id;
	}
}

$selected = ( new ReflectionMethod( CLI::class, 'selected_ids' ) );
$selected->setAccessible( true );
$default_ids = $selected->invoke( $cli, array() );

$check( 'the missing file is included in the default removal set', true, in_array( $gone_id, $default_ids, true ) );
$check( 'the missing generated size is not included in the default removal set', false, in_array( $size_id, $default_ids, true ) );

WP_CLI::log( '' );
WP_CLI::log( 'Dry run' );

$before = get_post( $post_id )->post_content;
$cli->remove( array(), array( 'ids' => (string) $gone_id, 'dry-run' => true ) );

$check( 'a dry run does not edit the post', $before, get_post( $post_id )->post_content );
$check( 'a dry run does not save an undo snapshot', false, Backup::exists( $post_id ) );
$check( 'a dry run does not change the finding status', Store::STATUS_BROKEN, Store::get( $gone_id )->status );

WP_CLI::log( '' );
WP_CLI::log( 'Remove' );

$cli->remove( array(), array( 'ids' => (string) $gone_id, 'yes' => true ) );

$cleaned = get_post( $post_id )->post_content;

$check( 'the missing file was removed', false, false !== strpos( $cleaned, 'gone.jpg' ) );
$check( 'the healthy image survived', true, false !== strpos( $cleaned, 'fine.jpg' ) );
$check( 'the missing generated size was left in the post', true, false !== strpos( $cleaned, 'photo-150x150.jpg' ) );
$check( 'the surrounding paragraph survived', true, false !== strpos( $cleaned, '<p>Keep this.</p>' ) );
$check( 'an undo snapshot was saved', true, Backup::exists( $post_id ) );

WP_CLI::log( '' );
WP_CLI::log( sprintf( '%d passed, %d failed', $passed, $failed ) );

if ( $failed > 0 ) {
	WP_CLI::error( 'CLI checks failed.' );
}

WP_CLI::success( 'CLI checks passed.' );
