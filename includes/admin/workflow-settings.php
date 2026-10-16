<?php

defined('ABSPATH') || exit;

/** Validiert neue Einstellungen, bevor bestehende Optionen geaendert werden. */
function beitragseinreichung_validate_workflow_settings(array $source)
{
    $text = isset($source['symbol_pool']) ? sanitize_textarea_field(wp_unslash($source['symbol_pool'])) : '';
    $pool = beitragseinreichung_parse_symbol_pool($text);
    return is_wp_error($pool) ? $pool : null;
}

/** Speichert die gezielt ergaenzten Workflow- und KI-Optionen. */
function beitragseinreichung_save_workflow_settings(array $source)
{
    $mode = sanitize_key($source['completion_mode'] ?? 'review');
    update_option('beitragseinreichung_completion_mode', $mode === 'publish' ? 'publish' : 'review');
    update_option('beitragseinreichung_require_featured_image', !empty($source['require_featured_image']) ? 1 : 0);
    foreach (['ki_min_words' => [30, 1, 500], 'ki_max_chars' => [40000, 500, 40000], 'ki_hourly_limit' => [20, 1, 200]] as $key => $bounds) {
        update_option('beitragseinreichung_' . $key, max($bounds[1], min($bounds[2], (int) ($source[$key] ?? $bounds[0]))));
    }
    update_option('beitragseinreichung_symbols_enabled', !empty($source['symbols_enabled']) ? 1 : 0);
    $pool = beitragseinreichung_parse_symbol_pool(sanitize_textarea_field(wp_unslash($source['symbol_pool'] ?? '')));
    if (!is_wp_error($pool)) {
        update_option('beitragseinreichung_symbol_pool', $pool);
    }
}

/** Zeigt neue Optionen im vorhandenen Einstellungsformular. */
function beitragseinreichung_workflow_settings_fields()
{
    $mode = get_option('beitragseinreichung_completion_mode', 'review');
    ?>
    <tr><th scope="row">Abschluss der Einreichung</th><td>
        <select name="completion_mode" aria-label="Abschlussmodus">
            <option value="review" <?php selected($mode, 'review'); ?>>Zur Prüfung einreichen</option>
            <option value="publish" <?php selected($mode, 'publish'); ?>>Direkt veröffentlichen</option>
        </select>
        <p class="description">Nur diese Hauptaktion wird angezeigt; ein Entwurf bleibt möglich. Direkt veröffentlichen erfordert zusätzlich das WordPress-Recht publish_posts.</p>
        <label><input type="checkbox" name="require_featured_image" value="1" <?php checked(get_option('beitragseinreichung_require_featured_image', 0), 1); ?>> Veröffentlichung nur mit Beitragsbild</label>
    </td></tr>
    <tr><th scope="row">Grenzen für KI-Anfragen</th><td>
        <?php foreach (['ki_min_words' => ['Mindestens Wörter im Ausgangstext', 30, 1, 500], 'ki_max_chars' => ['Maximal Zeichen im Ausgangstext', 40000, 500, 40000], 'ki_hourly_limit' => ['Maximal Anfragen je Benutzer und Stunde', 20, 1, 200]] as $key => $field): ?>
            <p><label><?php echo esc_html($field[0]); ?><br><input type="number" name="<?php echo esc_attr($key); ?>" min="<?php echo esc_attr($field[2]); ?>" max="<?php echo esc_attr($field[3]); ?>" value="<?php echo esc_attr(beitragseinreichung_limit($key, $field[1], $field[2], $field[3])); ?>"></label></p>
        <?php endforeach; ?>
        <p class="description">Auch erneute KI-Überarbeitungen zählen. Die manuelle Vorschau bleibt verfügbar.</p>
    </td></tr>
    <tr><th scope="row">Lokaler Symbolpool</th><td>
        <label><input type="checkbox" name="symbols_enabled" value="1" <?php checked(get_option('beitragseinreichung_symbols_enabled', 0), 1); ?>> Symbole für manuelle Texte und KI aktivieren</label>
        <p class="description">Optional und standardmäßig deaktiviert. Im Text: <code>[[symbol:info]]</code>. Die KI erhält nur die in der Stilgruppe freigegebenen Symbole. Bilder werden lokal geladen.</p>
        <details><summary>Symbolpool bearbeiten und Lucide-Namen nachschlagen</summary>
            <p>Eine Zeile pro Symbol: <code>kennung | lucide-name | Bezeichnung | Verwendung</code>. Zwei neutrale Grundsymbole sind vorbereitet. Maximal 80 Symbole.</p>
            <textarea id="beitrag-symbol-pool" name="symbol_pool" class="large-text code" rows="7"><?php
            $lines = [];
            foreach (beitragseinreichung_symbol_pool() as $key => $item) {
                $lines[] = $key . ' | ' . $item['icon'] . ' | ' . $item['label'] . ' | ' . $item['usage'];
            }
            echo esc_textarea(implode("\n", $lines));
            ?></textarea>
            <p><label>Lokalen Lucide-Namen suchen <input type="search" id="beitrag-icon-search" list="beitrag-icon-names" placeholder="z. B. trophy"></label> <img id="beitrag-icon-preview" alt="Symbolvorschau" width="26" height="26" hidden></p>
            <datalist id="beitrag-icon-names"><?php foreach (beitragseinreichung_icon_names() as $name): ?><option value="<?php echo esc_attr($name); ?>"></option><?php endforeach; ?></datalist>
            <p class="description">Nach Änderungen am Pool zuerst die Einstellungen speichern, danach Symbole in den Stilgruppen auswählen. Unbekannte Lucide-Namen werden abgelehnt.</p>
        </details>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const input = document.getElementById('beitrag-icon-search');
            const preview = document.getElementById('beitrag-icon-preview');
            const names = <?php echo wp_json_encode(beitragseinreichung_icon_names()); ?>;
            const base = <?php echo wp_json_encode(plugin_dir_url(dirname(__DIR__, 2) . '/wp-form.php') . 'assets/lucide/icons/'); ?>;
            input.addEventListener('input', function () {
                preview.hidden = !names.includes(input.value);
                if (!preview.hidden) preview.src = base + input.value + '.svg';
            });
        });
        </script>
    </td></tr>
    <tr><th scope="row">Rechte und Mailversand</th><td>
        <p class="description">Beiträge einreichen, KI-Protokoll lesen, KI-Protokoll löschen und Einstellungen verwalten sind getrennte WordPress-Capabilities. Sie lassen sich z. B. mit Members auch eigenen Rollen zuweisen. Beim Upgrade bleiben die bisherigen Zugriffe erhalten.</p>
        <p class="description">Der Versandstatus steht im Beitragseditor unter „Beitragseinreichung: Mailversand“. Automatische Wiederholungen benötigen funktionierendes WP-Cron.</p>
    </td></tr>
    <?php
}
