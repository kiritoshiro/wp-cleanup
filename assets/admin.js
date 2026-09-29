/* global wpCleanup */
( function () {
	'use strict';

	var form = document.querySelector( '.wpcu-clean-form' );

	if ( form ) {
		var statusSelect = form.querySelector( '.wpcu-filter-status' );
		var search = form.querySelector( '.wpcu-filter-search' );
		var counter = form.querySelector( '.wpcu-visible-count' );
		var rows = Array.prototype.slice.call( form.querySelectorAll( '.wpcu-items tbody tr[data-status]' ) );
		var cleanable = [ 'orphaned', 'safe', 'unknown', 'inactive' ];
		var remembered = null;

		try {
			remembered = window.localStorage.getItem( 'wpcuStatusFilter' );
		} catch ( e ) {}
		if ( remembered && statusSelect.querySelector( 'option[value="' + remembered + '"]' ) ) {
			statusSelect.value = remembered;
		}

		var apply = function () {
			var status = statusSelect.value;
			var term = search.value.trim().toLowerCase();
			var visible = 0;
			rows.forEach( function ( row ) {
				var s = row.getAttribute( 'data-status' );
				var okStatus = 'all' === status || ( 'cleanable' === status ? cleanable.indexOf( s ) !== -1 : s === status );
				var okTerm = ! term || row.getAttribute( 'data-search' ).indexOf( term ) !== -1;
				var show = okStatus && okTerm;
				row.hidden = ! show;
				if ( ! show ) {
					var box = row.querySelector( 'input[type=checkbox]' );
					if ( box ) {
						box.checked = false;
					}
				}
				visible += show ? 1 : 0;
			} );
			counter.textContent = visible + ' / ' + rows.length;
		};

		statusSelect.addEventListener( 'change', function () {
			try {
				window.localStorage.setItem( 'wpcuStatusFilter', statusSelect.value );
			} catch ( e ) {}
			apply();
		} );
		search.addEventListener( 'input', apply );
		apply();

		form.querySelector( '.wpcu-check-all' ).addEventListener( 'change', function ( event ) {
			rows.forEach( function ( row ) {
				var box = row.querySelector( 'input[type=checkbox]' );
				if ( box && ! row.hidden ) {
					box.checked = event.target.checked;
				}
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			var selected = form.querySelectorAll( 'input[name="items[]"]:checked' );
			if ( ! selected.length ) {
				event.preventDefault();
				window.alert( wpCleanup.nothing );
				return;
			}
			// Make the opt-in boxes match what is actually selected.
			var needs = {};
			Array.prototype.forEach.call( selected, function ( box ) {
				needs[ box.getAttribute( 'data-status' ) ] = true;
			} );
			var missing = Array.prototype.filter.call( form.querySelectorAll( '.wpcu-allow' ), function ( allow ) {
				return needs[ allow.getAttribute( 'data-status' ) ] && ! allow.checked;
			} );
			if ( missing.length ) {
				event.preventDefault();
				missing[ 0 ].parentNode.classList.add( 'wpcu-attention' );
				missing[ 0 ].focus();
				return;
			}
			if ( ! window.confirm( wpCleanup.confirmDelete.replace( '%d', selected.length ) ) ) {
				event.preventDefault();
			}
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-wpcu_restore, .wpcu-wpcu_delete_backup' ), function ( f ) {
		f.addEventListener( 'submit', function ( event ) {
			var message = f.classList.contains( 'wpcu-wpcu_restore' ) ? wpCleanup.confirmRestore : wpCleanup.confirmPurge;
			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
