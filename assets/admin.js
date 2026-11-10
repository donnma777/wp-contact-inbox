/**
 * フォームの編集画面：項目の追加・削除・並べ替え、種類による入力欄の出し分け、
 * 「件名に使う項目」などの選択肢の作り直し。
 */
( function () {
	var form = document.querySelector( '.df-edit' );
	if ( ! form ) {
		return;
	}
	var tbody = form.querySelector( '.df-fields tbody' );
	var template = document.getElementById( 'df-field-template' );
	var choiceTypes = [ 'select', 'radio', 'checkbox' ];

	function rows() {
		return Array.prototype.slice.call( tbody.querySelectorAll( '.df-field-row' ) );
	}

	// name を fields[0][label] … の順に振り直す（並べ替えた順で保存されるように）
	function renumber() {
		rows().forEach( function ( row, i ) {
			row.querySelectorAll( '[name]' ).forEach( function ( el ) {
				el.name = el.name.replace( /^fields\[[^\]]*\]/, 'fields[' + i + ']' );
			} );
		} );
	}

	function applyType( row ) {
		var type = row.querySelector( '.df-type' ).value;
		var choice = choiceTypes.indexOf( type ) !== -1;
		row.dataset.type = type;
		row.querySelector( '.df-options' ).hidden = ! choice;
		row.querySelector( '.df-max' ).hidden = choice;
		row.querySelector( '.df-rows' ).hidden = type !== 'textarea';
		row.querySelector( '.df-repeat-opt' ).hidden = type !== 'text' && type !== 'textarea';
	}

	function usedNames() {
		return rows().map( function ( row ) {
			return row.querySelector( '.df-name' ).value.trim();
		} );
	}

	function nextName() {
		var used = usedNames();
		var n = 1;
		while ( used.indexOf( 'f' + n ) !== -1 ) {
			n++;
		}
		return 'f' + n;
	}

	// 件名・返信先の選択肢を、いまの項目から作り直す
	function refreshSelects() {
		form.querySelectorAll( '.df-field-select' ).forEach( function ( select ) {
			var current = select.value;
			var onlyEmail = select.dataset.onlyEmail === '1';
			var first = select.options[ 0 ];
			select.innerHTML = '';
			select.appendChild( first );
			rows().forEach( function ( row ) {
				var name = row.querySelector( '.df-name' ).value.trim();
				var label = row.querySelector( '.df-label' ).value.trim();
				if ( ! name || ! label ) {
					return;
				}
				if ( onlyEmail && row.querySelector( '.df-type' ).value !== 'email' ) {
					return;
				}
				var option = document.createElement( 'option' );
				option.value = name;
				option.textContent = label;
				select.appendChild( option );
			} );
			select.value = current;
			if ( select.value !== current ) {
				select.value = '';
			}
		} );
	}

	form.querySelector( '.df-add' ).addEventListener( 'click', function () {
		var row = template.content.querySelector( '.df-field-row' ).cloneNode( true );
		row.querySelector( '.df-name' ).value = nextName();
		tbody.appendChild( row );
		applyType( row );
		renumber();
		row.querySelector( '.df-label' ).focus();
	} );

	tbody.addEventListener( 'click', function ( e ) {
		var row = e.target.closest( '.df-field-row' );
		if ( ! row ) {
			return;
		}
		if ( e.target.closest( '.df-remove' ) ) {
			row.remove();
		} else if ( e.target.closest( '.df-up' ) && row.previousElementSibling ) {
			tbody.insertBefore( row, row.previousElementSibling );
		} else if ( e.target.closest( '.df-down' ) && row.nextElementSibling ) {
			tbody.insertBefore( row.nextElementSibling, row );
		} else {
			return;
		}
		renumber();
		refreshSelects();
	} );

	tbody.addEventListener( 'change', function ( e ) {
		var row = e.target.closest( '.df-field-row' );
		if ( row && e.target.classList.contains( 'df-type' ) ) {
			applyType( row );
		}
		refreshSelects();
	} );

	tbody.addEventListener( 'input', function ( e ) {
		if ( e.target.classList.contains( 'df-label' ) || e.target.classList.contains( 'df-name' ) ) {
			refreshSelects();
		}
	} );

	form.addEventListener( 'submit', renumber );

	rows().forEach( applyType );
	renumber();
} )();
