<?php
/**
 * 管理画面「お問い合わせ → フォームの編集」。フォームの一覧・追加・編集・複製・削除。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function donnma_form_forms_page_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'edit.php?post_type=' . DONNMA_FORM_ENTRY . '&page=donnma-form-forms' ) );
}

function donnma_form_add_forms_page() {
	$hook = add_submenu_page(
		'edit.php?post_type=' . DONNMA_FORM_ENTRY,
		'フォームの編集',
		'フォームの編集',
		'manage_options',
		'donnma-form-forms',
		'donnma_form_render_forms_page'
	);
	add_action( 'load-' . $hook, 'donnma_form_handle_forms_post' );
}
add_action( 'admin_menu', 'donnma_form_add_forms_page' );

/**
 * プラグイン一覧に「フォームの編集」のリンクを出す。
 */
function donnma_form_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $links, '<a href="' . esc_url( donnma_form_forms_page_url() ) . '">フォームの編集</a>' );
	}
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( DONNMA_FORM_FILE ), 'donnma_form_action_links' );

/* ------------------------------------------------------------------
 * 保存・複製・削除
 * ---------------------------------------------------------------- */

/**
 * 送られた内容からフォームを組み立てる。
 *
 * @return array array( フォーム, ID, エラーの配列 )
 */
function donnma_form_build_from_post( $post, $forms ) {
	$errors      = array();
	$original_id = sanitize_key( $post['df_original_id'] ?? '' );
	$id          = $original_id !== '' ? $original_id : sanitize_key( $post['df_id'] ?? '' );

	$form = array(
		'title'            => sanitize_text_field( $post['df_title'] ?? '' ),
		'to'               => sanitize_email( $post['df_to'] ?? '' ),
		'from'             => sanitize_email( $post['df_from'] ?? '' ),
		'subject_field'    => sanitize_key( $post['df_subject_field'] ?? '' ),
		'reply_to_field'   => sanitize_key( $post['df_reply_to_field'] ?? '' ),
		'reply_name_field' => sanitize_key( $post['df_reply_name_field'] ?? '' ),
		'sent_message'     => sanitize_text_field( $post['df_sent_message'] ?? '' ),
		'submit_label'     => sanitize_text_field( $post['df_submit_label'] ?? '' ),
		'fields'           => array(),
	);

	if ( $form['title'] === '' ) {
		$errors[] = 'フォームの名前を入れてください。';
	}
	if ( $id === '' ) {
		$errors[] = 'ID を半角英数字で入れてください。';
	} elseif ( $original_id === '' && isset( $forms[ $id ] ) ) {
		$errors[] = 'ID「' . $id . '」はもう使われています。';
	}
	if ( ( $post['df_to'] ?? '' ) !== '' && $form['to'] === '' ) {
		$errors[] = '送信先のメールアドレスが正しくありません。';
	}
	if ( ( $post['df_from'] ?? '' ) !== '' && $form['from'] === '' ) {
		$errors[] = '送信元のメールアドレスが正しくありません。';
	}

	$types = donnma_form_field_types();
	$names = array();
	$rows  = isset( $post['fields'] ) && is_array( $post['fields'] ) ? $post['fields'] : array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$label = sanitize_text_field( $row['label'] ?? '' );
		if ( $label === '' ) {
			continue; // ラベルのない行は捨てる
		}
		$type  = isset( $row['type'], $types[ $row['type'] ] ) ? $row['type'] : 'text';
		$field = array(
			'name'     => sanitize_key( $row['name'] ?? '' ),
			'type'     => $type,
			'label'    => $label,
			'required' => ! empty( $row['required'] ),
			'max'      => absint( $row['max'] ?? 0 ),
			'rows'     => $type === 'textarea' ? absint( $row['rows'] ?? 0 ) : 0,
			'repeat'   => donnma_form_can_repeat( $type ) ? min( 50, absint( $row['repeat'] ?? 0 ) ) : 0,
			'options'  => array(),
		);
		if ( donnma_form_is_choice( $type ) ) {
			$options = preg_split( '/\r\n|\r|\n/', (string) ( $row['options'] ?? '' ) );
			$options = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $options ), 'strlen' ) ) );
			if ( ! $options ) {
				$errors[] = '「' . $label . '」の選択肢を1行に1つずつ入れてください。';
			}
			$field['options'] = $options;
			$field['max']     = 0;
		}
		if ( $field['name'] === '' ) {
			$n = 1;
			while ( in_array( 'f' . $n, $names, true ) ) {
				$n++;
			}
			$field['name'] = 'f' . $n;
		}
		if ( in_array( $field['name'], $names, true ) ) {
			$errors[] = '項目の名前「' . $field['name'] . '」が重なっています。';
		}
		$names[]          = $field['name'];
		$form['fields'][] = $field;
	}
	if ( ! $form['fields'] ) {
		$errors[] = '項目を1つ以上作ってください。';
	}

	// 件名・返信先の項目は、ある項目だけ（返信先はメールアドレスの項目だけ）
	$by_name = array();
	foreach ( $form['fields'] as $field ) {
		$by_name[ $field['name'] ] = $field;
	}
	foreach ( array( 'subject_field', 'reply_name_field' ) as $key ) {
		if ( ! isset( $by_name[ $form[ $key ] ] ) ) {
			$form[ $key ] = '';
		}
	}
	if ( ! isset( $by_name[ $form['reply_to_field'] ] ) || $by_name[ $form['reply_to_field'] ]['type'] !== 'email' ) {
		$form['reply_to_field'] = '';
	}

	return array( $form, $id, $errors );
}

