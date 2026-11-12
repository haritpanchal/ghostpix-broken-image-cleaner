<?php
/**
 * Walks the site's content looking for images whose files are missing.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drives the scan, a batch at a time.
 *
 * Scanning is read-only: it records what it finds and changes nothing. Work is
 * done in small batches with the position held in an option, so an archive of
 * any size can be scanned without a long-running request, and a scan survives
 * being paused, resumed, or interrupted by a timeout.
 */
class Scanner {

	/**
	 * Option holding the scan's progress.
	 */
	const STATE_OPTION = 'broken_image_cleaner_scan_state';

	/**
	 * Option holding the post types to scan.
	 */
	const POST_TYPES_OPTION = 'broken_image_cleaner_post_types';

	/**
	 * Posts examined per batch.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Option used as a lock so cron and the tools screen cannot scan at once.
	 *
	 * add_option() fails when the row already exists, which is the atomic part.
	 * A crashed batch leaves the row behind, so a lock older than a minute is
	 * taken over rather than respected.
	 */
	const LOCK_OPTION = 'broken_image_cleaner_scan_lock';

	/**
	 * Post statuses worth scanning.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array( 'publish', 'future', 'draft', 'pending', 'private' );
	}

	/**
	 * Post types the user has chosen to scan.
	 *
	 * @return array
	 */
	public static function post_types() {
		$configured = get_option( self::POST_TYPES_OPTION, array( 'post', 'page' ) );
		$configured = array_filter( (array) $configured, 'post_type_exists' );

		return ! empty( $configured ) ? array_values( $configured ) : array( 'post' );
	}

	/**
	 * Current scan state.
	 *
	 * @return array
	 */
	public static function state() {
		return wp_parse_args(
			(array) get_option( self::STATE_OPTION, array() ),
			array(
				'running'      => false,
				'last_post_id' => 0,
				'scanned'      => 0,
				'total'        => 0,
				'started_at'   => 0,
				'finished_at'  => 0,
			)
		);
	}

	/**
	 * Whether a scan is in progress.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = self::state();

		return ! empty( $state['running'] );
	}

	/**
	 * Begin a fresh scan.
	 *
	 * @return void
	 */
	public static function start() {
		update_option(
			self::STATE_OPTION,
			array(
				'running'      => true,
				'last_post_id' => 0,
				'scanned'      => 0,
				'total'        => self::count_candidates(),
				'started_at'   => time(),
				'finished_at'  => 0,
			),
			false
		);

		Cron::schedule_batch();
	}

	/**
	 * Stop a scan, keeping whatever it has found so far.
	 *
	 * @return void
	 */
	public static function stop() {
		$state                = self::state();
		$state['running']     = false;
		$state['finished_at'] = time();

		update_option( self::STATE_OPTION, $state, false );

		Cron::clear_batch();
	}

	/**
	 * Examine the next batch of posts.
	 *
	 * A batch shorter than BATCH_SIZE is the last one: the query is ordered by
	 * ID and limited, so a short page means nothing remains. Finishing here
	 * matters because the follow-up cron tick that used to notice the empty
	 * queue is not spawned from inside wp-cron.php, and the cron lock then
	 * blocks another spawn for a minute. A scan of a small site would otherwise
	 * sit on "in progress" after every post had already been checked.
	 *
	 * @return void
	 */
	public static function run_batch() {
		if ( ! self::is_running() ) {
			return;
		}

		if ( ! self::acquire_lock() ) {
			// The other runner owns this batch. Make sure a tick stays queued
			// for after it lets go, in case the event that called us was just
			// unscheduled by wp-cron and will not be put back.
			Cron::schedule_batch();

			return;
		}

		self::process_batch();
		self::release_lock();
	}

	/**
	 * Scan one batch. Caller holds the lock.
	 *
	 * @return void
	 */
	private static function process_batch() {
		$state = self::state();
		$posts = self::next_posts( $state['last_post_id'] );

		if ( empty( $posts ) ) {
			self::stop();

			return;
		}

		$resolver = new Resolver();

		foreach ( $posts as $post ) {
			self::scan_content( (int) $post->ID, $post->post_content, $resolver );

			$state['last_post_id'] = (int) $post->ID;
			++$state['scanned'];
		}

		update_option( self::STATE_OPTION, $state, false );

		if ( count( $posts ) < self::BATCH_SIZE ) {
			self::stop();

			return;
		}

		Cron::schedule_batch();
	}

