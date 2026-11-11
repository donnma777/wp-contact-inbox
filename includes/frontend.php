<?php
/**
 * フォームの表示と、送信の受け付け。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 入力のチェック
 * ---------------------------------------------------------------- */

/**
 * 制御文字を取り除く。$multiline のときは改行とタブを残す（改行は \n にそろえる）。
 */
function donnma_form_clean_text( $value, $multiline ) {
	$value = (string) $value;
	if ( ! wp_check_invalid_utf8( $value ) ) {
		return '';
	}
	$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
	if ( $multiline ) {
		$value = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value );
	} else {
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value );
	}
	return trim( $value );
}

/**
 * 項目の文字数の上限。上限を空にした項目も、DONNMA_FORM_HARD_MAX 文字までにする。
 */
function donnma_form_max_length( $field ) {
	return $field['max'] > 0 ? min( (int) $field['max'], DONNMA_FORM_HARD_MAX ) : DONNMA_FORM_HARD_MAX;
}

function donnma_form_length( $value ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : (int) preg_match_all( '/./us', $value );
}

/**
 * 送られた値をチェックする。
 *
 * @return array array( 値の配列, エラーの配列 )。どちらも項目名がキー。チェックボックスの値は配列
 */
function donnma_form_validate( $form, $raw ) {
	$values = array();
	$errors = array();

	foreach ( $form['fields'] as $field ) {
		$name  = $field['name'];
		$input = isset( $raw[ $name ] ) ? $raw[ $name ] : '';

		if ( $field['type'] === 'checkbox' ) {
			$picked = array();
			foreach ( (array) $input as $item ) {
				if ( is_scalar( $item ) ) {
					$picked[] = donnma_form_clean_text( $item, false );
				}
			}
			// 選択肢の順にそろえ、選択肢にないものは捨てる
			$values[ $name ] = array_values( array_intersect( $field['options'], $picked ) );
			if ( ! $values[ $name ] && $field['required'] ) {
				$errors[ $name ] = count( $field['options'] ) > 1 ? 'ひとつ以上選んでください。' : 'チェックを入れてください。';
			}
			continue;
		}

		// くり返しの項目：空の欄は捨て、残りを順に並べる
		if ( $field['repeat'] > 0 ) {
			$items = array();
			foreach ( (array) $input as $item ) {
				if ( is_scalar( $item ) ) {
					$item = donnma_form_clean_text( $item, $field['type'] === 'textarea' );
					if ( $item !== '' ) {
						$items[] = $item;
					}
				}
			}
			$values[ $name ] = $items;
			if ( ! $items && $field['required'] ) {
				$errors[ $name ] = '入力してください。';
			} elseif ( count( $items ) > $field['repeat'] ) {
				$errors[ $name ] = sprintf( '%d 個までにしてください。', $field['repeat'] );
			} else {
				$max = donnma_form_max_length( $field );
				foreach ( $items as $i => $item ) {
					if ( donnma_form_length( $item ) > $max ) {
						$errors[ $name ] = sprintf( '%d 個目が長すぎます。%d 文字以内で入力してください。', $i + 1, $max );
						break;
					}
				}
			}
			continue;
		}

		$value = is_scalar( $input ) ? donnma_form_clean_text( $input, $field['type'] === 'textarea' ) : '';
		if ( $field['type'] === 'slug' ) {
			$value = strtolower( $value );
		}
		$values[ $name ] = $value;

		if ( $value === '' ) {
			if ( $field['required'] ) {
				$errors[ $name ] = in_array( $field['type'], array( 'select', 'radio' ), true ) ? '選んでください。' : '入力してください。';
			}
			continue;
		}

		$max = donnma_form_max_length( $field );
		if ( donnma_form_length( $value ) > $max ) {
			$errors[ $name ] = sprintf( '%d 文字以内で入力してください。', $max );
			continue;
		}

		switch ( $field['type'] ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					$errors[ $name ] = 'メールアドレスの形式が正しくありません。';
				}
				break;
			case 'tel':
				if ( ! preg_match( '/^[0-9０-９+＋\-－ー()（）\s]{6,}$/u', $value ) ) {
					$errors[ $name ] = '電話番号の形式が正しくありません。';
				}
				break;
			case 'url':
				if ( ! preg_match( '#^https?://#i', $value ) || ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$errors[ $name ] = 'http:// か https:// で始まる URL を入力してください。';
				}
				break;
			case 'slug':
				if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value ) ) {
					$errors[ $name ] = '半角の英小文字・数字・ハイフンで入力してください（例：new-year-tea-room）。';
				}
				break;
			case 'select':
			case 'radio':
				if ( ! in_array( $value, $field['options'], true ) ) {
					$errors[ $name ] = '一覧から選んでください。';
				}
				break;
		}
	}

	return array( $values, $errors );
}

