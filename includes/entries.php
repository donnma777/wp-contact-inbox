<?php
/**
 * 受信一覧。送られた内容を投稿タイプ df_entry に保存し、管理画面「お問い合わせ」で見る。
 *
 * 状態: df_received（受信）/ df_spam（迷惑メール。DONNMA_FORM_SPAM_DAYS 日で消す）/ trash
 * メタ: _df_form, _df_form_title, _df_fields（name / label / value の配列）, _df_name, _df_email,
 *       _df_page, _df_ip, _df_ua, _df_unread, _df_mail_failed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DONNMA_FORM_ENTRY', 'df_entry' );

/* ------------------------------------------------------------------
 * 投稿タイプと状態
 * ---------------------------------------------------------------- */

function donnma_form_register_entry_type() {
	$cap = 'manage_options';

	register_post_type(
		DONNMA_FORM_ENTRY,
		array(
			'labels'           => array(
				'name'               => '受信一覧',
				'singular_name'      => '受信',
				'menu_name'          => 'お問い合わせ',
				'all_items'          => '受信一覧',
				'edit_item'          => '受信内容',
				'view_item'          => '受信内容',
				'search_items'       => '受信を検索',
				'not_found'          => 'まだ受信はありません。',
				'not_found_in_trash' => 'ゴミ箱は空です。',
			),
			'public'           => false,
			'show_ui'          => true,
			'show_in_menu'     => true,
			'show_in_rest'     => false,
			'menu_position'    => 26,
			'menu_icon'        => 'dashicons-email-alt',
			'supports'         => false,
			'rewrite'          => false,
			'query_var'        => false,
			'delete_with_user' => false,
			'map_meta_cap'     => false,
			'capabilities'     => array(
				'edit_post'              => $cap,
				'read_post'              => $cap,
				'delete_post'            => $cap,
				'edit_posts'             => $cap,
				'edit_others_posts'      => $cap,
				'edit_published_posts'   => $cap,
				'edit_private_posts'     => $cap,
				'publish_posts'          => $cap,
				'read_private_posts'     => $cap,
				'delete_posts'           => $cap,
				'delete_others_posts'    => $cap,
				'delete_published_posts' => $cap,
				'delete_private_posts'   => $cap,
				'create_posts'           => 'do_not_allow',
			),
		)
	);

	register_post_status(
		'df_received',
		array(
			'label'                     => '受信',
			'public'                    => false,
			'protected'                 => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: 件数 */
			'label_count'               => _n_noop( '受信 <span class="count">(%s)</span>', '受信 <span class="count">(%s)</span>' ),
		)
	);

	register_post_status(
		'df_spam',
		array(
			'label'                     => '迷惑メール',
			'public'                    => false,
			'protected'                 => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: 件数 */
			'label_count'               => _n_noop( '迷惑メール <span class="count">(%s)</span>', '迷惑メール <span class="count">(%s)</span>' ),
		)
	);
}
add_action( 'init', 'donnma_form_register_entry_type' );

/**
 * Jetpack の同期（Sync）で、受信の中身を WordPress.com に送らない。
 * Jetpack Social などを入れているサイトでは、投稿タイプごとサイトのデータが送られるため。
 * 送られるのは ID・更新日時・投稿タイプだけになる（Jetpack の照合用）。
 */
function donnma_form_jetpack_sync_block( $prevent, $post ) {
	if ( is_object( $post ) && isset( $post->post_type ) && DONNMA_FORM_ENTRY === $post->post_type ) {
		return true;
	}
	return $prevent;
}
add_filter( 'jetpack_sync_prevent_sending_post_data', 'donnma_form_jetpack_sync_block', 10, 2 );

/**
 * 受信を保存する。
 *
 * @return int 投稿 ID（失敗したら 0）
 */
function donnma_form_save_entry( $form_id, $form, $values, $text, $page_url, $status ) {
	$fields = array();
	foreach ( $form['fields'] as $field ) {
		$fields[] = array(
			'name'  => $field['name'],
			'label' => $field['label'],
			'value'  => $values[ $field['name'] ],
			'repeat' => $field['repeat'] > 0,
		);
	}

	// wp_insert_post と update_post_meta は値のバックスラッシュを外すので、先に足しておく
	$entry_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => DONNMA_FORM_ENTRY,
				'post_status'  => $status,
				'post_title'   => donnma_form_subject( $form, $values ),
				'post_content' => $text,
				'post_author'  => 0,
			)
		),
		true
	);
	if ( is_wp_error( $entry_id ) || ! $entry_id ) {
		return 0;
	}

	$meta = array(
		'_df_form'       => $form_id,
		'_df_form_title' => $form['title'],
		'_df_fields'     => $fields,
		'_df_name'       => donnma_form_reply_name( $form, $values ),
		'_df_email'      => donnma_form_reply_email( $form, $values ),
		'_df_page'       => $page_url,
		'_df_ip'         => donnma_form_client_ip(),
		'_df_ua'         => donnma_form_user_agent(),
	);
	if ( $status === 'df_received' ) {
		$meta['_df_unread'] = 1;
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $entry_id, $key, wp_slash( $value ) );
	}

	return (int) $entry_id;
}

