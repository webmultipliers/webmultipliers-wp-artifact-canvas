( function ( blocks, blockEditor, components, element, i18n ) {
	var registerBlockType  = blocks.registerBlockType;
	var useBlockProps      = blockEditor.useBlockProps;
	var InspectorControls  = blockEditor.InspectorControls;
	var PlainText          = blockEditor.PlainText;
	var Button             = components.Button;
	var PanelBody          = components.PanelBody;
	var TextareaControl    = components.TextareaControl;
	var ToggleControl      = components.ToggleControl;
	var TextControl        = components.TextControl;
	var createElement      = element.createElement;
	var Fragment           = element.Fragment;
	var useState           = element.useState;
	var useRef             = element.useRef;
	var useEffect          = element.useEffect;
	var __                 = i18n.__;
	var useEntityProp      = wp.coreData && wp.coreData.useEntityProp;

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

			// Dynamically create the textarea so React never touches it.
			var textarea = document.createElement( 'textarea' );
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

			editor.codemirror.on( 'change', function ( cm ) {
				onChange( cm.getValue() );
			} );

			return function () {
				if ( editorRef.current ) {
					try {
						editorRef.current.codemirror.toTextArea();
					} catch ( e ) {}
					editorRef.current = null;
				}
			};
		}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

		return createElement( 'div', {
			ref:       containerRef,
			className: 'wmac-code wmac-code--codemirror' + ( dark ? ' wmac-dark' : '' ),
		} );
	}

	/**
	 * Ensures the post has been saved (has a real post ID) before calling back.
	 * If the post is new (auto-draft), triggers a save and waits up to 15 s for
	 * isCurrentPostNew() to become false. Times out with an error notice.
	 */
	function saveIfNeededThen( callback ) {
		var editor = wp.data.select( 'core/editor' );
		if ( ! editor.isCurrentPostNew() ) {
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
			if ( ! s.isCurrentPostNew() ) {
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

		// Ref for the hidden <input type="file"> used by "Upload HTML" (client-side read).
		var fileInputRef   = useRef( null );
		// Ref for the hidden <input type="file"> used by "Attach File" / "Replace File" (server upload).
		var attachInputRef = useRef( null );

		var hasCodeEditor = typeof wp !== 'undefined' && !! wp.codeEditor;

		// Reactive: re-renders whenever the editor store changes (e.g. after first save).
		var previewLink = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostPreviewLink();
		} );

		// Per-artifact meta (read/write via REST). Falls back gracefully if wp.coreData unavailable.
		var _metaState = useEntityProp
			? useEntityProp( 'postType', 'wm_artifact', 'meta' )
			: [ {}, function () {} ];
		var meta    = _metaState[ 0 ] || {};
		var setMeta = _metaState[ 1 ];

		function updateMeta( key, value ) {
			var updated = Object.assign( {}, meta );
			updated[ key ] = value;
			setMeta( updated );
		}

		var metaPrompt  = meta[ '_wmac_prompt' ]      || '';
		var metaNoindex = meta[ '_wmac_noindex' ]     || '';
		var metaSeo     = meta[ '_wmac_seo_enabled' ] || '';
		var metaCsp     = meta[ '_wmac_csp' ]         || '';

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

		// --- Body ---

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
			body = createElement( 'iframe', {
				className: 'wmac-preview',
				sandbox:   'allow-scripts',
				srcDoc:    html,
				title:     __( 'Artifact Preview', 'webmultipliers-wp-artifact-canvas' ),
			} );
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

		// --- Inspector (sidebar) ---

		var inspector = createElement(
			InspectorControls,
			null,
			createElement(
				PanelBody,
				{
					title:       __( 'Artifact Settings', 'webmultipliers-wp-artifact-canvas' ),
					initialOpen: true,
				},
				createElement( ToggleControl, {
					label:    __( 'Discourage search engines (noindex)', 'webmultipliers-wp-artifact-canvas' ),
					help:     __( 'Sends a noindex header. Default is on; toggle off to allow indexing.', 'webmultipliers-wp-artifact-canvas' ),
					checked:  metaNoindex !== '0',
					onChange: function ( v ) { updateMeta( '_wmac_noindex', v ? '1' : '0' ); },
				} ),
				createElement( ToggleControl, {
					label:    __( 'Inject SEO meta tags', 'webmultipliers-wp-artifact-canvas' ),
					help:     __( 'Adds og: / twitter: tags from the post title, excerpt, and featured image.', 'webmultipliers-wp-artifact-canvas' ),
					checked:  metaSeo === '1',
					onChange: function ( v ) { updateMeta( '_wmac_seo_enabled', v ? '1' : '0' ); },
				} ),
				createElement( TextControl, {
					label:    __( 'Content Security Policy', 'webmultipliers-wp-artifact-canvas' ),
					help:     __( 'Per-artifact CSP header. Overrides the global wmac_csp filter. Leave blank to inherit.', 'webmultipliers-wp-artifact-canvas' ),
					value:    metaCsp,
					onChange: function ( v ) { updateMeta( '_wmac_csp', v ); },
				} ),
				createElement( TextareaControl, {
					label:    __( 'Generation Prompt', 'webmultipliers-wp-artifact-canvas' ),
					help:     __( 'Paste the prompt used to generate this artifact. Private — not published.', 'webmultipliers-wp-artifact-canvas' ),
					value:    metaPrompt,
					onChange: function ( v ) { updateMeta( '_wmac_prompt', v ); },
					rows:     5,
				} )
			)
		);

		// --- Toolbar ---
		// Both hidden inputs are always mounted so their refs stay stable.
		// Toolbar action buttons swap based on fileStored state.

		return createElement(
			Fragment,
			null,
			inspector,
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
							setPreviewing( function ( v ) { return ! v; } );
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
