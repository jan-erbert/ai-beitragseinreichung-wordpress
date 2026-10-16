/* Local symbol previews and editing in the existing WordPress settings form. */
(function () {
    'use strict';
    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function button(text, handler, className = 'button') {
        const node = element('button', className, text);
        node.type = 'button';
        node.addEventListener('click', handler);
        return node;
    }
    function picture(base, name, size = 28) {
        const image = element('img');
        image.src = base + name + '.svg';
        image.alt = '';
        image.width = size;
        image.height = size;
        image.loading = 'lazy';
        return image;
    }
    function modal(title, opener) {
        const overlay = element('div', 'beitrag-symbol-layer');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        const headingId = 'beitrag-symbol-layer-title';
        overlay.setAttribute('aria-labelledby', headingId);
        const panel = element('div', 'beitrag-symbol-layer__panel');
        const header = element('div', 'beitrag-symbol-layer__header');
        const heading = element('h2', '', title);
        heading.id = headingId;
        let beforeClose = () => true;
        const oldOverflow = document.body.style.overflow;
        function close(force = false) {
            if (!force && !beforeClose()) return;
            overlay.remove();
            document.body.style.overflow = oldOverflow;
            opener.focus();
        }
        const closeButton = button('×', () => close(), 'beitrag-symbol-layer__close');
        closeButton.setAttribute('aria-label', 'Popup schließen');
        header.append(heading, closeButton);
        const body = element('div', 'beitrag-symbol-layer__body');
        const footer = element('div', 'beitrag-symbol-layer__footer');
        panel.append(header, body, footer);
        overlay.append(panel);
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        overlay.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
            if (event.key !== 'Tab') return;
            const focusable = Array.from(panel.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),select:not(:disabled)')).filter(node => node.getClientRects().length);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });
        document.body.append(overlay);
        document.body.style.overflow = 'hidden';
        closeButton.focus();
        return {body, footer, close, onClose: callback => { beforeClose = callback; }};
    }
    function tile(base, name, label, subtitle, handler) {
        const node = button('', handler, 'beitrag-symbol-tile');
        node.append(picture(base, name, 30), element('strong', '', label));
        if (subtitle) node.append(element('span', '', subtitle));
        return node;
    }
    function openManager(root, config, opener) {
        let pool = JSON.parse(root.querySelector('[name=symbol_pool_json]').value);
        if (Array.isArray(pool)) pool = {};
        const names = new Set(config.names);
        const view = modal('Symbolpool verwalten', opener);
        const layout = element('div', 'beitrag-symbol-layout');
        const collection = element('section');
        const editor = element('section', 'beitrag-symbol-editor');
        const toolbar = element('div', 'beitrag-symbol-toolbar');
        const search = element('input');
        search.type = 'search'; search.placeholder = 'Deine Symbole suchen …'; search.setAttribute('aria-label', 'Deine Symbole suchen');
        const grid = element('div', 'beitrag-symbol-grid');
        const status = element('p', 'beitrag-symbol-status');
        status.setAttribute('role', 'status');
        let selected = null;
        let editorDirty = false;
        let poolDirty = false;
        toolbar.append(search, button('Neues Symbol', () => edit(null)));
        collection.append(element('h3', '', 'Deine Symbole'), toolbar, grid);
        layout.append(collection, editor);
        view.body.append(element('p', 'description', 'Wähle ein Symbol zum Bearbeiten oder ergänze ein neues. Die Vorschau zeigt genau das Symbol, das später im Beitrag erscheint.'), layout, status);
        const fields = {};
        editor.append(element('h3', '', 'Symbol bearbeiten'));
        const preview = element('div', 'beitrag-symbol-large-preview');
        editor.append(preview);
        function field(key, label, kind = 'input', hint = '') {
            const wrapper = element('label', 'beitrag-symbol-field');
            const node = element(kind);
            if (kind === 'input') node.type = 'text';
            if (kind === 'textarea') { node.rows = 3; node.maxLength = 600; }
            if (key === 'label') node.maxLength = 80;
            if (key === 'key') node.maxLength = 40;
            wrapper.append(element('strong', '', label), node);
            if (hint) wrapper.append(element('small', 'description', hint));
            editor.append(wrapper); fields[key] = node;
            node.addEventListener('input', () => { editorDirty = true; });
            return node;
        }
        field('key', 'Feste Kennung', 'input', 'Wird bei neuen Symbolen vorgeschlagen. Bestehende Kennungen bleiben erhalten, damit Texte und Stilgruppen weiterpassen.');
        const iconField = field('icon', 'Lucide-Symbolname', 'input', 'Wähle ein Symbol aus der Vorschau oder gib einen Namen aus dem lokalen Katalog ein.');
        field('label', 'Bezeichnung für Redaktion und KI');
        field('usage', 'Wann passt dieses Symbol?', 'textarea', 'Zum Beispiel: Wandern und Spaziergänge; nicht für Laufberichte verwenden.');
        const flags = element('fieldset', 'beitrag-symbol-flags');
        flags.append(element('legend', '', 'Verwendung und Freigaben'));
        const flagLabels = {manual: 'Im manuellen Formular verfügbar', ai: 'Für die KI-Auswahl freigeben', inline: 'Im Text verwendbar', heading: 'Für Zwischenüberschriften verwendbar'};
        Object.entries(flagLabels).forEach(([key, label]) => {
            const wrapper = element('label');
            const node = element('input'); node.type = 'checkbox'; fields[key] = node;
            wrapper.append(node, document.createTextNode(' ' + label)); flags.append(wrapper);
            node.addEventListener('change', () => { editorDirty = true; });
        });
        editor.append(flags);
        const catalog = element('details', 'beitrag-symbol-catalog');
        catalog.append(element('summary', '', 'Lucide-Symbole mit Vorschau durchsuchen'));
        const catalogSearch = element('input'); catalogSearch.type = 'search'; catalogSearch.placeholder = 'z. B. trophy, footprints, bike …'; catalogSearch.setAttribute('aria-label', 'Lucide-Katalog durchsuchen');
        const catalogGrid = element('div', 'beitrag-symbol-grid');
        let visibleCount = 48;
        const more = button('Weitere Symbole anzeigen', () => { visibleCount += 48; renderCatalog(); });
        catalog.append(catalogSearch, catalogGrid, more);
        // Die Namensauswahl bleibt nahe an ihrem Eingabefeld.
        iconField.parentElement.after(catalog);
        function renderCatalog() {
            const term = catalogSearch.value.trim().toLowerCase();
            const matches = config.names.filter(name => name.includes(term));
            catalogGrid.replaceChildren();
            matches.slice(0, visibleCount).forEach(name => {
                const item = tile(config.base, name, name, '', () => {
                    iconField.value = name; iconField.setCustomValidity(''); editorDirty = true;
                    if (!fields.key.value && selected === null) fields.key.value = name;
                    renderPreview(); iconField.focus();
                });
                catalogGrid.append(item);
            });
            if (!matches.length) catalogGrid.append(element('p', '', 'Kein passendes Symbol gefunden.'));
            more.hidden = matches.length <= visibleCount;
        }
        catalog.addEventListener('toggle', () => { if (catalog.open) renderCatalog(); });
        catalogSearch.addEventListener('input', () => { visibleCount = 48; renderCatalog(); });
        function renderPreview() {
            preview.replaceChildren();
            const name = iconField.value.trim();
            if (names.has(name)) preview.append(picture(config.base, name, 52), element('span', '', fields.label.value || name));
            else preview.append(element('span', 'description', 'Wähle einen gültigen Lucide-Symbolnamen für die Vorschau.'));
        }
        iconField.addEventListener('input', () => { iconField.setCustomValidity(''); renderPreview(); });
        fields.label.addEventListener('input', renderPreview);
        function renderPool() {
            grid.replaceChildren();
            const term = search.value.trim().toLowerCase();
            Object.entries(pool).filter(([key, item]) => (key + ' ' + item.label + ' ' + item.usage).toLowerCase().includes(term)).forEach(([key, item]) => {
                const node = tile(config.base, item.icon, item.label, item.usage, () => edit(key));
                node.setAttribute('aria-pressed', String(selected === key));
                grid.append(node);
            });
            if (!grid.children.length) grid.append(element('p', 'description', 'Noch kein passendes Symbol. Ergänze über „Neues Symbol“ einen Eintrag.'));
        }
        function edit(key) {
            if (editorDirty && !window.confirm('Nicht übernommene Änderungen an diesem Symbol verwerfen?')) return;
            selected = key;
            const item = key !== null ? pool[key] : {icon: '', label: '', usage: ''};
            fields.key.value = key || ''; fields.key.readOnly = key !== null;
            ['icon', 'label', 'usage'].forEach(name => { fields[name].value = item[name] || ''; });
            Object.keys(flagLabels).forEach(name => { fields[name].checked = item[name] !== false; });
            editorDirty = false; status.textContent = '';
            renderPreview(); renderPool();
            const activeTile = grid.querySelector('[aria-pressed="true"]');
            if (activeTile) activeTile.focus(); else iconField.focus();
        }
        function saveEntry() {
            const key = fields.key.value.trim() || iconField.value.trim();
            const icon = iconField.value.trim();
            if (!/^[a-z][a-z0-9_-]{0,39}$/.test(key) || (selected === null && Object.prototype.hasOwnProperty.call(pool, key))) {
                status.textContent = 'Bitte verwende eine eindeutige Kennung mit Kleinbuchstaben, Zahlen, Bindestrich oder Unterstrich.'; fields.key.focus(); return false;
            }
            if (!names.has(icon)) {
                iconField.setCustomValidity('Bitte wähle einen Symbolnamen aus dem lokalen Lucide-Katalog.'); iconField.reportValidity(); return false;
            }
            if (!fields.label.value.trim()) { status.textContent = 'Bitte gib eine Bezeichnung ein.'; fields.label.focus(); return false; }
            if (selected === null && Object.keys(pool).length >= 80) { status.textContent = 'Es sind maximal 80 Symbole möglich.'; return false; }
            const item = {icon, label: fields.label.value.trim(), usage: fields.usage.value.trim()};
            Object.keys(flagLabels).forEach(name => { item[name] = fields[name].checked; });
            pool[key] = item; fields.key.value = key; fields.key.readOnly = true;
            selected = key; editorDirty = false; poolDirty = true;
            status.textContent = 'Symbol übernommen. Speichere anschließend den Symbolpool.'; renderPool();
            return true;
        }
        const actions = element('div', 'beitrag-symbol-toolbar');
        actions.append(button('Symbol übernehmen', saveEntry, 'button button-secondary'), button('Symbol entfernen', () => {
            if (selected === null) return;
            if (!window.confirm('Dieses Symbol aus dem Pool entfernen? Bereits gespeicherte Bilder bleiben bestehen. Prüfe anschließend die zugehörigen Stilgruppen.')) return;
            delete pool[selected]; editorDirty = false; poolDirty = true; edit(null);
        }, 'button button-link-delete'));
        editor.append(actions);
        search.addEventListener('input', renderPool);
        view.onClose(() => !(editorDirty || poolDirty) || window.confirm('Nicht gespeicherte Änderungen am Symbolpool verwerfen?'));
        view.footer.append(element('span', 'description', 'Speichern übernimmt auch die übrigen Einstellungen dieser Seite.'), button('Abbrechen', () => view.close()), button('Symbolpool speichern', () => {
            if (editorDirty && !saveEntry()) return;
            root.querySelector('[name=symbol_pool_json]').value = JSON.stringify(pool);
            const form = root.closest('form');
            view.close(true);
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        }, 'button button-primary'));
        edit(Object.keys(pool)[0] || null);
    }
    function openPicker(root, config, opener) {
        const field = document.getElementById('beitrag_inhalt');
        if (!field) return;
        const start = field.selectionStart;
        const end = field.selectionEnd;
        const heading = /^\s*#{1,6}\s+/.test(field.value.slice(field.value.lastIndexOf('\n', start - 1) + 1, start));
        const view = modal('Symbol für deinen Beitrag auswählen', opener);
        const search = element('input'); search.type = 'search'; search.placeholder = 'Symbol suchen …'; search.setAttribute('aria-label', 'Symbol suchen');
        const grid = element('div', 'beitrag-symbol-grid');
        const hint = element('p', 'description', heading ? 'Einfügen in eine Zwischenüberschrift.' : 'Einfügen in den Text. Für eine Zwischenüberschrift setze den Cursor hinter ##.');
        view.body.append(search, hint, grid);
        function render() {
            grid.replaceChildren();
            const term = search.value.toLowerCase();
            Object.entries(config.pool).filter(([, item]) => (item.label + ' ' + item.usage).toLowerCase().includes(term)).forEach(([key, item]) => {
                const node = tile(config.base, item.icon, item.label, item.usage, () => {
                    field.setSelectionRange(start, end);
                    field.setRangeText('[[symbol:' + key + ']] ', start, end, 'end');
                    field.dispatchEvent(new Event('input', {bubbles: true}));
                    view.close(true); field.focus();
                });
                if (item[heading ? 'heading' : 'inline'] === false) { node.disabled = true; node.title = heading ? 'Nur im Text verfügbar' : 'Nur für Zwischenüberschriften verfügbar'; node.append(element('small', '', node.title)); }
                grid.append(node);
            });
            if (!grid.children.length) grid.append(element('p', '', 'Keine passenden Symbole verfügbar.'));
        }
        search.addEventListener('input', render); render(); search.focus();
        view.footer.append(button('Schließen', () => view.close()));
    }
    document.querySelectorAll('.beitrag-symbol-manager,.beitrag-symbol-picker').forEach(root => {
        const config = JSON.parse(root.dataset.config);
        const opener = root.querySelector('.beitrag-symbol-open');
        opener.addEventListener('click', () => {
            if (root.classList.contains('beitrag-symbol-manager')) openManager(root, config, opener);
            else openPicker(root, config, opener);
        });
    });
})();