/* ------------------------------------------------------------------
 * 迷惑メール対策
 * ---------------------------------------------------------------- */

/**
 * フォームを表示した時刻を、書き換えられないよう署名つきで渡す。
 */
function donnma_form_make_token() {
	$time = (string) time();
	return $time . '.' . hash_hmac( 'sha256', $time, wp_salt( 'nonce' ) );
}

/**
 * 時刻の署名が正しく、表示から DONNMA_FORM_MIN_SECONDS 秒以上たっていれば true。
 * ページがキャッシュされても困らないよう、古すぎる時刻ははじかない。
 */
function donnma_form_check_token( $token ) {
	$parts = explode( '.', (string) $token );
	if ( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) ) {
		return false;
	}
	$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) );
	if ( ! hash_equals( $expected, $parts[1] ) ) {
		return false;
	}
	return ( time() - (int) $parts[0] ) >= DONNMA_FORM_MIN_SECONDS;
}

function donnma_form_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

function donnma_form_user_agent() {
	return isset( $_SERVER['HTTP_USER_AGENT'] ) ? donnma_form_clean_text( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), false ) : '';
}

/**
 * Akismet で迷惑メールか調べる。Akismet が使えないときや通信に失敗したときは false。
 */
function donnma_form_akismet_is_spam( $form, $values, $page_url ) {
	if ( ! class_exists( 'Akismet' ) || ! method_exists( 'Akismet', 'http_post' ) || ! Akismet::get_api_key() ) {
		return false;
	}

	$content = array();
	foreach ( $form['fields'] as $field ) {
		if ( $field['name'] !== $form['reply_to_field'] && $field['name'] !== $form['reply_name_field'] ) {
			$content[] = donnma_form_field_text( $field, $values[ $field['name'] ] );
		}
	}

	$request = array(
		'blog'                 => home_url(),
		'blog_lang'            => get_locale(),
		'blog_charset'         => get_option( 'blog_charset' ),
		'user_ip'              => donnma_form_client_ip(),
		'user_agent'           => donnma_form_user_agent(),
		'referrer'             => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		'permalink'            => $page_url,
		'comment_type'         => 'contact-form',
		'comment_author'       => donnma_form_value_text( $values[ $form['reply_name_field'] ] ?? '' ),
		'comment_author_email' => donnma_form_value_text( $values[ $form['reply_to_field'] ] ?? '' ),
		'comment_content'      => implode( "\n\n", $content ),
	);

	$response = Akismet::http_post( Akismet::build_query( $request ), 'comment-check' );
	return isset( $response[1] ) && $response[1] === 'true';
}

/**
 * 同じ IP からの送信回数が上限に達していれば true。
 */
function donnma_form_rate_limited() {
	$count = (int) get_transient( 'donnma_form_rl_' . md5( donnma_form_client_ip() ) );
	return $count >= DONNMA_FORM_RATE_LIMIT;
}

function donnma_form_count_send() {
	$key   = 'donnma_form_rl_' . md5( donnma_form_client_ip() );
	$count = (int) get_transient( $key );
	set_transient( $key, $count + 1, DONNMA_FORM_RATE_WINDOW );
}