/**
 * ゴミ箱から戻したら、もとの状態（受信・迷惑メール）に戻す。WordPress の標準は下書き。
 */
function donnma_form_untrash_status( $new_status, $post_id, $previous_status ) {
	return get_post_type( $post_id ) === DONNMA_FORM_ENTRY && $previous_status ? $previous_status : $new_status;
}
add_filter( 'wp_untrash_post_status', 'donnma_form_untrash_status', 10, 3 );

/* ------------------------------------------------------------------
 * 迷惑メールの片付け
 * ---------------------------------------------------------------- */

function donnma_form_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'donnma_form_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'donnma_form_cleanup' );
	}
}
add_action( 'admin_init', 'donnma_form_schedule_cleanup' );

function donnma_form_unschedule_cleanup() {
	wp_clear_scheduled_hook( 'donnma_form_cleanup' );
}

function donnma_form_cleanup() {
	$ids = get_posts(
		array(
			'post_type'      => DONNMA_FORM_ENTRY,
			'post_status'    => 'df_spam',
			'date_query'     => array( array( 'before' => DONNMA_FORM_SPAM_DAYS . ' days ago' ) ),
			'fields'         => 'ids',
			'posts_per_page' => 200,
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}
add_action( 'donnma_form_cleanup', 'donnma_form_cleanup' );

/* ------------------------------------------------------------------
 * メニュー
 * ---------------------------------------------------------------- */

function donnma_form_unread_count() {
	$query = new WP_Query(
		array(
			'post_type'      => DONNMA_FORM_ENTRY,
			'post_status'    => 'df_received',
			'meta_key'       => '_df_unread',
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	return (int) $query->found_posts;
}

/**
 * メニューに未読の数を出す。
 */
function donnma_form_menu_bubble() {
	global $menu;
	$count = donnma_form_unread_count();
	if ( ! $count || ! is_array( $menu ) ) {
		return;
	}
	foreach ( $menu as $i => $item ) {
		if ( isset( $item[2] ) && $item[2] === 'edit.php?post_type=' . DONNMA_FORM_ENTRY ) {
			$menu[ $i ][0] .= ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>';
		}
	}
}
add_action( 'admin_menu', 'donnma_form_menu_bubble', 999 );

/* ------------------------------------------------------------------
 * 一覧
 * ---------------------------------------------------------------- */

function donnma_form_entry_columns() {
	return array(
		'cb'       => '<input type="checkbox" />',
		'df_state' => '状態',
		'title'    => '件名',
		'df_from'  => '送信者',
		'df_form' => 'フォーム',
		'df_date' => '受信日時',
	);
}
add_filter( 'manage_' . DONNMA_FORM_ENTRY . '_posts_columns', 'donnma_form_entry_columns' );

function donnma_form_entry_sortable( $columns ) {
	$columns['df_date'] = array( 'date', true );
	return $columns;
}
add_filter( 'manage_edit-' . DONNMA_FORM_ENTRY . '_sortable_columns', 'donnma_form_entry_sortable' );

function donnma_form_entry_column( $column, $post_id ) {
	switch ( $column ) {
		case 'df_state':
			echo donnma_form_state_badge( $post_id ); // 中でエスケープ済み
			break;
		case 'df_from':
			$name  = get_post_meta( $post_id, '_df_name', true );
			$email = get_post_meta( $post_id, '_df_email', true );
			echo $name !== '' ? esc_html( $name ) : '<span class="df-muted">（名前なし）</span>';
			if ( $email !== '' ) {
				echo '<br /><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
			}
			break;
		case 'df_form':
			echo esc_html( get_post_meta( $post_id, '_df_form_title', true ) );
			break;
		case 'df_date':
			echo esc_html( get_the_date( 'Y/m/d H:i', $post_id ) );
			break;
	}
}
add_action( 'manage_' . DONNMA_FORM_ENTRY . '_posts_custom_column', 'donnma_form_entry_column', 10, 2 );

/**
 * 状態のバッジ（未読・既読・返信済み）。
 */
function donnma_form_state_badge( $post_id ) {
	$replied = (int) get_post_meta( $post_id, '_df_replied', true );
	if ( $replied ) {
		return '<span class="df-badge df-badge-replied">返信済み</span><br /><span class="df-muted">' . esc_html( wp_date( 'n/j H:i', $replied ) ) . '</span>';
	}
	if ( get_post_meta( $post_id, '_df_unread', true ) ) {
		return '<span class="df-badge df-badge-unread">未読</span>';
	}
	return '<span class="df-badge df-badge-read">既読</span>';
}

function donnma_form_entry_states( $states, $post ) {
	if ( $post->post_type !== DONNMA_FORM_ENTRY ) {
		return $states;
	}
	if ( get_post_meta( $post->ID, '_df_mail_failed', true ) ) {
		$states['df_mail_failed'] = 'メール送信失敗';
	}
	return $states;
}
add_filter( 'display_post_states', 'donnma_form_entry_states', 10, 2 );

function donnma_form_entry_row_class( $classes, $class, $post_id ) {
	if ( is_admin() && get_post_type( $post_id ) === DONNMA_FORM_ENTRY && get_post_meta( $post_id, '_df_unread', true ) ) {
		$classes[] = 'df-unread';
	}
	return $classes;
}
add_filter( 'post_class', 'donnma_form_entry_row_class', 10, 3 );

function donnma_form_entry_row_actions( $actions, $post ) {
	if ( $post->post_type !== DONNMA_FORM_ENTRY ) {
		return $actions;
	}
	unset( $actions['inline hide-if-no-js'] );
	if ( isset( $actions['edit'] ) ) {
		$actions['edit'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">表示</a>';
	}
	return $actions;
}
add_filter( 'post_row_actions', 'donnma_form_entry_row_actions', 10, 2 );

function donnma_form_entry_bulk_actions( $actions ) {
	unset( $actions['edit'] );
	$status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
	if ( $status !== 'trash' ) {
		$actions['df_read']      = '既読にする';
		$actions['df_unread']    = '未読にする';
		$actions['df_replied']   = '返信済みにする';
		$actions['df_unreplied'] = '返信済みを外す';
		if ( $status === 'df_spam' ) {
			$actions['df_ham'] = '迷惑メールではない';
		} else {
			$actions['df_spam'] = '迷惑メールにする';
		}
	}
	return $actions;
}
add_filter( 'bulk_actions-edit-' . DONNMA_FORM_ENTRY, 'donnma_form_entry_bulk_actions' );

function donnma_form_entry_handle_bulk( $redirect, $action, $ids ) {
	if ( ! in_array( $action, array( 'df_read', 'df_unread', 'df_replied', 'df_unreplied', 'df_spam', 'df_ham' ), true ) || ! current_user_can( 'manage_options' ) ) {
		return $redirect;
	}
	foreach ( $ids as $id ) {
		donnma_form_entry_apply( (int) $id, $action );
	}
	return add_query_arg( 'df_done', count( $ids ), $redirect );
}
add_filter( 'handle_bulk_actions-edit-' . DONNMA_FORM_ENTRY, 'donnma_form_entry_handle_bulk', 10, 3 );

/**
 * 1件に操作をする（既読・未読・迷惑メール・迷惑メールではない）。
 */
function donnma_form_entry_apply( $id, $action ) {
	if ( get_post_type( $id ) !== DONNMA_FORM_ENTRY ) {
		return;
	}
	switch ( $action ) {
		case 'df_read':
			delete_post_meta( $id, '_df_unread' );
			break;
		case 'df_unread':
			update_post_meta( $id, '_df_unread', 1 );
			break;
		case 'df_replied':
			if ( ! get_post_meta( $id, '_df_replied', true ) ) {
				update_post_meta( $id, '_df_replied', time() );
			}
			delete_post_meta( $id, '_df_unread' );
			break;
		case 'df_unreplied':
			delete_post_meta( $id, '_df_replied' );
			break;
		case 'df_spam':
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'df_spam',
				)
			);
			delete_post_meta( $id, '_df_unread' );
			break;
		case 'df_ham':
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'df_received',
				)
			);
			break;
	}
}

function donnma_form_entry_bulk_notice() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->id !== 'edit-' . DONNMA_FORM_ENTRY ) {
		return;
	}
	if ( isset( $_GET['df_done'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . (int) $_GET['df_done'] . ' 件を変更しました。</p></div>';
	}
}
add_action( 'admin_notices', 'donnma_form_entry_bulk_notice' );

/**
 * 一覧の上に、フォームでの絞り込みと CSV の書き出しを置く。
 */
function donnma_form_entry_filters( $post_type, $which ) {
	if ( $post_type !== DONNMA_FORM_ENTRY || $which !== 'top' ) {
		return;
	}
	$current = isset( $_GET['df_form'] ) ? sanitize_key( wp_unslash( $_GET['df_form'] ) ) : '';
	$titles  = donnma_form_entry_form_titles();

	echo '<select name="df_form"><option value="">すべてのフォーム</option>';
	foreach ( $titles as $id => $title ) {
		echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $title ) . '</option>';
	}
	echo '</select>';

	$state = isset( $_GET['df_state'] ) ? sanitize_key( wp_unslash( $_GET['df_state'] ) ) : '';
	echo '<select name="df_state"><option value="">すべての状態</option>';
	foreach ( array( 'unread' => '未読', 'unreplied' => '未返信', 'replied' => '返信済み' ) as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $state, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	submit_button( '絞り込み', '', 'filter_action', false );

	$status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
	$url    = wp_nonce_url(
		add_query_arg(
			array(
				'action'      => 'df_export',
				'df_form'     => $current,
				'post_status' => $status === 'df_spam' ? 'df_spam' : 'df_received',
			),
			admin_url( 'admin-post.php' )
		),
		'df_export'
	);
	echo ' <a class="button" href="' . esc_url( $url ) . '">CSV に書き出す</a>';
}
add_action( 'restrict_manage_posts', 'donnma_form_entry_filters', 10, 2 );

/**
 * 受信のあるフォームと、いまあるフォームの名前。ID => 名前
 */
function donnma_form_entry_form_titles() {
	global $wpdb;
	$titles = array();
	foreach ( donnma_form_definitions() as $id => $form ) {
		$titles[ $id ] = $form['title'];
	}
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT f.meta_value AS id, t.meta_value AS title FROM {$wpdb->postmeta} f
			INNER JOIN {$wpdb->posts} p ON p.ID = f.post_id AND p.post_type = %s
			LEFT JOIN {$wpdb->postmeta} t ON t.post_id = f.post_id AND t.meta_key = '_df_form_title'
			WHERE f.meta_key = '_df_form'",
			DONNMA_FORM_ENTRY
		)
	);
	foreach ( $rows as $row ) {
		if ( ! isset( $titles[ $row->id ] ) ) {
			$titles[ $row->id ] = $row->title . '（削除済み）';
		}
	}
	return $titles;
}