function donnma_form_unique_id( $base, $forms ) {
	$id = $base;
	$n  = 2;
	while ( isset( $forms[ $id ] ) ) {
		$id = $base . '-' . $n;
		$n++;
	}
	return $id;
}

/**
 * 画面を出す前に、保存・複製・削除を処理する。
 */
function donnma_form_handle_forms_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$forms = get_option( DONNMA_FORM_OPTION, array() );
	if ( ! is_array( $forms ) ) {
		$forms = donnma_form_definitions();
	}

	donnma_form_save_reply_template();

	// 複製・削除（一覧のボタン）
	if ( isset( $_GET['df_do'], $_GET['form'] ) ) {
		$do = sanitize_key( wp_unslash( $_GET['df_do'] ) );
		$id = sanitize_key( wp_unslash( $_GET['form'] ) );
		check_admin_referer( 'df_form_' . $do . '_' . $id );
		if ( isset( $forms[ $id ] ) ) {
			if ( $do === 'copy' ) {
				$copy          = $forms[ $id ];
				$copy['title'] = $copy['title'] . '（コピー）';
				$new_id        = donnma_form_unique_id( $id . '-copy', $forms );
				$forms[ $new_id ] = $copy;
				donnma_form_save_all( $forms );
				wp_safe_redirect( donnma_form_forms_page_url( array( 'edit' => $new_id, 'df_msg' => 'copied' ) ) );
				exit;
			}
			if ( $do === 'delete' ) {
				unset( $forms[ $id ] );
				donnma_form_save_all( $forms );
				wp_safe_redirect( donnma_form_forms_page_url( array( 'df_msg' => 'deleted' ) ) );
				exit;
			}
		}
		wp_safe_redirect( donnma_form_forms_page_url() );
		exit;
	}

	// 保存（編集画面）
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['df_save_form'] ) ) {
		check_admin_referer( 'df_save_form' );
		list( $form, $id, $errors ) = donnma_form_build_from_post( wp_unslash( $_POST ), $forms );
		if ( $errors ) {
			$GLOBALS['donnma_form_edit_state'] = array(
				'form'   => donnma_form_normalize( $form ),
				'id'     => $id,
				'is_new' => sanitize_key( wp_unslash( $_POST['df_original_id'] ?? '' ) ) === '',
				'errors' => $errors,
			);
			return;
		}
		$forms[ $id ] = $form;
		donnma_form_save_all( $forms );
		wp_safe_redirect( donnma_form_forms_page_url( array( 'edit' => $id, 'df_msg' => 'saved' ) ) );
		exit;
	}
}

/* ------------------------------------------------------------------
 * 画面
 * ---------------------------------------------------------------- */

function donnma_form_render_forms_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$messages = array(
		'saved'   => '保存しました。',
		'copied'  => '複製しました。名前と ID を確かめて保存してください。',
		'deleted'  => 'フォームを削除しました。受信した内容は受信一覧に残っています。',
		'template' => '返信のひな形を保存しました。',
	);
	$msg = isset( $_GET['df_msg'] ) ? sanitize_key( wp_unslash( $_GET['df_msg'] ) ) : '';

	echo '<div class="wrap df-forms">';
	if ( isset( $GLOBALS['donnma_form_edit_state'] ) || isset( $_GET['edit'] ) || isset( $_GET['new'] ) ) {
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}
		donnma_form_render_edit();
	} else {
		echo '<h1 class="wp-heading-inline">フォームの編集</h1> <a class="page-title-action" href="' . esc_url( donnma_form_forms_page_url( array( 'new' => 1 ) ) ) . '">フォームを追加</a><hr class="wp-header-end" />';
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}
		donnma_form_render_list();
		donnma_form_render_reply_template_setting();
	}
	echo '</div>';
}

