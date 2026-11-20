<?php
/**
 * WP-CLI commands.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Find images whose files are missing and remove them from post content.
 *
 * Scanning and listing only read. remove edits post content, and only for the
 * findings in the Broken view: a missing file, an empty file, or a responsive
 * size that is gone. A missing generated size, where the original file is still
 * there, is left out unless --include-missing-sizes is passed.
 *
 * ## EXAMPLES
 *
 *     wp ghostpix-broken-image-cleaner scan
 *     wp ghostpix-broken-image-cleaner list
 *     wp ghostpix-broken-image-cleaner remove --dry-run
 *     wp ghostpix-broken-image-cleaner remove --yes
 */
class CLI {

	/**
	 * Findings examined per query when collecting a whole view.
	 */
	const PAGE_SIZE = 200;

	/**
	 * Register the command.
	 *
	 * @return void
	 */
	public static function register() {
		\WP_CLI::add_command( 'ghostpix-broken-image-cleaner', self::class );
	}

	/**
	 * Scan post content for images whose files are missing.
	 *
	 * Runs in the foreground, one batch at a time, until every candidate post
	 * has been checked. A scan that is already in progress is continued rather
	 * than started again.
	 *
	 * ## OPTIONS
	 *
	 * [--restart]
	 * : Abandon a scan that is already in progress and start from the first post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ghostpix-broken-image-cleaner scan
	 *     wp ghostpix-broken-image-cleaner scan --restart
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		$this->require_user();

		if ( ! empty( $assoc_args['restart'] ) && Scanner::is_running() ) {
			Scanner::stop();
		}

		if ( Scanner::is_running() ) {
			\WP_CLI::log( 'Resuming the scan already in progress.' );
		} else {
			Scanner::start();
		}

		$state = Scanner::state();
		$total = (int) $state['total'];

		if ( 0 === $total ) {
			Scanner::run_batch();
			\WP_CLI::success( 'No posts contain images to scan.' );

			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning', $total );
		$done     = (int) $state['scanned'];

		if ( $done > 0 ) {
			$progress->tick( min( $done, $total ) );
		}

		$stalls = 0;

		while ( Scanner::is_running() ) {
			$before = (int) Scanner::state()['scanned'];

			Scanner::run_batch();

			$state = Scanner::state();
			$after = (int) $state['scanned'];
			$delta = $after - $before;

			if ( $delta > 0 ) {
				$stalls = 0;
				$progress->tick( $delta );

				continue;
			}

			if ( ! $state['running'] ) {
				break;
			}

			// Another request holds the scan lock. Give it a moment, then fail
			// rather than spinning if it never lets go.
			++$stalls;

			if ( $stalls > 5 ) {
				$progress->finish();
				\WP_CLI::error( 'The scan made no progress. Another scan may be holding its lock.' );
			}

			sleep( 1 );
		}

		$progress->finish();

		$state = Scanner::state();

		\WP_CLI::success(
			sprintf(
				'Scanned %1$d of %2$d posts.',
				(int) $state['scanned'],
				(int) $state['total']
			)
		);
	}

	/**
	 * List findings from the latest scan.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : broken, ignored, removed, or restored. Default: broken.
	 *
	 * [--reason=<reason>]
	 * : Comma-separated reasons to include. For broken findings the default is
	 * file_missing, zero_byte and srcset_missing, which is the Broken view on
	 * the tools screen. Missing generated sizes are not included.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - ids
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ghostpix-broken-image-cleaner list
	 *     wp ghostpix-broken-image-cleaner list --status=removed --format=count
	 *     wp ghostpix-broken-image-cleaner list --reason=size_missing
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_findings( $args, $assoc_args ) {
		$this->require_user();

		$status = isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : Store::STATUS_BROKEN;
		$known  = array( Store::STATUS_BROKEN, Store::STATUS_IGNORED, Store::STATUS_REMOVED, Store::STATUS_RESTORED );

		if ( ! in_array( $status, $known, true ) ) {
			\WP_CLI::error( 'Status must be one of: broken, ignored, removed, restored.' );
		}

		$reasons = $this->reasons_from_args( $assoc_args, Store::STATUS_BROKEN === $status );
		$rows    = $this->collect_rows( $status, $reasons );
		$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( empty( $rows ) ) {
			if ( 'count' === $format ) {
				\WP_CLI::log( '0' );

				return;
			}

			\WP_CLI::log( 'No findings.' );

			return;
		}

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'id'        => (int) $row->id,
				'post_id'   => (int) $row->post_id,
				'post'      => html_entity_decode( get_the_title( $row->post_id ), ENT_QUOTES, 'UTF-8' ),
				'image_url' => $row->image_url,
				'reason'    => $row->reason,
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'post_id', 'post', 'image_url', 'reason' ) );
	}

	/**
	 * Remove broken images from post content.
	 *
	 * Without --ids, every finding in the Broken view is removed. Pass --dry-run
	 * to print that plan and write nothing: no post is edited, no undo snapshot
	 * is saved, and no finding changes status.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated finding IDs. Default: every finding in the Broken view.
	 *
	 * [--include-missing-sizes]
	 * : Also remove findings whose generated size is missing while the original
	 * file is still there. Left out by default.
	 *
	 * [--dry-run]
	 * : Print what would be removed and write nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ghostpix-broken-image-cleaner remove --dry-run
	 *     wp ghostpix-broken-image-cleaner remove --yes
	 *     wp ghostpix-broken-image-cleaner remove --ids=12,15 --yes
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function remove( $args, $assoc_args ) {
		$this->require_user();

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			\WP_CLI::error( __( 'Your account is not allowed to save unfiltered HTML, so post content cannot be edited safely. Ask an administrator to run the clean-up.', 'ghostpix-broken-image-cleaner' ) );
		}

		$ids = $this->selected_ids( $assoc_args );

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'Nothing to remove.' );

			return;
		}

		$plan  = $this->combined_preview( $ids );
		$dry   = ! empty( $assoc_args['dry-run'] );
		$shown = array();

		foreach ( $plan['items'] as $item ) {
			if ( 'remove' !== $item['action'] && ! $dry ) {
				continue;
			}

			$shown[] = array(
				'id'        => $item['id'],
				'post_id'   => $item['post_id'],
				'post'      => html_entity_decode( get_the_title( $item['post_id'] ), ENT_QUOTES, 'UTF-8' ),
				'image_url' => $item['image_url'],
				'action'    => $item['action'],
				'detail'    => $item['detail'],
			);
		}

		if ( ! empty( $shown ) ) {
			\WP_CLI\Utils\format_items( 'table', $shown, array( 'id', 'post_id', 'post', 'image_url', 'action', 'detail' ) );
		}

		$this->report_errors( $plan['errors'] );

		$summary = $this->removal_summary( $plan['images_removed'], $plan['posts_changed'], $plan['skipped'] );

		if ( $dry ) {
			\WP_CLI::success( 'Dry run: would remove ' . $summary . ' Nothing was written.' );

			return;
		}

		if ( 0 === (int) $plan['images_removed'] ) {
			\WP_CLI::success( 'Nothing to remove. ' . $summary );

			return;
		}

		\WP_CLI::confirm( 'Remove ' . $summary, $assoc_args );

		$report = $this->combined_remove( $ids );

		$this->report_errors( $report['errors'] );

		\WP_CLI::success( 'Removed ' . $this->removal_summary( $report['images_removed'], $report['posts_changed'], $report['skipped'] ) );
	}

	/**
	 * "N images from N posts", with a skipped count only when something was left.
	 *
	 * @param int $images  Image references.
	 * @param int $posts   Posts.
	 * @param int $skipped Findings left alone.
	 * @return string
	 */
	private function removal_summary( $images, $posts, $skipped ) {
		$images  = (int) $images;
		$posts   = (int) $posts;
		$skipped = (int) $skipped;

		$summary = sprintf(
			/* translators: 1: number of images, 2: number of posts. */
			_n( '%1$s image from %2$s post.', '%1$s images from %2$s posts.', $images, 'ghostpix-broken-image-cleaner' ),
			number_format_i18n( $images ),
			number_format_i18n( $posts )
		);

		if ( $skipped > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: number of findings left alone. */
				_n( '%s was left alone.', '%s were left alone.', $skipped, 'ghostpix-broken-image-cleaner' ),
				number_format_i18n( $skipped )
			);
		}

