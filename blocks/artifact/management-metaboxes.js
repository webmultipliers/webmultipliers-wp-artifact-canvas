( function () {
	var element    = wp.element;
	var components = wp.components;
	var data       = wp.data;
	var i18n       = wp.i18n;

	var createElement   = element.createElement;
	var useState        = element.useState;
	var useSelect       = data.useSelect;
	var useDispatch     = data.useDispatch;

	var Button          = components.Button;
	var SelectControl   = components.SelectControl;
	var TextControl     = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var ToggleControl   = components.ToggleControl;
	var __              = i18n.__;

	// ---------------------------------------------------------------------------
	// Tabs
	// ---------------------------------------------------------------------------

	var TABS = [
		{ id: 'settings',   label: __( 'Settings',      'webmultipliers-wp-artifact-canvas' ) },
		{ id: 'governance', label: __( 'Governance',    'webmultipliers-wp-artifact-canvas' ) },
		{ id: 'tracking',   label: __( 'Tracking',      'webmultipliers-wp-artifact-canvas' ) },
		{ id: 'tags',       label: __( 'Merge Tags',    'webmultipliers-wp-artifact-canvas' ) },
		{ id: 'assets',     label: __( 'Asset Mapping', 'webmultipliers-wp-artifact-canvas' ) },
	];

	// ---------------------------------------------------------------------------
	// Helpers (merge tags / asset path detection)
	// ---------------------------------------------------------------------------

	function parseMergeTags( html ) {
		if ( ! html ) return [];
		var found = [], seen = {}, pattern = /\{\{([a-zA-Z0-9_]+)\}\}/g, match;
		while ( ( match = pattern.exec( html ) ) !== null ) {
			if ( ! seen[ match[ 1 ] ] ) { seen[ match[ 1 ] ] = true; found.push( match[ 1 ] ); }
		}
		return found;
	}

	function parseUnresolvedAssets( html ) {
		if ( ! html ) return [];
		var found = [], seen = {}, pattern = /(?:src|href)\s*=\s*["']([^"']+)["']/gi, match;
		while ( ( match = pattern.exec( html ) ) !== null ) {
			var path = match[ 1 ];
			if ( /^(?:https?:\/\/|\/\/|data:|#|mailto:|tel:)/i.test( path ) || path.charAt( 0 ) === '/' ) continue;
			if ( ! seen[ path ] ) { seen[ path ] = true; found.push( path ); }
		}
		return found;
	}

	function mergeUnique( a, b ) {
		var seen = {}, result = [];
		a.concat( b ).forEach( function ( v ) {
			if ( ! seen[ v ] ) { seen[ v ] = true; result.push( v ); }
		} );
		return result;
	}

	// ---------------------------------------------------------------------------
	// Shared data hook — entity meta + block HTML + site URL + snippet capability
	// ---------------------------------------------------------------------------

	function useArtifactData( postId ) {
		var meta = useSelect( function ( select ) {
			var record = postId
				? select( 'core' ).getEditedEntityRecord( 'postType', 'wm_artifact', postId )
				: null;
			return ( record && record.meta ) ? record.meta : {};
		} );

		var _blockData = useSelect( function ( select ) {
			var blocks = select( 'core/block-editor' ).getBlocks();
			for ( var i = 0; i < blocks.length; i++ ) {
				if ( blocks[ i ].name === 'wmac/artifact' ) {
					return {
						html:       blocks[ i ].attributes.html       || '',
						fileStored: !! blocks[ i ].attributes.fileStored,
					};
				}
			}
			return { html: '', fileStored: false };
		} );

		var siteUrl = useSelect( function ( select ) {
			var site = select( 'core' ).getSite();
			return site ? ( site.url || '' ) : '';
		} );

		var canSaveSnippet = useSelect( function ( select ) {
			var post = select( 'core/editor' ).getCurrentPost();
			return !! ( post && post._links && post._links[ 'wp:action-unfiltered-html' ] );
		} );

		var _dispatch        = useDispatch( 'core' );
		var editEntityRecord = _dispatch && _dispatch.editEntityRecord;

		function setMeta( newMeta ) {
			if ( editEntityRecord && postId ) {
				editEntityRecord( 'postType', 'wm_artifact', postId, { meta: newMeta } );
			}
		}

		return {
			meta:            meta,
			setMeta:         setMeta,
			html:            _blockData.html,
			fileStored:      _blockData.fileStored,
			siteUrl:         siteUrl,
			canSaveSnippet:  canSaveSnippet,
		};
	}

	// ---------------------------------------------------------------------------
	// Settings panel
	// ---------------------------------------------------------------------------

	function SettingsPanel( props ) {
		var meta    = props.meta;
		var setMeta = props.setMeta;
		var siteUrl = props.siteUrl;

		var alias   = meta[ '_wmac_alias' ]        || '';
		var noindex = meta[ '_wmac_noindex' ];
		var seo     = meta[ '_wmac_seo_enabled' ];
		var csp     = meta[ '_wmac_csp' ]          || '';
		var prompt  = meta[ '_wmac_prompt' ]       || '';

		function update( key, value ) {
			setMeta( Object.assign( {}, meta, { [ key ]: value } ) );
		}

		return createElement(
			'div',
			{ className: 'wmac-panel wmac-panel--settings' },
			createElement( TextControl, {
				label:                   __( 'Custom URL Alias', 'webmultipliers-wp-artifact-canvas' ),
				help:                    alias && siteUrl
					? siteUrl.replace( /\/$/, '' ) + '/' + alias
					: __( 'Optional. Enter a path (e.g. "pricing") to serve this artifact at that URL on the front end.', 'webmultipliers-wp-artifact-canvas' ),
				value:                   alias,
				placeholder:             'e.g. pricing',
				onChange:                function ( v ) { update( '_wmac_alias', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( ToggleControl, {
				label:    __( 'Discourage search engines (noindex)', 'webmultipliers-wp-artifact-canvas' ),
				help:     __( 'Sends a noindex header. Default is on; toggle off to allow indexing.', 'webmultipliers-wp-artifact-canvas' ),
				checked:  noindex !== '0',
				onChange: function ( v ) { update( '_wmac_noindex', v ? '1' : '0' ); },
			} ),
			createElement( ToggleControl, {
				label:    __( 'Inject SEO meta tags', 'webmultipliers-wp-artifact-canvas' ),
				help:     __( 'Adds og: / twitter: tags from the post title, excerpt, and featured image.', 'webmultipliers-wp-artifact-canvas' ),
				checked:  seo === '1',
				onChange: function ( v ) { update( '_wmac_seo_enabled', v ? '1' : '0' ); },
			} ),
			createElement( TextControl, {
				label:                   __( 'Content Security Policy', 'webmultipliers-wp-artifact-canvas' ),
				help:                    __( 'Per-artifact CSP header. Overrides the global wmac_csp filter. Leave blank to inherit.', 'webmultipliers-wp-artifact-canvas' ),
				value:                   csp,
				onChange:                function ( v ) { update( '_wmac_csp', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( TextareaControl, {
				label:                   __( 'Generation Prompt', 'webmultipliers-wp-artifact-canvas' ),
				help:                    __( 'Paste the prompt used to generate this artifact. Private — not published.', 'webmultipliers-wp-artifact-canvas' ),
				value:                   prompt,
				rows:                    5,
				onChange:                function ( v ) { update( '_wmac_prompt', v ); },
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	// ---------------------------------------------------------------------------
	// Governance panel
	// ---------------------------------------------------------------------------

	function GovernancePanel( props ) {
		var meta    = props.meta;
		var setMeta = props.setMeta;

		var expiresAt   = meta[ '_wmac_expires_at' ] || '';
		var maxViews    = meta[ '_wmac_max_views' ];
		var viewCount   = parseInt( meta[ '_wmac_view_count' ] || '0', 10 );
		var maxViewsStr = ( maxViews !== undefined && maxViews !== null && Number( maxViews ) > 0 )
			? String( maxViews )
			: '';

		function update( key, value ) {
			setMeta( Object.assign( {}, meta, { [ key ]: value } ) );
		}

		return createElement(
			'div',
			{ className: 'wmac-panel wmac-panel--governance' },
			createElement( TextControl, {
				label:                   __( 'Expire after date (UTC)', 'webmultipliers-wp-artifact-canvas' ),
				help:                    __( 'ISO 8601: 2025-12-31T23:59:59. Leave blank for no date expiry.', 'webmultipliers-wp-artifact-canvas' ),
				value:                   expiresAt,
				placeholder:             '2025-12-31T23:59:59',
				onChange:                function ( v ) { update( '_wmac_expires_at', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( TextControl, {
				label:                   __( 'Max public views', 'webmultipliers-wp-artifact-canvas' ),
				help:                    __( 'Link expires after this many public views. Leave blank or 0 for unlimited.', 'webmultipliers-wp-artifact-canvas' ),
				value:                   maxViewsStr,
				type:                    'number',
				min:                     '0',
				onChange:                function ( v ) { update( '_wmac_max_views', parseInt( v, 10 ) || 0 ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement(
				'p',
				{ className: 'wmac-view-count' },
				__( 'Public views: ', 'webmultipliers-wp-artifact-canvas' ),
				createElement( 'strong', null, String( viewCount ) )
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Tracking panel
	// ---------------------------------------------------------------------------

	function TrackingPanel( props ) {
		var meta           = props.meta;
		var setMeta        = props.setMeta;
		var canSaveSnippet = props.canSaveSnippet;

		var snippet    = meta[ '_wmac_tracking_snippet' ] || '';
		var webhookUrl = meta[ '_wmac_view_webhook_url' ] || '';

		function update( key, value ) {
			setMeta( Object.assign( {}, meta, { [ key ]: value } ) );
		}

		return createElement(
			'div',
			{ className: 'wmac-panel wmac-panel--tracking' },
			! canSaveSnippet && createElement(
				'p',
				{ className: 'wmac-panel-hint--warning' },
				__( 'Analytics snippets require administrator (unfiltered_html) privileges to save.', 'webmultipliers-wp-artifact-canvas' )
			),
			createElement( TextareaControl, {
				label:                   __( 'Analytics snippet', 'webmultipliers-wp-artifact-canvas' ),
				help:                    __( 'Injected before </head> on every serve. Paste a Plausible, Fathom, or custom <script> tag. Requires administrator privileges.', 'webmultipliers-wp-artifact-canvas' ),
				value:                   snippet,
				rows:                    5,
				disabled:                ! canSaveSnippet,
				onChange:                function ( v ) { update( '_wmac_tracking_snippet', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( TextControl, {
				label:                   __( 'View alert webhook URL', 'webmultipliers-wp-artifact-canvas' ),
				help:                    __( 'Receives a JSON POST each time a public visitor views this artifact. Leave blank to disable.', 'webmultipliers-wp-artifact-canvas' ),
				value:                   webhookUrl,
				type:                    'url',
				placeholder:             'https://hooks.slack.com/…',
				onChange:                function ( v ) { update( '_wmac_view_webhook_url', v ); },
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	// ---------------------------------------------------------------------------
	// Merge Tags manager
	// ---------------------------------------------------------------------------

	function MergeTagsManager( props ) {
		var meta       = props.meta;
		var setMeta    = props.setMeta;
		var html       = props.html;
		var fileStored = props.fileStored;

		var tagMap = {};
		try { tagMap = JSON.parse( meta[ '_wmac_tag_map' ] || '{}' ); } catch ( e ) { tagMap = {}; }

		var _newTagState  = useState( '' );
		var newTagName    = _newTagState[ 0 ];
		var setNewTagName = _newTagState[ 1 ];

		var detectedTags   = parseMergeTags( html );
		var configuredTags = Object.keys( tagMap );
		var allTags        = mergeUnique( detectedTags, configuredTags );

		function updateTag( tag, config ) {
			var updated    = Object.assign( {}, tagMap );
			updated[ tag ] = config;
			setMeta( Object.assign( {}, meta, { _wmac_tag_map: JSON.stringify( updated ) } ) );
		}

		function removeTag( tag ) {
			var updated = Object.assign( {}, tagMap );
			delete updated[ tag ];
			setMeta( Object.assign( {}, meta, { _wmac_tag_map: JSON.stringify( updated ) } ) );
		}

		function addTag() {
			var name = newTagName.trim().replace( /[^a-zA-Z0-9_]/g, '' );
			if ( ! name || tagMap[ name ] !== undefined ) return;
			updateTag( name, { mode: 'static', value: '' } );
			setNewTagName( '' );
		}

		return createElement(
			'div',
			{ className: 'wmac-mgmt' },
			fileStored && createElement(
				'p',
				{ className: 'wmac-mgmt__note' },
				__( 'HTML is stored on the server — tags below apply to {{placeholders}} in that file.', 'webmultipliers-wp-artifact-canvas' )
			),
			allTags.length === 0 && createElement(
				'p',
				{ className: 'wmac-mgmt__empty' },
				fileStored
					? __( 'No tags configured yet. Enter a tag name below to get started.', 'webmultipliers-wp-artifact-canvas' )
					: __( 'No {{tags}} detected in the artifact HTML. Add placeholders like {{customer_name}} to the HTML, or enter a name below to configure one manually.', 'webmultipliers-wp-artifact-canvas' )
			),
			allTags.length > 0 && createElement(
				'table',
				{ className: 'wmac-mgmt__table' },
				createElement(
					'thead',
					null,
					createElement(
						'tr',
						null,
						createElement( 'th', null, __( 'Tag', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement( 'th', null, __( 'Type', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement( 'th', null, __( 'Value / Hook', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement( 'th', null )
					)
				),
				createElement(
					'tbody',
					null,
					allTags.map( function ( tag ) {
						var inHtml = detectedTags.indexOf( tag ) !== -1;
						var config = ( tagMap[ tag ] && typeof tagMap[ tag ] === 'object' )
							? tagMap[ tag ]
							: { mode: 'static', value: '' };
						var mode  = config.mode === 'dynamic' ? 'dynamic' : 'static';
						var value = typeof config.value === 'string' ? config.value : '';

						return createElement(
							'tr',
							{ key: tag, className: 'wmac-mgmt__row' + ( ! inHtml ? ' wmac-mgmt__row--orphan' : '' ) },
							createElement(
								'td',
								null,
								createElement( 'code', { className: 'wmac-mgmt__tag' }, '{{' + tag + '}}' ),
								! inHtml && createElement( 'span', { className: 'wmac-mgmt__orphan' }, __( ' (not in HTML)', 'webmultipliers-wp-artifact-canvas' ) )
							),
							createElement(
								'td',
								null,
								createElement( SelectControl, {
									label:                   __( 'Type', 'webmultipliers-wp-artifact-canvas' ),
									hideLabelFromVision:     true,
									value:                   mode,
									options:                 [
										{ value: 'static',  label: __( 'Static',  'webmultipliers-wp-artifact-canvas' ) },
										{ value: 'dynamic', label: __( 'Dynamic', 'webmultipliers-wp-artifact-canvas' ) },
									],
									onChange:                function ( v ) {
										updateTag( tag, Object.assign( {}, config, { mode: v } ) );
									},
									__nextHasNoMarginBottom: true,
								} )
							),
							createElement(
								'td',
								null,
								mode === 'static' && createElement( TextControl, {
									label:                   __( 'Value', 'webmultipliers-wp-artifact-canvas' ),
									hideLabelFromVision:     true,
									value:                   value,
									placeholder:             __( 'Replacement value…', 'webmultipliers-wp-artifact-canvas' ),
									onChange:                function ( v ) {
										updateTag( tag, Object.assign( {}, config, { mode: 'static', value: v } ) );
									},
									__nextHasNoMarginBottom: true,
								} ),
								mode === 'dynamic' && createElement(
									'code',
									{ className: 'wmac-mgmt__hook' },
									'wmac_resolve_tag_' + tag
								)
							),
							createElement(
								'td',
								null,
								createElement( Button, {
									variant:       'tertiary',
									size:          'small',
									isDestructive: true,
									onClick:       function () { removeTag( tag ); },
									'aria-label':  __( 'Remove tag', 'webmultipliers-wp-artifact-canvas' ),
								}, '×' )
							)
						);
					} )
				)
			),
			createElement(
				'div',
				{ className: 'wmac-mgmt__add' },
				createElement( TextControl, {
					label:                   __( 'Add tag', 'webmultipliers-wp-artifact-canvas' ),
					hideLabelFromVision:     true,
					value:                   newTagName,
					placeholder:             __( 'tag_name', 'webmultipliers-wp-artifact-canvas' ),
					onChange:                setNewTagName,
					onKeyDown:               function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); addTag(); } },
					__nextHasNoMarginBottom: true,
				} ),
				createElement( Button, {
					variant:  'secondary',
					size:     'small',
					onClick:  addTag,
					disabled: ! newTagName.trim(),
				}, __( '+ Add Tag', 'webmultipliers-wp-artifact-canvas' ) )
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Asset Mapping manager
	// ---------------------------------------------------------------------------

	function AssetMappingManager( props ) {
		var meta       = props.meta;
		var setMeta    = props.setMeta;
		var html       = props.html;
		var fileStored = props.fileStored;

		var assetMap = {};
		try { assetMap = JSON.parse( meta[ '_wmac_asset_map' ] || '{}' ); } catch ( e ) { assetMap = {}; }

		var _newPathState = useState( '' );
		var newPath       = _newPathState[ 0 ];
		var setNewPath    = _newPathState[ 1 ];

		var detectedPaths   = parseUnresolvedAssets( html );
		var configuredPaths = Object.keys( assetMap );
		var allPaths        = mergeUnique( detectedPaths, configuredPaths );

		function updatePath( path, url ) {
			var updated = Object.assign( {}, assetMap );
			if ( url === '' ) { delete updated[ path ]; }
			else { updated[ path ] = url; }
			setMeta( Object.assign( {}, meta, { _wmac_asset_map: JSON.stringify( updated ) } ) );
		}

		function openMediaFrame( path ) {
			if ( ! wp.media ) return;
			var frame = wp.media( {
				title:    __( 'Select or Upload a File', 'webmultipliers-wp-artifact-canvas' ),
				button:   { text: __( 'Use this file', 'webmultipliers-wp-artifact-canvas' ) },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				updatePath( path, attachment.url );
			} );
			frame.open();
		}

		function addPathAndMap() {
			var p = newPath.trim();
			if ( ! p ) return;
			setNewPath( '' );
			openMediaFrame( p );
		}

		return createElement(
			'div',
			{ className: 'wmac-mgmt' },
			fileStored && createElement(
				'p',
				{ className: 'wmac-mgmt__note' },
				__( 'HTML is stored on the server. Enter relative paths from that file to map them to Media Library URLs.', 'webmultipliers-wp-artifact-canvas' )
			),
			allPaths.length === 0 && createElement(
				'p',
				{ className: 'wmac-mgmt__empty' },
				fileStored
					? __( 'No paths mapped yet. Enter a relative path below to map it to a Media Library file.', 'webmultipliers-wp-artifact-canvas' )
					: __( 'No unresolved relative asset paths detected in the artifact HTML.', 'webmultipliers-wp-artifact-canvas' )
			),
			allPaths.length > 0 && createElement(
				'table',
				{ className: 'wmac-mgmt__table' },
				createElement(
					'thead',
					null,
					createElement(
						'tr',
						null,
						createElement( 'th', null, __( 'Path', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement( 'th', null, __( 'Mapped URL', 'webmultipliers-wp-artifact-canvas' ) ),
						createElement( 'th', null )
					)
				),
				createElement(
					'tbody',
					null,
					allPaths.map( function ( path ) {
						var inHtml    = detectedPaths.indexOf( path ) !== -1;
						var mappedUrl = ( typeof assetMap[ path ] === 'string' ) ? assetMap[ path ] : '';

						return createElement(
							'tr',
							{ key: path, className: 'wmac-mgmt__row' + ( ! inHtml ? ' wmac-mgmt__row--orphan' : '' ) },
							createElement(
								'td',
								null,
								createElement( 'code', { className: 'wmac-mgmt__tag' }, path ),
								! inHtml && createElement( 'span', { className: 'wmac-mgmt__orphan' }, __( ' (not in HTML)', 'webmultipliers-wp-artifact-canvas' ) )
							),
							createElement(
								'td',
								{ className: 'wmac-mgmt__asset-url-cell' },
								mappedUrl
									? createElement( 'a', { href: mappedUrl, target: '_blank', rel: 'noreferrer', className: 'wmac-mgmt__asset-url', title: mappedUrl }, mappedUrl )
									: createElement( 'span', { className: 'wmac-mgmt__unmapped' }, __( 'Not mapped', 'webmultipliers-wp-artifact-canvas' ) )
							),
							createElement(
								'td',
								{ className: 'wmac-mgmt__actions' },
								createElement( Button, {
									variant: 'secondary',
									size:    'small',
									onClick: function () { openMediaFrame( path ); },
								}, mappedUrl
									? __( 'Change', 'webmultipliers-wp-artifact-canvas' )
									: __( 'Select', 'webmultipliers-wp-artifact-canvas' )
								),
								mappedUrl && createElement( Button, {
									variant:       'tertiary',
									size:          'small',
									isDestructive: true,
									onClick:       function () { updatePath( path, '' ); },
								}, __( 'Remove', 'webmultipliers-wp-artifact-canvas' ) )
							)
						);
					} )
				)
			),
			createElement(
				'div',
				{ className: 'wmac-mgmt__add' },
				createElement( TextControl, {
					label:                   __( 'Add path', 'webmultipliers-wp-artifact-canvas' ),
					hideLabelFromVision:     true,
					value:                   newPath,
					placeholder:             __( 'images/hero.jpg', 'webmultipliers-wp-artifact-canvas' ),
					onChange:                setNewPath,
					onKeyDown:               function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); addPathAndMap(); } },
					__nextHasNoMarginBottom: true,
				} ),
				createElement( Button, {
					variant:  'secondary',
					size:     'small',
					onClick:  addPathAndMap,
					disabled: ! newPath.trim(),
				}, __( '+ Map Path', 'webmultipliers-wp-artifact-canvas' ) )
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Root metabox component — owns tab state, renders the active panel
	// ---------------------------------------------------------------------------

	function ArtifactMetabox( props ) {
		var d = useArtifactData( props.postId );

		var _tabState    = useState( 'settings' );
		var activeTab    = _tabState[ 0 ];
		var setActiveTab = _tabState[ 1 ];

		var panels = {
			settings:   createElement( SettingsPanel,      { meta: d.meta, setMeta: d.setMeta, siteUrl: d.siteUrl } ),
			governance: createElement( GovernancePanel,    { meta: d.meta, setMeta: d.setMeta } ),
			tracking:   createElement( TrackingPanel,      { meta: d.meta, setMeta: d.setMeta, canSaveSnippet: d.canSaveSnippet } ),
			tags:       createElement( MergeTagsManager,   { meta: d.meta, setMeta: d.setMeta, html: d.html, fileStored: d.fileStored } ),
			assets:     createElement( AssetMappingManager, { meta: d.meta, setMeta: d.setMeta, html: d.html, fileStored: d.fileStored } ),
		};

		return createElement(
			'div',
			{ className: 'wmac-metabox' },
			createElement(
				'nav',
				{ className: 'nav-tab-wrapper wmac-metabox__tabs' },
				TABS.map( function ( tab ) {
					return createElement(
						'button',
						{
							key:       tab.id,
							type:      'button',
							className: 'nav-tab' + ( activeTab === tab.id ? ' nav-tab-active' : '' ),
							onClick:   function () { setActiveTab( tab.id ); },
						},
						tab.label
					);
				} )
			),
			createElement(
				'div',
				{ className: 'wmac-metabox__panel' },
				panels[ activeTab ]
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Mount into the PHP metabox div
	// ---------------------------------------------------------------------------

	wp.domReady( function () {
		var container = document.getElementById( 'wmac-artifact-root' );
		if ( ! container ) return;

		var postId = parseInt( container.dataset.postId || '0', 10 );
		var vnode  = createElement( ArtifactMetabox, { postId: postId } );

		if ( element.createRoot ) {
			element.createRoot( container ).render( vnode );
		} else {
			element.render( vnode, container );
		}
	} );

} )();
