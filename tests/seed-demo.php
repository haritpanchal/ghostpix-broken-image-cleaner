<?php
/**
 * Creates demo content for testing the plugin by hand.
 *
 * Every post is written the way the Classic Editor writes it — images as raw
 * HTML in post_content, captions as [caption] shortcodes — and a mixture of
 * images that are genuinely missing, images that are fine and must survive, and
 * the awkward cases the plugin is supposed to leave alone.
 *
 * Nothing here runs a scan. Start that yourself from Tools → Broken Images, so
 * you see the plugin find these rather than being handed the answers.
 *
 *     npx wp-env run cli wp eval-file wp-content/plugins/ghostpix-broken-image-cleaner/tests/seed-demo.php
 *
 * Re-running replaces the demo content. To remove it and the files it created:
 *
 *     npx wp-env run cli wp eval-file wp-content/plugins/ghostpix-broken-image-cleaner/tests/seed-demo.php cleanup
 *
 * @package BrokenImageCleaner
 */

use BrokenImageCleaner\Store;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 'This script must be run through WP-CLI.' );
}

const BROKEN_IMAGE_CLEANER_DEMO_META = '_broken_image_cleaner_demo';

$mode = ( isset( $args ) && isset( $args[0] ) ) ? $args[0] : 'seed';

$uploads = wp_get_upload_dir();
$basedir = $uploads['basedir'];
$baseurl = $uploads['baseurl'];

// Files the demo creates. Everything else it references is meant to be absent.
$present = array(
	'2013/07/keeper-hero.jpg',
	'2013/07/keeper-inline.jpg',
	'2013/07/photo.jpg',
	'2016/03/keeper-old.jpg',
);

$empty_files = array( '2013/07/empty.jpg' );

/**
 * Remove every demo post and the files this script created.
 *
 * @param string $basedir     Uploads base directory.
 * @param array  $present     Files created as working images.
 * @param array  $empty_files Files created as zero-byte images.
 * @return int Number of posts deleted.
 */
function broken_image_cleaner_demo_cleanup( $basedir, array $present, array $empty_files ) {
	$posts = get_posts(
		array(
			'post_type'   => 'any',
			'post_status' => 'any',
			'numberposts' => 200,
			'fields'      => 'ids',
			'meta_key'    => BROKEN_IMAGE_CLEANER_DEMO_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		)
	);

	foreach ( $posts as $post_id ) {
		Store::prune_post( $post_id, array() );
		wp_delete_post( $post_id, true );
	}

	foreach ( array_merge( $present, $empty_files ) as $relative ) {
		$path = $basedir . '/' . $relative;

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	return count( $posts );
}

if ( 'cleanup' === $mode ) {
	$removed = broken_image_cleaner_demo_cleanup( $basedir, $present, $empty_files );

	WP_CLI::success( sprintf( 'Removed %d demo posts and the files they used.', $removed ) );

	return;
}

// Start from a clean slate so the script can be run repeatedly.
broken_image_cleaner_demo_cleanup( $basedir, $present, $empty_files );

// A real one-pixel GIF, so the images that are supposed to work actually render.
$pixel = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );

foreach ( $present as $relative ) {
	$path = $basedir . '/' . $relative;
	wp_mkdir_p( dirname( $path ) );
	file_put_contents( $path, $pixel );
}

foreach ( $empty_files as $relative ) {
	$path = $basedir . '/' . $relative;
	wp_mkdir_p( dirname( $path ) );
	file_put_contents( $path, '' );
}

$u    = $baseurl . '/2013/07';
$old  = $baseurl . '/2016/03';
$data = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

