<?php

defined('ABSPATH') || exit;

/** Zeigt den Einstieg zur visuellen Poolverwaltung im bestehenden Formular. */
function beitragseinreichung_symbol_manager_fields()
{
    $pool = beitragseinreichung_symbol_pool();
    $config = ['pool' => $pool, 'names' => beitragseinreichung_icon_names(), 'base' => plugin_dir_url(dirname(__DIR__, 2) . '/wp-form.php') . 'assets/lucide/icons/'];
    ?>
    <div class="beitrag-symbol-manager" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
        <input type="hidden" name="symbol_pool_json" value="<?php echo esc_attr(wp_json_encode((object) $pool)); ?>">
        <div class="beitrag-symbol-summary">
            <?php foreach (array_slice($pool, 0, 8) as $item): ?>
                <span><img src="<?php echo esc_url($config['base'] . $item['icon'] . '.svg'); ?>" width="24" height="24" alt=""><?php echo esc_html($item['label']); ?></span>
            <?php endforeach; ?>
        </div>
        <p><button type="button" class="button beitrag-symbol-open">Symbolpool mit Vorschau verwalten</button></p>
        <p class="description">Symbole ansehen, ergänzen und einzeln festlegen, wo Redaktion und KI sie verwenden dürfen.</p>
        <noscript><p>Bitte JavaScript aktivieren, um den Symbolpool zu bearbeiten. Vorhandene Symbole bleiben beim Speichern erhalten.</p></noscript>
    </div>
    <?php
}

/** Stellt die manuelle Symbolauswahl als bebildertes Popup bereit. */
function beitragseinreichung_symbol_picker_fields()
{
    $pool = array_filter(beitragseinreichung_symbol_pool(), function ($item) {
        return $item['manual'] && ($item['inline'] || $item['heading']);
    });
    $config = ['pool' => $pool, 'base' => plugin_dir_url(dirname(__DIR__, 2) . '/wp-form.php') . 'assets/lucide/icons/'];
    ?>
    <div class="beitrag-symbol-picker" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
        <button type="button" class="button beitrag-symbol-open">Symbol mit Vorschau auswählen</button>
        <p class="description">Wähle eine Vorschaukachel. Das Symbol wird an deiner Textposition eingefügt.</p>
    </div>
    <?php
}
