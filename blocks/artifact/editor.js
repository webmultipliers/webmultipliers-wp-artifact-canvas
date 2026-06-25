( function ( blocks, blockEditor, components, element, i18n ) {
	var registerBlockType = blocks.registerBlockType;
	var useBlockProps = blockEditor.useBlockProps;
	var PlainText = blockEditor.PlainText;
	var Button = components.Button;
	var createElement = element.createElement;
	var useState = element.useState;
	var __ = i18n.__;

	function Edit( props ) {
		var html = props.attributes.html;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps();
		var _useState = useState( false );
		var previewing = _useState[ 0 ];
		var setPreviewing = _useState[ 1 ];
		var body;

		if ( previewing ) {
			body = createElement( 'iframe', {
				className: 'wmac-preview',
				sandbox: 'allow-scripts',
				srcDoc: html,
				title: __( 'Artifact Preview', 'webmultipliers-wp-artifact-canvas' ),
			} );
		} else {
			body = createElement( PlainText, {
				className: 'wmac-code',
				value: html,
				onChange: function ( value ) {
					setAttributes( { html: value } );
				},
				placeholder: __(
					'Paste your complete HTML document here…',
					'webmultipliers-wp-artifact-canvas'
				),
				'aria-label': __( 'Artifact HTML', 'webmultipliers-wp-artifact-canvas' ),
			} );
		}

		return createElement(
			'div',
			blockProps,
			createElement(
				'div',
				{ className: 'wmac-toolbar' },
				createElement(
					Button,
					{
						variant: 'tertiary',
						size: 'small',
						onClick: function () {
							setPreviewing( function ( value ) {
								return ! value;
							} );
						},
					},
					previewing
						? __( 'Edit', 'webmultipliers-wp-artifact-canvas' )
						: __( 'Preview', 'webmultipliers-wp-artifact-canvas' )
				)
			),
			body
		);
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
				type: 'string',
				default: '',
			},
		},
		supports: {
			html: false,
			reusable: false,
			lock: false,
		},
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.blockEditor, wp.components, wp.element, wp.i18n );