function donnma_form_entry_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== DONNMA_FORM_ENTRY ) {
		return;
	}
	$meta = array();
	if ( ! empty( $_GET['df_form'] ) ) {
		$meta[] = array(
			'key'   => '_df_form',
			'value' => sanitize_key( wp_unslash( $_GET['df_form'] ) ),
		);
	}
	$state = isset( $_GET['df_state'] ) ? sanitize_key( wp_unslash( $_GET['df_state'] ) ) : '';
	if ( $state === 'unread' ) {
		$meta[] = array(
			'key'     => '_df_unread',
			'compare' => 'EXISTS',
		);
	} elseif ( $state === 'unreplied' ) {
		$meta[] = array(
			'key'     => '_df_replied',
			'compare' => 'NOT EXISTS',
		);
	} elseif ( $state === 'replied' ) {
		$meta[] = array(
			'key'     => '_df_replied',
			'compare' => 'EXISTS',
		);
	}
	if ( $meta ) {
		$query->set( 'meta_query', $meta );
	}
}
add_action( 'pre_get_posts', 'donnma_form_entry_query' );

/* ------------------------------------------------------------------
 * 1件の表示
 * ---------------------------------------------------------------- */

function donnma_form_entry_boxes( $post ) {
	remove_meta_box( 'submitdiv', DONNMA_FORM_ENTRY, 'side' );
	remove_meta_box( 'slugdiv', DONNMA_FORM_ENTRY, 'normal' );
	add_meta_box( 'df_entry_content', esc_html( $post->post_title ), 'donnma_form_entry_content_box', DONNMA_FORM_ENTRY, 'normal', 'high' );
	add_meta_box( 'df_entry_info', '受信の情報', 'donnma_form_entry_info_box', DONNMA_FORM_ENTRY, 'side', 'high' );
	add_meta_box( 'df_entry_reply', '返信', 'donnma_form_reply_box', DONNMA_FORM_ENTRY, 'normal', 'default' );
}
add_action( 'add_meta_boxes_' . DONNMA_FORM_ENTRY, 'donnma_form_entry_boxes' );