/* ------------------------------------------------------------------
 * 送信
 * ---------------------------------------------------------------- */

/**
 * メールのヘッダーに入れる名前。改行や区切りに使われる記号を除く。
 */
function donnma_form_header_name( $name ) {
	$name = preg_replace( '/[\x00-\x1F\x7F"<>,;:\\\\]/u', '', (string) $name );
	return trim( $name );
}

/**
 * 件名（メール・受信一覧の見出し）。
 */
function donnma_form_subject( $form, $values ) {
	$value = $form['subject_field'] !== '' ? donnma_form_value_text( $values[ $form['subject_field'] ] ?? '' ) : '';
	return $value !== '' ? $value : $form['title'];
}

/**
 * メール本文と同じ形の文章（受信一覧の検索にも使う）。
 */
function donnma_form_build_text( $form, $values, $page_url ) {
	$lines = array();
	foreach ( $form['fields'] as $field ) {
		$value = donnma_form_field_text( $field, $values[ $field['name'] ] );
		if ( $field['type'] === 'textarea' || $field['repeat'] > 0 ) {
			$lines[] = '';
			$lines[] = $field['label'] . ':';
			$lines[] = $value;
			$lines[] = '';
		} else {
			$lines[] = $field['label'] . ': ' . ( $value !== '' ? $value : '（なし）' );
		}
	}
	$lines[] = '--';
	$lines[] = 'フォーム: ' . $form['title'];
	$lines[] = '送信元ページ: ' . $page_url;
	$lines[] = '日時: ' . wp_date( 'Y-m-d H:i:s' );
	$lines[] = 'IP: ' . donnma_form_client_ip();
	$lines[] = 'ブラウザ: ' . donnma_form_user_agent();
	return implode( "\n", $lines );
}

function donnma_form_reply_email( $form, $values ) {
	$email = $form['reply_to_field'] !== '' ? donnma_form_value_text( $values[ $form['reply_to_field'] ] ?? '' ) : '';
	return ( $email !== '' && is_email( $email ) ) ? $email : '';
}

function donnma_form_reply_name( $form, $values ) {
	return $form['reply_name_field'] !== '' ? donnma_form_value_text( $values[ $form['reply_name_field'] ] ?? '' ) : '';
}

function donnma_form_send_mail( $form_id, $form, $values, $text ) {
	$to = $form['to'] !== '' && is_email( $form['to'] ) ? $form['to'] : get_option( 'admin_email' );
	$to = apply_filters( 'donnma_form_recipient', $to, $form_id );

	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$subject = sprintf( '[%s] %s', $site, donnma_form_subject( $form, $values ) );

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	if ( $form['from'] !== '' && is_email( $form['from'] ) ) {
		$headers[] = 'From: ' . donnma_form_header_name( $site ) . ' <' . $form['from'] . '>';
	}
	$email = donnma_form_reply_email( $form, $values );
	if ( $email !== '' ) {
		$name      = donnma_form_header_name( donnma_form_reply_name( $form, $values ) );
		$headers[] = 'Reply-To: ' . ( $name !== '' ? '"' . $name . '" <' . $email . '>' : $email );
	} else {
		// 返信先がない。受け取り側の自動応答を止める
		$headers[] = 'Auto-Submitted: auto-generated';
	}

	return wp_mail( $to, $subject, $text, $headers );
}

/**
 * 送信を受け取る。ページの表示より前（template_redirect）に動く。
 * 送れたときは同じページへ移動し、再読み込みで二重に送られないようにする。
 */
