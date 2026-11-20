<?php
/**
 * Applies removals to post content.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a set of reviewed findings into edits.
 *
 * This is the only class in the plugin that writes to post content, and it is
 * guarded accordingly: findings are grouped so each post is written once, the
 * user's permission is checked per post, and every image is re-checked on disk
 * immediately before the edit so a stale queue row cannot delete an image that
 * has since been put back.
 */
class Remover {

	/**
	 * Most findings that may be applied in a single request.
	 */
	const MAX_PER_REQUEST = 200;

	/**
	 * Apply removals for the given findings.
	 *
	 * @param array $finding_ids Finding IDs to act on.
	 * @return array {
	 *     @type int   $posts_changed  Posts that were edited.
	 *     @type int   $images_removed Image references removed.
	 *     @type int   $skipped        Findings left alone.
	 *     @type array $errors         Human-readable messages.
	 * }
	 */
	public static function remove( array $finding_ids ) {
		$report = self::apply( $finding_ids, true );

		unset( $report['items'] );

		return $report;
	}

	/**
	 * Report what remove() would do, without writing.
	 *
	 * The same checks run: permission, the file still being missing, and the
	 * rewriter actually having something to cut out. Nothing is saved, and no
	 * finding changes status. Callers that only want to show the plan — the
	 * WP-CLI dry run — must use this and not remove().
	 *
	 * @param array $finding_ids Finding IDs to assess.
	 * @return array {
	 *     @type int   $posts_changed  Posts that would be edited.
	 *     @type int   $images_removed Image references that would be removed.
	 *     @type int   $skipped        Findings that would be left alone.
	 *     @type array $errors         Human-readable messages.
	 *     @type array $items          One row per finding: id, post_id, image_url, reason, action, detail.
	 * }
	 */
	public static function preview( array $finding_ids ) {
		return self::apply( $finding_ids, false );
	}

	/**
	 * Assess a set of findings, and write the result only when asked.
	 *
	 * @param array $finding_ids Finding IDs to act on.
	 * @param bool  $write       Whether to save posts and update finding status.
	 * @return array
	 */
	private static function apply( array $finding_ids, $write ) {
		$report = array(
			'posts_changed'  => 0,
			'images_removed' => 0,
			'skipped'        => 0,
			'errors'         => array(),
			'items'          => array(),
		);

		$finding_ids = array_slice( array_filter( array_map( 'absint', $finding_ids ) ), 0, self::MAX_PER_REQUEST );

		if ( empty( $finding_ids ) ) {
			return $report;
		}

		// Content is saved verbatim, so the caller must be allowed to post
		// unfiltered HTML. Without it wp_update_post would run the whole post
		// through kses and strip markup that has nothing to do with the image.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$report['errors'][] = __( 'Your account is not allowed to save unfiltered HTML, so post content cannot be edited safely. Ask an administrator to run the clean-up.', 'ghostpix-broken-image-cleaner' );
			$report['skipped']  = count( $finding_ids );

			return $report;
		}

		$by_post = array();

		foreach ( Store::get_many( $finding_ids ) as $finding ) {
			$by_post[ (int) $finding->post_id ][] = $finding;
		}

		$resolver = new Resolver();

		foreach ( $by_post as $post_id => $findings ) {
			$result = self::process_post( $post_id, $findings, $resolver, $write );

			$report['images_removed'] += $result['images_removed'];
			$report['skipped']        += $result['skipped'];
			$report['errors']          = array_merge( $report['errors'], $result['errors'] );
			$report['items']           = array_merge( $report['items'], $result['items'] );

			if ( $result['images_removed'] > 0 ) {
				++$report['posts_changed'];
			}
		}

