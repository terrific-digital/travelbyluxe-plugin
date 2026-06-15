/* Terrific Form — Gutenberg block (no build step; uses wp.element.createElement) */
( function ( blocks, element, components, blockEditor, i18n, serverSideRender ) {
	'use strict';
	var el = element.createElement;
	var Fragment = element.Fragment;
	var registerBlockType = blocks.registerBlockType;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;
	var Button = components.Button;
	var PanelBody = components.PanelBody;
	var ExternalLink = components.ExternalLink;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var ServerSideRender = serverSideRender; // default export is the component

	var data = window.TF_BLOCK || { forms: [], newUrl: '' };
	var options = [ { label: '— Select a form —', value: '' } ].concat(
		( data.forms || [] ).map( function ( f ) {
			return { label: f.title + ( f.form_id ? ' (' + f.form_id + ')' : '' ), value: String( f.id ) };
		} )
	);
	function findForm( id ) {
		return ( data.forms || [] ).find( function ( f ) { return String( f.id ) === String( id ); } );
	}

	registerBlockType( 'terrific-digital/form', {
		apiVersion: 2,
		title: 'Terrific Form',
		description: 'Display a form built with Terrific Forms.',
		category: 'terrific-digital',
		icon: 'feedback',
		keywords: [ 'form', 'terrific', 'contact', 'lead' ],
		supports: { html: false, align: [ 'wide', 'full' ] },
		attributes: { formId: { type: 'string', default: '' } },

		edit: function ( props ) {
			var formId = props.attributes.formId;
			var selected = findForm( formId );

			// Sidebar: choose form + edit/create links.
			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: 'Form', initialOpen: true },
					el( SelectControl, {
						label: 'Which form',
						value: formId,
						options: options,
						onChange: function ( v ) { props.setAttributes( { formId: v } ); }
					} ),
					selected && el( 'p', { key: 'edit', style: { marginTop: '8px' } },
						el( ExternalLink, { href: selected.edit_url }, 'Edit this form' )
					),
					el( 'p', { key: 'new', style: { marginTop: '4px' } },
						el( ExternalLink, { href: data.newUrl }, 'Create a new form' )
					)
				)
			);

			var body;
			if ( selected ) {
				// Quick-edit bar + live server-rendered preview.
				body = el( 'div', { className: 'tf-block-shell', style: { border: '1px solid #e0e0e0', borderRadius: '6px', overflow: 'hidden' } },
					el( 'div', {
						className: 'tf-block-bar',
						style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', padding: '8px 12px', background: '#f6f7f7', borderBottom: '1px solid #e0e0e0', fontSize: '13px' }
					},
						el( 'span', null, el( 'span', { className: 'dashicons dashicons-feedback', style: { verticalAlign: 'middle', marginRight: '4px' } } ), el( 'strong', null, selected.title ), selected.form_id ? el( 'code', { style: { marginLeft: '8px', fontSize: '11px' } }, selected.form_id ) : null ),
						el( ExternalLink, { href: selected.edit_url, className: 'tf-block-edit' }, 'Edit form' )
					),
					el( 'div', {
						className: 'tf-block-preview',
						// Display-only: pointer-events blocks the mouse; `inert` (set via ref so it
						// also covers content ServerSideRender injects later) blocks focus/keyboard/submit.
						style: { padding: '20px', pointerEvents: 'none', userSelect: 'none' },
						'aria-hidden': 'true',
						ref: function ( node ) { if ( node ) { node.inert = true; } }
					},
						el( ServerSideRender, { block: 'terrific-digital/form', attributes: { formId: formId } } )
					)
				);
			} else {
				body = el( Placeholder, {
					icon: 'feedback',
					label: 'Terrific Form',
					instructions: 'Choose which form to display on this page.'
				},
					el( SelectControl, { value: formId, options: options, onChange: function ( v ) { props.setAttributes( { formId: v } ); } } ),
					( ! data.forms || ! data.forms.length ) && el( Button, { variant: 'secondary', href: data.newUrl, target: '_blank' }, 'Create a form' )
				);
			}

			return el( 'div', useBlockProps(), inspector, body );
		},

		save: function () { return null; } // dynamic — rendered by PHP
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.i18n, window.wp.serverSideRender );
