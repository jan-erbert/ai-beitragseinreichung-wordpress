<?php

defined('ABSPATH') || exit;

/** Liefert den festen lokalen Lucide-Katalog. */
function beitragseinreichung_icon_names()
{
    static $names;
    if ($names === null) {
        $names = (array) json_decode(file_get_contents(dirname(__DIR__, 2) . '/assets/lucide/names.json'), true);
    }
    return $names;
}

/** Prueft Namen ausschliesslich gegen mitgelieferte Dateien. */
function beitragseinreichung_has_icon($name)
{
    return is_string($name) && preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $name)
        && in_array($name, beitragseinreichung_icon_names(), true);
}

/** Liefert die lokal aktivierten Symbole mit fachlicher Bedeutung. */
function beitragseinreichung_symbol_pool()
{
    $default = [
        'info' => ['icon' => 'info', 'label' => 'Information', 'usage' => 'Wichtige sachliche Hinweise'],
        'termin' => ['icon' => 'calendar-days', 'label' => 'Veranstaltung', 'usage' => 'Termine und Veranstaltungen'],
    ];
    $pool = get_option('beitragseinreichung_symbol_pool', $default);
    return is_array($pool) ? $pool : $default;
}

/** Normalisiert einen editierbaren Pool; SVG oder externe URLs sind ausgeschlossen. */
function beitragseinreichung_parse_symbol_pool($text)
{
    $pool = [];
    foreach (preg_split('/\R/u', (string) $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) !== 4 || !preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $parts[0]) || !beitragseinreichung_has_icon($parts[1]) || $parts[2] === '') {
            return new WP_Error('symbol', 'Ungültiges Symbol. Erwartet: kennung | Lucide-Name | Bezeichnung | Verwendung. Lucide-Namen müssen im lokalen Katalog existieren.');
        }
        if (isset($pool[$parts[0]]) || count($pool) >= 80) {
            return new WP_Error('symbol', 'Symbolkennungen müssen eindeutig sein; maximal 80 Einträge.');
        }
        $pool[$parts[0]] = ['icon' => $parts[1], 'label' => sanitize_text_field($parts[2]), 'usage' => sanitize_text_field($parts[3])];
    }
    return $pool;
}

/** Ermittelt ausschliesslich fuer die gewaehlte Stilgruppe freigegebene Symbole. */
function beitragseinreichung_group_symbols($label)
{
    if (!get_option('beitragseinreichung_symbols_enabled', 0)) {
        return [];
    }
    foreach ((array) get_option('beitragseinreichung_ki_stilgruppen', []) as $group) {
        if (($group['label'] ?? '') === $label) {
            return array_intersect_key(beitragseinreichung_symbol_pool(), array_flip((array) ($group['symbols'] ?? [])));
        }
    }
    return [];
}

/** Beschreibt der KI eine begrenzte Symbolsprache statt freier SVG-Ausgabe. */
function beitragseinreichung_symbol_prompt($label)
{
    $pool = beitragseinreichung_group_symbols($label);
    if (!$pool) {
        return "\nKeine Symbolmarker verwenden.";
    }
    $text = "\nOptionale lokale Symbole: Setze sparsam [[symbol:kennung]] vor passende Zwischenüberschriften oder kurze Hervorhebungen. Keine HTML-, SVG- oder Bildausgabe. Nur folgende Kennungen sind erlaubt:\n";
    foreach ($pool as $key => $item) {
        $text .= $key . ': ' . $item['label'] . ' – ' . $item['usage'] . "\n";
    }
    return $text;
}

/** Entfernt nicht freigegebene KI-Marker vor Speicherung und Vorschau. */
function beitragseinreichung_filter_ai_symbols($content, $label)
{
    $allowed = beitragseinreichung_group_symbols($label);
    return preg_replace_callback('/\[\[symbol:([^\]\r\n]*)\]\]/u', function ($match) use ($allowed) {
        return isset($allowed[$match[1]]) ? $match[0] : '';
    }, $content);
}

/** Rendert feste lokale Bilder; umgebende Texte bleiben unveraendert. */
function beitragseinreichung_render_symbols($html)
{
    $pool = beitragseinreichung_symbol_pool();
    $enabled = get_option('beitragseinreichung_symbols_enabled', 0);
    $render = function ($match) use ($pool, $enabled) {
        if (!$enabled || !isset($pool[$match[1]]) || !beitragseinreichung_has_icon($pool[$match[1]]['icon'])) {
            return '';
        }
        $item = $pool[$match[1]];
        $url = plugin_dir_url(dirname(__DIR__, 2) . '/wp-form.php') . 'assets/lucide/icons/' . $item['icon'] . '.svg';
        return '<img class="beitrag-symbol" src="' . esc_url($url) . '" alt="" aria-hidden="true" width="22" height="22" style="width:1.1em;height:1.1em;vertical-align:-0.15em;margin-right:0.3em;display:inline-block;">';
    };
    $parts = wp_html_split($html);
    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) {
            $parts[$index] = preg_replace_callback('/\[\[symbol:([^\]\r\n]*)\]\]/u', $render, $part);
        }
    }
    return implode('', $parts);
}
