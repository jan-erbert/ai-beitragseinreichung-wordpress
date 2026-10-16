<?php

defined('ABSPATH') || exit;

/** Speichert einmalig und veroeffentlicht erst nach dem Setzen aller Beitragsdaten. */
function beitragseinreichung_save_submission(array $source)
{
    if (!current_user_can('beitragseinreichung_submit')) {
        return new WP_Error('forbidden', 'Keine Berechtigung.');
    }
    $token = sanitize_text_field(wp_unslash($source['beitrag_submission_token'] ?? ''));
    if (!preg_match('/^[a-f0-9-]{36}$/D', $token)) {
        return new WP_Error('token', 'Bitte öffne das Formular erneut.');
    }
    $key = 'beitragseinreichung_form_' . get_current_user_id() . '_' . $token;
    $receipt = get_transient($key . '_done');
    if ($receipt) {
        return (int) $receipt;
    }
    if (!get_transient($key)) {
        return new WP_Error('expired', 'Das Formular ist abgelaufen. Deine Eingaben bleiben über Zurück erreichbar; bitte erstelle eine neue Vorschau in einem neuen Formular.');
    }
    if (!add_option($key . '_lock', time(), '', false)) {
        return new WP_Error('busy', 'Diese Einreichung wird bereits verarbeitet. Bitte lade die Seite nicht erneut ab.');
    }
    $post_id = 0;
    $preview_lock = '';
    try {
        // Zweite Pruefung nach dem Sperren verhindert parallele Doppelspeicherungen.
        $receipt = get_transient($key . '_done');
        if ($receipt) {
            return (int) $receipt;
        }
        $input = beitragseinreichung_get_submission_input($source);
        $save_action = sanitize_key($source['beitrag_save_action'] ?? 'complete');
        $preview_token = sanitize_text_field(wp_unslash($source['beitrag_preview_token'] ?? ''));
        if (!empty($source['beitrag_preview_ready'])) {
            if (!preg_match('/^[a-f0-9-]{36}$/D', $preview_token)) {
                return new WP_Error('preview_invalid', 'Bitte erstelle eine neue Vorschau.');
            }
            $candidate_lock = 'beitragseinreichung_preview_lock_' . get_current_user_id() . '_' . $preview_token;
            if (!add_option($candidate_lock, time(), '', false)) {
                return new WP_Error('preview_busy', 'Diese Vorschau wird bereits gespeichert.');
            }
            $preview_lock = $candidate_lock;
            $preview = beitragseinreichung_get_stored_submission_preview($preview_token);
            if (!$preview) {
                return new WP_Error('preview_expired', 'Die Vorschau ist abgelaufen oder wurde bereits verwendet. Bitte gehe zurück und erstelle eine neue Vorschau.');
            }
        } else {
            if ($save_action === 'draft') {
                // Ein Entwurf speichert den Ausgangstext ohne neuen KI-Aufruf.
                $input['ki_active'] = false;
                $input['ki_tags_active'] = true;
            }
            $errors = beitragseinreichung_validate_submission_input($input);
            if ($errors) {
                return new WP_Error('validation', implode(' ', $errors));
            }
            global $beitrag_ki_fehler, $beitrag_ki_fehler_meldung;
            $beitrag_ki_fehler = false;
            $preview = beitragseinreichung_build_submission_preview($input);
            if (!empty($input['ki_active']) && !empty($beitrag_ki_fehler)) {
                return new WP_Error('ai_failed', $beitrag_ki_fehler_meldung ?: 'Die KI-Verarbeitung ist fehlgeschlagen. Deine Eingaben wurden nicht gespeichert.');
            }
        }
        $status = beitragseinreichung_submission_status($save_action);
        $title = beitrag_bereinige_titel_text($preview['title']);
        $image = (int) ($preview['featured_image_id'] ?? 0);
        if ($title === '' || trim($preview['content']) === '') {
            return new WP_Error('empty', 'Titel und Inhalt dürfen nicht leer sein.');
        }
        if ($image && (!wp_attachment_is_image($image) || !current_user_can('read_post', $image))) {
            return new WP_Error('image', 'Das Beitragsbild ist nicht verfügbar oder nicht freigegeben.');
        }
        if ($status === 'publish' && get_option('beitragseinreichung_require_featured_image', 0) && !$image) {
            return new WP_Error('image_required', 'Zum Veröffentlichen ist ein Beitragsbild nötig. Du kannst den Beitrag als Entwurf speichern.');
        }
        $gallery_ids = beitragseinreichung_parse_gallery_ids($preview['gallery_ids'] ?? '');
        foreach ($gallery_ids as $id) {
            if (!wp_attachment_is_image($id) || !current_user_can('read_post', $id)) {
                return new WP_Error('gallery', 'Ein zusätzliches Bild ist nicht verfügbar oder nicht freigegeben.');
            }
        }
        $content = beitrag_wandle_zu_gutenberg_blocks($preview['content']);
        if ($gallery_ids) {
            $content .= "\n\n" . (count($gallery_ids) > 1
                ? beitragseinreichung_render_gallery_slider($gallery_ids)
                : beitragseinreichung_render_single_gallery_image($gallery_ids[0]));
        }
        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => sanitize_text_field($preview['excerpt'] ?? ''),
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => get_current_user_id(),
            'post_name' => sanitize_title(beitrag_remove_emojis($title)),
            'post_category' => [(int) $preview['category_id']],
            'tags_input' => beitragseinreichung_parse_tags($preview['tags']),
        ], true);
        if (is_wp_error($post_id) || !$post_id) {
            $error = is_wp_error($post_id) ? $post_id : new WP_Error('save', 'Der Beitrag konnte nicht gespeichert werden.');
            $post_id = 0;
            return $error;
        }
        // Quittung vor Folgeaktionen: auch Mailfehler duerfen keinen zweiten Beitrag erzeugen.
        set_transient($key . '_done', $post_id, DAY_IN_SECONDS);
        delete_transient($key);
        if ($preview_token !== '') {
            beitragseinreichung_delete_stored_submission_preview($preview_token);
        }
        update_post_meta($post_id, '_beitragseinreichung_style_group', sanitize_text_field($preview['style_group'] ?? ''));
        if ($image && !set_post_thumbnail($post_id, $image)) {
            return new WP_Error('thumbnail', 'Der Beitrag wurde als Entwurf gespeichert. Bitte prüfe das Beitragsbild im Editor. Beitrags-ID: ' . $post_id);
        }
        if ($status !== 'draft') {
            $result = wp_update_post(['ID' => $post_id, 'post_status' => $status], true);
            if (is_wp_error($result) || !$result) {
                return new WP_Error('status', 'Der Beitrag bleibt als Entwurf gespeichert. Bitte prüfe ihn im Editor. Beitrags-ID: ' . $post_id);
            }
            beitragseinreichung_sende_beitrag_benachrichtigung($post_id, $title, $preview['content'], $preview['ki_active'], $preview['model'], $preview['ai_hint'], $preview['excerpt'], implode(',', $gallery_ids), $preview['style_group']);
        }
        if (!empty($preview['ki_active'])) {
            beitrag_ki_log_speichern($post_id, get_current_user_id(), $preview['original_title'], $title, $preview['original_content'], $preview['content'], $preview['model'], $preview['ai_hint'], $preview['style_group']);
        }
        return $post_id;
    } finally {
        // Bei unerwartetem Abbruch nach der Erstellung bleibt die Sperre bestehen.
        if (!$post_id || get_transient($key . '_done')) {
            delete_option($key . '_lock');
            if ($preview_lock !== '') {
                delete_option($preview_lock);
            }
        }
    }
}

add_action('admin_init', function () {
    if (wp_doing_ajax() || !isset($_POST['beitrag_nonce'])) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['beitrag_nonce'])), 'beitrag_einreichen')) {
        wp_die(esc_html__('Die Sicherheitsprüfung ist fehlgeschlagen.', 'ai-beitragseinreichung'));
    }
    $result = beitragseinreichung_save_submission($_POST);
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()), esc_html__('Beitrag nicht abgeschlossen', 'ai-beitragseinreichung'), ['back_link' => true]);
    }
    wp_safe_redirect(admin_url('admin.php?page=beitragseinreichung&erfolg=1&beitrag_id=' . (int) $result));
    exit;
});