$posts = array(

	array(
		'title'   => 'Demo 01 — Captioned image, the Classic Editor default',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '1 broken. The whole [caption] block should go, caption text included.',
		'content' => '<p>The photograph below was lost in a migration years ago.</p>
[caption id="attachment_1201" align="alignnone" width="640"]<img src="' . $u . '/gone-caption.jpg" alt="A lost photograph" width="640" height="480" />The caption that belongs to it[/caption]
<p>This paragraph must survive untouched.</p>',
	),

	array(
		'title'   => 'Demo 02 — Image wrapped in a link',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '1 broken. The empty <a> should go with it.',
		'content' => '<p>A thumbnail that used to link to the full-size version.</p>
<a href="' . $u . '/gone-link-full.jpg"><img src="' . $u . '/gone-link.jpg" alt="Thumbnail" /></a>
<p>Closing text.</p>',
	),

	array(
		'title'   => 'Demo 03 — Figure with a caption',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '1 broken. The <figure> and its <figcaption> should go too.',
		'content' => '<p>Some later content used figure elements.</p>
<figure class="wp-caption alignnone"><img src="' . $u . '/gone-figure.jpg" alt="Missing" /><figcaption class="wp-caption-text">A caption inside a figure</figcaption></figure>
<p>Closing text.</p>',
	),

	array(
		'title'   => 'Demo 04 — Mixed: three broken, one perfectly fine',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '3 broken. keeper-hero.jpg and keeper-inline.jpg must NOT be touched.',
		'content' => '<img src="' . $u . '/keeper-hero.jpg" alt="Working hero image" />
<p>An opening paragraph, followed by an image that is gone.</p>
<img src="' . $u . '/gone-mixed-a.jpg" alt="Gone" />
<p>More text, and an image that still works:</p>
<img src="' . $u . '/keeper-inline.jpg" alt="Working inline image" />
<p>And two more that do not:</p>
[caption id="attachment_1202" align="alignright" width="300"]<img src="' . $u . '/gone-mixed-b.jpg" />Another caption[/caption]
<a href="/somewhere"><img src="' . $u . '/gone-mixed-c.jpg" /></a>
<p>The end.</p>',
	),

	array(
		'title'   => 'Demo 05 — Only a thumbnail size is missing',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => 'Lands under "Missing sizes", NOT under "Broken". photo.jpg exists; the 150x150 does not. Removing it would be the wrong fix.',
		'content' => '<p>The original is on disk, but this generated size was never created.</p>
<img src="' . $u . '/photo-150x150.jpg" alt="Thumbnail" width="150" height="150" />
<p>Regenerating thumbnails is the right repair here.</p>',
	),

	array(
		'title'   => 'Demo 06 — File exists but is empty',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '1 broken, reported as "File is empty" rather than not found.',
		'content' => '<p>A truncated upload — the file is there, but it is zero bytes.</p>
<img src="' . $u . '/empty.jpg" alt="Empty file" />',
	),

	array(
		'title'   => 'Demo 07 — Things that must be left alone',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '0 findings. External images, data URIs and commented-out images are all out of scope.',
		'content' => '<p>An image on someone else\'s server:</p>
<img src="https://example.com/not-our-problem.jpg" alt="External" />
<p>An inline data URI:</p>
<img src="' . $data . '" alt="Inline pixel" />
<p>And one commented out of the page entirely:</p>
<!-- <img src="' . $u . '/gone-commented.jpg" alt="Commented out" /> -->
<p>None of these should show up.</p>',
	),

	array(
		'title'   => 'Demo 08 — Caption holding two images, one broken',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '1 broken. The caption must SURVIVE, because the working image is still inside it.',
		'content' => '<p>A side-by-side pair sharing one caption.</p>
[caption id="attachment_1203" align="alignnone" width="640"]<img src="' . $u . '/gone-pair.jpg" /><img src="' . $u . '/keeper-hero.jpg" />Two images, one caption[/caption]
<p>Only the broken one should disappear.</p>',
	),

	array(
		'title'   => 'Demo 09 — Responsive image with a missing srcset candidate',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => 'The missing srcset candidate is listed on its own. Removing it must edit the srcset only — the image still displays and must stay on the page.',
		'content' => '<p>A responsive image whose larger version went missing.</p>
<img src="' . $u . '/keeper-hero.jpg" srcset="' . $u . '/keeper-hero.jpg 640w, ' . $u . '/gone-large.jpg 1280w" sizes="(max-width: 640px) 100vw, 640px" alt="Responsive" />',
	),

	array(
		'title'   => 'Demo 10 — A draft, not published',
		'status'  => 'draft',
		'type'    => 'post',
		'expect'  => '1 broken. Drafts are scanned too.',
		'content' => '<p>Still being written.</p>
<img src="' . $u . '/gone-draft.jpg" alt="Gone" />',
	),

	array(
		'title'   => 'Demo 11 — A page rather than a post',
		'status'  => 'publish',
		'type'    => 'page',
		'expect'  => '1 broken, if Pages are enabled in settings. Untick Pages and it should vanish from the list.',
		'content' => '<p>An old landing page.</p>
<img src="' . $old . '/gone-old.jpg" alt="Gone" />
<p>With one image that is still fine:</p>
<img src="' . $old . '/keeper-old.jpg" alt="Working" />',
	),

	array(
		'title'   => 'Demo 12 — Nothing wrong with this one',
		'status'  => 'publish',
		'type'    => 'post',
		'expect'  => '0 findings. A control: if this ever shows up, something is wrong.',
		'content' => '<p>Every image here is present and correct.</p>
<img src="' . $u . '/keeper-hero.jpg" alt="Working" />
[caption id="attachment_1204" align="alignnone" width="640"]<img src="' . $u . '/keeper-inline.jpg" />A caption that should never be removed[/caption]',
	),
);

$created = array();

foreach ( $posts as $index => $spec ) {
	$post_id = wp_insert_post(
		array(
			'post_title'   => $spec['title'],
			'post_content' => $spec['content'],
			'post_status'  => $spec['status'],
			'post_type'    => $spec['type'],
			'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( 60 - $index ) . ' days' ) ),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( 'Could not create: ' . $spec['title'] );
		continue;
	}

	update_post_meta( $post_id, BROKEN_IMAGE_CLEANER_DEMO_META, 1 );

	$created[] = array( $post_id, $spec['title'], $spec['expect'] );
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Created %d demo posts.', count( $created ) ) );
WP_CLI::log( '' );

foreach ( $created as $row ) {
	WP_CLI::log( sprintf( '#%d  %s', $row[0], $row[1] ) );
	WP_CLI::log( sprintf( '     %s', $row[2] ) );
}

WP_CLI::log( '' );
WP_CLI::log( 'Files created (these are the ones that must survive):' );

foreach ( $present as $relative ) {
	WP_CLI::log( '  ' . $baseurl . '/' . $relative );
}

WP_CLI::log( '  ' . $baseurl . '/2013/07/empty.jpg  (deliberately zero bytes)' );
WP_CLI::log( '' );
WP_CLI::log( 'Now go to Tools → Broken Images and run a scan.' );

WP_CLI::success( 'Demo content ready.' );