function donnma_form_handle_post() {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty( $_POST['df_form'] ) ) {
		return;
	}

	$form_id = sanitize_key( wp_unslash( $_POST['df_form'] ) );
	$form    = donnma_form_get( $form_id );
	if ( ! $form ) {
		return;
	}

	$page_url = get_permalink( get_queried_object_id() );
	if ( ! $page_url ) {
		$page_url = home_url( '/' );
	}
	$done_url = add_query_arg( 'df_sent', $form_id, $page_url ) . '#df-' . $form_id;

	$raw = isset( $_POST['df'] ) && is_array( $_POST['df'] ) ? wp_unslash( $_POST['df'] ) : array();
	list( $values, $errors ) = donnma_form_validate( $form, $raw );

	$state = array(
		'id'     => $form_id,
		'values' => $values,
		'errors' => $errors,
		'status' => '',
	);

	if ( $errors ) {
		$state['status']              = 'invalid';
		$GLOBALS['donnma_form_state'] = $state;
		return;
	}

	// 見えない欄が埋まっている・表示から早すぎる送信はロボット。送れたふりをして捨てる
	$honeypot = isset( $_POST['df_hp'] ) ? (string) wp_unslash( $_POST['df_hp'] ) : '';
	$token    = isset( $_POST['df_ts'] ) ? (string) wp_unslash( $_POST['df_ts'] ) : '';
	if ( $honeypot !== '' || ! donnma_form_check_token( $token ) ) {
		wp_safe_redirect( $done_url, 303 );
		exit;
	}

	if ( donnma_form_rate_limited() ) {
		$state['status']              = 'limited';
		$GLOBALS['donnma_form_state'] = $state;
		return;
	}
	// 保存まで進む送信は、迷惑メールと判定されたものも回数に数える（同じ IP から保存され続けないように）
	donnma_form_count_send();

	$text = donnma_form_build_text( $form, $values, $page_url );

	// 迷惑メールの判定でも、誤判定に気づけるよう受信一覧の「迷惑メール」に残す
	if ( donnma_form_akismet_is_spam( $form, $values, $page_url ) ) {
		donnma_form_save_entry( $form_id, $form, $values, $text, $page_url, 'df_spam' );
		$state['status']              = 'spam';
		$GLOBALS['donnma_form_state'] = $state;
		return;
	}

	$entry_id = donnma_form_save_entry( $form_id, $form, $values, $text, $page_url, 'df_received' );
	$mailed   = donnma_form_send_mail( $form_id, $form, $values, $text );

	if ( ! $mailed ) {
		if ( ! $entry_id ) {
			$state['status']              = 'failed';
			$GLOBALS['donnma_form_state'] = $state;
			return;
		}
		// 受信一覧には残っているので、送信者には「送れた」と伝える
		update_post_meta( $entry_id, '_df_mail_failed', 1 );
	}

	// SNS Post・Discord Post Notify などへ知らせる。項目名（ラベル）=> 値
	$labeled = array();
	foreach ( $form['fields'] as $field ) {
		$labeled[ $field['label'] ] = donnma_form_field_text( $field, $values[ $field['name'] ] );
	}
	do_action( 'dsp_form_submitted', $form['title'], $labeled );
	do_action( 'dpn_form_submitted', $form['title'], $labeled );
	do_action( 'donnma_form_sent', $form_id, $values, $entry_id );

	wp_safe_redirect( $done_url, 303 );
	exit;
}
add_action( 'template_redirect', 'donnma_form_handle_post' );

/* ------------------------------------------------------------------
 * 表示
 * ---------------------------------------------------------------- */

function donnma_form_messages( $form ) {
	return array(
		'sent'    => array( 'ok', $form['sent_message'] !== '' ? $form['sent_message'] : donnma_form_default_message() ),
		'invalid' => array( 'ng', '入力内容に問題があります。確認して、もう一度送信してください。' ),
		'limited' => array( 'ng', '短い時間に何度も送信されたため、受け付けを止めています。しばらくたってから送信してください。' ),
		'spam'    => array( 'ng', '送信できませんでした。お手数ですが、内容を変えるか、しばらくたってから送信してください。' ),
		'failed'  => array( 'ng', 'メッセージの送信に失敗しました。しばらくたってから、もう一度お試しください。' ),
	);
}

/**
 * くり返しの項目の1欄。$n を '__n__' にするとテンプレートになる（JavaScript が番号に置きかえる）。
 */
