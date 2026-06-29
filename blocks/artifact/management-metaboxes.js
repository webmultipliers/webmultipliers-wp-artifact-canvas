/**
 * Management Metaboxes — Merge Tags & Asset Mapping
 *
 * Does two things:
 *
 *   1. Mounts interactive React apps into the PHP metabox divs rendered by
 *      ManagementMetaboxes.php. Both apps read/write the entity store via
 *      wp.data so changes are captured in the Gutenberg dirty-state and
 *      saved atomically when the user clicks Update.
 *
 *   2. Registers a PluginSidebar ("Artifact Management") accessible from the
 *      editor header's More tools & options (…) menu, giving a second entry
 *      point to the same management UI without scrolling to the metaboxes.
 */
( function () {
	var plugins    = wp.plugins;
	var editPost   = wp.editPost  || {};
	var editor     = wp.editor    || {};
	var element    = wp.element;
	var components = wp.components;
	var data       = wp.data;
	var i18n       = wp.i18n;

	var registerPlugin           = plugins  && plugins.registerPlugin;
	var PluginSidebar            = editPost.PluginSidebar            || editor.PluginSidebar;
	var PluginSidebarMoreMenuItem = editPost.PluginSidebarMoreMenuItem || editor.PluginSidebarMoreMenuItem;

	var createElement  = element.createElement;
	var Fragment       = element.Fragment;
	var useState       = element.useState;
	var useSelect      = data.useSelect;
	var useDispatch    = data.useDispatch;
	var Button         = components.Button;
	var SelectControl  = components.SelectControl;
	var TextControl    = components.TextControl;
	var __             = i18n.__;

	// ---------------------------------------------------------------------------
	// Helpers
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
	// Shared data hook — reads entity meta and block HTML from the WP data store.
	// Works in both the metabox React roots and the PluginSidebar.
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

		var _dispatch        = useDispatch( 'core' );
		var editEntityRecord = _dispatch && _dispatch.editEntityRecord;

		function setMeta( newMeta ) {
			if ( editEntityRecord && postId ) {
				editEntityRecord( 'postType', 'wm_artifact', postId, { meta: newMeta } );
			}
		}

		return {
			meta:       meta,
			setMeta:    setMeta,
			html:       _blockData.html,
			fileStored: _blockData.fileStored,
		};
	}

	// ---------------------------------------------------------------------------
	// Merge Tags Manager
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
	// Asset Mapping Manager
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
	// Metabox root components — each owns its own useArtifactData call
	// ---------------------------------------------------------------------------

	function MergeTagsMetabox( props ) {
		var d = useArtifactData( props.postId );
		return createElement( MergeTagsManager, { meta: d.meta, setMeta: d.setMeta, html: d.html, fileStored: d.fileStored } );
	}

	function AssetMappingMetabox( props ) {
		var d = useArtifactData( props.postId );
		return createElement( AssetMappingManager, { meta: d.meta, setMeta: d.setMeta, html: d.html, fileStored: d.fileStored } );
	}

	// ---------------------------------------------------------------------------
	// PluginSidebar — triggered from the editor header (More tools & options …)
	// ---------------------------------------------------------------------------

	if ( registerPlugin && PluginSidebar && PluginSidebarMoreMenuItem ) {
		function ArtifactManagementSidebar() {
			var postId = useSelect( function ( select ) {
				return select( 'core/editor' ).getCurrentPostId();
			} );

			var _tabState    = useState( 'tags' );
			var activeTab    = _tabState[ 0 ];
			var setActiveTab = _tabState[ 1 ];

			var d = useArtifactData( postId );

			return createElement(
				Fragment,
				null,
				createElement(
					PluginSidebarMoreMenuItem,
					{ target: 'wmac-artifact-management' },
					__( 'Merge Tags & Assets', 'webmultipliers-wp-artifact-canvas' )
				),
				createElement(
					PluginSidebar,
					{
						name:  'wmac-artifact-management',
						title: __( 'Artifact Management', 'webmultipliers-wp-artifact-canvas' ),
					},
					createElement(
						'div',
						{ className: 'wmac-sidebar-mgmt' },
						createElement(
							'div',
							{ className: 'wmac-sidebar-mgmt__tabs' },
							createElement(
								'button',
								{
									type:      'button',
									className: 'wmac-sidebar-mgmt__tab' + ( activeTab === 'tags' ? ' is-active' : '' ),
									onClick:   function () { setActiveTab( 'tags' ); },
								},
								__( 'Merge Tags', 'webmultipliers-wp-artifact-canvas' )
							),
							createElement(
								'button',
								{
									type:      'button',
									className: 'wmac-sidebar-mgmt__tab' + ( activeTab === 'assets' ? ' is-active' : '' ),
									onClick:   function () { setActiveTab( 'assets' ); },
								},
								__( 'Asset Mapping', 'webmultipliers-wp-artifact-canvas' )
							)
						),
						activeTab === 'tags' && createElement( MergeTagsManager, {
							meta: d.meta, setMeta: d.setMeta, html: d.html, fileStored: d.fileStored,
						} ),
						activeTab === 'assets' && createElement( AssetMappingManager, {
							meta: d.meta, setMeta: d.setMeta, html: d.html, fileStored: d.fileStored,
						} )
					)
				)
			);
		}

		registerPlugin( 'wmac-artifact-management', { render: ArtifactManagementSidebar } );
	}

	// ---------------------------------------------------------------------------
	// Mount React into the PHP metabox divs
	// ---------------------------------------------------------------------------

	wp.domReady( function () {
		function mountComponent( elementId, Component ) {
			var container = document.getElementById( elementId );
			if ( ! container ) return;

			var postId = parseInt( container.dataset.postId || '0', 10 );
			var vnode  = createElement( Component, { postId: postId } );

			// React 18 (WP 6.3+) uses createRoot; older WP uses render.
			if ( element.createRoot ) {
				element.createRoot( container ).render( vnode );
			} else {
				element.render( vnode, container );
			}
		}

		mountComponent( 'wmac-merge-tags-root',    MergeTagsMetabox    );
		mountComponent( 'wmac-asset-mapping-root', AssetMappingMetabox );
	} );

} )();
