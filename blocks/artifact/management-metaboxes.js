( function () {
	'use strict';

	var postId = window.wmacArtifactPostId || 0;

	// ---------------------------------------------------------------------------
	// Autosave — PATCH straight to the REST API. No wp.data, no React: this file
	// runs inside the PHP metabox iframe, where the block-editor / editor stores
	// are never populated (there's no block editor instance in that frame). A
	// direct REST call has no such dependency.
	// ---------------------------------------------------------------------------

	function setStatus( el, text, isError ) {
		if ( ! el ) return;
		el.textContent = text;
		el.classList.toggle( 'wmac-save-status--error', !! isError );
	}

	// Last value successfully sent per meta key. Skipping identical re-sends
	// (debounce + blur double-fire) avoids pointless requests and a core
	// gotcha: update_metadata() returns false when the sanitized value equals
	// the stored one, which the REST API surfaces as a 500 even though
	// nothing is wrong.
	var lastSaved = {};

	function saveMeta( metaKey, value, statusEl ) {
		if ( ! postId ) {
			setStatus( statusEl, wp.i18n.__( 'Save the post first', 'webmultipliers-wp-artifact-canvas' ), true );
			return;
		}

		if ( Object.prototype.hasOwnProperty.call( lastSaved, metaKey ) && lastSaved[ metaKey ] === value ) {
			return;
		}

		setStatus( statusEl, wp.i18n.__( 'Saving…', 'webmultipliers-wp-artifact-canvas' ) );

		var data    = { meta: {} };
		data.meta[ metaKey ] = value;

		wp.apiFetch( {
			path:   '/wp/v2/wm_artifact/' + postId,
			method: 'PATCH',
			data:   data,
		} ).then( function () {
			lastSaved[ metaKey ] = value;
			setStatus( statusEl, wp.i18n.__( 'Saved', 'webmultipliers-wp-artifact-canvas' ) );
			setTimeout( function () {
				if ( statusEl && statusEl.textContent === wp.i18n.__( 'Saved', 'webmultipliers-wp-artifact-canvas' ) ) {
					statusEl.textContent = '';
				}
			}, 2000 );
		} ).catch( function ( err ) {
			delete lastSaved[ metaKey ]; // let the user retry
			var message = ( err && err.message ) ? err.message : wp.i18n.__( 'Error saving', 'webmultipliers-wp-artifact-canvas' );
			setStatus( statusEl, message, true );
		} );
	}

	function debounce( fn, wait ) {
		var timer;
		return function () {
			var args = arguments;
			clearTimeout( timer );
			timer = setTimeout( function () { fn.apply( null, args ); }, wait );
		};
	}

	// ---------------------------------------------------------------------------
	// Generic [data-wmac-meta] fields (Settings / Governance / Tracking)
	// ---------------------------------------------------------------------------

	function wireSimpleFields() {
		document.querySelectorAll( '[data-wmac-meta]' ).forEach( function ( el ) {
			var metaKey  = el.dataset.wmacMeta;
			var wrapper  = el.closest( '.wmac-metabox' );
			var statusEl = wrapper ? wrapper.querySelector( '.wmac-save-status' ) : null;

			function getValue() {
				if ( el.type === 'checkbox' ) {
					return el.checked ? '1' : '0';
				}
				if ( el.type === 'number' ) {
					// Integer-typed meta rejects '' at the REST schema layer;
					// a cleared field means 0 (= unlimited for max views).
					return parseInt( el.value, 10 ) || 0;
				}
				return el.value;
			}

			var save = function () { saveMeta( metaKey, getValue(), statusEl ); };

			if ( el.type === 'checkbox' || el.tagName === 'SELECT' ) {
				el.addEventListener( 'change', save );
			} else {
				var debounced = debounce( save, 600 );
				el.addEventListener( 'input', debounced );
				el.addEventListener( 'blur', save );
			}
		} );
	}

	// ---------------------------------------------------------------------------
	// Merge Tags table
	// ---------------------------------------------------------------------------

	function collectTagMap( tbody ) {
		var map = {};
		tbody.querySelectorAll( 'tr' ).forEach( function ( row ) {
			var tag  = row.dataset.tag;
			var mode = row.querySelector( '.wmac-tag-mode' ).value;
			var entry = { mode: mode };
			if ( mode === 'static' ) {
				entry.value = row.querySelector( '.wmac-tag-value' ).value;
				var contextEl = row.querySelector( '.wmac-tag-context' );
				entry.context = contextEl ? contextEl.value : 'text';
			}
			map[ tag ] = entry;
		} );
		return map;
	}

	function saveTagMap( wrapper ) {
		var table   = wrapper.querySelector( '#wmac-tagmap-table' );
		var map     = collectTagMap( table.querySelector( 'tbody' ) );
		var statusEl = wrapper.querySelector( '.wmac-save-status' );
		saveMeta( wrapper.dataset.wmacMapMeta, JSON.stringify( map ), statusEl );
	}

	function toggleTagRowMode( row ) {
		var mode      = row.querySelector( '.wmac-tag-mode' ).value;
		var valueEl   = row.querySelector( '.wmac-tag-value' );
		var hookEl    = row.querySelector( '.wmac-mgmt__hook' );
		var contextEl = row.querySelector( '.wmac-tag-context' );
		valueEl.style.display = mode === 'dynamic' ? 'none' : '';
		hookEl.style.display  = mode === 'static'  ? 'none' : '';
		if ( contextEl ) {
			contextEl.style.display = mode === 'dynamic' ? 'none' : '';
		}
	}

	function wireTagRow( row, wrapper ) {
		var modeEl  = row.querySelector( '.wmac-tag-mode' );
		var valueEl = row.querySelector( '.wmac-tag-value' );
		var removeEl = row.querySelector( '.wmac-tag-remove' );
		var contextEl = row.querySelector( '.wmac-tag-context' );

		modeEl.addEventListener( 'change', function () {
			toggleTagRowMode( row );
			saveTagMap( wrapper );
		} );
		if ( contextEl ) {
			contextEl.addEventListener( 'change', function () { saveTagMap( wrapper ); } );
		}
		valueEl.addEventListener( 'input', debounce( function () { saveTagMap( wrapper ); }, 600 ) );
		valueEl.addEventListener( 'blur', function () { saveTagMap( wrapper ); } );
		removeEl.addEventListener( 'click', function () {
			var table = wrapper.querySelector( '#wmac-tagmap-table' );
			row.remove();
			refreshEmptyState( wrapper, table );
			saveTagMap( wrapper );
		} );
	}

	function refreshEmptyState( wrapper, table ) {
		var hasRows = table.querySelector( 'tbody tr' ) !== null;
		table.style.display = hasRows ? '' : 'none';
		var empty = wrapper.querySelector( '.wmac-mgmt__empty' );
		if ( empty ) empty.style.display = hasRows ? 'none' : '';
	}

	function initMergeTags() {
		var wrapper = document.querySelector( '[data-wmac-map-meta="_wmac_tag_map"]' );
		if ( ! wrapper ) return;

		var table = wrapper.querySelector( '#wmac-tagmap-table' );
		table.querySelectorAll( 'tbody tr' ).forEach( function ( row ) { wireTagRow( row, wrapper ); } );

		var input  = wrapper.querySelector( '#wmac-new-tag' );
		var button = wrapper.querySelector( '#wmac-add-tag' );
		var template = document.getElementById( 'wmac-tag-row-template' );

		input.addEventListener( 'input', function () {
			button.disabled = input.value.trim() === '';
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); if ( ! button.disabled ) button.click(); }
		} );

		button.addEventListener( 'click', function () {
			var name = input.value.trim().replace( /[^a-zA-Z0-9_]/g, '' );
			if ( ! name ) return;
			var tbody = table.querySelector( 'tbody' );
			if ( tbody.querySelector( 'tr[data-tag="' + CSS.escape( name ) + '"]' ) ) return;

			var rowHtml = template.innerHTML.split( '__TAG__' ).join( name );
			var temp = document.createElement( 'tbody' );
			temp.innerHTML = rowHtml;
			var newRow = temp.querySelector( 'tr' );
			tbody.appendChild( newRow );

			wireTagRow( newRow, wrapper );
			refreshEmptyState( wrapper, table );
			saveTagMap( wrapper );

			input.value = '';
			button.disabled = true;
			newRow.querySelector( '.wmac-tag-value' ).focus();
		} );
	}

	// ---------------------------------------------------------------------------
	// Asset Mapping table
	// ---------------------------------------------------------------------------

	function collectAssetMap( tbody ) {
		var map = {};
		tbody.querySelectorAll( 'tr' ).forEach( function ( row ) {
			var link = row.querySelector( '.wmac-mgmt__asset-url' );
			map[ row.dataset.path ] = ( link && link.style.display !== 'none' ) ? link.getAttribute( 'href' ) : '';
		} );
		return map;
	}

	function saveAssetMap( wrapper ) {
		var table    = wrapper.querySelector( '#wmac-assetmap-table' );
		var map      = collectAssetMap( table.querySelector( 'tbody' ) );
		var statusEl = wrapper.querySelector( '.wmac-save-status' );
		saveMeta( wrapper.dataset.wmacMapMeta, JSON.stringify( map ), statusEl );
	}

	function setAssetRowUrl( row, url ) {
		var link      = row.querySelector( '.wmac-mgmt__asset-url' );
		var unmapped  = row.querySelector( '.wmac-mgmt__unmapped' );
		var selectBtn = row.querySelector( '.wmac-asset-select' );
		var removeBtn = row.querySelector( '.wmac-asset-remove' );

		if ( url ) {
			link.setAttribute( 'href', url );
			link.setAttribute( 'title', url );
			link.textContent = url;
			link.style.display = '';
			unmapped.style.display = 'none';
			selectBtn.textContent = wp.i18n.__( 'Change', 'webmultipliers-wp-artifact-canvas' );
			removeBtn.style.display = '';
		} else {
			link.removeAttribute( 'href' );
			link.textContent = '';
			link.style.display = 'none';
			unmapped.style.display = '';
			selectBtn.textContent = wp.i18n.__( 'Select', 'webmultipliers-wp-artifact-canvas' );
			removeBtn.style.display = 'none';
		}
	}

	function openMediaFrame( onSelect ) {
		if ( ! wp.media ) return;
		var frame = wp.media( {
			title:    wp.i18n.__( 'Select or Upload a File', 'webmultipliers-wp-artifact-canvas' ),
			button:   { text: wp.i18n.__( 'Use this file', 'webmultipliers-wp-artifact-canvas' ) },
			multiple: false,
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			onSelect( attachment.url );
		} );
		frame.open();
	}

	function wireAssetRow( row, wrapper ) {
		var selectBtn = row.querySelector( '.wmac-asset-select' );
		var removeBtn = row.querySelector( '.wmac-asset-remove' );

		selectBtn.addEventListener( 'click', function () {
			openMediaFrame( function ( url ) {
				setAssetRowUrl( row, url );
				saveAssetMap( wrapper );
			} );
		} );

		removeBtn.addEventListener( 'click', function () {
			var table = wrapper.querySelector( '#wmac-assetmap-table' );
			if ( row.classList.contains( 'wmac-mgmt__row--orphan' ) ) {
				setAssetRowUrl( row, '' );
			} else {
				row.remove();
			}
			refreshEmptyState( wrapper, table );
			saveAssetMap( wrapper );
		} );
	}

	function initAssetMapping() {
		var wrapper = document.querySelector( '[data-wmac-map-meta="_wmac_asset_map"]' );
		if ( ! wrapper ) return;

		var table = wrapper.querySelector( '#wmac-assetmap-table' );
		table.querySelectorAll( 'tbody tr' ).forEach( function ( row ) { wireAssetRow( row, wrapper ); } );

		var input  = wrapper.querySelector( '#wmac-new-path' );
		var button = wrapper.querySelector( '#wmac-add-path' );
		var template = document.getElementById( 'wmac-asset-row-template' );

		input.addEventListener( 'input', function () {
			button.disabled = input.value.trim() === '';
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); if ( ! button.disabled ) button.click(); }
		} );

		button.addEventListener( 'click', function () {
			var path = input.value.trim();
			if ( ! path ) return;
			var tbody = table.querySelector( 'tbody' );
			if ( tbody.querySelector( 'tr[data-path="' + CSS.escape( path ) + '"]' ) ) return;

			var rowHtml = template.innerHTML.split( '__PATH__' ).join( path.replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
			} ) );
			var temp = document.createElement( 'tbody' );
			temp.innerHTML = rowHtml;
			var newRow = temp.querySelector( 'tr' );
			tbody.appendChild( newRow );

			wireAssetRow( newRow, wrapper );
			refreshEmptyState( wrapper, table );
			saveAssetMap( wrapper ); // persist the row immediately, even if the picker below is cancelled

			input.value = '';
			button.disabled = true;

			openMediaFrame( function ( url ) {
				setAssetRowUrl( newRow, url );
				saveAssetMap( wrapper );
			} );
		} );
	}

	// ---------------------------------------------------------------------------

	wp.domReady( function () {
		wireSimpleFields();
		initMergeTags();
		initAssetMapping();
	} );

} )();
