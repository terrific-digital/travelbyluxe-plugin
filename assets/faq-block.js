/* TBL FAQ — Gutenberg block (no build step; uses wp.element.createElement) */
( function ( blocks, element, components, blockEditor, i18n, serverSideRender ) {
	'use strict';
	var el = element.createElement;
	var registerBlockType = blocks.registerBlockType;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;
	var Button = components.Button;
	var PanelBody = components.PanelBody;
	var ExternalLink = components.ExternalLink;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var ServerSideRender = serverSideRender;

	var data = window.TD_FAQ_BLOCK || { lists: [], newUrl: '' };
	var options = [ { label: '— Select a FAQ list —', value: '' } ].concat(
		( data.lists || [] ).map( function ( f ) {
			return { label: f.title + ' (' + f.count + ' Q)', value: String( f.id ) };
		} )
	);
	function findList( id ) {
		return ( data.lists || [] ).find( function ( f ) { return String( f.id ) === String( id ); } );
	}

	registerBlockType( 'terrific-digital/faq', {
		apiVersion: 2,
		title: 'TBL FAQ',
		description: 'Display a FAQ list built with Travel by Luxe.',
		category: 'terrific-digital',
		icon: 'editor-help',
		keywords: [ 'faq', 'terrific', 'accordion', 'questions' ],
		supports: { html: false, align: [ 'wide', 'full' ] },
		attributes: { faqId: { type: 'string', default: '' } },

		edit: function ( props ) {
			var faqId = props.attributes.faqId;
			var selected = findList( faqId );

			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: 'FAQ list', initialOpen: true },
					el( SelectControl, {
						label: 'Which list',
						value: faqId,
						options: options,
						onChange: function ( v ) { props.setAttributes( { faqId: v } ); }
					} ),
					selected && el( 'p', { key: 'edit', style: { marginTop: '8px' } },
						el( ExternalLink, { href: selected.edit_url }, 'Edit this FAQ list' )
					),
					el( 'p', { key: 'new', style: { marginTop: '4px' } },
						el( ExternalLink, { href: data.newUrl }, 'Create a new FAQ list' )
					)
				)
			);

			var body;
			if ( selected ) {
				body = el( 'div', { className: 'tf-block-shell', style: { border: '1px solid #e0e0e0', borderRadius: '6px', overflow: 'hidden' } },
					el( 'div', {
						className: 'tf-block-bar',
						style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', padding: '8px 12px', background: '#f6f7f7', borderBottom: '1px solid #e0e0e0', fontSize: '13px' }
					},
						el( 'span', null, el( 'span', { className: 'dashicons dashicons-editor-help', style: { verticalAlign: 'middle', marginRight: '4px' } } ), el( 'strong', null, selected.title ), el( 'code', { style: { marginLeft: '8px', fontSize: '11px' } }, selected.count + ' questions' ) ),
						el( ExternalLink, { href: selected.edit_url, className: 'tf-block-edit' }, 'Edit FAQ' )
					),
					el( 'div', {
						className: 'tf-block-preview',
						style: { padding: '20px', pointerEvents: 'none', userSelect: 'none' },
						'aria-hidden': 'true',
						ref: function ( node ) { if ( node ) { node.inert = true; } }
					},
						el( ServerSideRender, { block: 'terrific-digital/faq', attributes: { faqId: faqId } } )
					)
				);
			} else {
				body = el( Placeholder, {
					icon: 'editor-help',
					label: 'TBL FAQ',
					instructions: 'Choose which FAQ list to display on this page.'
				},
					el( SelectControl, { value: faqId, options: options, onChange: function ( v ) { props.setAttributes( { faqId: v } ); } } ),
					( ! data.lists || ! data.lists.length ) && el( Button, { variant: 'secondary', href: data.newUrl, target: '_blank' }, 'Create a FAQ list' )
				);
			}

			return el( 'div', useBlockProps(), inspector, body );
		},

		save: function () { return null; } // dynamic — rendered by PHP
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.i18n, window.wp.serverSideRender );