		return $summary;
	}

	/**
	 * Stop unless the current account can review findings.
	 *
	 * WP-CLI runs as nobody unless --user is passed, and nobody cannot edit
	 * posts. Failing here is clearer than a later capability error.
	 *
	 * @return void
	 */
	private function require_user() {
		if ( current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		\WP_CLI::error( 'Run this with --user for an account that can edit other people\'s posts.' );
	}

	/**
	 * Reasons named on the command line, or the Broken view when none were.
	 *
	 * @param array $assoc_args    Associative arguments.
	 * @param bool  $default_view  Whether omitting --reason means the Broken view.
	 * @return array
	 */
	private function reasons_from_args( array $assoc_args, $default_view ) {
		if ( isset( $assoc_args['reason'] ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', explode( ',', $assoc_args['reason'] ) ) ) );
		}

		if ( ! $default_view ) {
			return array();
		}

		$reasons = self::broken_view_reasons();

		if ( ! empty( $assoc_args['include-missing-sizes'] ) ) {
			$reasons[] = Store::REASON_SIZE_MISSING;
		}

		return $reasons;
	}

	/**
	 * Reasons shown in the Broken view on the tools screen.
	 *
	 * A missing generated size is deliberately absent: the original file is
	 * still there, and removing the image is rarely the right fix.
	 *
	 * @return array
	 */
	private static function broken_view_reasons() {
		return array(
			Store::REASON_FILE_MISSING,
			Store::REASON_ZERO_BYTE,
			Store::REASON_SRCSET_MISSING,
		);
	}

	/**
	 * Finding IDs the remove command should act on.
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return array
	 */
	private function selected_ids( array $assoc_args ) {
		if ( isset( $assoc_args['ids'] ) ) {
			return array_values( array_filter( array_map( 'absint', explode( ',', $assoc_args['ids'] ) ) ) );
		}

		$ids     = array();
		$reasons = $this->reasons_from_args( $assoc_args, true );

		foreach ( $this->collect_rows( Store::STATUS_BROKEN, $reasons ) as $row ) {
			$ids[] = (int) $row->id;
		}

		return $ids;
	}

	/**
	 * Every finding matching a status and set of reasons.
	 *
	 * @param string $status  Finding status.
	 * @param array  $reasons Reasons to restrict to. Empty means all reasons.
	 * @return array
	 */
	private function collect_rows( $status, array $reasons ) {
		$rows  = array();
		$paged = 1;
		$found = 0;

		do {
			$batch = Store::query(
				array(
					'status'   => $status,
					'reasons'  => $reasons,
					'per_page' => self::PAGE_SIZE,
					'paged'    => $paged,
				)
			);

			$rows  = array_merge( $rows, $batch );
			$found = count( $batch );
			++$paged;
		} while ( self::PAGE_SIZE === $found );

		return $rows;
	}

	/**
	 * Preview every selected finding, in chunks the remover will accept.
	 *
	 * @param array $ids Finding IDs.
	 * @return array
	 */
	private function combined_preview( array $ids ) {
		return $this->combine( $ids, false );
	}

	/**
	 * Remove every selected finding, in chunks the remover will accept.
	 *
	 * @param array $ids Finding IDs.
	 * @return array
	 */
	private function combined_remove( array $ids ) {
		return $this->combine( $ids, true );
	}

	/**
	 * Run preview or remove across every chunk and add the reports together.
	 *
	 * @param array $ids   Finding IDs.
	 * @param bool  $write Whether to pass each chunk to remove() rather than preview().
	 * @return array
	 */
	private function combine( array $ids, $write ) {
		$report = array(
			'posts_changed'  => 0,
			'images_removed' => 0,
			'skipped'        => 0,
			'errors'         => array(),
			'items'          => array(),
		);

		foreach ( array_chunk( $ids, Remover::MAX_PER_REQUEST ) as $chunk ) {
			$part = $write ? Remover::remove( $chunk ) : Remover::preview( $chunk );

			$report['posts_changed']  += (int) $part['posts_changed'];
			$report['images_removed'] += (int) $part['images_removed'];
			$report['skipped']        += (int) $part['skipped'];
			$report['errors']          = array_merge( $report['errors'], $part['errors'] );

			if ( ! $write ) {
				$report['items'] = array_merge( $report['items'], $part['items'] );
			}
		}

		return $report;
	}

	/**
	 * Print remover errors as warnings.
	 *
	 * A permission failure is fatal: nothing can be edited, so a warning under
	 * a success line would claim a clean run that did not happen.
	 *
	 * @param array $errors Human-readable messages.
	 * @return void
	 */
	private function report_errors( array $errors ) {
		if ( empty( $errors ) ) {
			return;
		}

		$permission = __( 'Your account is not allowed to save unfiltered HTML, so post content cannot be edited safely. Ask an administrator to run the clean-up.', 'ghostpix-broken-image-cleaner' );

		if ( in_array( $permission, $errors, true ) ) {
			\WP_CLI::error( $permission );
		}

		foreach ( $errors as $error ) {
			\WP_CLI::warning( $error );
		}
	}
}
