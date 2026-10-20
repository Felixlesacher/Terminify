/* konstanth – Gutenberg-Blöcke mit Live-Vorschau. Dynamische Blöcke: Die Ausgabe erzeugt der Server, wie beim Shortcode. */
(function (blocks, element, blockEditor, components, ServerSideRender) {
  'use strict';
  if (!blocks || !ServerSideRender) { return; }
  var el = element.createElement;
  var data = window.knBlocks || { institutions: [] };
  var defs = [
    { name: 'events', title: 'konstanth: Termine', icon: 'calendar-alt', description: 'Termine mit Filtern, Liste und Monatskalender.', layout: 'list' },
    { name: 'jobs', title: 'konstanth: Stellen', icon: 'id-alt', description: 'Offene Stellen der Einrichtungen.', layout: 'grid' },
    { name: 'institutions', title: 'konstanth: Einrichtungen', icon: 'groups', description: 'Verzeichnis der Einrichtungen.', layout: 'grid' },
    { name: 'news', title: 'konstanth: Nachrichten', icon: 'megaphone', description: 'Nachrichten der Einrichtungen.', layout: 'grid' },
    { name: 'donations', title: 'konstanth: Fördern', icon: 'heart', description: 'Förder- und Spendenangaben der Einrichtungen.', layout: 'grid' },
    { name: 'newsletter', title: 'konstanth: Rundmail-Anmeldung', icon: 'email-alt', description: 'Anmeldung zu Themen und Einrichtungen mit Bestätigung per E-Mail.', newsletter: true }
  ];
  var institutions = [{ label: 'Alle Einrichtungen', value: 0 }].concat((data.institutions || []).map(function (item) { return { label: item.title, value: item.id }; }));

  defs.forEach(function (def) {
    var attributes = { institution: { type: 'number', default: 0 } };
    if (!def.newsletter) {
      attributes.limit = { type: 'number', default: 12 };
      attributes.layout = { type: 'string', default: def.layout };
      attributes.heading = { type: 'string', default: '' };
    }
    blocks.registerBlockType('konstanth/' + def.name, {
      apiVersion: 3,
      title: def.title,
      description: def.description,
      icon: def.icon,
      category: 'konstanth',
      attributes: attributes,
      supports: { html: false },
      edit: function (props) {
        var a = props.attributes, set = props.setAttributes;
        var controls = [
          el(components.SelectControl, { key: 'institution', label: 'Einrichtung', value: a.institution, options: institutions, onChange: function (v) { set({ institution: parseInt(v, 10) || 0 }); } })
        ];
        if (!def.newsletter) {
          controls.push(
            el(components.RangeControl, { key: 'limit', label: 'Einträge pro Seite', min: 1, max: 100, value: a.limit, onChange: function (v) { set({ limit: v || 12 }); } }),
            el(components.SelectControl, { key: 'layout', label: 'Anordnung', value: a.layout, options: [{ label: 'Liste', value: 'list' }, { label: 'Raster', value: 'grid' }], onChange: function (v) { set({ layout: v }); } }),
            el(components.TextControl, { key: 'heading', label: 'Überschrift (optional)', value: a.heading, onChange: function (v) { set({ heading: v }); } })
          );
        }
        return el('div', blockEditor.useBlockProps(),
          el(blockEditor.InspectorControls, null, el(components.PanelBody, { title: 'Einstellungen', initialOpen: true }, controls)),
          // The preview is inert so clicks select the block instead of following links.
          el('div', { style: { pointerEvents: 'none' } }, el(ServerSideRender, { block: 'konstanth/' + def.name, attributes: a }))
        );
      },
      save: function () { return null; }
    });
  });
})(window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.blockEditor, window.wp && window.wp.components, window.wp && window.wp.serverSideRender);