function donnma_form_count_entries( $form_id ) {
	$query = new WP_Query(
		array(
			'post_type'      => DONNMA_FORM_ENTRY,
			'post_status'    => 'df_received',
			'meta_key'       => '_df_form',
			'meta_value'     => $form_id,
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	return (int) $query->found_posts;
}

function donnma_form_render_list() {
	$forms = donnma_form_definitions();
	echo '<p>ページには、ブロック「フォーム」を追加して、表示するフォームを選びます。ブロックが使えない場所（ウィジェットなど）では、ショートコードを貼ります。</p>';
	echo '<table class="widefat striped df-form-list"><thead><tr><th>フォーム</th><th>ショートコード</th><th>項目</th><th>受信</th><th>送信先</th></tr></thead><tbody>';
	if ( ! $forms ) {
		echo '<tr><td colspan="5">フォームがありません。</td></tr>';
	}
	foreach ( $forms as $id => $form ) {
		$edit   = donnma_form_forms_page_url( array( 'edit' => $id ) );
		$copy   = wp_nonce_url( donnma_form_forms_page_url( array( 'df_do' => 'copy', 'form' => $id ) ), 'df_form_copy_' . $id );
		$delete = wp_nonce_url( donnma_form_forms_page_url( array( 'df_do' => 'delete', 'form' => $id ) ), 'df_form_delete_' . $id );
		$count  = donnma_form_count_entries( $id );
		$list   = admin_url( 'edit.php?post_type=' . DONNMA_FORM_ENTRY . '&df_form=' . rawurlencode( $id ) );

		echo '<tr><td><strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $form['title'] ) . '</a></strong>';
		echo '<div class="row-actions"><span><a href="' . esc_url( $edit ) . '">編集</a> | </span><span><a href="' . esc_url( $copy ) . '">複製</a> | </span>';
		echo '<span class="trash"><a href="' . esc_url( $delete ) . '" onclick="return confirm(\'フォーム「' . esc_js( $form['title'] ) . '」を削除しますか？\nページに貼ったショートコードは表示されなくなります。受信した内容は残ります。\')">削除</a></span></div></td>';
		echo '<td><input type="text" class="df-shortcode" readonly value="' . esc_attr( '[donnma_form id="' . $id . '"]' ) . '" onclick="this.select()" /></td>';
		echo '<td>' . count( $form['fields'] ) . '</td>';
		echo '<td><a href="' . esc_url( $list ) . '">' . number_format_i18n( $count ) . ' 件</a></td>';
		echo '<td>' . esc_html( $form['to'] !== '' ? $form['to'] : get_option( 'admin_email' ) . '（管理者）' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/**
 * 項目の1行。$i は '__i__' にするとテンプレートになる。
 */
function donnma_form_render_field_row( $i, $field ) {
	$types  = donnma_form_field_types();
	$prefix = 'fields[' . $i . ']';
	$choice = donnma_form_is_choice( $field['type'] );

	echo '<tr class="df-field-row" data-type="' . esc_attr( $field['type'] ) . '">';
	echo '<td class="df-move"><button type="button" class="button-link df-up" aria-label="上へ">▲</button><button type="button" class="button-link df-down" aria-label="下へ">▼</button></td>';
	echo '<td><input type="text" class="df-label" name="' . esc_attr( $prefix . '[label]' ) . '" value="' . esc_attr( $field['label'] ) . '" placeholder="例: お名前" /></td>';
	echo '<td><input type="text" class="df-name code" name="' . esc_attr( $prefix . '[name]' ) . '" value="' . esc_attr( $field['name'] ) . '" pattern="[a-z0-9_\-]*" title="半角の英小文字・数字・_ と -" /></td>';
	echo '<td><select class="df-type" name="' . esc_attr( $prefix . '[type]' ) . '">';
	foreach ( $types as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $field['type'], $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></td>';
	echo '<td class="df-center"><input type="checkbox" name="' . esc_attr( $prefix . '[required]' ) . '" value="1"' . checked( $field['required'], true, false ) . ' aria-label="必須" /></td>';
	echo '<td class="df-detail">';
	echo '<textarea class="df-options" name="' . esc_attr( $prefix . '[options]' ) . '" rows="4" placeholder="1行に1つ"' . ( $choice ? '' : ' hidden' ) . '>' . esc_textarea( implode( "\n", $field['options'] ) ) . '</textarea>';
	echo '<label class="df-max"' . ( $choice ? ' hidden' : '' ) . '>最大 <input type="number" min="0" class="small-text" name="' . esc_attr( $prefix . '[max]' ) . '" value="' . ( $field['max'] > 0 ? (int) $field['max'] : '' ) . '" /> 文字</label>';
	echo '<label class="df-rows"' . ( $field['type'] === 'textarea' ? '' : ' hidden' ) . '>高さ <input type="number" min="0" class="small-text" name="' . esc_attr( $prefix . '[rows]' ) . '" value="' . ( $field['rows'] > 0 ? (int) $field['rows'] : '' ) . '" /> 行</label>';
	echo '<label class="df-repeat-opt"' . ( donnma_form_can_repeat( $field['type'] ) ? '' : ' hidden' ) . ' title="空なら1つだけ。数を入れると、フォームで「＋ 追加」で欄を増やせます">くり返し 最大 <input type="number" min="0" max="50" class="small-text" name="' . esc_attr( $prefix . '[repeat]' ) . '" value="' . ( $field['repeat'] > 0 ? (int) $field['repeat'] : '' ) . '" /> 個</label>';
	echo '</td>';
	echo '<td><button type="button" class="button-link button-link-delete df-remove">削除</button></td>';
	echo '</tr>';
}

function donnma_form_render_field_select( $name, $form, $selected, $only_email, $none_label ) {
	echo '<select name="' . esc_attr( $name ) . '" class="df-field-select" data-only-email="' . ( $only_email ? '1' : '' ) . '" data-selected="' . esc_attr( $selected ) . '">';
	echo '<option value="">' . esc_html( $none_label ) . '</option>';
	foreach ( $form['fields'] as $field ) {
		if ( $only_email && $field['type'] !== 'email' ) {
			continue;
		}
		echo '<option value="' . esc_attr( $field['name'] ) . '"' . selected( $selected, $field['name'], false ) . '>' . esc_html( $field['label'] ) . '</option>';
	}
	echo '</select>';
}

function donnma_form_render_edit() {
	$state = $GLOBALS['donnma_form_edit_state'] ?? null;
	if ( $state ) {
		$form   = $state['form'];
		$id     = $state['id'];
		$is_new = $state['is_new'];
	} elseif ( isset( $_GET['edit'] ) ) {
		$id     = sanitize_key( wp_unslash( $_GET['edit'] ) );
		$form   = donnma_form_get( $id );
		$is_new = false;
		if ( ! $form ) {
			echo '<h1>フォームの編集</h1><p>フォーム「' . esc_html( $id ) . '」はありません。</p><p><a href="' . esc_url( donnma_form_forms_page_url() ) . '">← フォームの一覧へ</a></p>';
			return;
		}
	} else {
		// 送信先・送信元は、いまある最初のフォームに合わせておく
		$first  = current( donnma_form_definitions() );
		$id     = '';
		$is_new = true;
		$form   = donnma_form_normalize(
			array(
				'title'            => '',
				'to'               => $first ? $first['to'] : '',
				'from'             => $first ? $first['from'] : '',
				'reply_to_field'   => 'email',
				'reply_name_field' => 'name',
				'fields'           => array(
					array( 'name' => 'name', 'type' => 'text', 'label' => 'お名前', 'max' => 400 ),
					array( 'name' => 'email', 'type' => 'email', 'label' => 'メールアドレス', 'max' => 400 ),
					array( 'name' => 'body', 'type' => 'textarea', 'label' => 'メッセージ本文', 'required' => true, 'max' => 2000, 'rows' => 10 ),
				),
			)
		);
	}

	echo '<h1>' . ( $is_new ? 'フォームを追加' : 'フォームの編集：' . esc_html( $form['title'] ) ) . '</h1>';
	echo '<p><a href="' . esc_url( donnma_form_forms_page_url() ) . '">← フォームの一覧へ</a></p>';

	if ( $state && $state['errors'] ) {
		echo '<div class="notice notice-error"><p>保存できませんでした。</p><ul>';
		foreach ( $state['errors'] as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	echo '<form method="post" action="' . esc_url( donnma_form_forms_page_url( $is_new ? array( 'new' => 1 ) : array( 'edit' => $id ) ) ) . '" class="df-edit">';
	wp_nonce_field( 'df_save_form' );
	echo '<input type="hidden" name="df_save_form" value="1" />';
	echo '<input type="hidden" name="df_original_id" value="' . esc_attr( $is_new ? '' : $id ) . '" />';

	echo '<h2>基本</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row"><label for="df_title">フォームの名前</label></th><td><input type="text" id="df_title" name="df_title" class="regular-text" value="' . esc_attr( $form['title'] ) . '" required /><p class="description">メールの件名や受信一覧に出ます。</p></td></tr>';
	echo '<tr><th scope="row"><label for="df_id">ID</label></th><td>';
	if ( $is_new ) {
		echo '<input type="text" id="df_id" name="df_id" class="regular-text code" value="' . esc_attr( $id ) . '" pattern="[a-z0-9_\-]+" required placeholder="例: request" /><p class="description">半角の英小文字・数字・_ と -。ショートコードに使います。あとから変えられません。</p>';
	} else {
		echo '<code>' . esc_html( $id ) . '</code>　ショートコード <input type="text" class="df-shortcode" readonly value="' . esc_attr( '[donnma_form id="' . $id . '"]' ) . '" onclick="this.select()" />';
	}
	echo '</td></tr>';
	echo '<tr><th scope="row"><label for="df_sent_message">送信後のメッセージ</label></th><td><input type="text" id="df_sent_message" name="df_sent_message" class="large-text" value="' . esc_attr( $form['sent_message'] ) . '" placeholder="' . esc_attr( donnma_form_default_message() ) . '" /></td></tr>';
	echo '<tr><th scope="row"><label for="df_submit_label">送信ボタンの文字</label></th><td><input type="text" id="df_submit_label" name="df_submit_label" class="regular-text" value="' . esc_attr( $form['submit_label'] ) . '" placeholder="送信" /></td></tr>';
	echo '</table>';

	echo '<h2>項目</h2>';
	echo '<p class="description">ラベルを空にした行は保存されません。「名前」は空なら自動で付きます。</p>';
	echo '<table class="widefat df-fields"><thead><tr><th class="df-move">順</th><th>ラベル</th><th>名前</th><th>種類</th><th class="df-center">必須</th><th>選択肢・文字数</th><th></th></tr></thead><tbody>';
	foreach ( $form['fields'] as $i => $field ) {
		donnma_form_render_field_row( $i, $field );
	}
	echo '</tbody></table>';
	echo '<p><button type="button" class="button df-add">項目を追加</button></p>';
	echo '<template id="df-field-template"><table><tbody>';
	donnma_form_render_field_row( '__i__', donnma_form_normalize( array( 'fields' => array( array() ) ) )['fields'][0] );
	echo '</tbody></table></template>';

	echo '<h2>メール</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row"><label for="df_to">送信先</label></th><td><input type="email" id="df_to" name="df_to" class="regular-text" value="' . esc_attr( $form['to'] ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" /><p class="description">空なら管理者のメールアドレス。送信者のメールアドレスは返信先（Reply-To）に入るので、送信先に自動応答を設定していれば送信者に届きます。</p></td></tr>';
	echo '<tr><th scope="row"><label for="df_from">送信元</label></th><td><input type="email" id="df_from" name="df_from" class="regular-text" value="' . esc_attr( $form['from'] ) . '" /></td></tr>';
	echo '<tr><th scope="row">件名に使う項目</th><td>';
	donnma_form_render_field_select( 'df_subject_field', $form, $form['subject_field'], false, '（フォームの名前）' );
	echo '</td></tr>';
	echo '<tr><th scope="row">返信先のメールアドレス</th><td>';
	donnma_form_render_field_select( 'df_reply_to_field', $form, $form['reply_to_field'], true, '（なし）' );
	echo '<p class="description">種類が「メールアドレス」の項目から選びます。</p></td></tr>';
	echo '<tr><th scope="row">返信先の名前</th><td>';
	donnma_form_render_field_select( 'df_reply_name_field', $form, $form['reply_name_field'], false, '（なし）' );
	echo '</td></tr>';
	echo '</table>';

	submit_button( $is_new ? 'フォームを追加' : '保存' );
	echo '</form>';
}