function donnma_form_render_repeat_item( $id, $name, $field, $n, $value ) {
	$item_id = $id . '-' . $n;
	$attrs   = ' id="' . esc_attr( $item_id ) . '" name="' . esc_attr( $name . '[]' ) . '"';
	if ( $field['max'] > 0 ) {
		$attrs .= ' maxlength="' . (int) $field['max'] . '"';
	}
	if ( $field['required'] && $n === 1 ) {
		$attrs .= ' required aria-required="true"';
	}
	$out  = '<div class="df-repeat-item">';
	$out .= '<label for="' . esc_attr( $item_id ) . '"><span class="df-repeat-label">' . esc_html( $field['label'] ) . ' <span class="df-repeat-n">' . esc_html( (string) $n ) . '</span></span></label>';
	$out .= ' <button type="button" class="df-repeat-remove">削除</button><br />';
	if ( $field['type'] === 'textarea' ) {
		$rows = $field['rows'] > 0 ? $field['rows'] : 4;
		$out .= '<textarea' . $attrs . ' rows="' . $rows . '" cols="40">' . esc_textarea( $value ) . '</textarea>';
	} else {
		$out .= '<input type="text"' . $attrs . ' value="' . esc_attr( $value ) . '" size="40" />';
	}
	$out .= '</div>';
	return $out;
}

function donnma_form_render_field( $form_id, $field, $value, $error ) {
	$id       = 'df-' . $form_id . '-' . $field['name'];
	$name     = 'df[' . $field['name'] . ']';
	$required = $field['required'];
	$mark     = $required ? '(必須)' : '(任意)';
	$describe = $error !== '' ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '';

	$out = '<div class="df-field df-type-' . esc_attr( $field['type'] ) . '">';

	if ( $field['repeat'] > 0 ) {
		// くり返しの項目：欄を「＋ 追加」で増やせる。最初は入力のある数（なければ1つ）だけ出す
		$items = array_values( (array) $value );
		if ( ! $items ) {
			$items = array( '' );
		}
		$out .= '<fieldset class="df-repeat" data-max="' . (int) $field['repeat'] . '"' . $describe . '><legend>' . esc_html( $field['label'] ) . ' ' . $mark . '<span class="df-repeat-note">（' . (int) $field['repeat'] . ' 個まで）</span></legend>';
		$out .= '<div class="df-repeat-items">';
		foreach ( $items as $i => $item ) {
			$out .= donnma_form_render_repeat_item( $id, $name, $field, $i + 1, $item );
		}
		$out .= '</div>';
		$out .= '<template class="df-repeat-template">' . donnma_form_render_repeat_item( $id, $name, $field, '__n__', '' ) . '</template>';
		$out .= '<button type="button" class="df-repeat-add"' . ( count( $items ) >= $field['repeat'] ? ' hidden' : '' ) . '>＋ ' . esc_html( $field['label'] ) . 'を追加</button>';
		$out .= '</fieldset>';
	} elseif ( in_array( $field['type'], array( 'radio', 'checkbox' ), true ) ) {
		$out .= '<fieldset' . $describe . '><legend>' . esc_html( $field['label'] ) . ' ' . $mark . '</legend>';
		$picked = (array) $value;
		foreach ( $field['options'] as $i => $option ) {
			$input_name = $field['type'] === 'checkbox' ? $name . '[]' : $name;
			$checked    = in_array( $option, $picked, true ) ? ' checked' : '';
			// ラジオの必須はブラウザでも確かめる。チェックボックスは1つ以上なのでサーバーだけで確かめる
			$req  = ( $required && $field['type'] === 'radio' && $i === 0 ) ? ' required' : '';
			$out .= '<label class="df-choice"><input type="' . $field['type'] . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $option ) . '"' . $checked . $req . ' /> ' . esc_html( $option ) . '</label>';
		}
		$out .= '</fieldset>';
	} else {
		$attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $describe;
		if ( $required ) {
			$attrs .= ' required aria-required="true"';
		}
		if ( $field['max'] > 0 && $field['type'] !== 'select' ) {
			$attrs .= ' maxlength="' . (int) $field['max'] . '"';
		}
		$autocomplete = array(
			'email' => 'email',
			'tel'   => 'tel',
			'url'   => 'url',
		);
		if ( isset( $autocomplete[ $field['type'] ] ) ) {
			$attrs .= ' autocomplete="' . $autocomplete[ $field['type'] ] . '"';
		} elseif ( $field['name'] === 'name' ) {
			$attrs .= ' autocomplete="name"';
		}

		$out .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ' ' . $mark . '</label><br />';

		switch ( $field['type'] ) {
			case 'textarea':
				$rows = $field['rows'] > 0 ? $field['rows'] : 8;
				$out .= '<textarea' . $attrs . ' rows="' . $rows . '" cols="40">' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'select':
				$out .= '<select' . $attrs . '>';
				foreach ( $field['options'] as $option ) {
					$out .= '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
				}
				$out .= '</select>';
				break;

			case 'slug':
				$out .= '<input type="text"' . $attrs . ' value="' . esc_attr( $value ) . '" size="40" pattern="[a-zA-Z0-9]+(-[a-zA-Z0-9]+)*" title="半角の英小文字・数字・ハイフン（例：new-year-tea-room）" autocapitalize="off" spellcheck="false" placeholder="new-year-tea-room" />';
				break;

			default:
				$out .= '<input type="' . esc_attr( $field['type'] ) . '"' . $attrs . ' value="' . esc_attr( $value ) . '" size="40" />';
		}
	}

	if ( $error !== '' ) {
		$out .= '<span class="df-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $error ) . '</span>';
	}
	$out .= '</div>';
	return $out;
}