/**
 * 開いたら既読にする。
 */
function donnma_form_entry_mark_read() {
	$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	if ( $id && get_post_type( $id ) === DONNMA_FORM_ENTRY && current_user_can( 'manage_options' ) ) {
		delete_post_meta( $id, '_df_unread' );
	}
}
add_action( 'load-post.php', 'donnma_form_entry_mark_read' );

function donnma_form_entry_content_box( $post ) {
	$fields = get_post_meta( $post->ID, '_df_fields', true );
	if ( ! is_array( $fields ) ) {
		echo '<pre class="df-text">' . esc_html( $post->post_content ) . '</pre>';
		return;
	}
	echo '<table class="df-entry-table">';
	foreach ( $fields as $field ) {
		$value = donnma_form_field_text( $field, $field['value'] );
		echo '<tr><th scope="row">' . esc_html( $field['label'] ) . '</th><td>';
		echo $value !== '' ? nl2br( esc_html( $value ) ) : '<span class="df-muted">（なし）</span>';
		echo '</td></tr>';
	}
	echo '</table>';
}

function donnma_form_entry_info_box( $post ) {
	$email = get_post_meta( $post->ID, '_df_email', true );

	$rows = array(
		'受信日時'   => esc_html( get_the_date( 'Y/m/d H:i:s', $post ) ),
		'フォーム'   => esc_html( get_post_meta( $post->ID, '_df_form_title', true ) ),
		'状態'     => $post->post_status === 'df_spam' ? '<span class="df-badge df-badge-spam">迷惑メール</span>' : donnma_form_state_badge( $post->ID ),
		'送信元ページ' => '<a href="' . esc_url( get_post_meta( $post->ID, '_df_page', true ) ) . '" target="_blank" rel="noopener">' . esc_html( get_post_meta( $post->ID, '_df_page', true ) ) . '</a>',
		'IP'     => esc_html( get_post_meta( $post->ID, '_df_ip', true ) ),
		'ブラウザ'   => esc_html( get_post_meta( $post->ID, '_df_ua', true ) ),
	);
	if ( get_post_meta( $post->ID, '_df_mail_failed', true ) ) {
		$rows['メール'] = '<strong class="df-warn">送信に失敗しました（内容はここにだけあります）</strong>';
	}

	echo '<dl class="df-info">';
	foreach ( $rows as $label => $html ) {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd>'; // $html は上でエスケープ済み
	}
	echo '</dl>';

	echo '<p class="df-actions">';
	if ( $post->post_status !== 'df_spam' ) {
		$replied = get_post_meta( $post->ID, '_df_replied', true );
		echo '<a class="button" href="' . esc_url( donnma_form_entry_action_url( $post->ID, $replied ? 'df_unreplied' : 'df_replied' ) ) . '">' . ( $replied ? '返信済みを外す' : '返信済みにする' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( donnma_form_entry_action_url( $post->ID, 'df_unread' ) ) . '">未読に戻す</a> ';
	}
	$action = $post->post_status === 'df_spam' ? 'df_ham' : 'df_spam';
	$label  = $post->post_status === 'df_spam' ? '迷惑メールではない' : '迷惑メールにする';
	echo '<a class="button" href="' . esc_url( donnma_form_entry_action_url( $post->ID, $action ) ) . '">' . esc_html( $label ) . '</a> ';
	echo '<a class="button df-delete" href="' . esc_url( get_delete_post_link( $post->ID ) ) . '">ゴミ箱へ</a>';
	echo '</p>';
	if ( $email !== '' ) {
		$mailto = 'mailto:' . rawurlencode( $email ) . '?subject=' . rawurlencode( 'Re: ' . $post->post_title );
		echo '<p><a href="' . esc_attr( $mailto ) . '">メールソフトで返信する</a></p>';
	}
	echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . DONNMA_FORM_ENTRY ) ) . '">← 受信一覧へ</a></p>';
}

