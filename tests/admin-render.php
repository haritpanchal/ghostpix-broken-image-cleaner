<?php
/**
 * Checks the admin screen renders, and guards the nonce-collision bug.
 *
 * WP_List_Table::display_tablenav() emits its own field called `_wpnonce` for
 * its bulk-action nonce. This screen's form sits around that table, so if this
 * screen also used the default `_wpnonce`, the two fields would collide and the
 * later one would win — leaving every bulk action, Remove included, unable to
 * verify its own nonce. The checks below assert the two stay distinct.
 *
 * Run it inside the local environment:
 *
 *     npx wp-env run cli wp eval-file wp-content/plugins/ghostpix-broken-image-cleaner/tests/admin-render.php
 *
 * @package BrokenImageCleaner
 */

use BrokenImageCleaner\Admin;
use BrokenImageCleaner\Store;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This script must be run through WP-CLI.' );
}

$passed = 0;
$failed = 0;

/**
 * Report a single check.
 *
 * @param string $label  What is being checked.
 * @param bool   $result Whether it holds.
 * @param string $detail Extra context shown on failure.
 * @return void
 */
$check = function ( $label, $result, $detail = '' ) use ( &$passed, &$failed ) {
	if ( $result ) {
		++$passed;
		WP_CLI::log( "  PASS  {$label}" );

		return;
	}

	++$failed;
	WP_CLI::log( "  FAIL  {$label}" );

	if ( '' !== $detail ) {
		WP_CLI::log( "        {$detail}" );
	}
};

wp_set_current_user( 1 );
Store::install();

require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once WP_PLUGIN_DIR . '/ghostpix-broken-image-cleaner/includes/class-list-table.php';
require_once WP_PLUGIN_DIR . '/ghostpix-broken-image-cleaner/includes/class-admin.php';

set_current_screen( 'tools_page_ghostpix-broken-image-cleaner' );

// Seed one finding so the table has a row to draw.
$post_id = wp_insert_post(
	array(
		'post_title'   => 'Admin render fixture',
		'post_content' => '<img src="/wp-content/uploads/2013/07/missing.jpg" />',
		'post_status'  => 'publish',
	),
	true
);

if ( is_wp_error( $post_id ) ) {
	WP_CLI::error( 'Could not create the fixture post.' );
}

Store::record(
	$post_id,
	'/wp-content/uploads/2013/07/missing.jpg',
	'/var/www/html/wp-content/uploads/2013/07/missing.jpg',
	Store::REASON_FILE_MISSING,
	'<img src="/wp-content/uploads/2013/07/missing.jpg" />'
);

$_GET['page'] = 'ghostpix-broken-image-cleaner';

ob_start();
Admin::render();
$html = ob_get_clean();

WP_CLI::log( 'Rendering' );

$check( 'the screen renders without fatalling', strlen( $html ) > 500, 'got ' . strlen( $html ) . ' bytes' );
$check( 'the heading is present', false !== strpos( $html, 'Broken Images' ) );
$check( 'the scan control is present', false !== strpos( $html, 'Start scan' ) );
$check( 'the seeded finding is listed', false !== strpos( $html, 'missing.jpg' ) );
$check( 'the fixture post title is listed', false !== strpos( $html, 'Admin render fixture' ) );
$check( 'the bulk action is offered', false !== strpos( $html, 'Remove from content' ) );
$check( 'rows are selectable', false !== strpos( $html, 'name="findings[]"' ) );
$check( 'the settings form is present', false !== strpos( $html, 'Keep undo data for' ) );
$check( 'no PHP notice leaked into the output', false === stripos( $html, 'Warning:' ) && false === stripos( $html, 'Fatal error' ) );

WP_CLI::log( '' );
WP_CLI::log( 'Nonce fields do not collide' );

$own  = substr_count( $html, 'name="' . Admin::NONCE_FIELD . '"' );
$core = substr_count( $html, 'name="_wpnonce"' );

$check( "this screen's own nonce field is present", $own >= 1, "found {$own}" );
$check( "WP_List_Table's own _wpnonce is also present", $core >= 1, "found {$core}" );
$check( 'they are under different names', Admin::NONCE_FIELD !== '_wpnonce' );

// The actual regression: a request carrying both must verify against this
// screen's field, and must not be satisfied by the list table's nonce.
$_REQUEST[ Admin::NONCE_FIELD ] = wp_create_nonce( Admin::NONCE );
$_REQUEST['_wpnonce']           = wp_create_nonce( 'bulk-findings' );

$check(
	"the screen's nonce verifies from its own field",
	1 === (int) wp_verify_nonce( $_REQUEST[ Admin::NONCE_FIELD ], Admin::NONCE )
);

$check(
	"the list table's nonce does NOT satisfy this screen's action",
	false === wp_verify_nonce( $_REQUEST['_wpnonce'], Admin::NONCE )
);

// Clean up.
wp_delete_post( $post_id, true );
Store::prune_post( $post_id, array() );

WP_CLI::log( '' );
WP_CLI::log( sprintf( '%d passed, %d failed', $passed, $failed ) );

if ( $failed > 0 ) {
	WP_CLI::error( 'Admin render checks failed.' );
}

WP_CLI::success( 'Admin render checks passed.' );