function donnma_form_shortcode( $atts ) {
	$atts    = shortcode_atts( array( 'id' => 'contact' ), $atts, 'donnma_form' );
	$form_id = sanitize_key( $atts['id'] );
	$form    = donnma_form_get( $form_id );
	if ( ! $form ) {
		return current_user_can( 'edit_posts' ) ? '<p>[donnma_form] フォーム「' . esc_html( $form_id ) . '」はありません。</p>' : '';
	}

	$state  = isset( $GLOBALS['donnma_form_state'] ) && $GLOBALS['donnma_form_state']['id'] === $form_id ? $GLOBALS['donnma_form_state'] : null;
	$status = $state ? $state['status'] : '';
	if ( ! $state && isset( $_GET['df_sent'] ) && sanitize_key( wp_unslash( $_GET['df_sent'] ) ) === $form_id ) {
		$status = 'sent';
	}

	$out  = donnma_form_style();
	$out .= '<div class="df-form" id="df-' . esc_attr( $form_id ) . '">';

	$messages = donnma_form_messages( $form );
	if ( isset( $messages[ $status ] ) ) {
		list( $kind, $text ) = $messages[ $status ];
		$out .= '<div class="df-notice df-notice-' . $kind . '" role="' . ( $kind === 'ok' ? 'status' : 'alert' ) . '">' . esc_html( $text ) . '</div>';
	}

	$out .= '<form method="post" action="' . esc_url( get_permalink() . '#df-' . $form_id ) . '" aria-label="' . esc_attr( $form['title'] ) . '">';
	$out .= '<input type="hidden" name="df_form" value="' . esc_attr( $form_id ) . '" />';
	$out .= '<input type="hidden" name="df_ts" value="' . esc_attr( donnma_form_make_token() ) . '" />';

	foreach ( $form['fields'] as $field ) {
		$empty = $field['type'] === 'checkbox' ? array() : '';
		$value = $state ? $state['values'][ $field['name'] ] : $empty;
		$error = $state && isset( $state['errors'][ $field['name'] ] ) ? $state['errors'][ $field['name'] ] : '';
		$out  .= donnma_form_render_field( $form_id, $field, $value, $error );
	}

	// ロボットよけ。人には見えず、読み上げもされない
	$out .= '<p class="df-hp" aria-hidden="true"><label>この欄は空のままにしてください<input type="text" name="df_hp" value="" tabindex="-1" autocomplete="off" /></label></p>';
	$out .= '<p class="df-submit"><input type="submit" value="' . esc_attr( $form['submit_label'] !== '' ? $form['submit_label'] : '送信' ) . '" /></p>';
	$out .= '</form></div>';

	// 二度押し防止
	$out .= '<script>(function(f){if(!f)return;f.addEventListener("submit",function(){var b=f.querySelector("[type=submit]");setTimeout(function(){b.disabled=true;b.value="送信中…";},0);});})(document.querySelector("#df-' . esc_js( $form_id ) . ' form"));</script>';
	$out .= donnma_form_repeat_script();

	return $out;
}

