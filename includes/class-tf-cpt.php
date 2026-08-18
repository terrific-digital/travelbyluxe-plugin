<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the Terrific Form custom post type and ensures a static form ID on save.
 */
class TF_CPT_Manager {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . TF_CPT, array( __CLASS__, 'on_save' ), 10, 2 );
		// Show the static ID + shortcode + submissions count in the forms list table.
		add_filter( 'manage_' . TF_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . TF_CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	/** Number of submissions stored for a form. */
	protected static function subs_count( $post_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . TF_Submissions::table() . ' WHERE form_post_id = %d', $post_id ) );
	}

	/** Submissions screen URL filtered to one form. */
	protected static function subs_url( $post_id ) {
		return add_query_arg( array( 'page' => 'tf-submissions', 'form' => (int) $post_id ), admin_url( 'admin.php' ) );
	}

	/** Add a "Submissions (N)" link to each form's row actions. */
	public static function row_actions( $actions, $post ) {
		if ( $post->post_type === TF_CPT && current_user_can( 'edit_pages' ) ) {
			$count = self::subs_count( $post->ID );
			$actions['tf_subs'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::subs_url( $post->ID ) ),
				esc_html( sprintf( __( 'Submissions (%s)', 'terrific-digital' ), number_format_i18n( $count ) ) )
			);
		}
		return $actions;
	}

	public static function register() {
		register_post_type( TF_CPT, array(
			'labels' => array(
				'name'               => 'Terrific Forms',
				'singular_name'      => 'Form',
				'add_new'            => 'Add New Form',
				'add_new_item'       => 'Add New Form',
				'edit_item'          => 'Edit Form',
				'new_item'           => 'New Form',
				'view_item'          => 'View Form',
				'search_items'       => 'Search Forms',
				'not_found'          => 'No forms found',
				'menu_name'          => 'Terrific Forms',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false, // nested under the "Terrific Digital" menu by TD_Menu.
			'supports'            => array( 'title' ),
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
		) );
	}

	public static function on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		tf_ensure_form_id( $post_id );
	}

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['tf_id']        = 'Form ID';
				$new['tf_intent']    = 'Intent';
				$new['tf_actions']   = 'Actions';
				$new['tf_shortcode'] = 'Shortcode';
				$new['tf_subs']      = 'Submissions';
			}
		}
		return $new;
	}

	public static function column_content( $col, $post_id ) {
		if ( 'tf_id' === $col ) {
			echo '<code>' . esc_html( TF_Forms::get_form_id( $post_id ) ) . '</code>';
		}
		if ( 'tf_intent' === $col ) {
			$intents = tf_intents();
			$key     = tf_get_intent( $post_id );
			$label   = isset( $intents[ $key ]['label'] ) ? $intents[ $key ]['label'] : $key;
			$param   = isset( $intents[ $key ]['param'] ) ? $intents[ $key ]['param'] : '';
			echo esc_html( $label );
			if ( $param ) {
				echo '<br><code style="font-size:11px">' . esc_html( $param ) . '</code>';
			}
		}
		if ( 'tf_actions' === $col ) {
			$actions = tf_get_actions( $post_id );
			$chips   = array();
			foreach ( $actions as $a ) {
				$type    = isset( $a['type'] ) ? $a['type'] : '';
				$enabled = ! empty( $a['enabled'] );
				if ( 'webhook' === $type ) {
					$cfg     = isset( $a['config'] ) ? $a['config'] : array();
					$source  = ( isset( $cfg['source'] ) && 'global' === $cfg['source'] ) ? 'Global webhook' : 'Webhook';
					$trigger = ( isset( $cfg['trigger'] ) ? $cfg['trigger'] : 'final' ) === 'every' ? ' · every step' : '';
					$label   = $source . $trigger;
					$bg      = $enabled ? '#e7f1fb' : '#f0f0f1';
					$fg      = $enabled ? '#1d4d8a' : '#787c82';
					$bd      = $enabled ? '#c8d6e8' : '#dcdcde';
				} elseif ( 'redirect' === $type ) {
					$cfg   = isset( $a['config'] ) ? $a['config'] : array();
					$mode  = ( isset( $cfg['mode'] ) && 'page' === $cfg['mode'] ) ? 'Redirect to page' : 'Redirect to URL';
					$label = $mode;
					$bg    = $enabled ? '#fff4e6' : '#f0f0f1';
					$fg    = $enabled ? '#8a4a00' : '#787c82';
					$bd    = $enabled ? '#f5d7a8' : '#dcdcde';
				} elseif ( 'datalayer' === $type ) {
					$cfg   = isset( $a['config'] ) ? $a['config'] : array();
					$event = isset( $cfg['event'] ) ? trim( (string) $cfg['event'] ) : '';
					if ( '' === $event ) { continue; } // skip blank event names
					$label = 'DL: ' . $event;
					$bg    = $enabled ? '#eafaf1' : '#f0f0f1';
					$fg    = $enabled ? '#0a6b3a' : '#787c82';
					$bd    = $enabled ? '#bfe6cf' : '#dcdcde';
				} else {
					continue;
				}
				$title = $enabled ? $label : ( $label . ' (disabled)' );
				$chips[] = sprintf(
					'<span title="%s" style="display:inline-block;margin:1px 3px 1px 0;padding:2px 8px;border:1px solid %s;border-radius:999px;background:%s;color:%s;font-size:11px;font-weight:600;line-height:1.5;%s">%s</span>',
					esc_attr( $title ),
					esc_attr( $bd ),
					esc_attr( $bg ),
					esc_attr( $fg ),
					$enabled ? '' : 'opacity:.65;text-decoration:line-through',
					esc_html( $label )
				);
			}
			echo $chips ? implode( '', $chips ) : '<em style="color:#999">—</em>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'tf_shortcode' === $col ) {
			echo '<code>[terrific_form id="' . esc_attr( $post_id ) . '"]</code>';
		}
		if ( 'tf_subs' === $col ) {
			$count = self::subs_count( $post_id );
			printf(
				'<a href="%s" class="button button-small" title="%s">%s %s</a>',
				esc_url( self::subs_url( $post_id ) ),
				esc_attr__( 'View submissions for this form', 'terrific-digital' ),
				'<span class="dashicons dashicons-feedback" style="vertical-align:text-bottom;width:16px;height:16px;font-size:16px"></span>',
				esc_html( number_format_i18n( $count ) )
			);
		}
	}
}