		return $report;
	}

	/**
	 * Apply every selected removal for one post in a single edit.
	 *
	 * @param int      $post_id  Post ID.
	 * @param array    $findings Findings belonging to this post.
	 * @param Resolver $resolver Shared resolver.
	 * @param bool     $write    Whether to save the post and update finding status.
	 * @return array
	 */
	private static function process_post( $post_id, array $findings, Resolver $resolver, $write = true ) {
		$result = array(
			'images_removed' => 0,
			'skipped'        => 0,
			'errors'         => array(),
			'items'          => array(),
		);

		$post    = get_post( $post_id );
		$refusal = self::refusal_reason( $post_id, $post );

		if ( null !== $refusal ) {
			$result['skipped'] += count( $findings );
			$result['errors'][] = $refusal;

			foreach ( $findings as $finding ) {
				$result['items'][] = self::finding_item( $finding, 'skip', $refusal );
			}

			return $result;
		}

		$original = $post->post_content;
		$outcome  = self::rewrite( $original, $findings, $resolver, $write );

		$result['images_removed'] = $outcome['removed'];
		$result['skipped']        = $outcome['skipped'];
		$result['items']          = $outcome['items'];

		if ( ! $write || $outcome['content'] === $original ) {
			return $result;
		}

		$saved = self::save( $post_id, $original, $outcome['content'], $outcome['applied'] );

		if ( is_wp_error( $saved ) ) {
			$result['errors'][]       = $saved->get_error_message();
			$result['skipped']       += count( $outcome['applied'] );
			$result['images_removed'] = 0;
		}

		return $result;
	}

	/**
	 * Why this post cannot be edited, if it cannot.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The post, if it still exists.
	 * @return string|null Message for the user, or null when the edit may go ahead.
	 */
	private static function refusal_reason( $post_id, $post ) {
		if ( ! $post ) {
			return sprintf(
				/* translators: %d: post ID. */
				__( 'Post %d no longer exists.', 'ghostpix-broken-image-cleaner' ),
				$post_id
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return sprintf(
				/* translators: %s: post title. */
				__( 'You are not allowed to edit "%s".', 'ghostpix-broken-image-cleaner' ),
				get_the_title( $post_id )
			);
		}

		return null;
	}

	/**
	 * Work the selected removals through a post's content.
	 *
	 * @param string   $content  Current post content.
	 * @param array    $findings Findings belonging to this post.
	 * @param Resolver $resolver Shared resolver.
	 * @param bool     $write    Whether a finding whose file is back may be marked restored.
	 * @return array {
	 *     @type string $content Content after the removals.
	 *     @type int    $removed References removed.
	 *     @type int    $skipped Findings left alone.
	 *     @type array  $applied IDs of the findings that were acted on.
	 *     @type array  $items   One row per finding, for a dry run.
	 * }
	 */
	private static function rewrite( $content, array $findings, Resolver $resolver, $write = true ) {
		$removed = 0;
		$skipped = 0;
		$applied = array();
		$items   = array();

		foreach ( $findings as $finding ) {
			// The queue may be minutes or weeks old. Confirm the file is still
			// missing before anything is deleted on the strength of it.
			$check = $resolver->check( $finding->image_url );

			if ( Resolver::STATUS_BROKEN !== $check['status'] ) {
				++$skipped;

				if ( $write ) {
					Store::set_status( $finding->id, Store::STATUS_RESTORED );
				}

				$items[] = self::finding_item( $finding, 'skip', __( 'The file is there now.', 'ghostpix-broken-image-cleaner' ) );

				continue;
			}

			$outcome = Rewriter::remove_image( $content, $finding->image_url );

			if ( 0 === $outcome['removed'] ) {
				++$skipped;

				$items[] = self::finding_item( $finding, 'skip', __( 'The image is no longer in the post.', 'ghostpix-broken-image-cleaner' ) );

				continue;
			}

			$content   = $outcome['content'];
			$removed  += $outcome['removed'];
			$applied[] = (int) $finding->id;
			$items[]   = self::finding_item( $finding, 'remove', '' );
		}

		return array(
			'content' => $content,
			'removed' => $removed,
			'skipped' => $skipped,
			'applied' => $applied,
			'items'   => $items,
		);
	}

	/**
	 * One finding, as a dry run will print it.
	 *
	 * @param object $finding Finding row.
	 * @param string $action  Either "remove" or "skip".
	 * @param string $detail  Why a finding was skipped, when it was.
	 * @return array
	 */
	private static function finding_item( $finding, $action, $detail ) {
		return array(
			'id'        => (int) $finding->id,
			'post_id'   => (int) $finding->post_id,
			'image_url' => (string) $finding->image_url,
			'reason'    => (string) $finding->reason,
			'action'    => $action,
			'detail'    => $detail,
		);
	}

	/**
	 * Snapshot the post, save the new content, and mark the findings done.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $original Content before the edit.
	 * @param string $content  Content after the edit.
	 * @param array  $applied  Findings that were acted on.
	 * @return true|\WP_Error
	 */
	private static function save( $post_id, $original, $content, array $applied ) {
		Backup::save( $post_id, $original, $applied );

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		foreach ( $applied as $finding_id ) {
			Store::set_status( $finding_id, Store::STATUS_REMOVED );
		}

		return true;
	}
}
