/**
 * Document-level sidebar panels for WP Artifact Canvas.
 *
 * Panels (all PluginDocumentSettingPanel, visible in the Post sidebar):
 *   • Artifact Settings  — custom alias, noindex, SEO tag injection, CSP, generation prompt
 *   • Lifecycle Status   — custom status picker (In Review / Approved / Archived)
 *   • Ownership          — assign a responsible developer / PM (WP user)
 *   • Link Governance    — expiry date, max-view limit, current view count
 *   • Tracking & Alerts  — analytics snippet + view-alert webhook URL
 *
 * Merge Tags and Asset Mapping are managed in the full-width bottom panel
 * that appears below the code editor (see editor.js).
 *
 * This script is only enqueued on wm_artifact edit screens (see BlockType.php),
 * so registerPlugin runs exclusively for that post type.
 */
( function () {
	var plugins    = wp.plugins;
	var editPost   = wp.editPost  || {};
	var editor     = wp.editor    || {};
	var components = wp.components;
	var element    = wp.element;
	var data       = wp.data;
	var i18n       = wp.i18n;
	var coreData   = wp.coreData;

	var registerPlugin             = plugins && plugins.registerPlugin;
	var PluginDocumentSettingPanel = editPost.PluginDocumentSettingPanel
	                              || editor.PluginDocumentSettingPanel;

	var TextControl     = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var ToggleControl   = components.ToggleControl;
	var SelectControl   = components.SelectControl;
	var ComboboxControl = components.ComboboxControl;
	var createElement   = element.createElement;
	var Fragment        = element.Fragment;
	var useSelect       = data.useSelect;
	var useDispatch     = data.useDispatch;
	var useEntityProp   = coreData && coreData.useEntityProp;
	var __              = i18n.__;

	if ( ! registerPlugin || ! PluginDocumentSettingPanel || ! useEntityProp ) {
		return;
	}

	// ---------------------------------------------------------------------------
	// Artifact Settings Panel
	// ---------------------------------------------------------------------------

	function ArtifactSettingsPanel() {
		var _metaState = useEntityProp( 'postType', 'wm_artifact', 'meta' );
		var meta       = _metaState[ 0 ] || {};
		var setMeta    = _metaState[ 1 ];

		var metaAlias   = meta[ '_wmac_alias' ]        || '';
		var metaPrompt  = meta[ '_wmac_prompt' ]       || '';
		var metaNoindex = meta[ '_wmac_noindex' ]      || '';
		var metaSeo     = meta[ '_wmac_seo_enabled' ]  || '';
		var metaCsp     = meta[ '_wmac_csp' ]          || '';

		var siteUrl = useSelect( function ( select ) {
			var site = select( 'core' ).getSite();
			return site ? ( site.url || '' ) : '';
		} );

		function update( key, value ) {
			setMeta( Object.assign( {}, meta, { [ key ]: value } ) );
		}

		return createElement(
			'div',
			{ className: 'wmac-artifact-settings-panel' },
			createElement( TextControl, {
				label:       __( 'Custom URL Alias', 'webmultipliers-wp-artifact-canvas' ),
				help:        metaAlias && siteUrl
					? siteUrl.replace( /\/$/, '' ) + '/' + metaAlias
					: __( 'Optional. Enter a path (e.g. "pricing") to serve this artifact at that URL on the front end.', 'webmultipliers-wp-artifact-canvas' ),
				value:       metaAlias,
				placeholder: 'e.g. pricing',
				onChange:    function ( v ) { update( '_wmac_alias', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( ToggleControl, {
				label:    __( 'Discourage search engines (noindex)', 'webmultipliers-wp-artifact-canvas' ),
				help:     __( 'Sends a noindex header. Default is on; toggle off to allow indexing.', 'webmultipliers-wp-artifact-canvas' ),
				checked:  metaNoindex !== '0',
				onChange: function ( v ) { update( '_wmac_noindex', v ? '1' : '0' ); },
			} ),
			createElement( ToggleControl, {
				label:    __( 'Inject SEO meta tags', 'webmultipliers-wp-artifact-canvas' ),
				help:     __( 'Adds og: / twitter: tags from the post title, excerpt, and featured image.', 'webmultipliers-wp-artifact-canvas' ),
				checked:  metaSeo === '1',
				onChange: function ( v ) { update( '_wmac_seo_enabled', v ? '1' : '0' ); },
			} ),
			createElement( TextControl, {
				label:    __( 'Content Security Policy', 'webmultipliers-wp-artifact-canvas' ),
				help:     __( 'Per-artifact CSP header. Overrides the global wmac_csp filter. Leave blank to inherit.', 'webmultipliers-wp-artifact-canvas' ),
				value:    metaCsp,
				onChange: function ( v ) { update( '_wmac_csp', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( TextareaControl, {
				label:    __( 'Generation Prompt', 'webmultipliers-wp-artifact-canvas' ),
				help:     __( 'Paste the prompt used to generate this artifact. Private — not published.', 'webmultipliers-wp-artifact-canvas' ),
				value:    metaPrompt,
				onChange: function ( v ) { update( '_wmac_prompt', v ); },
				rows:     5,
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	// ---------------------------------------------------------------------------
	// Lifecycle Status Panel
	// ---------------------------------------------------------------------------

	var CUSTOM_STATUSES = [
		{ value: 'wm_review',   label: __( 'In Review',  'webmultipliers-wp-artifact-canvas' ) },
		{ value: 'wm_approved', label: __( 'Approved',   'webmultipliers-wp-artifact-canvas' ) },
		{ value: 'wm_archived', label: __( 'Archived',   'webmultipliers-wp-artifact-canvas' ) },
	];

	var CORE_STATUSES = [
		{ value: 'draft',   label: __( 'Draft',          'webmultipliers-wp-artifact-canvas' ) },
		{ value: 'pending', label: __( 'Pending Review', 'webmultipliers-wp-artifact-canvas' ) },
		{ value: 'publish', label: __( 'Published',      'webmultipliers-wp-artifact-canvas' ) },
		{ value: 'private', label: __( 'Private',        'webmultipliers-wp-artifact-canvas' ) },
	];

	var ALL_STATUSES = CORE_STATUSES.concat( CUSTOM_STATUSES );

	function LifecycleStatusPanel() {
		var currentStatus = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'status' ) || 'draft';
		} );

		var _dispatch    = useDispatch( 'core/editor' );
		var editPostFn   = _dispatch && _dispatch.editPost;

		return createElement(
			'div',
			{ className: 'wmac-lifecycle-panel' },
			createElement( SelectControl, {
				label:    __( 'Lifecycle Status', 'webmultipliers-wp-artifact-canvas' ),
				value:    currentStatus,
				options:  ALL_STATUSES,
				onChange: function ( v ) {
					if ( editPostFn ) editPostFn( { status: v } );
				},
				__nextHasNoMarginBottom: true,
			} ),
			currentStatus.startsWith( 'wm_' ) && createElement(
				'p',
				{ className: 'wmac-panel-hint' },
				__( 'Custom statuses are private — the artifact is not served publicly until status is "Published".', 'webmultipliers-wp-artifact-canvas' )
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Ownership Panel
	// ---------------------------------------------------------------------------

	function OwnershipPanel() {
		var _metaState = useEntityProp( 'postType', 'wm_artifact', 'meta' );
		var meta       = _metaState[ 0 ] || {};
		var setMeta    = _metaState[ 1 ];

		// Meta is registered as integer — compare as strings for ComboboxControl.
		var ownerId = parseInt( meta[ '_wmac_owner_id' ] || '0', 10 ) || 0;

		var users = useSelect( function ( select ) {
			return select( 'core' ).getUsers( { who: 'authors', per_page: 100 } ) || [];
		} );

		// ComboboxControl requires string option values.
		var userOptions = users.map( function ( u ) {
			return { value: String( u.id ), label: u.name };
		} );

		var currentValue = ownerId ? String( ownerId ) : '';

		if ( ! ComboboxControl ) {
			return createElement( TextControl, {
				label:    __( 'Owner (User ID)', 'webmultipliers-wp-artifact-canvas' ),
				value:    currentValue,
				type:     'number',
				onChange: function ( v ) {
					setMeta( Object.assign( {}, meta, { _wmac_owner_id: parseInt( v, 10 ) || 0 } ) );
				},
				__nextHasNoMarginBottom: true,
			} );
		}

		return createElement( ComboboxControl, {
			label:               __( 'Owner / Project Manager', 'webmultipliers-wp-artifact-canvas' ),
			value:               currentValue || null,
			options:             userOptions,
			allowReset:          true,
			onChange:            function ( v ) {
				setMeta( Object.assign( {}, meta, { _wmac_owner_id: parseInt( v, 10 ) || 0 } ) );
			},
			onFilterValueChange: function () {},
			__nextHasNoMarginBottom: true,
		} );
	}

	// ---------------------------------------------------------------------------
	// Link Governance Panel
	// ---------------------------------------------------------------------------

	function LinkGovernancePanel() {
		var _metaState = useEntityProp( 'postType', 'wm_artifact', 'meta' );
		var meta       = _metaState[ 0 ] || {};
		var setMeta    = _metaState[ 1 ];

		var expiresAt = meta[ '_wmac_expires_at' ] || '';
		var maxViews  = meta[ '_wmac_max_views' ];
		var viewCount = parseInt( meta[ '_wmac_view_count' ] || '0', 10 );

		// Integer meta: 0 = unlimited; display blank for 0.
		var maxViewsStr = ( maxViews !== undefined && maxViews !== null && Number( maxViews ) > 0 )
			? String( maxViews )
			: '';

		function update( key, value ) {
			setMeta( Object.assign( {}, meta, { [ key ]: value } ) );
		}

		return createElement(
			'div',
			{ className: 'wmac-governance-panel' },
			createElement( TextControl, {
				label:       __( 'Expire after date (UTC)', 'webmultipliers-wp-artifact-canvas' ),
				help:        __( 'ISO 8601: 2025-12-31T23:59:59. Leave blank for no date expiry.', 'webmultipliers-wp-artifact-canvas' ),
				value:       expiresAt,
				placeholder: '2025-12-31T23:59:59',
				onChange:    function ( v ) { update( '_wmac_expires_at', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( TextControl, {
				label:       __( 'Max public views', 'webmultipliers-wp-artifact-canvas' ),
				help:        __( 'Link expires after this many public views. Leave blank or 0 for unlimited.', 'webmultipliers-wp-artifact-canvas' ),
				value:       maxViewsStr,
				type:        'number',
				min:         '0',
				onChange:    function ( v ) { update( '_wmac_max_views', parseInt( v, 10 ) || 0 ); },
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
	// Tracking & Alerts Panel
	// ---------------------------------------------------------------------------

	function TrackingPanel() {
		var _metaState = useEntityProp( 'postType', 'wm_artifact', 'meta' );
		var meta       = _metaState[ 0 ] || {};
		var setMeta    = _metaState[ 1 ];

		var snippet    = meta[ '_wmac_tracking_snippet' ] || '';
		var webhookUrl = meta[ '_wmac_view_webhook_url' ] || '';

		// unfiltered_html is required to save the snippet; surface a hint when absent.
		var canSaveSnippet = useSelect( function ( select ) {
			var post = select( 'core/editor' ).getCurrentPost();
			return !! ( post && post._links && post._links[ 'wp:action-unfiltered-html' ] );
		} );

		function update( key, value ) {
			setMeta( Object.assign( {}, meta, { [ key ]: value } ) );
		}

		return createElement(
			'div',
			{ className: 'wmac-tracking-panel' },
			! canSaveSnippet && createElement(
				'p',
				{ className: 'wmac-panel-hint wmac-panel-hint--warning' },
				__( 'Analytics snippets require administrator (unfiltered_html) privileges to save.', 'webmultipliers-wp-artifact-canvas' )
			),
			createElement( TextareaControl, {
				label:    __( 'Analytics snippet', 'webmultipliers-wp-artifact-canvas' ),
				help:     __( 'Injected before </head> on every serve. Paste a Plausible, Fathom, or custom <script> tag. Requires administrator privileges.', 'webmultipliers-wp-artifact-canvas' ),
				value:    snippet,
				rows:     4,
				disabled: ! canSaveSnippet,
				onChange: function ( v ) { update( '_wmac_tracking_snippet', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			createElement( TextControl, {
				label:       __( 'View alert webhook URL', 'webmultipliers-wp-artifact-canvas' ),
				help:        __( 'Receives a JSON POST each time a public visitor views this artifact. Leave blank to disable.', 'webmultipliers-wp-artifact-canvas' ),
				value:       webhookUrl,
				type:        'url',
				placeholder: 'https://hooks.slack.com/…',
				onChange:    function ( v ) { update( '_wmac_view_webhook_url', v ); },
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	// ---------------------------------------------------------------------------
	// Plugin registration
	// ---------------------------------------------------------------------------

	registerPlugin( 'wmac-document-panels', {
		render: function () {
			return createElement(
				Fragment,
				null,
				createElement(
					PluginDocumentSettingPanel,
					{
						name:      'wmac-artifact-settings',
						title:     __( 'Artifact Settings', 'webmultipliers-wp-artifact-canvas' ),
						className: 'wmac-artifact-settings-panel',
					},
					createElement( ArtifactSettingsPanel, null )
				),
				createElement(
					PluginDocumentSettingPanel,
					{
						name:      'wmac-lifecycle-status',
						title:     __( 'Lifecycle Status', 'webmultipliers-wp-artifact-canvas' ),
						className: 'wmac-lifecycle-panel',
					},
					createElement( LifecycleStatusPanel, null )
				),
				createElement(
					PluginDocumentSettingPanel,
					{
						name:      'wmac-ownership',
						title:     __( 'Ownership', 'webmultipliers-wp-artifact-canvas' ),
						className: 'wmac-ownership-panel',
					},
					createElement( OwnershipPanel, null )
				),
				createElement(
					PluginDocumentSettingPanel,
					{
						name:      'wmac-link-governance',
						title:     __( 'Link Governance', 'webmultipliers-wp-artifact-canvas' ),
						className: 'wmac-governance-panel',
					},
					createElement( LinkGovernancePanel, null )
				),
				createElement(
					PluginDocumentSettingPanel,
					{
						name:      'wmac-tracking',
						title:     __( 'Tracking & Alerts', 'webmultipliers-wp-artifact-canvas' ),
						className: 'wmac-tracking-panel',
					},
					createElement( TrackingPanel, null )
				)
			);
		},
	} );

} )();
