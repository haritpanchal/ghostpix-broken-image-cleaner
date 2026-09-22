<?php
/**
 * Stores the content a post had before it was cleaned, so the edit can be undone.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshots post content before an edit and restores it on request.
 *
 * One snapshot is kept per post: the content immediately before the most recent
 * clean-up. Because all removals selected for a post are applied in a single
 * pass, undoing that one snapshot puts the post back exactly as it was.
 *
 * Snapshots are pruned on a schedule. Holding a full copy of every cleaned post
 * forever would be a real cost on the large archives this plugin is aimed at.
 */
class Backup {

	/**
	 * Meta key holding the snapshot. Underscore-prefixed so it stays out of the
	 * custom fields UI.
	 */
	const META_KEY = '_broken_image_cleaner_backup';

	/**
	 * Option holding the retention period, in days.
	 */
	const RETENTION_OPTION = 'broken_image_cleaner_retention_days';

	/**
	 * Default number of days a snapshot is kept.
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Save the content a post had before it was edited.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $content     Content before the edit.
	 * @param array  $finding_ids Findings applied in this edit.
	 * @return void
	 */
	public static function save( $post_id, $content, array $finding_ids = array() ) {
		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'content'     => $content,
				'time'        => time(),
				'user_id'     => get_current_user_id(),
				'finding_ids' => array_map( 'absint', $finding_ids ),
			)
		);
	}

	/**
	 * Fetch the snapshot for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		$backup = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $backup ) || ! isset( $backup['content'] ) ) {
			return null;
		}

		return $backup;
	}

	/**
	 * Whether a post can currently be restored.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function exists( $post_id ) {
		return null !== self::get( $post_id );
	}

	/**
	 * Put a post's content back as it was before the clean-up.
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	public static function restore( $post_id ) {
		$post_id = (int) $post_id;
		$backup  = self::get( $post_id );

		if ( null === $backup ) {
			return new \WP_Error(
				'broken_image_cleaner_no_backup',
				__( 'There is no saved copy of this post to restore.', 'ghostpix-broken-image-cleaner' )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'broken_image_cleaner_cannot_edit',
				__( 'You are not allowed to edit this post.', 'ghostpix-broken-image-cleaner' )
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $backup['content'],
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_post_meta( $post_id, self::META_KEY );

		Store::set_status_for_post( $post_id, Store::STATUS_REMOVED, Store::STATUS_RESTORED );

		return true;
	}

	/**
	 * How many days snapshots are kept for.
	 *
	 * @return int
	 */
	public static function retention_days() {
		$days = (int) get_option( self::RETENTION_OPTION, self::DEFAULT_RETENTION_DAYS );

		return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
	}

	/**
	 * Delete snapshots that are past the retention period.
	 *
	 * @return int Number of snapshots deleted.
	 */
	public static function prune() {
		$cutoff = time() - ( self::retention_days() * DAY_IN_SECONDS );

		$post_ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Deliberately bounded: this runs daily on cron and clears at most 200 expired snapshots per pass.
				'fields'           => 'ids',
				'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded, runs once a day on a cron hook.
				'suppress_filters' => false,
			)
		);

		$deleted = 0;

		foreach ( $post_ids as $post_id ) {
			$backup = self::get( $post_id );

			if ( null === $backup || ( isset( $backup['time'] ) && $backup['time'] > $cutoff ) ) {
				continue;
			}

			delete_post_meta( $post_id, self::META_KEY );
			++$deleted;
		}

		return $deleted;
	}

	/**
	 * Delete every snapshot. Used on uninstall when the user opts in.
	 *
	 * @return void
	 */
	public static function delete_all() {
		delete_post_meta_by_key( self::META_KEY );
	}
}
