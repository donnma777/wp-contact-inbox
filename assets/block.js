/**
 * ブロック「フォーム」のエディタ側。フォームを選ぶと、実際の表示をそのまま見せる。
 * 名前・アイコン・属性は block.json から PHP 経由で渡ってくる。
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var data = window.donnmaFormBlock || { forms: [], canEdit: false, editUrl: '', newUrl: '' };

	function findForm( id ) {
		for ( var i = 0; i < data.forms.length; i++ ) {
			if ( data.forms[ i ].id === id ) {
				return data.forms[ i ];
			}
		}
		return null;
	}

	function formSelect( value, onChange, label ) {
		return el( c.SelectControl, {
			label: label,
			value: value,
			options: [ { label: '― フォームを選ぶ ―', value: '' } ].concat(
				data.forms.map( function ( form ) {
					return { label: form.title, value: form.id };
				} )
			),
			onChange: onChange,
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
		} );
	}

	function links( formId ) {
		if ( ! data.canEdit ) {
			return null;
		}
		return el(
			'p',
			{ className: 'donnma-form-block-links' },
			formId && findForm( formId )
				? el( c.ExternalLink, { href: data.editUrl + encodeURIComponent( formId ) }, 'このフォームを編集' )
				: null,
			formId && findForm( formId ) ? ' ' : null,
			el( c.ExternalLink, { href: data.newUrl }, 'フォームを追加' )
		);
	}

	wp.blocks.registerBlockType( 'donnma/form', {
		edit: function ( props ) {
			var formId = props.attributes.formId;
			var form = findForm( formId );
			var blockProps = be.useBlockProps();
			var choose = function ( value ) {
				props.setAttributes( { formId: value } );
			};

			// 1つしかないなら最初から選んでおく
			if ( ! formId && data.forms.length === 1 ) {
				setTimeout( function () {
					choose( data.forms[ 0 ].id );
				}, 0 );
			}

			var inspector = el(
				be.InspectorControls,
				null,
				el( c.PanelBody, { title: 'フォーム' }, formSelect( formId, choose, '表示するフォーム' ), links( formId ) )
			);

			if ( ! form ) {
				var message;
				if ( ! data.forms.length ) {
					message = 'フォームがありません。「お問い合わせ → フォームの編集」で作ってください。';
				} else if ( formId ) {
					message = 'フォーム「' + formId + '」はありません（削除されたかもしれません）。選び直してください。';
				} else {
					message = '表示するフォームを選んでください。';
				}
				return el(
					'div',
					blockProps,
					inspector,
					el(
						c.Placeholder,
						{ icon: 'email-alt', label: 'フォーム', instructions: message },
						data.forms.length ? formSelect( formId, choose ) : null,
						links( formId )
					)
				);
			}

			return el(
				'div',
				blockProps,
				inspector,
				el(
					c.Disabled,
					null,
					el( ServerSideRender, { block: 'donnma/form', attributes: { formId: formId } } )
				)
			);
		},

		// 表示は PHP で作る
		save: function () {
			return null;
		},

		// [donnma_form id="…"] を貼ったら、このブロックにする
		transforms: {
			from: [
				{
					type: 'shortcode',
					tag: 'donnma_form',
					attributes: {
						formId: {
							type: 'string',
							shortcode: function ( attrs ) {
								return attrs.named.id || '';
							},
						},
					},
				},
			],
		},
	} );
} )( window.wp );
