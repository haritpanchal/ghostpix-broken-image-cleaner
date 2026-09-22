<?php
/**
 * The Tools screen.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the review screen and handles everything submitted from it.
 *
 * Actions are processed on the screen's load hook, before any output, so that
 * each one can finish with a redirect. That keeps a reload from re-submitting a
 * removal, which matters more than usual when the action edits content.
 */
class Admin {

	/**
	 * Menu slug.
	 */
	const SLUG = 'ghostpix-broken-image-cleaner';

	/**
	 * Nonce action for links and forms on this screen.
	 */
	const NONCE = 'broken_image_cleaner_action';

	/**
	 * Field and query-argument name carrying that nonce.
	 *
	 * Deliberately not the default `_wpnonce`: WP_List_Table::display_tablenav()
	 * emits its own `_wpnonce` for its bulk-action nonce, and as both fields sit
	 * in the same form the later one would win, leaving this screen unable to
	 * verify its own nonce.
	 */
	const NONCE_FIELD = 'broken_image_cleaner_nonce';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	/**
	 * Add the Tools submenu entry.
	 *
	 * @return void
	 */
	public static function register_page() {
		$hook = add_management_page(
			__( 'Broken Images', 'ghostpix-broken-image-cleaner' ),
			__( 'Broken Images', 'ghostpix-broken-image-cleaner' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle_actions' ) );
			add_action( 'admin_print_styles-' . $hook, array( __CLASS__, 'print_styles' ) );
		}
	}

	/**
	 * A nonce-protected link back to this screen carrying an action.
	 *
	 * @param string $action Action name.
	 * @param array  $args   Extra query arguments.
	 * @return string
	 */
	public static function action_url( $action, array $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'                        => self::SLUG,
					'view'                        => List_Table::current_view(),
					'broken_image_cleaner_action' => $action,
				),
				$args
			),
			admin_url( 'tools.php' )
		);

		return wp_nonce_url( $url, self::NONCE, self::NONCE_FIELD );
	}

	/**
	 * Process a submitted action, then redirect.
	 *
	 * @return void
	 */
	public static function handle_actions() {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$action = self::requested_action();

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE, self::NONCE_FIELD );

		$notice = '';

		switch ( $action ) {
			case 'scan_start':
				Scanner::start();
				$notice = 'scan_started';
				break;

			case 'scan_stop':
				Scanner::stop();
				$notice = 'scan_stopped';
				break;

			case 'clear':
				Store::truncate();
				$notice = 'cleared';
				break;

			case 'undo':
				$notice = self::handle_undo();
				break;

			case 'save_settings':
				self::handle_save_settings();
				$notice = 'settings_saved';
				break;

			case 'remove':
			case 'ignore':
			case 'unignore':
			case 'recheck':
			case 'restore':
				$notice = self::handle_bulk( $action );
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                        => self::SLUG,
					'view'                        => List_Table::current_view(),
					'broken_image_cleaner_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Work out which action was requested, from a link or a bulk dropdown.
	 *
	 * @return string
	 */
	private static function requested_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified by the caller once an action has been identified.
		if ( isset( $_REQUEST['broken_image_cleaner_action'] ) ) {
			return sanitize_key( wp_unslash( $_REQUEST['broken_image_cleaner_action'] ) );
		}

		foreach ( array( 'action', 'action2' ) as $field ) {
			if ( isset( $_REQUEST[ $field ] ) ) {
				$value = sanitize_key( wp_unslash( $_REQUEST[ $field ] ) );

				if ( '' !== $value && '-1' !== $value ) {
					return $value;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	/**
	 * The findings selected in the table.
	 *
	 * @return array
	 */
	private static function selected_findings() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- handle_actions() calls check_admin_referer() before dispatching here.
		if ( ! isset( $_REQUEST['findings'] ) || ! is_array( $_REQUEST['findings'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- handle_actions() calls check_admin_referer() before dispatching here.
		return array_filter( array_map( 'absint', wp_unslash( $_REQUEST['findings'] ) ) );
	}

	/**
	 * Apply a bulk action to the selected findings.
	 *
	 * @param string $action Action name.
	 * @return string Notice key.
	 */
	private static function handle_bulk( $action ) {
		$ids = self::selected_findings();

		if ( empty( $ids ) ) {
			return 'nothing_selected';
		}

		switch ( $action ) {
			case 'remove':
				$report = Remover::remove( $ids );

				set_transient( 'broken_image_cleaner_report_' . get_current_user_id(), $report, MINUTE_IN_SECONDS * 5 );

				return 'removed';

			case 'ignore':
				foreach ( $ids as $id ) {
					Store::set_status( $id, Store::STATUS_IGNORED );
				}

				return 'ignored';

			case 'unignore':
				foreach ( $ids as $id ) {
					Store::set_status( $id, Store::STATUS_BROKEN );
				}

				return 'unignored';

			case 'recheck':
				$post_ids = array();

				foreach ( Store::get_many( $ids ) as $finding ) {
					$post_ids[ (int) $finding->post_id ] = true;
				}

				foreach ( array_keys( $post_ids ) as $post_id ) {
					Scanner::rescan_post( $post_id );
				}

				return 'rechecked';

			case 'restore':
				$post_ids = array();

				foreach ( Store::get_many( $ids ) as $finding ) {
					$post_ids[ (int) $finding->post_id ] = true;
				}

				foreach ( array_keys( $post_ids ) as $post_id ) {
					Backup::restore( $post_id );
				}

				return 'restored';
		}

		return '';
	}

	/**
	 * Undo the clean-up applied to one post.
	 *
	 * @return string Notice key.
	 */
	private static function handle_undo() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- handle_actions() calls check_admin_referer() before dispatching here.
		$post_id = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0;

		if ( ! $post_id ) {
			return 'undo_failed';
		}

		$result = Backup::restore( $post_id );

		return is_wp_error( $result ) ? 'undo_failed' : 'restored';
	}

	/**
	 * Save the settings form.
	 *
	 * @return void
	 */
	private static function handle_save_settings() {
		$post_types = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- handle_actions() calls check_admin_referer() before dispatching here.
		if ( isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- handle_actions() calls check_admin_referer() before dispatching here.
			$submitted  = array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) );
			$post_types = array_values( array_filter( $submitted, 'post_type_exists' ) );
		}

		if ( ! empty( $post_types ) ) {
			update_option( Scanner::POST_TYPES_OPTION, $post_types );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- handle_actions() calls check_admin_referer() before dispatching here.
		$retention = isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : Backup::DEFAULT_RETENTION_DAYS;

		update_option( Backup::RETENTION_OPTION, max( 1, min( 365, $retention ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- handle_actions() calls check_admin_referer() before dispatching here.
		update_option( Plugin::DELETE_DATA_OPTION, ! empty( $_POST['delete_data'] ) );
	}

	/**
	 * Minimal styles for the review table.
	 *
	 * @return void
	 */
	public static function print_styles() {
		wp_enqueue_style(
			'ghostpix-broken-image-cleaner-admin',
			BROKEN_IMAGE_CLEANER_URL . 'assets/admin.css',
			array(),
			BROKEN_IMAGE_CLEANER_VERSION
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ghostpix-broken-image-cleaner' ) );
		}

		$table = new List_Table();
		$table->prepare_items();

		$state = Scanner::state();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Broken Images', 'ghostpix-broken-image-cleaner' ) . '</h1>';

		self::render_notice();
		self::render_scan_panel( $state );

		echo '<form method="post">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		printf( '<input type="hidden" name="view" value="%s" />', esc_attr( List_Table::current_view() ) );

		$table->views();
		$table->display();

		echo '</form>';

		self::render_settings();

		echo '</div>';
	}

	/**
	 * Show the result of the last action.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; nothing is changed here.
		$notice = isset( $_GET['broken_image_cleaner_notice'] ) ? sanitize_key( wp_unslash( $_GET['broken_image_cleaner_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'scan_started'     => __( 'Scan started. Results will appear here as it works through your content.', 'ghostpix-broken-image-cleaner' ),
			'scan_stopped'     => __( 'Scan stopped. Anything found so far has been kept.', 'ghostpix-broken-image-cleaner' ),
			'cleared'          => __( 'All results cleared.', 'ghostpix-broken-image-cleaner' ),
			'ignored'          => __( 'The selected images will be left alone.', 'ghostpix-broken-image-cleaner' ),
			'unignored'        => __( 'The selected images have been moved back to Broken.', 'ghostpix-broken-image-cleaner' ),
			'rechecked'        => __( 'Re-checked the selected images.', 'ghostpix-broken-image-cleaner' ),
			'restored'         => __( 'The post has been restored to how it was before the clean-up.', 'ghostpix-broken-image-cleaner' ),
			'undo_failed'      => __( 'That post could not be restored. The saved copy may have expired.', 'ghostpix-broken-image-cleaner' ),
			'settings_saved'   => __( 'Settings saved.', 'ghostpix-broken-image-cleaner' ),
			'nothing_selected' => __( 'No images were selected.', 'ghostpix-broken-image-cleaner' ),
		);

		if ( 'removed' === $notice ) {
			self::render_removal_report();

			return;
		}

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$class = in_array( $notice, array( 'undo_failed', 'nothing_selected' ), true ) ? 'notice-warning' : 'notice-success';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * Report what the last removal actually did.
	 *
	 * @return void
	 */
	private static function render_removal_report() {
		$key    = 'broken_image_cleaner_report_' . get_current_user_id();
		$report = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $report ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: number of images, 2: number of posts. */
			_n(
				'Removed %1$s image from %2$s post.',
				'Removed %1$s images from %2$s posts.',
				(int) $report['images_removed'],
				'ghostpix-broken-image-cleaner'
			),
			number_format_i18n( (int) $report['images_removed'] ),
			number_format_i18n( (int) $report['posts_changed'] )
		);

		if ( ! empty( $report['skipped'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of images. */
				_n( '%s was left alone.', '%s were left alone.', (int) $report['skipped'], 'ghostpix-broken-image-cleaner' ),
				number_format_i18n( (int) $report['skipped'] )
			);
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);

		foreach ( (array) $report['errors'] as $error ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * The scan status panel and its controls.
	 *
	 * @param array $state Scan state.
	 * @return void
	 */
	private static function render_scan_panel( array $state ) {
		echo '<div class="ghostpix-broken-image-cleaner-panel">';

		if ( Scanner::is_running() ) {
			printf(
				'<p><strong>%s</strong> %s</p>',
				esc_html__( 'Scan in progress.', 'ghostpix-broken-image-cleaner' ),
				esc_html(
					sprintf(
						/* translators: 1: posts scanned, 2: total posts. */
						__( 'Checked %1$s of %2$s posts so far. Reload this page for an update.', 'ghostpix-broken-image-cleaner' ),
						number_format_i18n( (int) $state['scanned'] ),
						number_format_i18n( (int) $state['total'] )
					)
				)
			);

			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( self::action_url( 'scan_stop' ) ),
				esc_html__( 'Stop scan', 'ghostpix-broken-image-cleaner' )
			);
		} else {
			if ( $state['finished_at'] ) {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: human-readable time difference. */
							__( 'Last scan finished %s ago.', 'ghostpix-broken-image-cleaner' ),
							human_time_diff( (int) $state['finished_at'] )
						)
					)
				);
			} else {
				printf(
					'<p>%s</p>',
					esc_html__( 'No scan has run yet. Scanning only reads your content — nothing is changed until you choose to remove something.', 'ghostpix-broken-image-cleaner' )
				);
			}

			printf(
				'<a href="%s" class="button button-primary">%s</a> ',
				esc_url( self::action_url( 'scan_start' ) ),
				esc_html__( 'Start scan', 'ghostpix-broken-image-cleaner' )
			);

			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( self::action_url( 'clear' ) ),
				esc_html__( 'Clear results', 'ghostpix-broken-image-cleaner' )
			);
		}

		echo '</div>';
	}

	/**
	 * The settings form.
	 *
	 * @return void
	 */
	private static function render_settings() {
		$selected  = Scanner::post_types();
		$retention = Backup::retention_days();

		echo '<h2>' . esc_html__( 'Settings', 'ghostpix-broken-image-cleaner' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="broken_image_cleaner_action" value="save_settings" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Post types to scan', 'ghostpix-broken-image-cleaner' ) . '</th><td>';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			printf(
				'<label><input type="checkbox" name="post_types[]" value="%s"%s /> %s</label><br />',
				esc_attr( $post_type->name ),
				in_array( $post_type->name, $selected, true ) ? ' checked="checked"' : '',
				esc_html( $post_type->labels->name )
			);
		}

		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ghostpix-broken-image-cleaner-retention">' . esc_html__( 'Keep undo data for', 'ghostpix-broken-image-cleaner' ) . '</label></th><td>';
		printf(
			'<input type="number" min="1" max="365" id="ghostpix-broken-image-cleaner-retention" name="retention_days" value="%s" class="small-text" /> %s',
			esc_attr( $retention ),
			esc_html__( 'days', 'ghostpix-broken-image-cleaner' )
		);
		echo '<p class="description">' . esc_html__( 'After this, saved copies of cleaned posts are deleted and those removals can no longer be undone.', 'ghostpix-broken-image-cleaner' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'On uninstall', 'ghostpix-broken-image-cleaner' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="delete_data" value="1"%s /> %s</label>',
			get_option( Plugin::DELETE_DATA_OPTION ) ? ' checked="checked"' : '',
			esc_html__( 'Delete this plugin\'s data, including saved copies used for undo, when the plugin is deleted.', 'ghostpix-broken-image-cleaner' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'ghostpix-broken-image-cleaner' ) );

		echo '</form>';
	}
}
