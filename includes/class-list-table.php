<?php
/**
 * The review table.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists findings for review, with the bulk actions that act on them.
 */
class List_Table extends \WP_List_Table {

	/**
	 * The view currently being displayed.
	 *
	 * @var string
	 */
	private $view = 'broken';

	/**
	 * Set the table up.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'finding',
				'plural'   => 'findings',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The views offered above the table, and how each maps to a query.
	 *
	 * Images whose generated size is missing are given their own view rather
	 * than sitting in the main list. Removing the image is rarely the right fix
	 * for those, so they are kept out of the way of a bulk clean-up.
	 *
	 * @return array
	 */
	public static function view_definitions() {
		return array(
			'broken'       => array(
				'label'   => __( 'Broken', 'ghostpix-broken-image-cleaner' ),
				'status'  => Store::STATUS_BROKEN,
				'reasons' => array( Store::REASON_FILE_MISSING, Store::REASON_ZERO_BYTE, Store::REASON_SRCSET_MISSING ),
			),
			'size_missing' => array(
				'label'   => __( 'Missing sizes', 'ghostpix-broken-image-cleaner' ),
				'status'  => Store::STATUS_BROKEN,
				'reasons' => array( Store::REASON_SIZE_MISSING ),
			),
			'ignored'      => array(
				'label'   => __( 'Ignored', 'ghostpix-broken-image-cleaner' ),
				'status'  => Store::STATUS_IGNORED,
				'reasons' => array(),
			),
			'removed'      => array(
				'label'   => __( 'Removed', 'ghostpix-broken-image-cleaner' ),
				'status'  => Store::STATUS_REMOVED,
				'reasons' => array(),
			),
		);
	}

	/**
	 * The view being displayed, taken from the request.
	 *
	 * @return string
	 */
	public static function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch; no state is changed.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'broken';

		return array_key_exists( $view, self::view_definitions() ) ? $view : 'broken';
	}

	/**
	 * Table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'post'         => __( 'Post', 'ghostpix-broken-image-cleaner' ),
			'image_url'    => __( 'Image', 'ghostpix-broken-image-cleaner' ),
			'reason'       => __( 'Problem', 'ghostpix-broken-image-cleaner' ),
			'context'      => __( 'Context', 'ghostpix-broken-image-cleaner' ),
			'last_checked' => __( 'Last checked', 'ghostpix-broken-image-cleaner' ),
		);
	}

	/**
	 * Links shown above the table.
	 *
	 * @return array
	 */
	protected function get_views() {
		$views   = array();
		$current = self::current_view();

		foreach ( self::view_definitions() as $key => $definition ) {
			$count = Store::count( $definition['status'], $definition['reasons'] );
			$url   = add_query_arg(
				array(
					'page' => Admin::SLUG,
					'view' => $key,
				),
				admin_url( 'tools.php' )
			);

			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$key === $current ? ' class="current"' : '',
				esc_html( $definition['label'] ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Bulk actions available in the current view.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		if ( 'removed' === self::current_view() ) {
			return array( 'restore' => __( 'Undo removal', 'ghostpix-broken-image-cleaner' ) );
		}

		$actions = array(
			'remove'  => __( 'Remove from content', 'ghostpix-broken-image-cleaner' ),
			'recheck' => __( 'Re-check', 'ghostpix-broken-image-cleaner' ),
		);

		if ( 'ignored' === self::current_view() ) {
			$actions['unignore'] = __( 'Move back to Broken', 'ghostpix-broken-image-cleaner' );
		} else {
			$actions['ignore'] = __( 'Ignore', 'ghostpix-broken-image-cleaner' );
		}

		return $actions;
	}

	/**
	 * Load the rows for the current view.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->view = self::current_view();
		$definition = self::view_definitions()[ $this->view ];

		$per_page = 20;
		$paged    = $this->get_pagenum();

		$this->items = Store::query(
			array(
				'status'   => $definition['status'],
				'reasons'  => $definition['reasons'],
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);

		$total = Store::count( $definition['status'], $definition['reasons'] );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Message shown when there is nothing to review.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nothing to show here.', 'ghostpix-broken-image-cleaner' );
	}

	/**
	 * Row selection checkbox.
	 *
	 * @param object $item Finding row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="findings[]" value="%d" />', (int) $item->id );
	}

	/**
	 * The post the image appears in.
	 *
	 * @param object $item Finding row.
	 * @return string
	 */
	protected function column_post( $item ) {
		$post_id = (int) $item->post_id;
		$title   = get_the_title( $post_id );

		if ( '' === trim( $title ) ) {
			/* translators: %d: post ID. */
			$title = sprintf( __( '(no title) #%d', 'ghostpix-broken-image-cleaner' ), $post_id );
		}

		$edit_link = get_edit_post_link( $post_id );
		$output    = $edit_link
			? sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $edit_link ), esc_html( $title ) )
			: '<strong>' . esc_html( $title ) . '</strong>';

		$actions = array();

		if ( Backup::exists( $post_id ) ) {
			$actions['undo'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Admin::action_url( 'undo', array( 'post' => $post_id ) ) ),
				esc_html__( 'Undo changes to this post', 'ghostpix-broken-image-cleaner' )
			);
		}

		return $output . $this->row_actions( $actions );
	}

	/**
	 * The image URL.
	 *
	 * @param object $item Finding row.
	 * @return string
	 */
	protected function column_image_url( $item ) {
		return '<code>' . esc_html( $item->image_url ) . '</code>';
	}

	/**
	 * Why the image was flagged.
	 *
	 * @param object $item Finding row.
	 * @return string
	 */
	protected function column_reason( $item ) {
		$labels = array(
			Store::REASON_FILE_MISSING   => __( 'File not found', 'ghostpix-broken-image-cleaner' ),
			Store::REASON_ZERO_BYTE      => __( 'File is empty', 'ghostpix-broken-image-cleaner' ),
			Store::REASON_SIZE_MISSING   => __( 'Generated size missing (original is present)', 'ghostpix-broken-image-cleaner' ),
			Store::REASON_SRCSET_MISSING => __( 'Responsive size missing — only the srcset entry is removed, the image stays', 'ghostpix-broken-image-cleaner' ),
		);

		return isset( $labels[ $item->reason ] ) ? esc_html( $labels[ $item->reason ] ) : esc_html( $item->reason );
	}

	/**
	 * Surrounding content, as captured during the scan.
	 *
	 * @param object $item Finding row.
	 * @return string
	 */
	protected function column_context( $item ) {
		return '<span class="ghostpix-broken-image-cleaner-context">' . esc_html( $item->context_snippet ) . '</span>';
	}

	/**
	 * When the image was last checked.
	 *
	 * @param object $item Finding row.
	 * @return string
	 */
	protected function column_last_checked( $item ) {
		$timestamp = strtotime( $item->last_checked . ' UTC' );

		if ( ! $timestamp ) {
			return '—';
		}

		return esc_html(
			sprintf(
				/* translators: %s: human-readable time difference, e.g. "2 hours". */
				__( '%s ago', 'ghostpix-broken-image-cleaner' ),
				human_time_diff( $timestamp )
			)
		);
	}

	/**
	 * Fallback for any column without its own method.
	 *
	 * @param object $item        Finding row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}
}
