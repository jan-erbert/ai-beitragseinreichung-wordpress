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
    $pool = is_array($pool) ? $pool : $default;
    foreach ($pool as &$item) {
        $item += ['manual' => true, 'ai' => true, 'inline' => true, 'heading' => true];
    }
    unset($item);
    return $pool;
}

/** Validiert strukturierte Symbolfelder einschliesslich lokaler Dateinamen. */
function beitragseinreichung_validate_symbol_pool(array $entries)
{
    if (count($entries) > 80) {
        return new WP_Error('symbol', 'Es sind maximal 80 Symbole erlaubt.');
    }
    $pool = [];
    foreach ($entries as $key => $item) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', (string) $key) || !is_array($item)
            || !beitragseinreichung_has_icon($item['icon'] ?? '')
            || !is_string($item['label'] ?? null) || trim($item['label']) === ''
            || !is_string($item['usage'] ?? null) || strlen($item['label']) > 240 || strlen($item['usage']) > 2000) {
            return new WP_Error('symbol', 'Bitte prüfe Symbolname, Bezeichnung und Verwendung. Erlaubt sind nur Symbole aus dem lokalen Lucide-Katalog.');
        }
        $pool[$key] = ['icon' => $item['icon'], 'label' => sanitize_text_field($item['label']), 'usage' => sanitize_textarea_field($item['usage'])];
        foreach (['manual', 'ai', 'inline', 'heading'] as $flag) {
            if (isset($item[$flag]) && !is_bool($item[$flag])) {
                return new WP_Error('symbol', 'Die Freigaben eines Symbols sind ungültig.');
            }
            $pool[$key][$flag] = $item[$flag] ?? true;
        }
    }
    return $pool;
}

/** Liest alte Zeilenformate weiterhin fuer vorhandene Installationen. */
function beitragseinreichung_parse_symbol_pool($text)
{
    $pool = [];
    foreach (preg_split('/\R/u', (string) $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) !== 4 || isset($pool[$parts[0]])) {
            return new WP_Error('symbol', 'Bitte prüfe die Symbolfelder und eindeutigen Kennungen.');
        }
        $pool[$parts[0]] = ['icon' => $parts[1], 'label' => $parts[2], 'usage' => $parts[3]];
    }
    return beitragseinreichung_validate_symbol_pool($pool);
}

/** Liest die Formularstruktur und akzeptiert das bisherige Textformat als Fallback. */
function beitragseinreichung_symbol_pool_from_input(array $source)
{
    if (isset($source['symbol_pool_json'])) {
        $json = wp_unslash($source['symbol_pool_json']);
        if (!is_string($json) || strlen($json) > 250000) {
            return new WP_Error('symbol', 'Der Symbolpool ist zu groß oder ungültig.');
        }
        $entries = json_decode($json, true, 8);
        if (!is_array($entries) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('symbol', 'Der Symbolpool konnte nicht gelesen werden. Bitte lade die Einstellungen erneut.');
        }
        return beitragseinreichung_validate_symbol_pool($entries);
    }
    if (isset($source['symbol_pool'])) {
        return beitragseinreichung_parse_symbol_pool(sanitize_textarea_field(wp_unslash($source['symbol_pool'])));
    }
    return beitragseinreichung_symbol_pool();
}

/** Ermittelt ausschliesslich fuer die gewaehlte Stilgruppe freigegebene Symbole. */
function beitragseinreichung_group_symbols($label)
{
    if (!get_option('beitragseinreichung_symbols_enabled', 0)) {
        return [];
    }
    foreach ((array) get_option('beitragseinreichung_ki_stilgruppen', []) as $group) {
        if (($group['label'] ?? '') === $label) {
            return array_filter(array_intersect_key(beitragseinreichung_symbol_pool(), array_flip((array) ($group['symbols'] ?? []))), function ($item) {
                return $item['ai'] && ($item['inline'] || $item['heading']);
            });
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
        $text .= $key . ': ' . $item['label'] . ' – ' . $item['usage'] . ' (erlaubt: ' . implode(', ', array_keys(array_filter(['Fließtext' => $item['inline'], 'Zwischenüberschrift' => $item['heading']]))) . ")\n";
    }
    return $text;
}

/** Entfernt nicht freigegebene KI-Marker vor Speicherung und Vorschau. */
function beitragseinreichung_filter_ai_symbols($content, $label)
{
    $allowed = beitragseinreichung_group_symbols($label);
    $lines = explode("\n", $content);
    foreach ($lines as &$line) {
        $heading = preg_match('/^\s*#{1,6}\s+/', $line);
        $line = preg_replace_callback('/\[\[symbol:([^\]\r\n]*)\]\]/u', function ($match) use ($allowed, $heading) {
            return isset($allowed[$match[1]]) && $allowed[$match[1]][$heading ? 'heading' : 'inline'] ? $match[0] : '';
        }, $line);
    }
    unset($line);
    return implode("\n", $lines);
}

/** Rendert feste lokale Bilder; umgebende Texte bleiben unveraendert. */
function beitragseinreichung_render_symbols($html)
{
    $pool = beitragseinreichung_symbol_pool();
    $enabled = get_option('beitragseinreichung_symbols_enabled', 0);
    $heading = false;
    $render = function ($match) use ($pool, $enabled, &$heading) {
        if (!$enabled || !isset($pool[$match[1]]) || !beitragseinreichung_has_icon($pool[$match[1]]['icon'])) {
            return '';
        }
        $item = $pool[$match[1]];
        if (!$item[$heading ? 'heading' : 'inline']) {
            return '';
        }
        $url = plugin_dir_url(dirname(__DIR__, 2) . '/wp-form.php') . 'assets/lucide/icons/' . $item['icon'] . '.svg';
        return '<img class="beitrag-symbol" src="' . esc_url($url) . '" alt="" aria-hidden="true" width="22" height="22" style="width:1.1em;height:1.1em;vertical-align:-0.15em;margin-right:0.3em;display:inline-block;">';
    };
    $parts = wp_html_split($html);
    foreach ($parts as $index => $part) {
        if ($index % 2 !== 0 && preg_match('/^<\/?h[1-6](?:\s|>)/i', $part)) {
            $heading = strpos($part, '</') !== 0;
        }
        if ($index % 2 === 0) {
            $parts[$index] = preg_replace_callback('/\[\[symbol:([^\]\r\n]*)\]\]/u', $render, $part);
        }
    }
    return implode('', $parts);
}