	/**
	 * Claim the scan lock.
	 *
	 * @return bool Whether this call owns the lock.
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );

		if ( ( time() - $locked_at ) < MINUTE_IN_SECONDS ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );

		return true;
	}

	/**
	 * Release the scan lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Record the broken images in one piece of content.
	 *
	 * @param int      $post_id  Post ID.
	 * @param string   $content  Post content.
	 * @param Resolver $resolver Shared resolver.
	 * @return int Number of broken images found.
	 */
	public static function scan_content( $post_id, $content, Resolver $resolver = null ) {
		if ( null === $resolver ) {
			$resolver = new Resolver();
		}

		$still_broken = array();
		$references   = Extractor::extract( $content );

		// Gather every src first. A URL that is some other image's source has
		// to be treated as one even where it also turns up in a srcset.
		$src_urls = array();

		foreach ( $references as $reference ) {
			$src_urls[ $reference['src'] ] = true;
		}

		foreach ( $references as $reference ) {
			$snippet = self::snippet( $content, $reference['offset'], $reference['length'] );
			$check   = $resolver->check( $reference['src'] );

			if ( Resolver::STATUS_BROKEN === $check['status'] ) {
				Store::record( $post_id, $reference['src'], $check['path'], $check['reason'], $snippet );

				$still_broken[] = $reference['src'];
			}

			foreach ( $reference['srcset'] as $candidate ) {
				if ( isset( $src_urls[ $candidate ] ) ) {
					continue;
				}

				$check = $resolver->check( $candidate );

				if ( Resolver::STATUS_BROKEN !== $check['status'] ) {
					continue;
				}

				// Recorded for what will be done about it rather than for why
				// the file is gone: either way the repair is to drop it from
				// the srcset and leave the image alone.
				Store::record( $post_id, $candidate, $check['path'], Store::REASON_SRCSET_MISSING, $snippet );

				$still_broken[] = $candidate;
			}
		}

		// Drop findings for images that have since been put back.
		Store::prune_post( $post_id, $still_broken );

		return count( $still_broken );
	}

	/**
	 * Re-check a single post and refresh its findings.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of broken images found.
	 */
	public static function rescan_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		return self::scan_content( (int) $post->ID, $post->post_content );
	}

	/**
	 * A short piece of surrounding content, for context in the review table.
	 *
	 * @param string $content Post content.
	 * @param int    $offset  Image tag offset.
	 * @param int    $length  Image tag length.
	 * @return string
	 */
	private static function snippet( $content, $offset, $length ) {
		$start = max( 0, $offset - 80 );
		$slice = substr( $content, $start, $length + 160 );

		// The slice is cut at a byte count, so it usually begins and ends in the
		// middle of a tag. Those half-tags have to go before the markup is
		// stripped: with its opening bracket already lost, a slice starting
		// inside a paragraph is not recognised as a tag at all and survives
		// stripping as the literal text "p>".
		if ( $start > 0 ) {
			$first_open  = strpos( $slice, '<' );
			$first_close = strpos( $slice, '>' );

			if ( false !== $first_close && ( false === $first_open || $first_close < $first_open ) ) {
				$slice = substr( $slice, $first_close + 1 );
			}
		}

		$last_open  = strrpos( $slice, '<' );
		$last_close = strrpos( $slice, '>' );

		if ( false !== $last_open && ( false === $last_close || $last_open > $last_close ) ) {
			$slice = substr( $slice, 0, $last_open );
		}

		return wp_html_excerpt( trim( $slice ), 200, '…' );
	}

	/**
	 * The condition that selects scannable posts, and the values behind it.
	 *
	 * Shared by the batch query and the count so the two can never drift into
	 * disagreeing about what counts as a candidate.
	 *
	 * @return array {
	 *     @type string $where  SQL condition using placeholders.
	 *     @type array  $params Values for those placeholders, in order.
	 * }
	 */
	private static function candidate_condition() {
		global $wpdb;

		$types    = self::post_types();
		$statuses = self::statuses();

		$type_placeholders   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		return array(
			'where'  => "post_type IN ( {$type_placeholders} )
				AND post_status IN ( {$status_placeholders} )
				AND post_content LIKE %s",
			'params' => array_merge( $types, $statuses, array( '%' . $wpdb->esc_like( '<img' ) . '%' ) ),
		);
	}

	/**
	 * Fetch the next batch of candidate posts.
	 *
	 * @param int $after_id Highest post ID already scanned.
	 * @return array
	 */
	private static function next_posts( $after_id ) {
		global $wpdb;

		$condition = self::candidate_condition();
		$params    = array_merge( array( (int) $after_id ), $condition['params'], array( self::BATCH_SIZE ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The only thing interpolated is the condition from candidate_condition(), which holds placeholders and nothing else; every value behind them is passed to prepare(). Selecting posts by a substring of post_content has no core API equivalent, and caching one forward-only pass over the archive would serve no one.
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				WHERE ID > %d
				AND {$condition['where']}
				ORDER BY ID ASC
				LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $posts;
	}

	/**
	 * How many posts the scan expects to look at.
	 *
	 * @return int
	 */
	private static function count_candidates() {
		global $wpdb;

		$condition = self::candidate_condition();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- As above: the condition carries only placeholders, and every value behind them is prepared. Every placeholder in this query lives inside that condition, which is why the analyser cannot find one in the string itself. This count runs once when a scan starts.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$condition['where']}",
				$condition['params']
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $total;
	}
}
