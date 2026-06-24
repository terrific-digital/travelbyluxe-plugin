<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Form revision history.
 *
 * Every time a form is saved, a full snapshot of its state (title, definition,
 * type, intent, submit text, success message, actions) is written to a dedicated
 * table. A per-form "Revisions" meta box lists those versions and lets an editor
 * restore any of them with one click — so a bad edit is always recoverable.
 *
 * Snapshots are de-duplicated by content hash (a no-op save adds nothing) and the
 * history is pruned to the most recent N per form (filterable) to stay bounded.
 */
class TF_Revisions {

	/** Snapshot keys → wp_tf_forms columns. `type` is implicit (lives in the definition JSON). */
	const FIELDS = array(
		'definition'  => 'definition',
		'intent'      => 'intent',
		'submit_text' => 'submit_text',
		'success'     => 'success_message',
		'actions'     => 'actions',
	);

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_table' ) );
		// Capture AFTER the builder has written its meta (TF_Builder::save runs at 20).
		add_action( 'save_post_' . TF_CPT, array( __CLASS__, 'on_save' ), 30, 1 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_tf_restore_revision', array( __CLASS__, 'restore' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* -------------------------------------------------------------------- Table */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tf_form_revisions';
	}

	public static function maybe_create_table() {
		if ( get_option( 'tf_revisions_db_version' ) !== TF_VERSION ) { self::create_table(); }
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			form_id VARCHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(191) NOT NULL DEFAULT '',
			snapshot LONGTEXT NULL,
			author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			author_name VARCHAR(191) NOT NULL DEFAULT '',
			note VARCHAR(191) NOT NULL DEFAULT '',
			hash CHAR(40) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY form_post_id (form_post_id),
			KEY created_at (created_at)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'tf_revisions_db_version', TF_VERSION );
	}

	/* ------------------------------------------------------------------ Capture */

	/** save_post handler: guard, then capture the current state. */
	public static function on_save( $post_id ) {
		// Only capture genuine builder saves (the nonce proves it's our edit screen).
		if ( ! isset( $_POST['tf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tf_nonce'] ) ), 'tf_save' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		self::capture( $post_id );
	}

	/** Build a snapshot array of the form's current persisted state. */
	public static function snapshot_of( $post_id ) {
		$row  = TF_Forms::get_row( $post_id );
		$snap = array( 'title' => get_post_field( 'post_title', $post_id ) );
		foreach ( self::FIELDS as $key => $col ) {
			$snap[ $key ] = $row && isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
		}
		return $snap;
	}

	/**
	 * Write a revision for the form's current state. Skips when nothing changed
	 * since the latest revision (same content hash). $note labels the entry
	 * (e.g. "Created", "Updated", "Restored from #12"); empty = auto.
	 *
	 * @return int|false Inserted revision id, or false if skipped.
	 */
	public static function capture( $post_id, $note = '' ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! $post_id || get_post_type( $post_id ) !== TF_CPT ) { return false; }

		$snap = self::snapshot_of( $post_id );
		$json = wp_json_encode( $snap );
		$hash = sha1( $json );

		$last = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, hash FROM ' . self::table() . ' WHERE form_post_id = %d ORDER BY id DESC LIMIT 1',
			$post_id
		) );
		// Identical to the most recent snapshot → nothing to record.
		if ( $last && $last->hash === $hash ) { return false; }

		if ( '' === $note ) { $note = $last ? __( 'Updated', 'terrific-digital' ) : __( 'Created', 'terrific-digital' ); }

		$user = wp_get_current_user();
		$wpdb->insert( self::table(), array(
			'form_post_id' => $post_id,
			'form_id'      => TF_Forms::get_form_id( $post_id ),
			'title'        => (string) $snap['title'],
			'snapshot'     => $json,
			'author_id'    => $user ? (int) $user->ID : 0,
			'author_name'  => $user && $user->ID ? $user->display_name : __( 'System', 'terrific-digital' ),
			'note'         => $note,
			'hash'         => $hash,
			'created_at'   => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;

		self::prune( $post_id );
		return $id;
	}

	/** Keep only the most recent N revisions per form. */
	protected static function prune( $post_id ) {
		global $wpdb;
		$keep = (int) apply_filters( 'terrific_forms/revisions_keep', 50 );
		if ( $keep < 1 ) { return; }
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE form_post_id = %d ORDER BY id DESC LIMIT %d, 100000',
			$post_id, $keep
		) );
		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' );
		}
	}

	/** Write a decoded snapshot back onto a form (wp_tf_forms row + post title). */
	public static function apply_snapshot( $post_id, $snap ) {
		// Guard: a malformed/empty snapshot must never blank a form's data.
		if ( ! is_array( $snap ) || ! array_key_exists( 'definition', $snap ) ) { return false; }
		$update = array();
		foreach ( self::FIELDS as $key => $col ) {
			$update[ $col ] = isset( $snap[ $key ] ) ? (string) $snap[ $key ] : '';
		}
		TF_Forms::save( $post_id, $update );
		if ( isset( $snap['title'] ) && $snap['title'] !== get_post_field( 'post_title', $post_id ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $snap['title'] ) );
		}
		return true;
	}

	public static function get( $rev_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $rev_id ) );
	}

	public static function for_form( $post_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE form_post_id = %d ORDER BY id DESC',
			(int) $post_id
		) );
	}

	/* ------------------------------------------------------------------ Restore */

	/** admin_post handler: write a revision's snapshot back onto the form. */
	public static function restore() {
		$rev_id  = isset( $_GET['rev'] ) ? (int) $_GET['rev'] : 0;
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		check_admin_referer( 'tf_restore_rev_' . $rev_id );

		$rev = $rev_id ? self::get( $rev_id ) : null;
		if ( ! $rev || (int) $rev->form_post_id !== $post_id ) { wp_die( 'Revision not found.' ); }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { wp_die( 'Not allowed.' ); }

		$snap = json_decode( $rev->snapshot, true );
		if ( ! is_array( $snap ) ) { wp_die( 'Corrupt revision.' ); }

		self::apply_snapshot( $post_id, $snap );

		// Record the restore as its own revision so the timeline stays honest.
		self::capture( $post_id, sprintf(
			/* translators: 1: revision id, 2: human date */
			__( 'Restored from #%1$d (%2$s)', 'terrific-digital' ),
			$rev_id,
			mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rev->created_at )
		) );

		wp_safe_redirect( add_query_arg( 'tf_restored', $rev_id, get_edit_post_link( $post_id, 'url' ) ) );
		exit;
	}

	public static function notices() {
		if ( empty( $_GET['tf_restored'] ) ) { return; }
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== TF_CPT ) { return; }
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( __( 'Form restored from revision #%d.', 'terrific-digital' ), (int) $_GET['tf_restored'] ) )
		);
	}

	/* ------------------------------------------------------------------ Meta box */

	public static function meta_box() {
		add_meta_box( 'tf-revisions-box', __( 'Revisions', 'terrific-digital' ), array( __CLASS__, 'render' ), TF_CPT, 'side', 'default' );
	}

	/** Compact human summary of a snapshot: type · N steps · N fields · N actions. */
	protected static function summary( $snap ) {
		$def    = isset( $snap['definition'] ) ? json_decode( $snap['definition'], true ) : null;
		$type   = ( isset( $def['type'] ) && 'multistep' === $def['type'] ) ? 'Multi-step' : 'Regular';
		$steps  = ( is_array( $def ) && ! empty( $def['steps'] ) ) ? count( $def['steps'] ) : 0;
		$fields = 0;
		if ( is_array( $def ) && ! empty( $def['steps'] ) ) {
			foreach ( $def['steps'] as $s ) { $fields += ( ! empty( $s['fields'] ) && is_array( $s['fields'] ) ) ? count( $s['fields'] ) : 0; }
		}
		$acts    = isset( $snap['actions'] ) ? json_decode( $snap['actions'], true ) : null;
		$enabled = 0;
		if ( is_array( $acts ) ) { foreach ( $acts as $a ) { if ( ! empty( $a['enabled'] ) ) { $enabled++; } } }

		$parts = array( $type );
		if ( 'Multi-step' === $type ) { $parts[] = sprintf( _n( '%d step', '%d steps', $steps, 'terrific-digital' ), $steps ); }
		$parts[] = sprintf( _n( '%d field', '%d fields', $fields, 'terrific-digital' ), $fields );
		if ( $enabled ) { $parts[] = sprintf( _n( '%d action', '%d actions', $enabled, 'terrific-digital' ), $enabled ); }
		return implode( ' · ', $parts );
	}

	public static function render( $post ) {
		$revisions = self::for_form( $post->ID );
		if ( empty( $revisions ) ) {
			echo '<p style="color:#777;margin:0">' . esc_html__( 'No revisions yet. Each time you update this form, a restorable snapshot is saved here.', 'terrific-digital' ) . '</p>';
			return;
		}
		$current_hash = sha1( wp_json_encode( self::snapshot_of( $post->ID ) ) );
		$fmt          = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<p style="margin:0 0 8px;color:#777">' . esc_html( sprintf(
			_n( '%d saved version. Restore any of them below.', '%d saved versions. Restore any of them below.', count( $revisions ), 'terrific-digital' ),
			count( $revisions )
		) ) . '</p>';

		echo '<ul class="tf-revisions" style="margin:0;max-height:340px;overflow:auto">';
		foreach ( $revisions as $i => $rev ) {
			$is_current = ( $rev->hash === $current_hash && 0 === $i );
			$ago        = human_time_diff( strtotime( $rev->created_at ), current_time( 'timestamp' ) );
			$abs        = mysql2date( $fmt, $rev->created_at );
			$confirm    = esc_js( __( 'Restore this version? Your current form will be saved as a new revision first.', 'terrific-digital' ) );
			$restore    = wp_nonce_url(
				add_query_arg(
					array( 'action' => 'tf_restore_revision', 'rev' => $rev->id, 'post' => $post->ID ),
					admin_url( 'admin-post.php' )
				),
				'tf_restore_rev_' . $rev->id
			);
			echo '<li style="border-bottom:1px solid #f0f0f1;padding:8px 0">';
			echo '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">';
			echo '<strong title="' . esc_attr( $abs ) . '">' . esc_html( sprintf( __( '%s ago', 'terrific-digital' ), $ago ) ) . '</strong>';
			if ( $is_current ) {
				echo '<span style="font-size:11px;background:#e6f4ea;color:#1e7e34;padding:1px 7px;border-radius:9px">' . esc_html__( 'Current', 'terrific-digital' ) . '</span>';
			} else {
				echo '<a href="' . esc_url( $restore ) . '" class="button button-small" onclick="return confirm(\'' . esc_attr( $confirm ) . '\')">' . esc_html__( 'Restore', 'terrific-digital' ) . '</a>';
			}
			echo '</div>';
			echo '<div style="font-size:12px;color:#50575e;margin-top:2px">' . esc_html( $rev->note ) . ' · ' . esc_html( self::summary( json_decode( $rev->snapshot, true ) ) ) . '</div>';
			echo '<div style="font-size:11px;color:#999">' . esc_html( sprintf( __( 'by %s', 'terrific-digital' ), $rev->author_name ) ) . ' · ' . esc_html( $abs ) . '</div>';
			echo '</li>';
		}
		echo '</ul>';
	}
}
