<?php
/**
 * End-to-end check against a real WordPress install.
 *
 * The standalone tests cover the parsing and splicing in isolation. This one
 * exercises the parts that need a database and a filesystem: the scanner, the
 * resolver's view of the uploads folder, the findings table, the removal, and
 * the undo.
 *
 * Run it inside the local environment:
 *
 *     npx wp-env run cli wp eval-file wp-content/plugins/ghostpix-broken-image-cleaner/tests/integration.php
 *
 * It creates one post and a couple of files in the uploads folder, and deletes
 * both again at the end.
 *
 * @package BrokenImageCleaner
 */

use BrokenImageCleaner\Backup;
use BrokenImageCleaner\Plugin;
use BrokenImageCleaner\Remover;
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

// A one-pixel GIF, so the files written below are real images rather than stubs.
$pixel = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );

$uploads = wp_get_upload_dir();
$dir     = $uploads['basedir'] . '/2013/07';
$url     = $uploads['baseurl'] . '/2013/07';

wp_mkdir_p( $dir );

// Present: the control image, and an original whose generated size is missing.
file_put_contents( $dir . '/fine.jpg', $pixel );
file_put_contents( $dir . '/photo.jpg', $pixel );

// Absent: everything named "gone-*". Make sure of it.
foreach ( array( 'gone-bare.jpg', 'gone-link.jpg', 'gone-caption.jpg', 'gone-figure.jpg', 'gone-large.jpg' ) as $missing ) {
	if ( file_exists( $dir . '/' . $missing ) ) {
		unlink( $dir . '/' . $missing );
	}
}

$content = <<<HTML
<p>Opening paragraph.</p>
<img src="{$url}/gone-bare.jpg" alt="bare" />
<p>Between images &amp; things.</p>
<a href="{$url}/gone-link.jpg"><img src="{$url}/gone-link.jpg" /></a>
[caption id="attachment_99" align="alignnone" width="300"]<img src="{$url}/gone-caption.jpg" />A caption[/caption]
<figure class="wp-block-image"><img src="{$url}/gone-figure.jpg" /><figcaption>A figcaption</figcaption></figure>
<p>This one is fine and must survive:</p>
<img src="{$url}/fine.jpg" alt="control" />
<p>This one is only a missing thumbnail:</p>
<img src="{$url}/photo-150x150.jpg" alt="thumb" />
<p>External images are out of scope in this version:</p>
<img src="https://example.com/remote.jpg" alt="remote" />
<p>A responsive image whose largest size is gone. The image still displays, so it must survive:</p>
<img src="{$url}/fine.jpg" srcset="{$url}/fine.jpg 640w, {$url}/gone-large.jpg 1280w" sizes="100vw" alt="responsive" />
<p>Closing paragraph with a [shortcode attr="1"].</p>
HTML;

$post_id = wp_insert_post(
	array(
		'post_title'   => 'Ghostpix Broken Image Cleaner integration fixture',
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => 'post',
	),
	true
);

if ( is_wp_error( $post_id ) ) {
	WP_CLI::error( 'Could not create the fixture post: ' . $post_id->get_error_message() );
}

wp_set_current_user( 1 );
Store::install();

WP_CLI::log( "Fixture post {$post_id}" );
WP_CLI::log( '' );
WP_CLI::log( 'Scanner and resolver' );

Store::prune_post( $post_id, array() );
$found = Scanner::rescan_post( $post_id );

$rows    = array();
$reasons = array();

foreach ( Store::query( array( 'per_page' => 100 ) ) as $row ) {
	if ( (int) $row->post_id !== (int) $post_id ) {
		continue;
	}

	$rows[ basename( $row->image_url ) ] = $row;
	$reasons[ basename( $row->image_url ) ] = $row->reason;
}

ksort( $reasons );

$check(
	'flags each missing file, the missing thumbnail and the dead srcset candidate',
	'gone-bare.jpg=file_missing, gone-caption.jpg=file_missing, gone-figure.jpg=file_missing, gone-large.jpg=srcset_missing, gone-link.jpg=file_missing, photo-150x150.jpg=size_missing',
	implode(
		', ',
		array_map(
			function ( $name, $reason ) {
				return "{$name}={$reason}";
			},
			array_keys( $reasons ),
			$reasons
		)
	)
);

