/**
 * Kontor Shop - Bloecke fuer den Block-Editor (ohne Build-Schritt).
 * Gerendert wird serverseitig (Modules\Blocks\BlocksModule); im Editor
 * steht nur eine Platzhalter-Karte, beim Shop-Block mit Einstellungen.
 */
(function (wp, config) {
    'use strict';

    if (!wp || !wp.blocks || !config) {
        return;
    }

    var el = wp.element.createElement;
    var be = wp.blockEditor;
    var c = wp.components;

    function placeholder(name, data, props) {
        return el('div', be.useBlockProps({ className: 'ks-block-placeholder' }),
            el(c.Placeholder, {
                icon: data.icon,
                label: data.title,
                instructions: data.description + ' ' + config.i18n.preview
            }, name === 'kontor-shop/catalog' && (props.attributes.shop || props.attributes.perPage)
                ? el('code', null, '[kontor_shop_catalog'
                    + (props.attributes.shop ? ' shop="' + props.attributes.shop + '"' : '')
                    + (props.attributes.perPage ? ' per_page="' + props.attributes.perPage + '"' : '') + ']')
                : null)
        );
    }

    Object.keys(config.blocks).forEach(function (name) {
        var data = config.blocks[name];
        var isCatalog = name === 'kontor-shop/catalog';

        wp.blocks.registerBlockType(name, {
            apiVersion: 3,
            title: data.title,
            description: data.description,
            icon: data.icon,
            category: config.category,
            attributes: isCatalog ? { shop: { type: 'string', default: '' }, perPage: { type: 'number', default: 0 } } : {},
            supports: { html: false, multiple: name !== 'kontor-shop/checkout' },
            edit: function (props) {
                if (!isCatalog) {
                    return placeholder(name, data, props);
                }

                return el(wp.element.Fragment, null,
                    el(be.InspectorControls, null,
                        el(c.PanelBody, { title: config.i18n.settings },
                            el(c.TextControl, {
                                label: config.i18n.shop,
                                help: config.i18n.shopHelp,
                                value: props.attributes.shop,
                                onChange: function (value) { props.setAttributes({ shop: value }); }
                            }),
                            el(c.TextControl, {
                                type: 'number',
                                min: 0,
                                max: 200,
                                label: config.i18n.perPage,
                                value: props.attributes.perPage,
                                onChange: function (value) { props.setAttributes({ perPage: Math.max(0, parseInt(value, 10) || 0) }); }
                            })
                        )
                    ),
                    placeholder(name, data, props)
                );
            },
            save: function () {
                return null;
            }
        });
    });
})(window.wp, window.kontorShopBlocks);
