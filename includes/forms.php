<?php
/**
 * フォームの定義。管理画面（お問い合わせ → フォームの編集）で作り、オプション donnma_form_forms に保存する。
 *
 * フォーム:
 *   title            フォームの名前
 *   to               送信先（空なら管理者のメールアドレス）
 *                    送信者のアドレスは Reply-To に入れる。送信先に自動応答があれば送信者に届く。
 *                    送信者のアドレスがないときは Auto-Submitted を付けて、自動応答が送信先自身へ返らないようにする
 *   from             送信元（空なら WordPress の標準）
 *   subject_field    メールの件名に使う項目
 *   reply_to_field   返信先にするメールアドレスの項目
 *   reply_name_field 返信先の名前にする項目
 *   sent_message     送信後に出すメッセージ
 *   submit_label     送信ボタンの文字
 *   fields           項目の配列（name / type / label / required / max / rows / options）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DONNMA_FORM_OPTION', 'donnma_form_forms' );

function donnma_form_field_types() {
	return array(
		'text'     => '1行テキスト',
		'email'    => 'メールアドレス',
		'tel'      => '電話番号',
		'url'      => 'URL',
		'slug'     => 'スラッグ（英小文字・数字・ハイフン）',
		'textarea' => '複数行テキスト',
		'select'   => 'プルダウン',
		'radio'    => 'ラジオボタン',
		'checkbox' => 'チェックボックス',
	);
}

/**
 * 選択肢を持つ種類か。
 */
function donnma_form_is_choice( $type ) {
	return in_array( $type, array( 'select', 'radio', 'checkbox' ), true );
}

function donnma_form_default_message() {
	return 'ありがとうございます。メッセージは送信されました。';
}

/**
 * 有効にしたとき、フォームがまだなければ入れておく問い合わせフォーム。
 */
function donnma_form_initial_forms() {
	return array(
		'contact' => array(
			'title'            => 'お問い合わせ',
			'to'               => '',
			'from'             => '',
			'subject_field'    => '',
			'reply_to_field'   => 'email',
			'reply_name_field' => 'name',
			'sent_message'     => '',
			'submit_label'     => '送信',
			'fields'           => array(
				array(
					'name'  => 'name',
					'type'  => 'text',
					'label' => 'お名前',
					'max'   => 400,
				),
				array(
					'name'  => 'email',
					'type'  => 'email',
					'label' => 'メールアドレス',
					'max'   => 400,
				),
				array(
					'name'     => 'body',
					'type'     => 'textarea',
					'label'    => 'メッセージ本文',
					'required' => true,
					'max'      => 2000,
					'rows'     => 10,
				),
			),
		),
	);
}

/**
 * 足りないキーを埋める。
 */
function donnma_form_normalize( $form ) {
	$form = wp_parse_args(
		(array) $form,
		array(
			'title'            => '',
			'to'               => '',
			'from'             => '',
			'subject_field'    => '',
			'reply_to_field'   => '',
			'reply_name_field' => '',
			'sent_message'     => '',
			'submit_label'     => '送信',
			'fields'           => array(),
		)
	);

	$fields = array();
	foreach ( (array) $form['fields'] as $field ) {
		$field = wp_parse_args(
			(array) $field,
			array(
				'name'     => '',
				'type'     => 'text',
				'label'    => '',
				'required' => false,
				'max'      => 0,
				'rows'     => 0,
				'repeat'   => 0,
				'options'  => array(),
			)
		);
		$field['required'] = (bool) $field['required'];
		$field['max']      = (int) $field['max'];
		$field['rows']     = (int) $field['rows'];
		$field['repeat']   = donnma_form_can_repeat( $field['type'] ) ? max( 0, (int) $field['repeat'] ) : 0;
		$field['options']  = array_values( (array) $field['options'] );
		$fields[]          = $field;
	}
	$form['fields'] = $fields;
	return $form;
}

/**
 * すべてのフォーム。ID => フォーム
 */
function donnma_form_definitions() {
	$forms = get_option( DONNMA_FORM_OPTION, null );
	if ( ! is_array( $forms ) ) {
		$forms = donnma_form_initial_forms();
		add_option( DONNMA_FORM_OPTION, $forms );
	}
	$forms = array_map( 'donnma_form_normalize', $forms );
	return apply_filters( 'donnma_form_definitions', $forms );
}

function donnma_form_get( $form_id ) {
	$forms = donnma_form_definitions();
	return isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : null;
}

function donnma_form_save_all( $forms ) {
	update_option( DONNMA_FORM_OPTION, $forms );
}

/**
 * くり返し（「＋ 追加」で欄を増やす）にできる種類か。
 */
function donnma_form_can_repeat( $type ) {
	return in_array( $type, array( 'text', 'textarea' ), true );
}

/**
 * 値を文字にする。チェックボックスは「, 」でつなぎ、くり返しの項目は (1) (2) … と番号を付けて行を分ける。
 */
function donnma_form_value_text( $value, $numbered = false ) {
	if ( ! is_array( $value ) ) {
		return (string) $value;
	}
	if ( ! $numbered ) {
		return implode( ', ', $value );
	}
	$lines = array();
	foreach ( array_values( $value ) as $i => $item ) {
		$lines[] = '(' . ( $i + 1 ) . ') ' . $item;
	}
	return implode( "\n", $lines );
}

/**
 * 項目の値を文字にする（項目の設定を見て番号を付けるか決める）。
 */
function donnma_form_field_text( $field, $value ) {
	return donnma_form_value_text( $value, ! empty( $field['repeat'] ) );
}