function donnma_form_entry_action() {
	$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}
	check_admin_referer( 'df_entry_action_' . $id );
	donnma_form_entry_apply( $id, $do );
	if ( $do === 'df_spam' ) {
		$back = admin_url( 'edit.php?post_type=' . DONNMA_FORM_ENTRY . '&post_status=df_spam' );
	} elseif ( $do === 'df_ham' || $do === 'df_unread' ) {
		// 未読に戻したら、開き直すと既読になってしまうので一覧へ
		$back = admin_url( 'edit.php?post_type=' . DONNMA_FORM_ENTRY );
	} else {
		$back = get_edit_post_link( $id, 'url' );
	}
	wp_safe_redirect( $back );
	exit;
}

function donnma_form_entry_action_url( $post_id, $do ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'df_entry_action',
				'do'     => $do,
				'post'   => $post_id,
			),
			admin_url( 'admin-post.php' )
		),
		'df_entry_action_' . $post_id
	);
}
add_action( 'admin_post_df_entry_action', 'donnma_form_entry_action' );

/* ------------------------------------------------------------------
 * CSV
 * ---------------------------------------------------------------- */

function donnma_form_csv_cell( $value ) {
	$value = (string) $value;
	// 表計算ソフトで式として動かないようにする
	if ( $value !== '' && strpos( "=+-@\t\r", $value[0] ) !== false ) {
		$value = "'" . $value;
	}
	return $value;
}

