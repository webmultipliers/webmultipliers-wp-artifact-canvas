/**
 * Document-level sidebar panels for WP Artifact Canvas.
 *
 * Two quick-reference panels in the Post sidebar (Document tab):
 *   • Lifecycle Status — custom + core status picker
 *   • Ownership        — assign a responsible developer / PM (WP user)
 *
 * Everything else (Settings, Governance, Tracking, Merge Tags, Asset Mapping)
 * lives in the full-width tabbed metabox below the editor (management-metaboxes.js).
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

	var SelectControl   = components.SelectControl;
	var ComboboxControl = components.ComboboxControl;
	var TextControl     = components.TextControl;
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

		var _dispatch  = useDispatch( 'core/editor' );
		var editPostFn = _dispatch && _dispatch.editPost;

		return createElement(
			'div',
			{ className: 'wmac-lifecycle-panel' },
			createElement( SelectControl, {
				label:                   __( 'Lifecycle Status', 'webmultipliers-wp-artifact-canvas' ),
				value:                   currentStatus,
				options:                 ALL_STATUSES,
				onChange:                function ( v ) {
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

		var ownerId = parseInt( meta[ '_wmac_owner_id' ] || '0', 10 ) || 0;

		var users = useSelect( function ( select ) {
			return select( 'core' ).getUsers( { who: 'authors', per_page: 100 } ) || [];
		} );

		var userOptions  = users.map( function ( u ) {
			return { value: String( u.id ), label: u.name };
		} );
		var currentValue = ownerId ? String( ownerId ) : '';

		if ( ! ComboboxControl ) {
			return createElement( TextControl, {
				label:                   __( 'Owner (User ID)', 'webmultipliers-wp-artifact-canvas' ),
				value:                   currentValue,
				type:                    'number',
				onChange:                function ( v ) {
					// Pass ONLY the changed key. Passing the whole meta object
					// marks every key as a pending edit, so the next editor
					// Update overwrites anything the metaboxes saved via REST
					// (merge tags, asset map, …) with stale page-load values.
					setMeta( { _wmac_owner_id: parseInt( v, 10 ) || 0 } );
				},
				__nextHasNoMarginBottom: true,
			} );
		}

		return createElement(
			'div',
			{ className: 'wmac-ownership-panel' },
			createElement( ComboboxControl, {
				label:                   __( 'Owner / Project Manager', 'webmultipliers-wp-artifact-canvas' ),
				value:                   currentValue || null,
				options:                 userOptions,
				allowReset:              true,
				onChange:                function ( v ) {
					// Only the changed key — see the note in the TextControl fallback.
					setMeta( { _wmac_owner_id: parseInt( v, 10 ) || 0 } );
				},
				onFilterValueChange:     function () {},
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
				)
			);
		},
	} );

} )();