/**
 * くり返しの項目の「＋ 追加」「削除」。1ページに1回だけ、フォームのあとに出す。
 */
function donnma_form_repeat_script() {
	static $printed = false;
	if ( $printed ) {
		return '';
	}
	$printed = true;
	return '<script>(function(){'
		. 'function fix(fs){var items=fs.querySelectorAll(".df-repeat-item");'
		. 'items.forEach(function(it,i){var n=i+1,f=it.querySelector("textarea,input");'
		. 'it.querySelector(".df-repeat-n").textContent=n;f.id=f.id.replace(/-[^-]*$/,"")+"-"+n;it.querySelector("label").htmlFor=f.id;'
		. 'it.querySelector(".df-repeat-remove").hidden=items.length<=1;});'
		. 'fs.querySelector(".df-repeat-add").hidden=items.length>=+fs.dataset.max;}'
		. 'document.addEventListener("click",function(e){'
		. 'var add=e.target.closest(".df-repeat-add"),rm=e.target.closest(".df-repeat-remove");if(!add&&!rm)return;'
		. 'var fs=e.target.closest(".df-repeat"),box=fs.querySelector(".df-repeat-items");'
		. 'if(add){var d=document.createElement("div");d.innerHTML=fs.querySelector(".df-repeat-template").innerHTML.split("__n__").join(box.children.length+1);'
		. 'var it=d.firstElementChild;box.appendChild(it);fix(fs);it.querySelector("textarea,input").focus();}'
		. 'else{var item=rm.closest(".df-repeat-item");if(box.children.length>1){item.remove();}else{item.querySelector("textarea,input").value="";}fix(fs);}});'
		. 'document.querySelectorAll(".df-repeat").forEach(fix);'
		. '})();</script>';
}
add_shortcode( 'donnma_form', 'donnma_form_shortcode' );

/**
 * 見た目。1ページに1回だけ出す。
 */
function donnma_form_style() {
	static $printed = false;
	if ( $printed ) {
		return '';
	}
	$printed = true;
	return '<style>'
		. '.df-form .df-field{margin:0 0 1.4em}'
		. '.df-form fieldset{border:0;margin:0;padding:0;min-width:0}'
		. '.df-form legend{padding:0;margin-bottom:.3em}'
		. '.df-form .df-choice{display:block;margin:.2em 0;cursor:pointer}'
		. '.df-form .df-error{display:block;color:#d63638;font-size:.9em;margin-top:.3em}'
		. '.df-form [aria-invalid="true"]{border-color:#d63638}'
		. '.df-form .df-notice{margin:0 0 1.5em;padding:.8em 1em;border:2px solid;border-radius:4px}'
		. '.df-form .df-notice-ok{border-color:#46b450}'
		. '.df-form .df-notice-ng{border-color:#d63638}'
		. '.df-form .df-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}'
		. '.df-form [hidden]{display:none!important}'
		. '.df-form .df-repeat-item{margin:0 0 .9em}'
		. '.df-form .df-repeat-note{font-size:.85em;opacity:.7;margin-left:.3em}'
		. '.df-form .df-repeat-remove{background:none;border:0;padding:0;color:#b32d2e;text-decoration:underline;font-size:.85em;cursor:pointer}'
		. '.df-form .df-repeat-add{cursor:pointer}'
		. '</style>';
}