function donnma_form_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}
	check_admin_referer( 'df_export' );

	$form_id = isset( $_GET['df_form'] ) ? sanitize_key( wp_unslash( $_GET['df_form'] ) ) : '';
	$status  = isset( $_GET['post_status'] ) && $_GET['post_status'] === 'df_spam' ? 'df_spam' : 'df_received';

	$args = array(
		'post_type'      => DONNMA_FORM_ENTRY,
		'post_status'    => $status,
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
	);
	if ( $form_id !== '' ) {
		$args['meta_key']   = '_df_form';
		$args['meta_value'] = $form_id;
	}
	$posts = get_posts( $args );

	// 列は項目のラベル。フォームの今の並び順を先に、ほかは出てきた順
	$labels = array();
	$form   = $form_id !== '' ? donnma_form_get( $form_id ) : null;
	if ( $form ) {
		foreach ( $form['fields'] as $field ) {
			$labels[ $field['label'] ] = true;
		}
	}
	$rows = array();
	foreach ( $posts as $post ) {
		$values = array();
		foreach ( (array) get_post_meta( $post->ID, '_df_fields', true ) as $field ) {
			if ( is_array( $field ) && isset( $field['label'] ) ) {
				$labels[ $field['label'] ] = true;
				$values[ $field['label'] ] = donnma_form_field_text( $field, $field['value'] );
			}
		}
		$rows[] = array( $post, $values );
	}
	$labels = array_keys( $labels );

	$name = 'contact-inbox-' . ( $form_id !== '' ? $form_id . '-' : '' ) . ( $status === 'df_spam' ? 'spam-' : '' ) . wp_date( 'Ymd-His' ) . '.csv';
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // Excel で文字化けしないように
	fputcsv( $out, array_merge( array( '受信日時', 'フォーム' ), $labels, array( '送信元ページ', 'IP' ) ) );
	foreach ( $rows as $row ) {
		list( $post, $values ) = $row;
		$line = array( get_the_date( 'Y-m-d H:i:s', $post ), get_post_meta( $post->ID, '_df_form_title', true ) );
		foreach ( $labels as $label ) {
			$line[] = $values[ $label ] ?? '';
		}
		$line[] = get_post_meta( $post->ID, '_df_page', true );
		$line[] = get_post_meta( $post->ID, '_df_ip', true );
		fputcsv( $out, array_map( 'donnma_form_csv_cell', $line ) );
	}
	fclose( $out );
	exit;
}
add_action( 'admin_post_df_export', 'donnma_form_export_csv' );

/* ------------------------------------------------------------------
 * 管理画面の見た目
 * ---------------------------------------------------------------- */

function donnma_form_admin_assets( $hook ) {
	$screen = get_current_screen();
	$ours   = $screen && ( $screen->post_type === DONNMA_FORM_ENTRY || strpos( $hook, 'donnma-form' ) !== false );
	if ( ! $ours ) {
		return;
	}
	wp_enqueue_style( 'donnma-form-admin', DONNMA_FORM_URL . 'assets/admin.css', array(), DONNMA_FORM_VERSION );
	if ( strpos( $hook, 'donnma-form-forms' ) !== false ) {
		wp_enqueue_script( 'donnma-form-admin', DONNMA_FORM_URL . 'assets/admin.js', array(), DONNMA_FORM_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'donnma_form_admin_assets' );
