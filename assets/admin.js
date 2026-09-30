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
			return fetch( wpCleanup.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
				return response.text().then( function ( raw ) {
					var json;
					try {
						json = JSON.parse( raw );
					} catch ( error ) {
						throw new Error( wpCleanup.mediaBadResponse.replace( '%d', response.status ) );
					}
					return json;
				} );
			} );
		};

		var appendList = function ( cell, label, entries, describe ) {
			if ( ! entries || ! entries.length ) { return; }
			var details = document.createElement( 'details' );
			var summary = document.createElement( 'summary' );
			summary.textContent = label.replace( '%d', entries.length );
			details.appendChild( summary );
			var list = document.createElement( 'ul' );
			entries.forEach( function ( entry ) {
				var item = document.createElement( 'li' );
				item.textContent = describe( entry );
				list.appendChild( item );
			} );
			details.appendChild( list );
			cell.appendChild( details );
		};

		Array.prototype.forEach.call( runButtons, function ( button ) {
			button.addEventListener( 'click', function () {
				var ids = mRows.filter( function ( row ) {
					var box = row.querySelector( 'input[type=checkbox]' );
					return ! box.disabled && ( 'all' === button.getAttribute( 'data-scope' ) || box.checked );
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
					var chunk = ids.slice( done, done + 1 );
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
								var resultCell = row.querySelector( '.wpcu-result' );
								resultCell.textContent = r.status + ': ' + r.message;
								appendList( resultCell, wpCleanup.mediaReferences, r.reference_changes, function ( item ) { return item.where + ': ' + item.from + ' → ' + item.to; } );
								appendList( resultCell, wpCleanup.mediaOriginals, r.backed_up, function ( item ) { return item; } );
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

	Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-removal-form' ), function ( removalForm ) {
		var boxes = function () {
			return Array.prototype.slice.call( document.querySelectorAll( 'input[name="ids[]"]' ) ).filter( function ( box ) {
				return box.form === removalForm;
			} );
		};
		var selectedIds = function () {
			return new window.FormData( removalForm ).getAll( 'ids[]' );
		};
		var count = removalForm.querySelector( '.wpcu-selection-count' );
		var updateCount = function () {
			count.textContent = selectedIds().length + ' / 20';
		};
		Array.prototype.forEach.call( removalForm.querySelectorAll( '.wpcu-select-images' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var mode = button.getAttribute( 'data-select-mode' );
				var picked = 0;
				boxes().forEach( function ( box ) {
					box.checked = 'clear' !== mode && ! box.disabled && picked < 20 && ( 'all' === mode || '1' === box.getAttribute( 'data-lookalike' ) );
					if ( box.checked ) { picked++; }
				} );
				updateCount();
			} );
		} );
		Array.prototype.forEach.call( removalForm.querySelectorAll( '.wpcu-single-remove' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var id = button.getAttribute( 'data-id' );
				boxes().forEach( function ( box ) { box.checked = ! box.disabled && box.value === id; } );
				updateCount();
				if ( removalForm.requestSubmit ) {
					removalForm.requestSubmit();
				} else {
					removalForm.querySelector( 'button[type="submit"]' ).click();
				}
			} );
		} );
		removalForm.addEventListener( 'change', updateCount );
		removalForm.addEventListener( 'input', updateCount );
		window.addEventListener( 'pageshow', updateCount );
		window.addEventListener( 'focus', updateCount );
		updateCount();
		removalForm.addEventListener( 'submit', function ( event ) {
			var selected = selectedIds().length;
			updateCount();
			if ( selected < 1 || selected > 20 ) {
				event.preventDefault();
				window.alert( wpCleanup.nothing + ' (1–20)' );
			} else if ( ! removalForm.querySelector( '.wpcu-removal-confirm' ).checked ) {
				event.preventDefault();
				window.alert( wpCleanup.mediaConfirmBox );
			} else if ( ! window.confirm( wpCleanup.confirmRemove.replace( '%d', selected ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-sortable' ), function ( table ) {
		var body = table.tBodies[ 0 ];
		Array.prototype.forEach.call( table.querySelectorAll( 'th[data-sort-type]' ), function ( heading ) {
			heading.querySelector( 'button' ).addEventListener( 'click', function () {
				var index = heading.cellIndex;
				var numeric = 'number' === heading.getAttribute( 'data-sort-type' );
				var direction = 'ascending' === heading.getAttribute( 'aria-sort' ) ? 'descending' : 'ascending';
				var rows = Array.prototype.slice.call( body.rows );
				var value = function ( row ) {
					var cell = row.cells[ index ];
					return cell.getAttribute( 'data-sort-value' ) || cell.textContent.trim();
				};
				rows = rows.map( function ( row, order ) { return { row: row, order: order }; } );
				rows.sort( function ( a, b ) {
					var av = value( a.row );
					var bv = value( b.row );
					var compared = numeric ? Number( av ) - Number( bv ) : av.localeCompare( bv, undefined, { numeric: true, sensitivity: 'base' } );
					return ( 'ascending' === direction ? compared : -compared ) || a.order - b.order;
				} );
				rows.forEach( function ( item ) { body.appendChild( item.row ); } );
				Array.prototype.forEach.call( table.querySelectorAll( 'th[data-sort-type]' ), function ( other ) { other.setAttribute( 'aria-sort', 'none' ); } );
				heading.setAttribute( 'aria-sort', direction );
			} );
		} );
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-wpcu_restore, .wpcu-wpcu_delete_backup' ), function ( f ) {
		f.addEventListener( 'submit', function ( event ) {
			var message = f.classList.contains( 'wpcu-wpcu_restore' ) ? wpCleanup.confirmRestore : wpCleanup.confirmPurge;
			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
