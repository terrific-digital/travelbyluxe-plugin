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
			'flag'       => 'Flag',
			'created_at' => 'Date',
			'status'     => 'Status',
			'form'       => 'Form',
			'intent'     => 'Intent',
			'summary'    => 'Submission',
			'source'     => 'Source',
		);
	}

	public function column_flag( $item ) {
		$cur = isset( $item->flag ) ? TF_Submissions::normalize_flag( $item->flag ) : '';
		$cls = 'tf-flag-cell';
		if ( 'test' === $cur ) { $cls .= ' tf-flag-is-test'; }
		if ( 'spam' === $cur ) { $cls .= ' tf-flag-is-spam'; }

		// Inline SVGs sized via CSS, currentColor inherits from the button.
		// Test = Erlenmeyer flask. Spam = prohibition circle (universal "blocked").
		$flask = '<svg class="tf-flag-icon" viewBox="0 0 24 24" aria-hidden="true">'
			. '<path fill="currentColor" d="M9 2h6v2h-1v5.5l4.6 7.7c.8 1.3-.2 3-1.7 3H7.1c-1.5 0-2.5-1.7-1.7-3L10 9.5V4H9V2zm2 2v6.1L7.1 17h9.8L13 10.1V4h-2z"/>'
			. '</svg>';
		$no    = '<svg class="tf-flag-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">'
			. '<circle cx="12" cy="12" r="9"/><line x1="6.2" y1="6.2" x2="17.8" y2="17.8"/>'
			. '</svg>';

		return sprintf(
			'<span class="%s" data-id="%d" data-flag="%s">'
				. '<button type="button" class="tf-flag-btn tf-flag-btn-test" data-set="test" aria-label="Mark as Test" title="Mark as Test">%s</button>'
				. '<button type="button" class="tf-flag-btn tf-flag-btn-spam" data-set="spam" aria-label="Mark as Spam" title="Mark as Spam">%s</button>'
			. '</span>',
			esc_attr( $cls ),
			(int) $item->id,
			esc_attr( $cur ),
			$flask, // phpcs:ignore — static SVG markup
			$no
		);
	}

	public function column_intent( $item ) {
		$param = isset( $item->intent ) ? (string) $item->intent : '';
		if ( '' === $param ) { return '<em>—</em>'; }
		// Map the stored param (e.g. generate_lead) back to its human label via tf_intents().
		$label = $param;
		foreach ( tf_intents() as $row ) {
			if ( isset( $row['param'], $row['label'] ) && $row['param'] === $param ) {
				$label = $row['label'];
				break;
			}
		}
		return esc_html( $label ) . '<br><code style="font-size:11px">' . esc_html( $param ) . '</code>';
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
		return array(
			'flag_test' => 'Mark as Test',
			'flag_spam' => 'Mark as Spam',
			'unflag'    => 'Clear flag',
			'delete'    => 'Delete',
		);
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

	/** Form + intent + flag filters above the table. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) { return; }
		$selected_form   = isset( $_REQUEST['form'] ) ? (int) $_REQUEST['form'] : 0;
		$selected_intent = isset( $_REQUEST['intent'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['intent'] ) ) : '';
		// Default to "active" on a fresh landing so test/spam noise stays out of the
		// working view. The user can still pick "All flags" — that submits flag='' as
		// an explicit choice, which we respect (isset detects it).
		$selected_flag = isset( $_REQUEST['flag'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['flag'] ) ) : 'active';
		echo '<div class="alignleft actions">';
		TF_Submissions::forms_dropdown( $selected_form );
		self::intent_dropdown( $selected_intent );
		self::flag_dropdown( $selected_flag );
		submit_button( 'Filter', 'button', 'filter_action', false );
		echo '</div>';
	}

	/** Flag filter: "all" / "active" (unflagged) / "test" / "spam". */
	protected static function flag_dropdown( $selected ) {
		$options = array(
			''       => 'All flags',
			'active' => 'Active (unflagged)',
			'test'   => 'Test',
			'spam'   => 'Spam',
		);
		echo '<select name="flag" style="margin-left:6px">';
		foreach ( $options as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $selected, $val, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/** Intent dropdown — options come from tf_intents() (param → label). */
	protected static function intent_dropdown( $selected ) {
		echo '<select name="intent" style="margin-left:6px">';
		echo '<option value="">All intents</option>';
		foreach ( tf_intents() as $row ) {
			if ( empty( $row['param'] ) ) { continue; }
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $row['param'] ),
				selected( $selected, $row['param'], false ),
				esc_html( $row['label'] )
			);
		}
		echo '</select>';
	}

	/** Handle bulk delete / flag changes before rows are fetched. */
	protected function process_bulk_action() {
		$action = $this->current_action();
		if ( ! in_array( $action, array( 'delete', 'flag_test', 'flag_spam', 'unflag' ), true ) ) { return; }
		check_admin_referer( 'bulk-' . $this->_args['plural'] );
		if ( ! current_user_can( 'edit_pages' ) ) { return; }
		$ids = isset( $_REQUEST['sub'] ) ? array_map( 'intval', (array) $_REQUEST['sub'] ) : array();
		if ( ! $ids ) { return; }

		global $wpdb;
		$table = TF_Submissions::table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( 'delete' === $action ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($in)", $ids ) ); // phpcs:ignore
			return;
		}
		$flag = 'flag_test' === $action ? 'test' : ( 'flag_spam' === $action ? 'spam' : '' );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET flag = %s WHERE id IN ($in)", array_merge( array( $flag ), $ids ) ) ); // phpcs:ignore
	}

	public function prepare_items() {
		global $wpdb;
		$table = TF_Submissions::table();

		$this->process_bulk_action();

		$per_page = $this->get_items_per_page( 'tf_subs_per_page', 30 );
		$paged    = max( 1, (int) $this->get_pagenum() );
		$form     = isset( $_REQUEST['form'] ) ? (int) $_REQUEST['form'] : 0;
		$intent   = isset( $_REQUEST['intent'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['intent'] ) ) : '';
		// Mirrors extra_tablenav: default to "active" so a fresh visit hides flagged rows.
		$flag     = isset( $_REQUEST['flag'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['flag'] ) ) : 'active';

		$orderby_allowed = array( 'id', 'created_at' );
		$orderby = isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], $orderby_allowed, true ) ? $_REQUEST['orderby'] : 'created_at';
		$order   = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';

		$clauses = array();
		$args    = array();
		if ( $form )   { $clauses[] = 'form_post_id = %d'; $args[] = $form; }
		if ( $intent ) { $clauses[] = 'intent = %s';       $args[] = $intent; }
		if ( 'active' === $flag ) {
			$clauses[] = "flag = ''";
		} elseif ( in_array( $flag, array( 'test', 'spam' ), true ) ) {
			$clauses[] = 'flag = %s'; $args[] = $flag;
		}
		$where = $clauses ? ( 'WHERE ' . implode( ' AND ', $clauses ) ) : '';
		$where_sql = ( $where && $args ) ? $wpdb->prepare( $where, $args ) : $where; // phpcs:ignore

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where_sql" ); // phpcs:ignore

		$offset = ( $paged - 1 ) * $per_page;
		$items  = $wpdb->get_results( "SELECT * FROM $table $where_sql ORDER BY $orderby $order LIMIT $per_page OFFSET $offset" ); // phpcs:ignore

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