$check( 'the healthy image is not flagged', false, isset( $rows['fine.jpg'] ) );
$check( 'the external image is not flagged', false, isset( $rows['remote.jpg'] ) );

// A snippet is cut at a byte count, so it lands mid-tag far more often than not.
// Any half-tag has to be dropped before the markup is stripped, or its remains
// show up in the review table as stray text like "p>".
$broken_snippets = array_filter(
	$rows,
	function ( $row ) {
		return (bool) preg_match( '/^\s*[a-zA-Z][a-zA-Z0-9-]*>/', $row->context_snippet );
	}
);

$check(
	'no context snippet starts with the remains of a cut-off tag',
	array(),
	array_map(
		function ( $row ) {
			return $row->context_snippet;
		},
		$broken_snippets
	)
);

WP_CLI::log( '' );
WP_CLI::log( 'Removal' );

$remove_ids = array();

foreach ( $rows as $name => $row ) {
	if ( Store::REASON_SIZE_MISSING !== $row->reason ) {
		$remove_ids[] = (int) $row->id;
	}
}

$report  = Remover::remove( $remove_ids );
$cleaned = get_post( $post_id )->post_content;

$check( 'five references were removed', 5, (int) $report['images_removed'] );
$check( 'one post was edited', 1, (int) $report['posts_changed'] );
$check( 'no errors were reported', array(), $report['errors'] );

$check( 'no caption shortcode is left behind', false, false !== strpos( $cleaned, '[caption' ) );
$check( 'no empty figure is left behind', false, false !== strpos( $cleaned, '<figure' ) );
$check( 'no empty link is left behind', false, false !== strpos( $cleaned, '<a href' ) );
$check( 'no reference to a removed image remains', false, false !== strpos( $cleaned, 'gone-' ) );

$check( 'the healthy image survived', true, false !== strpos( $cleaned, 'fine.jpg' ) );
$check( 'the missing thumbnail was left alone', true, false !== strpos( $cleaned, 'photo-150x150.jpg' ) );
$check( 'the external image was left alone', true, false !== strpos( $cleaned, 'remote.jpg' ) );

// The responsive image displays fine from its src. Only the dead candidate in
// its srcset should have gone; deleting the element would destroy a working image.
$check( 'the working responsive image survived', true, false !== strpos( $cleaned, 'alt="responsive"' ) );
$check( 'its dead srcset candidate is gone', false, false !== strpos( $cleaned, 'gone-large' ) );
$check( 'its surviving srcset candidate is still listed', true, false !== strpos( $cleaned, 'fine.jpg 640w' ) );
$check( 'unrelated shortcodes survived', true, false !== strpos( $cleaned, '[shortcode attr="1"]' ) );
$check( 'the opening paragraph survived', true, false !== strpos( $cleaned, '<p>Opening paragraph.</p>' ) );
$check( 'the escaped entity was not rewritten', true, false !== strpos( $cleaned, 'Between images &amp; things.' ) );

WP_CLI::log( '' );
WP_CLI::log( 'Undo' );

$check( 'a saved copy exists', true, Backup::exists( $post_id ) );

$restored = Backup::restore( $post_id );

$check( 'restore succeeded', true, true === $restored );
$check( 'the post is byte-for-byte what it was', $content, get_post( $post_id )->post_content );
$check( 'the saved copy is cleared afterwards', false, Backup::exists( $post_id ) );

// Clean up.
wp_delete_post( $post_id, true );
Store::prune_post( $post_id, array() );

foreach ( array( 'fine.jpg', 'photo.jpg' ) as $file ) {
	if ( file_exists( $dir . '/' . $file ) ) {
		unlink( $dir . '/' . $file );
	}
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( '%d passed, %d failed', $passed, $failed ) );

if ( $failed > 0 ) {
	WP_CLI::error( 'Integration checks failed.' );
}

WP_CLI::success( 'Integration checks passed.' );
