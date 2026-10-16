<?php

defined('ABSPATH') || exit;

/** Speichert einen festen Mailstand und sendet getrennt je Empfaenger. */
function beitragseinreichung_queue_notification($post_id, array $emails, $subject, $html)
{
    if (!get_post_meta($post_id, '_beitragseinreichung_mail', true)) {
        $recipients = [];
        foreach (array_unique($emails) as $email) {
            if (is_email($email)) {
                $recipients[strtolower($email)] = ['state' => 'pending', 'attempts' => 0];
            }
        }
        if (!$recipients) {
            return;
        }
        add_post_meta($post_id, '_beitragseinreichung_mail', [
            'subject' => $subject,
            'html' => $html,
            'recipients' => $recipients,
            'created' => time(),
        ], true);
    }
    beitragseinreichung_deliver_notification($post_id);
}

/** Wiederholt nur eindeutig fehlgeschlagene, niemals unklare Versandversuche. */
function beitragseinreichung_deliver_notification($post_id)
{
    $post_id = (int) $post_id;
    $lock = 'beitragseinreichung_mail_lock_' . $post_id;
    if (!add_option($lock, time(), '', false)) {
        return;
    }
    try {
        $mail = get_post_meta($post_id, '_beitragseinreichung_mail', true);
        if (!is_array($mail) || empty($mail['recipients'])) {
            return;
        }
        $retry = false;
        foreach ($mail['recipients'] as $email => $recipient) {
            if (!in_array($recipient['state'], ['pending', 'failed'], true) || $recipient['attempts'] >= 3) {
                continue;
            }
            $mail['recipients'][$email]['state'] = 'sending';
            ++$mail['recipients'][$email]['attempts'];
            $mail['recipients'][$email]['time'] = time();
            if (!update_post_meta($post_id, '_beitragseinreichung_mail', $mail)) {
                return;
            }
            // Ein Prozessabbruch laesst sending stehen, damit keine Doppelmail entsteht.
            $sent = wp_mail($email, $mail['subject'], $mail['html'], ['Content-Type: text/html; charset=UTF-8']);
            $mail['recipients'][$email]['state'] = $sent ? 'accepted' : 'failed';
            update_post_meta($post_id, '_beitragseinreichung_mail', $mail);
            $retry = $retry || (!$sent && $mail['recipients'][$email]['attempts'] < 3);
        }
        if ($retry && !wp_next_scheduled('beitragseinreichung_retry_mail', [$post_id])) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'beitragseinreichung_retry_mail', [$post_id]);
        }
    } finally {
        delete_option($lock);
    }
}
add_action('beitragseinreichung_retry_mail', 'beitragseinreichung_deliver_notification');

/** Zeigt Versandstaende nur Benutzern mit Bearbeitungsrecht am Beitrag. */
add_action('add_meta_boxes', function () {
    add_meta_box('beitragseinreichung-mail-status', 'Beitragseinreichung: Mailversand', 'beitragseinreichung_mail_status_box', 'post', 'side');
});

/** Rendert den gespeicherten Mailstatus ohne den Mailinhalt offenzulegen. */
function beitragseinreichung_mail_status_box($post)
{
    if (!current_user_can('edit_post', $post->ID)) {
        return;
    }
    $mail = get_post_meta($post->ID, '_beitragseinreichung_mail', true);
    if (!$mail) {
        echo '<p>Keine Benachrichtigung vorbereitet.</p>';
        return;
    }
    $labels = ['pending' => 'Vorgemerkt', 'sending' => 'Versand unklar – manuell prüfen', 'accepted' => 'An Maildienst übergeben', 'failed' => 'Versand fehlgeschlagen'];
    $can_retry = false;
    foreach ($mail['recipients'] as $email => $recipient) {
        $can_retry = $can_retry || (in_array($recipient['state'], ['pending', 'failed'], true) && $recipient['attempts'] < 3);
        echo '<p>' . esc_html($email) . '<br><strong>' . esc_html($labels[$recipient['state']] ?? 'Unbekannt') . '</strong><br>' . esc_html('Versuche: ' . $recipient['attempts']) . '</p>';
    }
    echo '<p>„Übergeben“ bestätigt keine Zustellung. Fehlgeschlagene Versuche werden über WP-Cron bis zu dreimal versucht.</p>';
    if ($can_retry && current_user_can('beitragseinreichung_settings')) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=beitragseinreichung_retry_mail&post_id=' . $post->ID), 'beitrag_retry_mail');
        echo '<p><a class="button" href="' . esc_url($url) . '">Ausstehende / fehlgeschlagene senden</a></p>';
    }
}

/** Startet nach Pruefung eine manuelle Wiederholung ohne erfolgreiche Empfaenger. */
add_action('admin_post_beitragseinreichung_retry_mail', function () {
    check_admin_referer('beitrag_retry_mail');
    $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
    if (!current_user_can('beitragseinreichung_settings') || !current_user_can('edit_post', $post_id)) {
        wp_die(esc_html__('Keine Berechtigung.', 'ai-beitragseinreichung'));
    }
    // Auch manuell bleibt die Grenze von drei Versuchen bestehen.
    beitragseinreichung_deliver_notification($post_id);
    wp_safe_redirect(admin_url('post.php?post=' . $post_id . '&action=edit'));
    exit;
});
