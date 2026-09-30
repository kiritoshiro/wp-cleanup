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

	var media = document.querySelector( '.wpcu-media-form' );

	if ( media ) {
		var mRows = Array.prototype.slice.call( media.querySelectorAll( '.wpcu-items tbody tr[data-id]' ) );
		var mSearch = media.querySelector( '.wpcu-filter-search' );
		var mCount = media.querySelector( '.wpcu-visible-count' );
		var progress = media.querySelector( '.wpcu-media-progress' );
		var status = media.querySelector( '.wpcu-media-status' );
		var stopButton = media.querySelector( '.wpcu-media-stop' );
		var runButtons = media.querySelectorAll( '.wpcu-media-run' );
		var stopRequested = false;

		var mUsage = media.querySelector( '.wpcu-filter-usage' );
		var mApply = function () {
			var term = mSearch.value.trim().toLowerCase();
			var used = mUsage ? mUsage.value : 'all';
			var visible = 0;
			mRows.forEach( function ( row ) {
				var show = ( ! term || row.getAttribute( 'data-search' ).indexOf( term ) !== -1 ) &&
					( 'all' === used || row.getAttribute( 'data-used' ) === used );
				row.hidden = ! show;
				if ( ! show ) {
					row.querySelector( 'input[type=checkbox]' ).checked = false;
				}
				visible += show ? 1 : 0;
			} );
			mCount.textContent = visible + ' / ' + mRows.length;
		};
		mSearch.addEventListener( 'input', mApply );
		if ( mUsage ) {
			mUsage.addEventListener( 'change', mApply );
		}
		mApply();

		media.querySelector( '.wpcu-check-all' ).addEventListener( 'change', function ( event ) {
			mRows.forEach( function ( row ) {
				if ( ! row.hidden ) {
					row.querySelector( 'input[type=checkbox]' ).checked = event.target.checked;
				}
			} );
		} );

		var setBusy = function ( busy ) {
			Array.prototype.forEach.call( runButtons, function ( b ) {
				b.disabled = busy;
			} );
			stopButton.hidden = ! busy;
			progress.hidden = false;
		};

		var post = function ( ids, backup ) {
			var body = new URLSearchParams();
			body.append( 'action', 'wpcu_media_batch' );
			body.append( '_ajax_nonce', wpCleanup.mediaNonce );
			body.append( 'backup', backup || '' );
			ids.forEach( function ( id ) {
				body.append( 'ids[]', id );
			} );
			return fetch( wpCleanup.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
				return r.json();
			} );
		};

		Array.prototype.forEach.call( runButtons, function ( button ) {
			button.addEventListener( 'click', function () {
				var ids = mRows.filter( function ( row ) {
					return 'all' === button.getAttribute( 'data-scope' ) || row.querySelector( 'input[type=checkbox]' ).checked;
				} ).map( function ( row ) {
					return row.getAttribute( 'data-id' );
				} );
				if ( ! ids.length ) {
					window.alert( wpCleanup.nothing );
					return;
				}
				if ( ! media.querySelector( '.wpcu-media-confirm' ).checked ) {
					window.alert( wpCleanup.mediaConfirmBox );
					return;
				}
				if ( ! window.confirm( wpCleanup.confirmMedia.replace( '%d', ids.length ) ) ) {
					return;
				}

				var total = ids.length;
				var done = 0;
				var tally = { converted: 0, failed: 0, other: 0 };
				var backup = '';
				stopRequested = false;
				setBusy( true );
				progress.max = total;

				var finish = function ( message ) {
					setBusy( false );
					status.textContent = message;
				};

				var next = function () {
					if ( stopRequested ) {
						finish( wpCleanup.mediaStopped + ' ' + wpCleanup.mediaDone.replace( '%1$d', tally.converted ).replace( '%2$d', tally.failed ).replace( '%3$d', tally.other ).replace( '%4$s', backup || '—' ) );
						return;
					}
					var chunk = ids.slice( done, done + 3 );
					if ( ! chunk.length ) {
						finish( wpCleanup.mediaDone.replace( '%1$d', tally.converted ).replace( '%2$d', tally.failed ).replace( '%3$d', tally.other ).replace( '%4$s', backup || '—' ) );
						return;
					}
					status.textContent = done + ' / ' + total + '…';
					post( chunk, backup ).then( function ( json ) {
						if ( ! json || ! json.success ) {
							finish( ( json && json.data && json.data.message ) || 'Request failed.' );
							return;
						}
						backup = json.data.backup;
						json.data.results.forEach( function ( r ) {
							var row = media.querySelector( 'tr[data-id="' + r.id + '"]' );
							if ( row ) {
								row.querySelector( '.wpcu-result' ).textContent = r.status + ': ' + r.message;
								row.classList.add( 'wpcu-row-' + r.status );
								var box = row.querySelector( 'input[type=checkbox]' );
								box.checked = false;
								box.disabled = 'converted' === r.status;
							}
							if ( 'converted' === r.status ) {
								tally.converted++;
							} else if ( 'failed' === r.status ) {
								tally.failed++;
							} else {
								tally.other++;
							}
						} );
						done += chunk.length;
						progress.value = done;
						next();
					} ).catch( function ( error ) {
						finish( String( error ) );
					} );
				};
				next();
			} );
		} );

		stopButton.addEventListener( 'click', function () {
			stopRequested = true;
			stopButton.disabled = true;
		} );
	}

	var unused = document.querySelector( '.wpcu-unused-form' );
	if ( unused ) {
		var uBoxes = Array.prototype.slice.call( unused.querySelectorAll( 'input[name="ids[]"]' ) );
		var uCount = unused.querySelector( '.wpcu-unused-count' );
		var updateUnused = function () {
			uCount.textContent = uBoxes.filter( function ( box ) { return box.checked; } ).length + ' / 20';
		};
		var selectUnused = function ( lookalikes ) {
			var count = 0;
			uBoxes.forEach( function ( box ) {
				box.checked = ! box.disabled && count < 20 && ( ! lookalikes || '1' === box.getAttribute( 'data-lookalike' ) );
				if ( box.checked ) { count++; }
			} );
			updateUnused();
		};
		unused.querySelector( '.wpcu-select-lookalikes' ).addEventListener( 'click', function () { selectUnused( true ); } );
		unused.querySelector( '.wpcu-select-unused' ).addEventListener( 'click', function () { selectUnused( false ); } );
		unused.querySelector( '.wpcu-clear-unused' ).addEventListener( 'click', function () {
			uBoxes.forEach( function ( box ) { box.checked = false; } );
			updateUnused();
		} );
		uBoxes.forEach( function ( box ) { box.addEventListener( 'change', updateUnused ); } );
		updateUnused();
		unused.addEventListener( 'submit', function ( event ) {
			var selected = uBoxes.filter( function ( box ) { return box.checked; } ).length;
			if ( selected < 1 || selected > 20 ) {
				event.preventDefault();
				window.alert( wpCleanup.nothing + ' (1–20)' );
			} else if ( ! unused.querySelector( '.wpcu-unused-confirm' ).checked ) {
				event.preventDefault();
				window.alert( wpCleanup.mediaConfirmBox );
			} else if ( ! window.confirm( wpCleanup.confirmRemove.replace( '%d', selected ) ) ) {
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
