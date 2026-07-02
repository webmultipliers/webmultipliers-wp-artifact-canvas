( function ( blocks, blockEditor, components, element, i18n ) {
	var registerBlockType  = blocks.registerBlockType;
	var useBlockProps      = blockEditor.useBlockProps;
	var BlockControls      = blockEditor.BlockControls;
	var PlainText          = blockEditor.PlainText;
	var Button             = components.Button;
	var Modal              = components.Modal;
	var Spinner            = components.Spinner;
	var ToolbarGroup       = components.ToolbarGroup;
	var ToolbarButton      = components.ToolbarButton;
	var createElement      = element.createElement;
	var Fragment           = element.Fragment;
	var useState           = element.useState;
	var useRef             = element.useRef;
	var useEffect          = element.useEffect;
	var __                 = i18n.__;

	var META_LABELS = {
		_wmac_alias:            __( 'Custom URL Alias', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_description:      __( 'Description', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_prompt:           __( 'Generation Prompt', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_noindex:          __( 'Noindex', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_seo_enabled:      __( 'Inject SEO Meta Tags', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_csp:              __( 'Content Security Policy', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_owner_id:         __( 'Owner / Project Manager', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_external_styles:  __( 'External Stylesheets', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_external_scripts: __( 'External Scripts', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_head_html:        __( 'Head HTML Injection', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_body_html:        __( 'Body HTML Injection', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_expires_at:       __( 'Expire At (UTC)', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_max_views:        __( 'Max Public Views', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_view_count:       __( 'Public View Count', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_tracking_snippet: __( 'Tracking Snippet', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_view_webhook_url: __( 'View Webhook URL', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_tag_map:          __( 'Merge Tag Map', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_asset_map:        __( 'Asset Map', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_file_token:       __( 'Stored File Token', 'webmultipliers-wp-artifact-canvas' ),
		_wmac_format:           __( 'Render Format', 'webmultipliers-wp-artifact-canvas' ),
	};

	var META_ORDER = [
		'_wmac_alias',
		'_wmac_description',
		'_wmac_prompt',
		'_wmac_noindex',
		'_wmac_seo_enabled',
		'_wmac_csp',
		'_wmac_owner_id',
		'_wmac_external_styles',
		'_wmac_external_scripts',
		'_wmac_head_html',
		'_wmac_body_html',
		'_wmac_expires_at',
		'_wmac_max_views',
		'_wmac_view_count',
		'_wmac_tracking_snippet',
		'_wmac_view_webhook_url',
		'_wmac_tag_map',
		'_wmac_asset_map',
		'_wmac_file_token',
		'_wmac_format',
	];

	function isBlankMetaValue( value ) {
		return value === null || value === undefined || value === '';
	}

	function isObjectValue( value ) {
		return !! value && Object.prototype.toString.call( value ) === '[object Object]';
	}

	function parseJsonMaybe( value ) {
		if ( typeof value !== 'string' || value.trim() === '' ) {
			return null;
		}

		try {
			return JSON.parse( value );
		} catch ( e ) {
			return null;
		}
	}

	function hasConfiguredValue( value ) {
		if ( value === null || value === undefined ) {
			return false;
		}

		if ( typeof value === 'string' ) {
			if ( value.trim() === '' ) {
				return false;
			}

			var parsed = parseJsonMaybe( value );
			if ( Array.isArray( parsed ) ) {
				return parsed.length > 0;
			}
			if ( isObjectValue( parsed ) ) {
				return Object.keys( parsed ).length > 0;
			}

			return true;
		}

		if ( Array.isArray( value ) ) {
			return value.length > 0;
		}

		if ( isObjectValue( value ) ) {
			return Object.keys( value ).length > 0;
		}

		if ( typeof value === 'number' ) {
			return value !== 0;
		}

		if ( typeof value === 'boolean' ) {
			return value;
		}

		return true;
	}

	function detectMergeTagsFromHtml( html ) {
		if ( ! html ) {
			return [];
		}

		var seen = {};
		var tags = [];
		var match;
		var tagRegex = /\{\{([a-zA-Z0-9_]+)\}\}/g;

		while ( ( match = tagRegex.exec( html ) ) !== null ) {
			if ( ! seen[ match[ 1 ] ] ) {
				seen[ match[ 1 ] ] = true;
				tags.push( match[ 1 ] );
			}
		}

		return tags;
	}

	function detectAssetPathsFromHtml( html ) {
		if ( ! html ) {
			return [];
		}

		var results    = {};
		var candidates = [];
		var match;

		var quotedAttrRegex = /(?:src|href|poster)\s*=\s*["']([^"']+)["']/ig;
		while ( ( match = quotedAttrRegex.exec( html ) ) !== null ) {
			candidates.push( match[ 1 ] );
		}

		var unquotedAttrRegex = /(?:src|href|poster)\s*=\s*([^\s"'=<>`]+)/ig;
		while ( ( match = unquotedAttrRegex.exec( html ) ) !== null ) {
			candidates.push( match[ 1 ] );
		}

		var srcsetRegex = /srcset\s*=\s*["']([^"']+)["']/ig;
		while ( ( match = srcsetRegex.exec( html ) ) !== null ) {
			match[ 1 ].split( ',' ).forEach( function ( entry ) {
				var parts = entry.trim().split( /\s+/ );
				if ( parts[ 0 ] ) {
					candidates.push( parts[ 0 ] );
				}
			} );
		}

		var cssUrlRegex = /url\(\s*(["']?)([^"')]+)\1\s*\)/ig;
		while ( ( match = cssUrlRegex.exec( html ) ) !== null ) {
			candidates.push( match[ 2 ] );
		}

		candidates.forEach( function ( raw ) {
			var path = String( raw || '' ).trim();

			if ( ! path ) {
				return;
			}

			if ( /^(?:https?:\/\/|\/\/|data:|#|mailto:|tel:|javascript:|blob:)/i.test( path ) ) {
				return;
			}

			if ( path.charAt( 0 ) === '/' || path.indexOf( '{{' ) !== -1 ) {
				return;
			}

			results[ path ] = true;
		} );

		return Object.keys( results );
	}

	function renderMetaValue( key, value ) {
		if ( isBlankMetaValue( value ) ) {
			return createElement( 'span', { className: 'wmac-config-value wmac-config-value--unset' }, __( 'Not set', 'webmultipliers-wp-artifact-canvas' ) );
		}

		if ( key === '_wmac_noindex' ) {
			if ( value === '1' || value === 1 ) {
				return createElement( 'span', { className: 'wmac-config-value' }, __( 'Enabled (discourage indexing)', 'webmultipliers-wp-artifact-canvas' ) );
			}
			if ( value === '0' || value === 0 ) {
				return createElement( 'span', { className: 'wmac-config-value' }, __( 'Disabled (allow indexing)', 'webmultipliers-wp-artifact-canvas' ) );
			}
		}

		if ( key === '_wmac_seo_enabled' ) {
			if ( value === '1' || value === 1 ) {
				return createElement( 'span', { className: 'wmac-config-value' }, __( 'Enabled', 'webmultipliers-wp-artifact-canvas' ) );
			}
			if ( value === '0' || value === 0 ) {
				return createElement( 'span', { className: 'wmac-config-value' }, __( 'Disabled', 'webmultipliers-wp-artifact-canvas' ) );
			}
		}

		var parsed = parseJsonMaybe( value );
		if ( isObjectValue( parsed ) || Array.isArray( parsed ) ) {
			return createElement( 'pre', { className: 'wmac-config-pre' }, JSON.stringify( parsed, null, 2 ) );
		}

		if ( isObjectValue( value ) || Array.isArray( value ) ) {
			return createElement( 'pre', { className: 'wmac-config-pre' }, JSON.stringify( value, null, 2 ) );
		}

		var text = String( value );
		if ( text.indexOf( '\n' ) !== -1 || text.length > 160 ) {
			return createElement(
				'details',
				{ className: 'wmac-config-value wmac-config-value--details' },
				createElement( 'summary', null, text.slice( 0, 120 ) + ( text.length > 120 ? '…' : '' ) ),
				createElement( 'pre', { className: 'wmac-config-pre' }, text )
			);
		}

		return createElement( 'span', { className: 'wmac-config-value' }, text );
	}

	function getMetaRowId( metaKey ) {
		return 'wmac-config-row-' + String( metaKey || '' ).replace( /[^a-zA-Z0-9_-]/g, '-' );
	}

	/**
	 * CodeMirror-backed editor. Rendered as a plain div container; CodeMirror
	 * builds its own DOM inside it, outside React's reconciliation tree.
	 * Falls back gracefully if wp.codeEditor is not loaded.
	 *
	 * The `key` prop on this component is used by the parent to force a full
	 * remount (and re-init of CodeMirror) after a file upload replaces html.
	 */
	function CodeEditor( props ) {
		var value        = props.value;
		var onChange     = props.onChange;
		var dark         = props.dark;
		var containerRef = useRef( null );
		var editorRef    = useRef( null );

		useEffect( function () {
			if ( ! containerRef.current || ! wp.codeEditor ) {
				return;
			}

			// The block canvas may live inside the editor iframe (API v3), so
			// the textarea must belong to the container's own document — and
			// React must never touch it.
			var ownerDocument = containerRef.current.ownerDocument || document;
			var textarea      = ownerDocument.createElement( 'textarea' );
			textarea.setAttribute(
				'aria-label',
				__( 'Artifact HTML', 'webmultipliers-wp-artifact-canvas' )
			);
			containerRef.current.appendChild( textarea );

			var settings = wp.codeEditor.defaultSettings
				? JSON.parse( JSON.stringify( wp.codeEditor.defaultSettings ) )
				: {};

			settings.codemirror = Object.assign( {}, settings.codemirror || {}, {
				mode:          'htmlmixed',
				lineNumbers:   true,
				lineWrapping:  true,
				autoCloseTags: true,
			} );

			var editor = wp.codeEditor.initialize( textarea, settings );
			editorRef.current = editor;
			editor.codemirror.setValue( value || '' );

			// CodeMirror measures itself on init, but the block editor lays the
			// canvas out asynchronously (and, when iframed, styles load late) —
			// measuring at zero size leaves the pane blank until something else
			// forces a refresh. Re-refresh on paint, and keep refreshing on any
			// container resize until the geometry is real.
			if ( typeof window !== 'undefined' && window.requestAnimationFrame ) {
				window.requestAnimationFrame( function () {
					window.requestAnimationFrame( function () {
						if ( editorRef.current ) {
							editorRef.current.codemirror.refresh();
						}
					} );
				} );
			}

			var resizeObserver = null;
			if ( typeof window !== 'undefined' && window.ResizeObserver ) {
				resizeObserver = new window.ResizeObserver( function () {
					if ( editorRef.current ) {
						editorRef.current.codemirror.refresh();
					}
				} );
				resizeObserver.observe( containerRef.current );
			} else {
				// No ResizeObserver: one late refresh after layout settles.
				setTimeout( function () {
					if ( editorRef.current ) {
						editorRef.current.codemirror.refresh();
					}
				}, 500 );
			}

			editor.codemirror.on( 'change', function ( cm ) {
				onChange( cm.getValue() );
			} );

			return function () {
				if ( resizeObserver ) {
					resizeObserver.disconnect();
				}
				if ( editorRef.current ) {
					try {
						editorRef.current.codemirror.toTextArea();
					} catch ( e ) {}
					editorRef.current = null;
				}
			};
		}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

		useEffect( function () {
			if ( ! editorRef.current || ! editorRef.current.codemirror ) {
				return;
			}

			var cm = editorRef.current.codemirror;
			var nextValue = value || '';

			// Keep CodeMirror synced when block attributes update after mount.
			if ( cm.getValue() !== nextValue ) {
				var cursor = cm.getCursor();
				cm.setValue( nextValue );
				cm.setCursor( cursor );
			}

			cm.refresh();
		}, [ value, dark ] );

		return createElement( 'div', {
			ref:       containerRef,
			className: 'wmac-code wmac-code--codemirror' + ( dark ? ' wmac-dark' : '' ),
		} );
	}

	/**
	 * Ensures the post has been saved (has a real post ID) before calling back.
	 * If the post is new (auto-draft), triggers a save and waits up to 15 s for
	 * the post to become persisted. Times out with an error notice.
	 */
	function saveIfNeededThen( callback ) {
		function isPostPersisted( selector ) {
			var postId = selector.getCurrentPostId && selector.getCurrentPostId();

			if ( ! postId ) {
				return false;
			}

			if ( selector.isEditedPostNew ) {
				return ! selector.isEditedPostNew();
			}

			var post = selector.getCurrentPost ? selector.getCurrentPost() : null;
			return !! ( post && post.status && post.status !== 'auto-draft' );
		}

		var editor = wp.data.select( 'core/editor' );
		if ( isPostPersisted( editor ) ) {
			callback( editor.getCurrentPostId() );
			return;
		}

		var timedOut    = false;
		var unsubscribe;

		var timeout = setTimeout( function () {
			timedOut = true;
			unsubscribe();
			wp.data.dispatch( 'core/notices' ).createErrorNotice(
				__( 'Could not save draft. Please save manually and try again.', 'webmultipliers-wp-artifact-canvas' ),
				{ id: 'wmac-save-error', isDismissible: true }
			);
		}, 15000 );

		unsubscribe = wp.data.subscribe( function () {
			if ( timedOut ) return;
			var s = wp.data.select( 'core/editor' );
			if ( isPostPersisted( s ) ) {
				clearTimeout( timeout );
				unsubscribe();
				callback( s.getCurrentPostId() );
			}
		} );

		wp.data.dispatch( 'core/editor' ).savePost();
	}

	function Edit( props ) {
		var html          = props.attributes.html;
		var fileStored    = props.attributes.fileStored;
		var setAttributes = props.setAttributes;
		var blockProps    = useBlockProps();

		var _previewState  = useState( false );
		var previewing     = _previewState[ 0 ];
		var setPreviewing  = _previewState[ 1 ];

		// Server-processed preview HTML (merge tags, code injection, asset
		// mapping applied). Null = fall back to the raw html attribute.
		var _previewHtmlState = useState( null );
		var previewHtml       = _previewHtmlState[ 0 ];
		var setPreviewHtml    = _previewHtmlState[ 1 ];

		var _previewLoadingState = useState( false );
		var previewLoading       = _previewLoadingState[ 0 ];
		var setPreviewLoading    = _previewLoadingState[ 1 ];

		// Incrementing this key forces CodeEditor to fully remount after a client-side upload.
		var _keyState    = useState( 0 );
		var editorKey    = _keyState[ 0 ];
		var setEditorKey = _keyState[ 1 ];

		var _attachingState = useState( false );
		var attaching       = _attachingState[ 0 ];
		var setAttaching    = _attachingState[ 1 ];

		// Initialised from OS preference; togglable by the user via the toolbar button.
		var _darkState  = useState(
			typeof window !== 'undefined' &&
			!! ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches )
		);
		var darkMode    = _darkState[ 0 ];
		var setDarkMode = _darkState[ 1 ];

		var _reportOpenState  = useState( false );
		var reportOpen        = _reportOpenState[ 0 ];
		var setReportOpen     = _reportOpenState[ 1 ];

		var _reportLoadingState = useState( false );
		var reportLoading       = _reportLoadingState[ 0 ];
		var setReportLoading    = _reportLoadingState[ 1 ];

		var _reportErrorState = useState( '' );
		var reportError       = _reportErrorState[ 0 ];
		var setReportError    = _reportErrorState[ 1 ];

		var _reportState  = useState( null );
		var reportData    = _reportState[ 0 ];
		var setReportData = _reportState[ 1 ];

		var _configuredOnlyState  = useState( false );
		var configuredOnly        = _configuredOnlyState[ 0 ];
		var setConfiguredOnly     = _configuredOnlyState[ 1 ];

		var _copyingState = useState( false );
		var copying       = _copyingState[ 0 ];
		var setCopying    = _copyingState[ 1 ];

		var _copyingSignalsState = useState( false );
		var copyingSignals       = _copyingSignalsState[ 0 ];
		var setCopyingSignals    = _copyingSignalsState[ 1 ];

		// Ref for the hidden <input type="file"> used by "Upload HTML" (client-side read).
		var fileInputRef   = useRef( null );
		// Ref for the hidden <input type="file"> used by "Attach File" / "Replace File" (server upload).
		var attachInputRef = useRef( null );

		var hasCodeEditor = typeof wp !== 'undefined' && !! wp.codeEditor;

		// Reactive: re-renders whenever the editor store changes (e.g. after first save).
		var previewLink = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostPreviewLink();
		} );

		// Client-side read: file contents go into the html block attribute.
		function handleFileSelect( event ) {
			var file = event.target.files && event.target.files[ 0 ];
			if ( ! file ) return;

			var reader = new FileReader();

			reader.onload = function ( e ) {
				setAttributes( { html: ( e.target.result ) || '', fileStored: false } );
				setEditorKey( function ( k ) { return k + 1; } );
				if ( fileInputRef.current ) {
					fileInputRef.current.value = '';
				}
			};

			reader.onerror = function () {
				if ( fileInputRef.current ) {
					fileInputRef.current.value = '';
				}
			};

			reader.readAsText( file );
		}

		// Server-side upload: file is stored on disk; block attribute tracks fileStored flag.
		function handleAttachFileSelect( event ) {
			var file = event.target.files && event.target.files[ 0 ];
			if ( ! file ) return;

			// Capture before clearing the input — File objects remain valid after input.value = ''.
			var capturedFile = file;
			if ( attachInputRef.current ) {
				attachInputRef.current.value = '';
			}

			setAttaching( true );

			saveIfNeededThen( function ( postId ) {
				var formData = new FormData();
				formData.append( 'file', capturedFile );

				wp.apiFetch( {
					path:   '/wmac/v1/artifacts/' + postId + '/file',
					method: 'POST',
					body:   formData,
				} ).then( function () {
					setAttributes( { fileStored: true, html: '' } );
					setAttaching( false );
				} ).catch( function ( err ) {
					setAttaching( false );
					var message = ( err && err.message )
						? err.message
						: __( 'File attachment failed. Please try again.', 'webmultipliers-wp-artifact-canvas' );
					wp.data.dispatch( 'core/notices' ).createErrorNotice(
						message,
						{ id: 'wmac-attach-error', isDismissible: true }
					);
				} );
			} );
		}

		/**
		 * Fetches the server-processed preview — the same wmac_rendered_html
		 * pipeline the front end runs (asset mapping, external styles/scripts,
		 * head/body injection, merge tags). Falls back to the raw html
		 * attribute for unsaved posts or on request failure.
		 */
		function loadProcessedPreview() {
			var postId = wp.data.select( 'core/editor' ).getCurrentPostId();

			if ( ! postId ) {
				setPreviewHtml( null );
				return;
			}

			setPreviewLoading( true );

			wp.apiFetch( {
				path:   '/wmac/v1/artifacts/' + postId + '/preview',
				method: 'POST',
				data:   { html: html || '' },
			} ).then( function ( res ) {
				setPreviewHtml( res && typeof res.html === 'string' ? res.html : null );
				setPreviewLoading( false );
			} ).catch( function () {
				setPreviewHtml( null );
				setPreviewLoading( false );
				wp.data.dispatch( 'core/notices' ).createWarningNotice(
					__( 'Showing the raw HTML — the processed preview could not be loaded.', 'webmultipliers-wp-artifact-canvas' ),
					{ id: 'wmac-preview-fallback', isDismissible: true }
				);
			} );
		}

		function handleDownload() {
			var postId    = wp.data.select( 'core/editor' ).getCurrentPostId();
			var filename  = 'artifact-' + ( postId || 'draft' ) + '.html';

			function triggerBlobDownload( content ) {
				var blob = new Blob( [ content ], { type: 'text/html' } );
				var url  = URL.createObjectURL( blob );
				var a    = document.createElement( 'a' );
				a.href     = url;
				a.download = filename;
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
			}

			if ( fileStored && postId ) {
				wp.apiFetch( {
					path:  '/wmac/v1/artifacts/' + postId + '/file',
					method: 'GET',
				} ).then( function ( data ) {
					triggerBlobDownload( data.html || '' );
				} ).catch( function () {
					wp.data.dispatch( 'core/notices' ).createErrorNotice(
						__( 'Could not download the file. Please try again.', 'webmultipliers-wp-artifact-canvas' ),
						{ id: 'wmac-download-error', isDismissible: true }
					);
				} );
			} else {
				triggerBlobDownload( html );
			}
		}

		function handleRemoveFile() {
			var postId = wp.data.select( 'core/editor' ).getCurrentPostId();
			if ( ! postId ) {
				setAttributes( { fileStored: false } );
				return;
			}
			wp.apiFetch( {
				path:   '/wmac/v1/artifacts/' + postId + '/file',
				method: 'DELETE',
			} ).then( function () {
				setAttributes( { fileStored: false } );
			} ).catch( function ( err ) {
				var message = ( err && err.message )
					? err.message
					: __( 'Could not remove the file. Please try again.', 'webmultipliers-wp-artifact-canvas' );
				wp.data.dispatch( 'core/notices' ).createErrorNotice(
					message,
					{ id: 'wmac-remove-error', isDismissible: true }
				);
			} );
		}

		function getEditorTitle() {
			var title = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' );
			if ( typeof title === 'string' ) {
				return title;
			}
			if ( title && typeof title.raw === 'string' ) {
				return title.raw;
			}
			if ( title && typeof title.rendered === 'string' ) {
				return title.rendered;
			}
			return '';
		}

		function buildReportData( restPost ) {
			var editorStore = wp.data.select( 'core/editor' );
			var editedMeta  = editorStore.getEditedPostAttribute( 'meta' ) || {};
			var restMeta    = restPost && restPost.meta ? restPost.meta : {};
			var mergedMeta  = Object.assign( {}, restMeta, editedMeta );

			return {
				collectedAt: new Date().toISOString(),
				post: {
					id:        editorStore.getCurrentPostId() || ( restPost && restPost.id ) || 0,
					title:     getEditorTitle() || ( restPost && restPost.title && restPost.title.rendered ) || '',
					status:    editorStore.getEditedPostAttribute( 'status' ) || ( restPost && restPost.status ) || '',
					slug:      editorStore.getEditedPostAttribute( 'slug' ) || ( restPost && restPost.slug ) || '',
					link:      ( restPost && restPost.link ) || '',
					preview:   previewLink || '',
					modified:  ( restPost && restPost.modified ) || '',
					author:    editorStore.getEditedPostAttribute( 'author' ) || ( restPost && restPost.author ) || '',
				},
				block: {
					fileStored:    !! fileStored,
					htmlLength:    ( html || '' ).length,
					htmlTagCount:  detectMergeTagsFromHtml( html || '' ),
					assetPathList: detectAssetPathsFromHtml( html || '' ),
				},
				meta: mergedMeta,
			};
		}

		function refreshReport() {
			var postId = wp.data.select( 'core/editor' ).getCurrentPostId();

			setReportLoading( true );
			setReportError( '' );
			setReportData( null );

			if ( ! postId ) {
				setReportData( buildReportData( null ) );
				setReportLoading( false );
				return;
			}

			wp.apiFetch( {
				path: '/wp/v2/wm_artifact/' + postId + '?context=edit',
			} ).then( function ( restPost ) {
				setReportData( buildReportData( restPost ) );
				setReportLoading( false );
			} ).catch( function ( err ) {
				setReportData( buildReportData( null ) );
				setReportLoading( false );
				setReportError(
					( err && err.message )
						? err.message
						: __( 'Could not refresh configuration from REST API.', 'webmultipliers-wp-artifact-canvas' )
				);
			} );
		}

		function openReport() {
			setReportOpen( true );
			refreshReport();
		}

		function copyTextToClipboard( payload, onSuccessNotice, onErrorNotice, setBusy ) {
			setBusy( true );

			if ( typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( payload ).then( function () {
					setBusy( false );
					wp.data.dispatch( 'core/notices' ).createSuccessNotice(
						onSuccessNotice,
						{ isDismissible: true }
					);
				} ).catch( function () {
					setBusy( false );
					wp.data.dispatch( 'core/notices' ).createErrorNotice(
						onErrorNotice,
						{ isDismissible: true }
					);
				} );
				return;
			}

			setBusy( false );
			wp.data.dispatch( 'core/notices' ).createErrorNotice(
				__( 'Clipboard is not available in this browser context.', 'webmultipliers-wp-artifact-canvas' ),
				{ id: 'wmac-config-copy-unavailable', isDismissible: true }
			);
		}

		function handleCopySnapshot() {
			if ( ! reportData || copying ) {
				return;
			}

			var payload = JSON.stringify( reportData, null, 2 );
			copyTextToClipboard(
				payload,
				__( 'Configuration snapshot copied to clipboard.', 'webmultipliers-wp-artifact-canvas' ),
				__( 'Could not copy snapshot to clipboard.', 'webmultipliers-wp-artifact-canvas' ),
				setCopying
			);
		}

		function renderMetaRows() {
			if ( ! reportData || ! reportData.meta ) {
				return null;
			}

			var rows      = [];
			var seen      = {};
			var meta      = reportData.meta;
			var index;
			var key;

			for ( index = 0; index < META_ORDER.length; index++ ) {
				key = META_ORDER[ index ];
				seen[ key ] = true;

				if ( configuredOnly && ! hasConfiguredValue( meta[ key ] ) ) {
					continue;
				}

				rows.push(
					createElement(
						'tr',
						{ key: key, id: getMetaRowId( key ) },
						createElement( 'td', { className: 'wmac-config-key' }, META_LABELS[ key ] || key ),
						createElement( 'td', { className: 'wmac-config-meta-key' }, key ),
						createElement( 'td', { className: 'wmac-config-cell' }, renderMetaValue( key, meta[ key ] ) )
					)
				);
			}

			Object.keys( meta ).sort().forEach( function ( extraKey ) {
				if ( seen[ extraKey ] ) {
					return;
				}

				if ( configuredOnly && ! hasConfiguredValue( meta[ extraKey ] ) ) {
					return;
				}

				rows.push(
					createElement(
						'tr',
						{ key: extraKey, id: getMetaRowId( extraKey ) },
						createElement( 'td', { className: 'wmac-config-key' }, META_LABELS[ extraKey ] || __( 'Additional Meta', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement( 'td', { className: 'wmac-config-meta-key' }, extraKey ),
						createElement( 'td', { className: 'wmac-config-cell' }, renderMetaValue( extraKey, meta[ extraKey ] ) )
					)
				);
			} );

			return rows;
		}

		function jumpToMetaSetting( metaKey ) {
			if ( ! metaKey ) {
				return;
			}

			if ( configuredOnly ) {
				setConfiguredOnly( false );
			}

			var rowId = getMetaRowId( metaKey );
			setTimeout( function () {
				var row = document.getElementById( rowId );
				if ( ! row ) {
					return;
				}

				row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				row.classList.add( 'wmac-config-row--focus' );

				setTimeout( function () {
					row.classList.remove( 'wmac-config-row--focus' );
				}, 1600 );
			}, configuredOnly ? 80 : 0 );
		}

		function buildSignalItems() {
			if ( ! reportData || ! reportData.meta ) {
				return [];
			}

			var items = [];
			var meta  = reportData.meta;

			if ( hasConfiguredValue( meta._wmac_external_scripts ) ) {
				items.push( {
					level: 'high',
					title: __( 'External scripts configured', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'Script URLs are injected before </body>; verify source trust and execution intent.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: '_wmac_external_scripts',
				} );
			}

			if ( hasConfiguredValue( meta._wmac_head_html ) || hasConfiguredValue( meta._wmac_body_html ) ) {
				items.push( {
					level: 'high',
					title: __( 'Raw HTML injection enabled', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'Custom head/body markup is configured and can run active content.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: hasConfiguredValue( meta._wmac_head_html ) ? '_wmac_head_html' : '_wmac_body_html',
				} );
			}

			if ( hasConfiguredValue( meta._wmac_tracking_snippet ) ) {
				items.push( {
					level: 'medium',
					title: __( 'Tracking snippet present', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'Analytics code is injected before </head>; validate consent/compliance requirements.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: '_wmac_tracking_snippet',
				} );
			}

			if ( hasConfiguredValue( meta._wmac_view_webhook_url ) ) {
				items.push( {
					level: 'medium',
					title: __( 'View webhook enabled', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'Public views trigger outbound POST requests to the configured endpoint.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: '_wmac_view_webhook_url',
				} );
			}

			if ( ! hasConfiguredValue( meta._wmac_csp ) ) {
				items.push( {
					level: 'medium',
					title: __( 'No per-artifact CSP override', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'This artifact inherits global policy; verify that baseline CSP is sufficiently strict.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: '_wmac_csp',
				} );
			}

			if ( meta._wmac_noindex === '0' || meta._wmac_noindex === 0 ) {
				items.push( {
					level: 'low',
					title: __( 'Indexing explicitly allowed', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'Noindex override is disabled; search engines may index this artifact.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: '_wmac_noindex',
				} );
			}

			if ( meta._wmac_seo_enabled === '0' || meta._wmac_seo_enabled === 0 ) {
				items.push( {
					level: 'low',
					title: __( 'SEO meta injection disabled', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'Open Graph and Twitter metadata injection is turned off for this artifact.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: '_wmac_seo_enabled',
				} );
			}

			if ( reportData.block.fileStored && ! hasConfiguredValue( meta._wmac_file_token ) ) {
				items.push( {
					level: 'low',
					title: __( 'Stored file mode active without token metadata', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'The block is in stored-file mode but file token metadata is empty.', 'webmultipliers-wp-artifact-canvas' ),
					metaKey: '_wmac_file_token',
				} );
			}

			if ( reportData.block.htmlLength === 0 && ! reportData.block.fileStored ) {
				items.push( {
					level: 'low',
					title: __( 'Artifact HTML is empty', 'webmultipliers-wp-artifact-canvas' ),
					detail: __( 'Neither inline HTML nor stored-file content is currently available.', 'webmultipliers-wp-artifact-canvas' ),
				} );
			}

			return items;
		}

		function renderSignalLevel( level ) {
			if ( level === 'high' ) {
				return __( 'High', 'webmultipliers-wp-artifact-canvas' );
			}
			if ( level === 'medium' ) {
				return __( 'Medium', 'webmultipliers-wp-artifact-canvas' );
			}
			return __( 'Low', 'webmultipliers-wp-artifact-canvas' );
		}

		function handleCopySignals() {
			if ( ! signalItems.length || copyingSignals ) {
				return;
			}

			var payload = JSON.stringify( {
				collectedAt: reportData && reportData.collectedAt ? reportData.collectedAt : new Date().toISOString(),
				postId: reportData && reportData.post ? reportData.post.id : 0,
				signals: signalItems,
			}, null, 2 );

			copyTextToClipboard(
				payload,
				__( 'Configuration signals copied to clipboard.', 'webmultipliers-wp-artifact-canvas' ),
				__( 'Could not copy signals to clipboard.', 'webmultipliers-wp-artifact-canvas' ),
				setCopyingSignals
			);
		}

		// --- Body ---

		var signalItems = buildSignalItems();

		var body;

		if ( fileStored ) {
			body = createElement(
				'div',
				{ className: 'wmac-file-attached' },
				createElement(
					'p',
					{ className: 'wmac-file-attached__label' },
					__( 'HTML file stored on server', 'webmultipliers-wp-artifact-canvas' )
				),
				createElement(
					'div',
					{ className: 'wmac-file-attached__actions' },
					createElement(
						Button,
						{
							variant:  'secondary',
							size:     'small',
							disabled: attaching,
							onClick:  function () {
								if ( attachInputRef.current ) {
									attachInputRef.current.click();
								}
							},
						},
						attaching
							? __( 'Uploading…', 'webmultipliers-wp-artifact-canvas' )
							: __( 'Replace File', 'webmultipliers-wp-artifact-canvas' )
					),
					createElement(
						Button,
						{
							variant:       'tertiary',
							size:          'small',
							isDestructive: true,
							disabled:      attaching,
							onClick:       handleRemoveFile,
						},
						__( 'Remove File', 'webmultipliers-wp-artifact-canvas' )
					)
				)
			);
		} else if ( previewing ) {
			body = createElement(
				Fragment,
				null,
				previewLoading && createElement(
					'div',
					{ className: 'wmac-preview-loading' },
					createElement( Spinner, null ),
					createElement( 'span', null, __( 'Rendering processed preview…', 'webmultipliers-wp-artifact-canvas' ) )
				),
				createElement( 'iframe', {
					className: 'wmac-preview',
					sandbox:   'allow-scripts',
					srcDoc:    previewHtml !== null ? previewHtml : html,
					title:     __( 'Artifact Preview', 'webmultipliers-wp-artifact-canvas' ),
				} )
			);
		} else if ( hasCodeEditor ) {
			body = createElement( CodeEditor, {
				key:      editorKey,
				value:    html,
				dark:     darkMode,
				onChange: function ( value ) {
					setAttributes( { html: value } );
				},
			} );
		} else {
			body = createElement( PlainText, {
				className: 'wmac-code',
				value:     html,
				onChange:  function ( value ) {
					setAttributes( { html: value } );
				},
				placeholder: __(
					'Paste your complete HTML document here…',
					'webmultipliers-wp-artifact-canvas'
				),
				'aria-label': __( 'Artifact HTML', 'webmultipliers-wp-artifact-canvas' ),
			} );
		}

		// --- Toolbar ---
		// Both hidden inputs are always mounted so their refs stay stable.
		// Toolbar action buttons swap based on fileStored state.

		return createElement(
			Fragment,
			null,
			createElement(
				BlockControls,
				null,
				createElement(
					ToolbarGroup,
					null,
					createElement( ToolbarButton, {
						icon:      'admin-generic',
						label:     __( 'Inspect Configuration', 'webmultipliers-wp-artifact-canvas' ),
						onClick:   openReport,
						isPressed: reportOpen,
					} )
				)
			),
			reportOpen && createElement(
				Modal,
				{
					title:          __( 'Artifact Configuration Report', 'webmultipliers-wp-artifact-canvas' ),
					onRequestClose: function () { setReportOpen( false ); },
					className:      'wmac-config-modal',
				},
				createElement(
					'div',
					{ className: 'wmac-config-header' },
					createElement( 'p', { className: 'wmac-config-subtitle' }, __( 'Read-only snapshot of the current block, post state, and artifact meta settings.', 'webmultipliers-wp-artifact-canvas' ) ),
					createElement(
						'div',
						{ className: 'wmac-config-header-actions' },
						createElement(
							Button,
							{
								variant:  configuredOnly ? 'primary' : 'secondary',
								size:     'small',
								onClick:  function () {
									setConfiguredOnly( function ( v ) { return ! v; } );
								},
							},
							configuredOnly
								? __( 'Showing Configured Only', 'webmultipliers-wp-artifact-canvas' )
								: __( 'Show Configured Only', 'webmultipliers-wp-artifact-canvas' )
						),
						createElement(
							Button,
							{
								variant:  'secondary',
								size:     'small',
								onClick:  handleCopySignals,
								disabled: ! reportData || ! signalItems.length || copyingSignals,
							},
							copyingSignals
								? __( 'Copying Signals…', 'webmultipliers-wp-artifact-canvas' )
								: __( 'Copy Signals', 'webmultipliers-wp-artifact-canvas' )
						),
						createElement(
							Button,
							{
								variant:  'secondary',
								size:     'small',
								onClick:  handleCopySnapshot,
								disabled: ! reportData || copying,
							},
							copying
								? __( 'Copying…', 'webmultipliers-wp-artifact-canvas' )
								: __( 'Copy JSON', 'webmultipliers-wp-artifact-canvas' )
						),
					createElement(
						Button,
						{
							variant:  'secondary',
							size:     'small',
							onClick:  refreshReport,
							disabled: reportLoading,
						},
						__( 'Refresh', 'webmultipliers-wp-artifact-canvas' )
						)
					)
					),
				reportLoading && createElement(
					'div',
					{ className: 'wmac-config-loading' },
					createElement( Spinner, null ),
					createElement( 'span', null, __( 'Collecting configuration…', 'webmultipliers-wp-artifact-canvas' ) )
				),
				reportError && createElement( 'p', { className: 'wmac-config-error' }, reportError ),
				reportData && createElement(
					'div',
					{ className: 'wmac-config-content' },
					createElement(
						'section',
						{ className: 'wmac-config-section' },
						createElement( 'h3', null, __( 'Configuration Signals', 'webmultipliers-wp-artifact-canvas' ) ),
						signalItems.length === 0 && createElement(
							'p',
							{ className: 'wmac-config-signal-empty' },
							__( 'No notable configuration signals detected from the current snapshot.', 'webmultipliers-wp-artifact-canvas' )
						),
						signalItems.length > 0 && createElement(
							'ul',
							{ className: 'wmac-config-signals' },
							signalItems.map( function ( item, idx ) {
								return createElement(
									'li',
									{ key: item.level + '-' + String( idx ), className: 'wmac-config-signal' },
									createElement( 'span', { className: 'wmac-config-badge wmac-config-badge--' + item.level }, renderSignalLevel( item.level ) ),
									createElement(
										'div',
										{ className: 'wmac-config-signal-copy' },
										createElement( 'strong', null, item.title ),
										createElement( 'p', null, item.detail ),
										item.metaKey && createElement(
											Button,
											{
												variant: 'link',
												size:    'small',
												onClick: function () {
													jumpToMetaSetting( item.metaKey );
												},
												className: 'wmac-config-signal-jump',
											},
											__( 'Jump to setting', 'webmultipliers-wp-artifact-canvas' )
										)
									)
								);
							} )
						)
					),
					createElement(
						'section',
						{ className: 'wmac-config-section' },
						createElement( 'h3', null, __( 'Post Snapshot', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement(
							'dl',
							{ className: 'wmac-config-grid' },
							createElement( 'dt', null, __( 'Post ID', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, String( reportData.post.id || 0 ) ),
							createElement( 'dt', null, __( 'Title', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.post.title || __( '(empty)', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Status', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.post.status || __( '(empty)', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Slug', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.post.slug || __( '(empty)', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Author', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, String( reportData.post.author || '' ) || __( '(empty)', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Public URL', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.post.link || __( '(not available)', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Preview URL', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.post.preview || __( '(not available)', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Modified', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.post.modified || __( '(unknown)', 'webmultipliers-wp-artifact-canvas' ) )
						)
					),
					createElement(
						'section',
						{ className: 'wmac-config-section' },
						createElement( 'h3', null, __( 'Block Snapshot', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement(
							'dl',
							{ className: 'wmac-config-grid' },
							createElement( 'dt', null, __( 'Stored File Mode', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.block.fileStored ? __( 'Enabled', 'webmultipliers-wp-artifact-canvas' ) : __( 'Disabled', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'HTML Size', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, String( reportData.block.htmlLength ) + ' ' + __( 'chars', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Detected Merge Tags', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.block.htmlTagCount.length ? reportData.block.htmlTagCount.join( ', ' ) : __( 'None detected in block HTML', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dt', null, __( 'Detected Relative Asset Paths', 'webmultipliers-wp-artifact-canvas' ) ),
							createElement( 'dd', null, reportData.block.assetPathList.length ? reportData.block.assetPathList.join( ', ' ) : __( 'None detected in block HTML', 'webmultipliers-wp-artifact-canvas' ) )
						)
					),
					createElement(
						'section',
						{ className: 'wmac-config-section' },
						createElement( 'h3', null, __( 'Meta Configuration', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement(
							'table',
							{ className: 'wmac-config-table' },
							createElement(
								'thead',
								null,
								createElement(
									'tr',
									null,
									createElement( 'th', null, __( 'Setting', 'webmultipliers-wp-artifact-canvas' ) ),
									createElement( 'th', null, __( 'Meta Key', 'webmultipliers-wp-artifact-canvas' ) ),
									createElement( 'th', null, __( 'Current Value', 'webmultipliers-wp-artifact-canvas' ) )
								)
							),
							createElement( 'tbody', null, renderMetaRows() )
						)
					),
					createElement(
						'p',
						{ className: 'wmac-config-collected-at' },
						__( 'Snapshot collected at:', 'webmultipliers-wp-artifact-canvas' ) + ' ' + reportData.collectedAt
					)
				)
			),
			createElement(
				'div',
				blockProps,
			createElement(
				'div',
				{ className: 'wmac-toolbar' },
				createElement( 'input', {
					ref:      fileInputRef,
					type:     'file',
					accept:   '.html,.htm',
					style:    { display: 'none' },
					onChange: handleFileSelect,
				} ),
				createElement( 'input', {
					ref:      attachInputRef,
					type:     'file',
					accept:   '.html,.htm',
					style:    { display: 'none' },
					onChange: handleAttachFileSelect,
				} ),
				! fileStored && createElement(
					Button,
					{
						variant: 'tertiary',
						size:    'small',
						onClick: function () {
							if ( fileInputRef.current ) {
								fileInputRef.current.click();
							}
						},
					},
					__( 'Upload HTML', 'webmultipliers-wp-artifact-canvas' )
				),
				! fileStored && createElement(
					Button,
					{
						variant:  'tertiary',
						size:     'small',
						disabled: attaching,
						onClick:  function () {
							if ( attachInputRef.current ) {
								attachInputRef.current.click();
							}
						},
					},
					attaching
						? __( 'Uploading…', 'webmultipliers-wp-artifact-canvas' )
						: __( 'Attach File', 'webmultipliers-wp-artifact-canvas' )
				),
				! fileStored && createElement(
					Button,
					{
						variant: 'tertiary',
						size:    'small',
						onClick: function () {
							if ( previewing ) {
								setPreviewing( false );
								setPreviewHtml( null );
							} else {
								setPreviewing( true );
								loadProcessedPreview();
							}
						},
					},
					previewing
						? __( 'Edit', 'webmultipliers-wp-artifact-canvas' )
						: __( 'Preview', 'webmultipliers-wp-artifact-canvas' )
				),
				! fileStored && ! previewing && hasCodeEditor && createElement(
					Button,
					{
						variant: 'tertiary',
						size:    'small',
						title:   darkMode
							? __( 'Switch to light theme', 'webmultipliers-wp-artifact-canvas' )
							: __( 'Switch to dark theme', 'webmultipliers-wp-artifact-canvas' ),
						onClick: function () {
							setDarkMode( function ( v ) { return ! v; } );
						},
					},
					darkMode ? '☀' : '🌙'
				),
				createElement(
					Button,
					{
						variant:       'tertiary',
						size:          'small',
						onClick:       openReport,
						'aria-pressed': reportOpen,
					},
					__( '⚙ Settings', 'webmultipliers-wp-artifact-canvas' )
				),
				createElement(
					Button,
					{
						variant:  'tertiary',
						size:     'small',
						disabled: ! html && ! fileStored,
						onClick:  handleDownload,
					},
					__( 'Download', 'webmultipliers-wp-artifact-canvas' )
				),
				createElement(
					Button,
					{
						variant:  'tertiary',
						size:     'small',
						href:     previewLink || undefined,
						target:   '_blank',
						rel:      'noreferrer',
						disabled: ! previewLink,
					},
					__( 'Open ↗', 'webmultipliers-wp-artifact-canvas' )
				)
			),
			body
			)   // close createElement( 'div', blockProps, … )
		);      // close createElement( Fragment, … )
	}

	registerBlockType( 'wmac/artifact', {
		apiVersion: 3,
		title: __( 'Artifact Canvas', 'webmultipliers-wp-artifact-canvas' ),
		category: 'text',
		description: __(
			'Stores a complete HTML document and serves it as a passthrough canvas on the front end.',
			'webmultipliers-wp-artifact-canvas'
		),
		attributes: {
			html: {
				type:    'string',
				default: '',
			},
			fileStored: {
				type:    'boolean',
				default: false,
			},
		},
		supports: {
			html:     false,
			reusable: false,
			lock:     false,
		},
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.blockEditor, wp.components, wp.element, wp.i18n );
