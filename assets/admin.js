/* global wpCleanup */
( function () {
	'use strict';

	var imageActionBusy = false;

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
		var initiallyDisabled = Array.prototype.map.call( runButtons, function ( button ) { return button.disabled; } );
		var stopRequested = false;
		var slowBox = media.querySelector( '.wpcu-media-slow' );
		var gapBox = media.querySelector( '.wpcu-size-gap' );
		var policyForm = document.querySelector( '.wpcu-policy-form' );
		var policyDirty = false;
		if ( policyForm ) {
			var policyValues = function () {
				return Array.prototype.map.call( policyForm.querySelectorAll( 'input[name]' ), function ( input ) {
					return input.name + ':' + ( input.type === 'checkbox' ? String( input.checked ) : input.value );
				} ).join( '|' );
			};
			var originalPolicy = policyValues();
			var checkPolicy = function () {
				policyDirty = policyValues() !== originalPolicy;
				Array.prototype.forEach.call( runButtons, function ( button, index ) {
					button.disabled = initiallyDisabled[ index ] || policyDirty;
				} );
				if ( policyDirty ) { status.textContent = wpCleanup.mediaPolicyUnsaved; }
			};
			policyForm.addEventListener( 'input', checkPolicy );
			policyForm.addEventListener( 'change', checkPolicy );
		}

		var mUsage = media.querySelector( '.wpcu-filter-usage' );
		var mApply = function () {
			var term = mSearch.value.trim().toLowerCase();
			var used = mUsage ? mUsage.value : 'all';
			var visible = 0;
			mRows.forEach( function ( row ) {
				var show = row.getAttribute( 'data-wpcu-removed' ) !== '1' && ( ! term || row.getAttribute( 'data-search' ).indexOf( term ) !== -1 ) &&
					( 'all' === used || row.getAttribute( 'data-used' ) === used );
				row.hidden = ! show;
				if ( ! show ) {
					row.querySelector( 'input[type=checkbox]' ).checked = false;
				}
				visible += show ? 1 : 0;
			} );
			mCount.textContent = visible + ' / ' + mRows.filter( function ( row ) { return row.getAttribute( 'data-wpcu-removed' ) !== '1'; } ).length;
		};
		mSearch.addEventListener( 'input', mApply );
		if ( mUsage ) {
			mUsage.addEventListener( 'change', mApply );
		}
		mApply();

		media.querySelector( '.wpcu-check-all' ).addEventListener( 'change', function ( event ) {
			mRows.forEach( function ( row ) {
				if ( ! row.hidden && ! row.querySelector( 'input[type=checkbox]' ).disabled ) {
					row.querySelector( 'input[type=checkbox]' ).checked = event.target.checked;
				}
			} );
		} );

		var setBusy = function ( busy ) {
			imageActionBusy = busy;
			Array.prototype.forEach.call( runButtons, function ( b, index ) {
				b.disabled = busy || policyDirty || initiallyDisabled[ index ];
			} );
			stopButton.hidden = ! busy;
			progress.hidden = false;
		};

		// Resolves to { json } or, when the server or a proxy sent no JSON (502/504, a dropped connection), { lost, httpStatus, waited }.
		var request = function ( action, fields ) {
			var body = new URLSearchParams();
			body.append( 'action', action );
			body.append( '_ajax_nonce', wpCleanup.mediaNonce );
			body.append( 'policy', wpCleanup.mediaPolicyHash );
			Object.keys( fields ).forEach( function ( key ) {
				[].concat( fields[ key ] ).forEach( function ( value ) {
					body.append( key, value );
				} );
			} );
			var started = Date.now();
			var lost = function ( status ) {
				return { lost: true, httpStatus: status, waited: Math.round( ( Date.now() - started ) / 1000 ) };
			};
			return fetch( wpCleanup.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
				return response.text().then( function ( raw ) {
					try {
						return { json: JSON.parse( raw ) };
					} catch ( error ) {
						return lost( response.status );
					}
				} );
			}, function () {
				return lost( 0 );
			} );
		};
		var post = function ( ids, backup, gap ) {
			return request( 'wpcu_media_batch', { backup: backup || '', 'ids[]': ids, gap: gap } );
		};
		var check = function ( id, backup, slow, waited, gap ) {
			return request( 'wpcu_media_check', { id: id, backup: backup || '', slow: slow ? '1' : '', waited: waited || '', gap: gap } );
		};
		var pause = function ( ms ) {
			return new Promise( function ( resolve ) { window.setTimeout( resolve, ms ); } );
		};

		// "1920×1280 · AVIF · 58.3 KB" for a file info object from the server.
		var infoText = function ( info ) {
			if ( ! info ) { return ''; }
			var parts = [];
			if ( info.width && info.height ) { parts.push( info.width + '×' + info.height ); }
			if ( info.mime ) { parts.push( String( info.mime ).replace( 'image/', '' ).toUpperCase() ); }
			if ( 'number' === typeof info.bytes ) {
				var b = info.bytes;
				parts.push( b >= 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : ( b >= 1024 ? ( b / 1024 ).toFixed( 1 ) + ' KB' : b + ' B' ) );
			}
			return parts.join( ' · ' );
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
				if ( entry && 'object' === typeof entry && 'where' in entry ) {
					item.className = 'wpcu-reference-change';
					var fromInfo = infoText( entry.from_info );
					var toInfo = infoText( entry.to_info );
					[ [ wpCleanup.mediaLocation, entry.where ], [ wpCleanup.mediaOriginal, entry.from + ( fromInfo ? ' — ' + fromInfo : '' ) ], [ wpCleanup.mediaNew, entry.to + ( toInfo ? ' — ' + toInfo : '' ) ] ].forEach( function ( pair ) {
						var line = document.createElement( 'div' );
						var name = document.createElement( 'strong' );
						name.textContent = pair[ 0 ] + ': ';
						line.appendChild( name );
						var value = document.createElement( 'span' );
						value.textContent = pair[ 1 ];
						line.appendChild( value );
						item.appendChild( line );
						} );
				} else {
					item.textContent = describe( entry );
				}
				list.appendChild( item );
			} );
			details.appendChild( list );
			cell.appendChild( details );
		};

		Array.prototype.forEach.call( runButtons, function ( button ) {
			button.addEventListener( 'click', function () {
				if ( imageActionBusy ) { return; }
				if ( policyDirty ) { status.textContent = wpCleanup.mediaPolicyUnsaved; return; }
				var selected = 'selected' === button.getAttribute( 'data-scope' );
				if ( selected && ! gapBox.reportValidity() ) { return; }
				var gap = selected ? parseInt( gapBox.value, 10 ) : 0;
				if ( ! Number.isInteger( gap ) || gap < 0 || gap > 8192 ) { gapBox.reportValidity(); return; }
				var ids = mRows.filter( function ( row ) {
					var box = row.querySelector( 'input[type=checkbox]' );
					return ! box.disabled && ( selected ? box.checked : row.getAttribute( 'data-kind' ) === 'convert' );
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
				if ( ! window.confirm( ( selected ? wpCleanup.confirmGap : wpCleanup.confirmMedia ).replace( '%d', ids.length ) ) ) {
					return;
				}

				var total = ids.length;
				var done = 0;
				var tally = { converted: 0, failed: 0, other: 0 };
				var backup = '';
				stopRequested = false;
				setBusy( true );
				progress.max = total;

				var deferred = [];
				var summary = function () {
					var text = wpCleanup.mediaDone.replace( '%1$d', tally.converted ).replace( '%2$d', tally.failed ).replace( '%3$d', tally.other ).replace( '%4$s', backup || '—' );
					if ( deferred.length ) {
						text += ' ' + wpCleanup.mediaDeferred.replace( '%1$d', deferred.length ).replace( '%2$s', 'wp cleanup images convert --ids=' + deferred.join( ',' ) );
					}
					return text;
				};
				var finish = function ( message ) {
					setBusy( false );
					status.textContent = message === wpCleanup.mediaStopped ? message + ' ' + summary() : message;
				};

				var next = function () {
					if ( stopRequested ) {
						finish( wpCleanup.mediaStopped );
						return;
					}
					var chunk = ids.slice( done, done + 1 );
					if ( ! chunk.length ) {
						finish( summary() );
						return;
					}
					status.textContent = done + ' / ' + total + '…';
					var id = chunk[ 0 ];
					var advance = function () {
						done += 1;
						progress.value = done;
						next();
					};
					var refreshRow = function ( row, current ) {
						if ( ! row || ! current ) { return; }
						row.querySelector( '.wpcu-name code' ).textContent = current.file;
						row.setAttribute( 'data-search', row.getAttribute( 'data-search' ) + ' ' + String( current.file ).toLowerCase() );
						row.querySelector( '.wpcu-format' ).textContent = String( current.mime ).replace( 'image/', '' );
						var size = row.querySelector( '.wpcu-size' );
						size.textContent = current.width + '×' + current.height;
						size.setAttribute( 'data-sort-value', current.width * current.height );
						var files = row.querySelector( '.wpcu-file-count' );
						files.textContent = Number( current.files ).toLocaleString();
						files.setAttribute( 'data-sort-value', current.files );
						var strays = row.querySelector( '.wpcu-stray-count' );
						strays.textContent = current.strays ? Number( current.strays ).toLocaleString() : '–';
						strays.setAttribute( 'data-sort-value', current.strays );
						var disk = row.querySelector( '.wpcu-disk' );
						disk.textContent = current.disk;
						disk.setAttribute( 'data-sort-value', current.bytes );
					};
					var mark = function ( r ) {
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
					};
					var serverError = function ( result ) {
						return result.json && result.json.data && result.json.data.message ? result.json.data.message : 'Request failed.';
					};
					var waitedTotal = 0;
					// Ask the server about this image until it is no longer being converted. After a lost answer, `lostAfter` is how long that request ran.
					var settle = function ( lostAfter ) {
						if ( stopRequested ) { return Promise.resolve( null ); }
						return check( id, backup, slowBox && slowBox.checked, lostAfter, gap ).then( function ( result ) {
							if ( result.lost ) {
								return pause( 5000 ).then( function () { waitedTotal += 5; return waitedTotal > 1200 ? { giveUp: true } : settle( lostAfter ? 1 : 0 ); } );
							}
							if ( ! result.json.success ) { throw new Error( serverError( result ) ); }
							var c = result.json.data;
							if ( c.backup ) { backup = c.backup; }
							if ( 'busy' !== c.state ) { return c; }
							status.textContent = wpCleanup.mediaWaiting.replace( '%1$d', id ).replace( '%2$d', waitedTotal );
							return pause( 5000 ).then( function () { waitedTotal += 5; return waitedTotal > 1200 ? { giveUp: true } : settle( lostAfter ? 1 : 0 ); } );
						} );
					};
					// Outcome of a check that did not lead to a conversion request.
					var settled = function ( c, afterLoss ) {
						refreshRow( media.querySelector( 'tr[data-id="' + id + '"]' ), c.current );
						if ( afterLoss && 'done' === c.state ) {
							mark( { id: id, status: 'converted', message: wpCleanup.mediaFinishedLate } );
						} else if ( afterLoss && 'ready' === c.state ) {
							mark( { id: id, status: 'failed', message: wpCleanup.mediaNotFinished } );
						} else if ( 'done' === c.state ) {
							mark( { id: id, status: 'compliant', message: c.message } );
						} else if ( 'interrupted' === c.state ) {
							mark( { id: id, status: 'failed', message: c.message } );
						} else if ( 'risky' === c.state ) {
							deferred.push( id );
							mark( { id: id, status: 'deferred', message: c.message } );
						} else {
							mark( { id: id, status: 'skipped', message: c.message } );
						}
						advance();
					};
					var recover = function ( lostResult ) {
						status.textContent = wpCleanup.mediaTimedOut.replace( '%1$d', lostResult.httpStatus ).replace( '%2$d', lostResult.waited ).replace( '%3$d', id );
						// Only a gateway answer (or a dropped connection) says something about the server's time limit.
						var waited = -1 === [ 0, 502, 503, 504, 524 ].indexOf( lostResult.httpStatus ) ? 0 : Math.max( 1, lostResult.waited );
						return settle( waited || 1 ).then( function ( c ) {
							if ( ! c ) { finish( wpCleanup.mediaStopped ); return; }
							if ( c.giveUp ) { finish( wpCleanup.mediaGiveUp ); return; }
							settled( c, true );
						} );
					};
					settle( 0 ).then( function ( c ) {
						if ( ! c ) { finish( wpCleanup.mediaStopped ); return; }
						if ( c.giveUp ) { finish( wpCleanup.mediaGiveUp ); return; }
						if ( 'ready' !== c.state ) { settled( c, false ); return; }
						return post( chunk, backup, gap ).then( function ( result ) {
							if ( result.lost ) { return recover( result ); }
							handle( result.json );
						} );
					} ).catch( function ( error ) {
						finish( String( error && error.message ? error.message : error ) );
					} );
					var handle = function ( json ) {
						if ( ! json || ! json.success ) {
							finish( ( json && json.data && json.data.message ) || 'Request failed.' );
							return;
						}
						backup = json.data.backup;
						json.data.results.forEach( function ( r ) {
							var row = media.querySelector( 'tr[data-id="' + r.id + '"]' );
							if ( row ) {
								refreshRow( row, r.current );
								var resultCell = row.querySelector( '.wpcu-result' );
								resultCell.textContent = r.status + ': ' + r.message;
								appendList( resultCell, wpCleanup.mediaReferences, r.reference_changes, function ( item ) { return item.where + ': ' + item.from + ' → ' + item.to; } );
								var fileLine = function ( item ) {
									if ( 'string' === typeof item ) { return item; }
									var text = infoText( item );
									return ( item.role ? item.role + ': ' : '' ) + item.path + ( text ? ' — ' + text : '' );
								};
								appendList( resultCell, wpCleanup.mediaOriginals, r.backed_up_info && r.backed_up_info.length ? r.backed_up_info : r.backed_up, fileLine );
								appendList( resultCell, wpCleanup.mediaCreated, r.created_info, fileLine );
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
						advance();
					};
				};
				next();
			} );
		} );

		stopButton.addEventListener( 'click', function () {
			stopRequested = true;
			stopButton.disabled = true;
		} );
	}

	// Keep every visible representation of an attachment in sync after a mutation.
	var syncRemovedImage = function ( id ) {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-image-id="' + id + '"], .wpcu-media-form tr[data-id="' + id + '"]' ), function ( item ) {
			item.hidden = true;
			item.setAttribute( 'data-wpcu-removed', '1' );
			Array.prototype.forEach.call( item.querySelectorAll( 'input, button, select' ), function ( control ) {
				control.disabled = true;
				control.setAttribute( 'data-wpcu-removed', '1' );
				if ( control.type === 'checkbox' ) { control.checked = false; }
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-merge-keeper option, .wpcu-group-keeper option' ), function ( option ) {
			if ( option.value === String( id ) ) { option.remove(); }
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-dup-group' ), function ( group ) {
			var active = group.querySelectorAll( '.wpcu-dup-item:not([data-wpcu-removed="1"])' );
			var head = group.querySelector( '.wpcu-dup-head' );
			if ( ! head.hasAttribute( 'data-group-label' ) ) { head.setAttribute( 'data-group-label', head.textContent.replace( /^.*? · /, '' ) ); }
			head.textContent = active.length > 1 ? active.length + ' · ' + head.getAttribute( 'data-group-label' ) : wpCleanup.mergeResolved;
			if ( active.length < 2 ) {
				Array.prototype.forEach.call( group.querySelectorAll( '.wpcu-dup-group-action, .wpcu-dup-merge, .wpcu-dup-remove' ), function ( item ) { item.hidden = true; } );
			}
		} );
		var stale = document.querySelector( '.wpcu-image-snapshot' );
		if ( stale ) { stale.hidden = false; stale.querySelector( 'p' ).textContent = wpCleanup.imageSnapshotStale; }
		if ( media ) { mApply(); }
		document.dispatchEvent( new Event( 'wpcu-images-changed' ) );
	};
	var syncKeeper = function ( data ) {
		if ( data.keeper_usage ) {
			Array.prototype.forEach.call( document.querySelectorAll( '[data-image-usage="' + data.keep + '"]' ), function ( item ) { item.innerHTML = data.keeper_usage; } );
		}
		if ( data.keeper_used ) {
			if ( media ) {
				var row = media.querySelector( 'tr[data-id="' + data.keep + '"]' );
				if ( row ) {
					row.setAttribute( 'data-used', '1' );
					var cell = row.querySelector( '.wpcu-used' );
					if ( cell && data.keeper_usage ) { cell.innerHTML = data.keeper_usage; }
				}
				mApply();
			}
			Array.prototype.forEach.call( document.querySelectorAll( '[data-image-id="' + data.keep + '"]' ), function ( item ) {
				if ( item.closest( '.wpcu-unused-form' ) ) {
					item.hidden = true;
					Array.prototype.forEach.call( item.querySelectorAll( 'input' ), function ( control ) {
						control.disabled = true;
						control.checked = false;
						control.setAttribute( 'data-wpcu-unavailable', '1' );
					} );
				}
				if ( item.classList.contains( 'wpcu-dup-item' ) ) {
					item.setAttribute( 'data-used', '1' );
					Array.prototype.forEach.call( item.querySelectorAll( '.wpcu-dup-remove input, .wpcu-single-remove' ), function ( control ) {
						control.disabled = true;
						control.setAttribute( 'data-wpcu-unavailable', '1' );
						if ( control.type === 'checkbox' ) { control.checked = false; }
					} );
					var removeControls = item.querySelector( '.wpcu-dup-remove' );
					if ( removeControls ) { removeControls.hidden = true; }
					var group = item.closest( '.wpcu-dup-group' );
					if ( group.getAttribute( 'data-identical' ) === '1' ) {
						Array.prototype.forEach.call( group.querySelectorAll( '.wpcu-dup-item' ), function ( member ) {
							var id = member.getAttribute( 'data-image-id' );
							Array.prototype.forEach.call( document.querySelectorAll( 'input[name="ids[]"][value="' + id + '"][data-used-keeper]' ), function ( box ) { box.setAttribute( 'data-used-keeper', data.keep ); } );
						} );
					}
				}
			} );
		}
	};
	var mergeRequest = function ( fields ) {
		var body = new URLSearchParams();
		body.append( 'action', 'wpcu_media_merge_step' );
		body.append( '_ajax_nonce', wpCleanup.mergeNonce );
		body.append( 'confirm', '1' );
		Object.keys( fields ).forEach( function ( key ) { body.append( key, fields[ key ] ); } );
		return fetch( wpCleanup.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.text().then( function ( raw ) {
				var json;
				try { json = JSON.parse( raw ); } catch ( e ) { throw new Error( wpCleanup.removeInterrupted ); }
				if ( ! json || ! json.success ) { throw new Error( json && json.data && json.data.message ? json.data.message : wpCleanup.removeInterrupted ); }
				return json.data;
			} );
		}, function () { throw new Error( wpCleanup.removeInterrupted ); } );
	};
	Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-merge-image, .wpcu-merge-group' ), function ( button ) {
		button.addEventListener( 'click', function () {
			if ( imageActionBusy ) { return; }
			var group = button.closest( '.wpcu-dup-group' );
			var whole = button.classList.contains( 'wpcu-merge-group' );
			var keeper = whole ? group.querySelector( '.wpcu-group-keeper' ) : button.parentNode.querySelector( '.wpcu-merge-keeper' );
			if ( ! keeper || ! keeper.value ) { return; }
			var keep = keeper.value;
			var drops = whole ? Array.prototype.map.call( group.querySelectorAll( '.wpcu-dup-item:not([data-wpcu-removed="1"])' ), function ( item ) { return item.getAttribute( 'data-image-id' ); } ).filter( function ( id ) { return id !== keep; } ) : [ button.getAttribute( 'data-drop' ) ];
			if ( ! drops.length ) { return; }
			var confirmation = whole ? wpCleanup.confirmMergeGroup.replace( '%1$d', keep ).replace( '%2$d', drops.length ) : wpCleanup.confirmMerge.replace( '%1$d', drops[ 0 ] ).replace( '%2$d', keep );
			if ( ! window.confirm( confirmation ) ) { return; }
			imageActionBusy = true;
			var controls = Array.prototype.slice.call( document.querySelectorAll( '.wpcu-media-form input, .wpcu-media-form button, .wpcu-removal-form input, .wpcu-removal-form button, .wpcu-removal-form select' ) );
			var prior = controls.map( function ( control ) { return control.disabled; } );
			controls.forEach( function ( control ) { control.disabled = true; } );
			var feedback = group.querySelector( '.wpcu-dup-feedback' );
			var backup = '';
			var done = 0;
			var finish = function ( extra ) {
				imageActionBusy = false;
				controls.forEach( function ( control, index ) { control.disabled = prior[ index ] || control.getAttribute( 'data-wpcu-removed' ) === '1' || control.getAttribute( 'data-wpcu-unavailable' ) === '1'; } );
				feedback.textContent = wpCleanup.mergeDone.replace( '%1$d', done ).replace( '%2$d', drops.length ).replace( '%3$s', backup || '—' ) + ( extra ? ' ' + extra : '' );
				document.dispatchEvent( new Event( 'wpcu-images-changed' ) );
			};
			var next = function () {
				if ( done === drops.length ) { finish( '' ); return; }
				feedback.textContent = done + ' / ' + drops.length + ' · ' + backup;
				mergeRequest( { drop: drops[ done ], keep: keep, backup: backup, identical: whole ? '1' : '' } ).then( function ( data ) {
					syncRemovedImage( data.drop );
					syncKeeper( data );
					done++;
					if ( data.warning ) { finish( data.warning ); return; }
					next();
				} ).catch( function ( error ) { finish( error.message ); } );
			};
			mergeRequest( { kind: 'start' } ).then( function ( data ) { backup = data.backup; next(); } ).catch( function ( error ) { finish( error.message ); } );
		} );
	} );

	var setupRemoval = function ( form, kind ) {
		var field = 'image' === kind ? 'ids[]' : 'paths[]';
		var count = form.querySelector( '.wpcu-selection-count' );
		var progress = form.querySelector( '.wpcu-removal-progress' );
		var status = form.querySelector( '.wpcu-removal-status' );
		var errors = form.querySelector( '.wpcu-removal-errors' );
		var stopButton = form.querySelector( '.wpcu-removal-stop' );
		var buttons = Array.prototype.slice.call( form.querySelectorAll( 'button:not(.wpcu-removal-stop)' ) );
		var running = false;
		var automaticDuplicates = false;
		var stopRequested = false;
		var boxes = function () {
			return Array.prototype.slice.call( document.querySelectorAll( 'input[name="' + field + '"]' ) ).filter( function ( box ) {
				return box.form === form;
			} );
		};
		var selected = function () {
			return boxes().filter( function ( box ) { return box.checked && ! box.disabled; } );
		};
		var updateCount = function () {
			count.textContent = boxes().filter( function ( box ) { return box.checked; } ).length;
		};
		var request = function ( fields ) {
			var body = new URLSearchParams();
			body.append( 'action', 'wpcu_media_remove_step' );
			body.append( '_ajax_nonce', wpCleanup.removeNonce );
			body.append( 'confirm', '1' );
			Object.keys( fields ).forEach( function ( key ) { body.append( key, fields[ key ] ); } );
			return fetch( wpCleanup.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
				return response.text().then( function ( raw ) {
					var json;
					try { json = JSON.parse( raw ); } catch ( e ) { throw new Error( wpCleanup.removeInterrupted ); }
					if ( ! json || ! json.success ) {
						throw new Error( json && json.data && json.data.message ? json.data.message : wpCleanup.removeInterrupted );
					}
					return json.data;
				} );
			}, function () { throw new Error( wpCleanup.removeInterrupted ); } );
		};
		var busy = function ( active, prior ) {
			running = active;
			imageActionBusy = active;
			buttons.forEach( function ( button ) { button.disabled = active || button.getAttribute( 'data-wpcu-removed' ) === '1'; } );
			boxes().forEach( function ( box, index ) {
				box.disabled = active || prior[ index ] || box.getAttribute( 'data-wpcu-removed' ) === '1' || box.getAttribute( 'data-wpcu-unavailable' ) === '1';
			} );
			stopButton.hidden = ! active;
		};
		Array.prototype.forEach.call( form.querySelectorAll( '.wpcu-select-images' ), function ( button ) {
			button.addEventListener( 'click', function () {
				if ( running ) { return; }
				var mode = button.getAttribute( 'data-select-mode' );
				automaticDuplicates = mode === 'used-identical';
				boxes().forEach( function ( box ) {
					box.checked = 'clear' !== mode && ! box.disabled && ( 'all' === mode || ( mode === 'used-identical' ? Number( box.getAttribute( 'data-used-keeper' ) ) > 0 : '1' === box.getAttribute( 'data-lookalike' ) ) );
				} );
				updateCount();
			} );
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '.wpcu-single-remove' ), function ( button ) {
			button.addEventListener( 'click', function () {
				if ( running ) { return; }
				automaticDuplicates = false;
				var id = button.getAttribute( 'data-id' );
				boxes().forEach( function ( box ) { box.checked = ! box.disabled && box.value === id; } );
				updateCount();
				if ( form.requestSubmit ) { form.requestSubmit(); }
				else { form.querySelector( 'button[type="submit"]' ).click(); }
			} );
		} );
		form.addEventListener( 'change', updateCount );
		document.addEventListener( 'wpcu-images-changed', updateCount );
		form.addEventListener( 'input', updateCount );
		window.addEventListener( 'pageshow', updateCount );
		window.addEventListener( 'focus', updateCount );
		stopButton.addEventListener( 'click', function () {
			stopRequested = true;
			stopButton.disabled = true;
		} );
		updateCount();
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( running || imageActionBusy ) { return; }
			var chosen = selected();
			if ( ! chosen.length ) { window.alert( wpCleanup.nothing ); return; }
			if ( ! form.querySelector( '[name="confirm"]' ).checked ) { window.alert( wpCleanup.mediaConfirmBox ); return; }
			if ( ! window.confirm( ( 'image' === kind ? wpCleanup.confirmRemove : wpCleanup.confirmServerFiles ).replace( '%d', chosen.length ) ) ) { return; }
			var total = chosen.length;
			var prior = boxes().map( function ( box ) { return box.disabled; } );
			var backup = '';
			var done = 0;
			var moved = 0;
			stopRequested = false;
			stopButton.disabled = false;
			errors.textContent = '';
			progress.max = total;
			progress.value = 0;
			progress.hidden = false;
			busy( true, prior );
			var finish = function ( extra ) {
				busy( false, prior );
				updateCount();
				var summary = wpCleanup.removeDone.replace( '%1$d', moved ).replace( '%2$d', total ).replace( '%3$s', backup || '—' );
				status.textContent = summary + ( extra ? ' ' + extra : '' );
			};
			var next = function () {
				if ( stopRequested || done === total ) {
					finish( stopRequested ? wpCleanup.removeStopped : '' );
					return;
				}
				var box = chosen[ done ];
				status.textContent = done + ' / ' + total + ' · ' + ( backup || '…' );
				var safeDuplicate = 'image' === kind && automaticDuplicates;
				var fields = { kind: safeDuplicate ? 'duplicate' : kind, backup: backup };
				if ( safeDuplicate ) { fields.keep = box.getAttribute( 'data-used-keeper' ) || '0'; }
				fields[ 'image' === kind ? 'id' : 'path' ] = box.value;
				request( fields ).then( function ( data ) {
					var result = data.result;
					if ( ! result ) { throw new Error( wpCleanup.removeInterrupted ); }
					if ( 'deleted' === result.status ) {
						moved++;
						if ( 'image' === kind ) { syncRemovedImage( box.value ); }
						box.checked = false;
						box.setAttribute( 'data-wpcu-removed', '1' );
						var row = box.closest( 'tr' ) || box.closest( '.wpcu-dup-item' );
						if ( row ) {
							row.classList.add( 'wpcu-row-deleted' );
							Array.prototype.forEach.call( row.querySelectorAll( 'button' ), function ( button ) { button.setAttribute( 'data-wpcu-removed', '1' ); } );
						}
					} else {
						var item = document.createElement( 'li' );
						item.textContent = box.value + ': ' + result.message;
						errors.appendChild( item );
					}
					done++;
					progress.value = done;
					updateCount();
					next();
				} ).catch( function ( error ) {
					finish( String( error && error.message ? error.message : error ) );
				} );
			};
			request( { kind: 'start' } ).then( function ( data ) {
				backup = data.backup;
				next();
			} ).catch( function ( error ) {
				finish( String( error && error.message ? error.message : error ) );
			} );
		} );
	};
	Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-removal-form' ), function ( form ) {
		setupRemoval( form, 'image' );
	} );
	Array.prototype.forEach.call( document.querySelectorAll( '.wpcu-orphan-form' ), function ( form ) {
		setupRemoval( form, 'file' );
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
