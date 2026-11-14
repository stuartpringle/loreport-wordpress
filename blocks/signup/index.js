/* The "LorePort signup" block in the editor. Plain JavaScript, no build step:
   it uses the editor's own React (wp.element) and components. The form itself
   is drawn by PHP (LorePort_Form::render), shown here as a preview. */
( function ( wp ) {
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Notice = wp.components.Notice;
	var ServerSideRender = wp.serverSideRender;

	function useLists() {
		var state = useState( null );
		useEffect( function () {
			wp.apiFetch( { path: '/loreport/v1/lists' } )
				.then( function ( lists ) { state[ 1 ]( lists ); } )
				.catch( function () { state[ 1 ]( [] ); } );
		}, [] );
		return state[ 0 ];
	}

	wp.blocks.registerBlockType( 'loreport/signup', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var lists = useLists();
			var options = [ { label: __( 'The default list', 'loreport' ), value: '' } ].concat(
				( lists || [] ).map( function ( list ) { return { label: list.name, value: list.id }; } )
			);

			return el( 'div', useBlockProps(),
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Signup form', 'loreport' ) },
						lists && lists.length === 0 && el( Notice, { status: 'warning', isDismissible: false },
							__( 'Your LorePort lists couldn\'t be loaded. Check Settings → LorePort.', 'loreport' ) ),
						el( SelectControl, { label: __( 'List', 'loreport' ), value: a.listId, options: options, onChange: function ( v ) { set( { listId: v } ); } } ),
						el( TextControl, { label: __( 'Heading (optional)', 'loreport' ), value: a.title, onChange: function ( v ) { set( { title: v } ); } } ),
						el( TextareaControl, { label: __( 'Description (optional)', 'loreport' ), help: __( 'A line under the heading, such as what readers get.', 'loreport' ), value: a.description, rows: 2, onChange: function ( v ) { set( { description: v } ); } } ),
						el( SelectControl, { label: __( 'Look', 'loreport' ), value: a.formStyle, options: [
							{ label: __( 'Card (a soft panel)', 'loreport' ), value: 'card' },
							{ label: __( 'Plain (just the fields)', 'loreport' ), value: 'plain' },
						], onChange: function ( v ) { set( { formStyle: v } ); } } ),
						el( SelectControl, { label: __( 'Layout', 'loreport' ), value: a.layout, options: [
							{ label: __( 'Stacked', 'loreport' ), value: 'stacked' },
							{ label: __( 'Inline (email and button on one line)', 'loreport' ), value: 'inline' },
						], onChange: function ( v ) { set( { layout: v } ); } } ),
						el( ToggleControl, { label: __( 'Ask for their name', 'loreport' ), checked: a.showName, onChange: function ( v ) { set( { showName: v } ); } } ),
						el( TextControl, { label: __( 'Button text', 'loreport' ), placeholder: __( 'Subscribe', 'loreport' ), value: a.buttonText, onChange: function ( v ) { set( { buttonText: v } ); } } )
					)
				),
				el( 'div', { style: { pointerEvents: 'none' } },
					el( ServerSideRender, { block: 'loreport/signup', attributes: a } )
				)
			);
		},
		save: function () {
			return null; // Drawn by PHP when the page is shown.
		},
	} );
}( window.wp ) );
