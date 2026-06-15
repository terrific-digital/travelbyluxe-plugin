<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin list table for Terrific Forms submissions.
 */
class TF_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'submission',
			'plural'   => 'submissions',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'id'         => 'ID',
			'created_at' => 'Date',
			'status'     => 'Status',
			'form'       => 'Form',
			'summary'    => 'Submission',
			'source'     => 'Source',
		);
	}

	public function column_status( $item ) {
		$partial = isset( $item->status ) && 'completed' !== $item->status;
		$bg      = $partial ? '#f0b849' : '#46b450';
		$label   = $partial ? 'Partial' : 'Completed';
		$out     = '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:' . $bg . '">' . esc_html( $label ) . '</span>';
		if ( $partial && ! empty( $item->last_step ) ) {
			$out .= '<br><span style="font-size:11px;color:#999">reached step ' . (int) $item->last_step . '</span>';
		}
		return $out;
	}

	public function column_source( $item ) {
		$out = '';
		if ( ! empty( $item->user_ip ) ) {
			$out .= '<div><strong>IP:</strong> <code style="font-size:11px">' . esc_html( $item->user_ip ) . '</code></div>';
		}
		if ( ! empty( $item->page_url ) ) {
			$path = wp_parse_url( $item->page_url, PHP_URL_PATH );
			$out .= '<div><a href="' . esc_url( $item->page_url ) . '" target="_blank" title="' . esc_attr( $item->page_url ) . '">' . esc_html( $path ?: $item->page_url ) . ' ↗</a></div>';
		}
		if ( ! empty( $item->user_agent ) ) {
			$out .= '<div style="font-size:11px;color:#777;max-width:240px" title="' . esc_attr( $item->user_agent ) . '">' . esc_html( wp_trim_words( $item->user_agent, 12, '…' ) ) . '</div>';
		}
		return $out ? $out : '<em>—</em>';
	}

	public function get_sortable_columns() {
		return array(
			'id'         => array( 'id', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	protected function get_bulk_actions() {
		return array( 'delete' => 'Delete' );
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="sub[]" value="%d" />', $item->id );
	}

	public function column_id( $item ) {
		$view = add_query_arg( array( 'page' => 'tf-submissions', 'view' => $item->id ), admin_url( 'admin.php' ) );
		$del  = wp_nonce_url(
			add_query_arg( array( 'action' => 'tf_delete', 'id' => $item->id ), admin_url( 'admin-post.php' ) ),
			'tf_delete_' . $item->id
		);
		$actions = array(
			'view'   => sprintf( '<a href="%s">View</a>', esc_url( $view ) ),
			'delete' => sprintf( '<a href="%s" onclick="return confirm(\'Delete this submission?\')" style="color:#b32d2e">Delete</a>', esc_url( $del ) ),
		);
		return sprintf( '<a href="%s"><strong>#%d</strong></a>', esc_url( $view ), $item->id ) . $this->row_actions( $actions );
	}

	public function column_created_at( $item ) {
		return esc_html( mysql2date( 'Y-m-d H:i', $item->created_at ) );
	}

	public function column_form( $item ) {
		return esc_html( $item->form_name ) . '<br><code style="font-size:11px">' . esc_html( $item->form_id ) . '</code>';
	}

	public function column_summary( $item ) {
		$d = json_decode( $item->data, true );
		if ( ! is_array( $d ) || ! $d ) { return '<em>—</em>'; }
		$out = '<ul style="margin:0;list-style:none">';
		foreach ( $d as $k => $v ) {
			if ( '' === $v && '0' !== $v ) { continue; }
			$out .= '<li><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( wp_trim_words( (string) $v, 24, '…' ) ) . '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/** Form filter dropdown above the table. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) { return; }
		$selected = isset( $_REQUEST['form'] ) ? (int) $_REQUEST['form'] : 0;
		echo '<div class="alignleft actions">';
		TF_Submissions::forms_dropdown( $selected );
		submit_button( 'Filter', 'button', 'filter_action', false );
		echo '</div>';
	}

	/** Handle bulk delete before rows are fetched. */
	protected function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) { return; }
		check_admin_referer( 'bulk-' . $this->_args['plural'] );
		if ( ! current_user_can( 'edit_pages' ) ) { return; }
		$ids = isset( $_REQUEST['sub'] ) ? array_map( 'intval', (array) $_REQUEST['sub'] ) : array();
		if ( $ids ) {
			global $wpdb;
			$table = TF_Submissions::table();
			$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($in)", $ids ) ); // phpcs:ignore
		}
	}

	public function prepare_items() {
		global $wpdb;
		$table = TF_Submissions::table();

		$this->process_bulk_action();

		$per_page = $this->get_items_per_page( 'tf_subs_per_page', 30 );
		$paged    = max( 1, (int) $this->get_pagenum() );
		$form     = isset( $_REQUEST['form'] ) ? (int) $_REQUEST['form'] : 0;

		$orderby_allowed = array( 'id', 'created_at' );
		$orderby = isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], $orderby_allowed, true ) ? $_REQUEST['orderby'] : 'created_at';
		$order   = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';

		$where = $form ? $wpdb->prepare( 'WHERE form_post_id = %d', $form ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" ); // phpcs:ignore

		$offset = ( $paged - 1 ) * $per_page;
		$items  = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY $orderby $order LIMIT $per_page OFFSET $offset" ); // phpcs:ignore

		$this->items = $items;
		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'summary' );
	}

	public function no_items() {
		esc_html_e( 'No submissions found.', 'terrific-forms' );
	}
}
