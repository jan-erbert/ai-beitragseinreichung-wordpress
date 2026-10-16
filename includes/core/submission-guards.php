<?php

defined('ABSPATH') || exit;

/** Liefert eine begrenzte numerische Einstellung. */
function beitragseinreichung_limit($name, $default, $min, $max)
{
    return max($min, min($max, (int) get_option('beitragseinreichung_' . $name, $default)));
}

/** Prueft KI-Eingaben vor kostenpflichtigen Aufrufen. */
function beitragseinreichung_ai_input_error($content, $hint = '', $change = '')
{
    $plain = wp_strip_all_tags((string) $content);
    preg_match_all('/[\p{L}\p{N}]+(?:[-\x{2019}\']+[\p{L}\p{N}]+)*/u', $plain, $words);
    $minimum = beitragseinreichung_limit('ki_min_words', 30, 1, 500);
    $maximum = beitragseinreichung_limit('ki_max_chars', 40000, 500, 40000);
    $length = function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain);
    if (count($words[0]) < $minimum) {
        return sprintf('Bitte gib für die KI mindestens %d Wörter ein.', $minimum);
    }
    if ($length > $maximum || strlen($hint) > 8000 || strlen($change) > 8000) {
        return 'Der Text oder die Zusatzhinweise sind zu lang. Bitte kürze deine Eingaben.';
    }
    return '';
}

/** Zaehlt KI-Aufrufe unter einer atomaren Sperre pro Benutzer. */
function beitragseinreichung_reserve_ai_request()
{
    $user = get_current_user_id();
    $lock = 'beitragseinreichung_rate_lock_' . $user;
    if (!add_option($lock, time(), '', false)) {
        return 'Eine KI-Anfrage wird gerade vorbereitet. Bitte versuche es gleich erneut.';
    }
    try {
        $key = 'beitragseinreichung_rate_' . $user;
        $state = get_transient($key);
        if (!is_array($state) || ($state['until'] ?? 0) <= time()) {
            $state = ['until' => time() + HOUR_IN_SECONDS, 'count' => 0];
        }
        $limit = beitragseinreichung_limit('ki_hourly_limit', 20, 1, 200);
        if ($state['count'] >= $limit) {
            return 'Dein KI-Limit für diese Stunde ist erreicht. Bitte versuche es später erneut.';
        }
        ++$state['count'];
        if (!set_transient($key, $state, max(1, $state['until'] - time()))) {
            return 'Die KI-Anfrage konnte nicht sicher vorbereitet werden. Bitte erneut versuchen.';
        }
        return '';
    } finally {
        delete_option($lock);
    }
}

/** Erzeugt ein benutzergebundenes Einmaltoken fuer die Speicherung. */
function beitragseinreichung_create_submission_token()
{
    $token = wp_generate_uuid4();
    set_transient('beitragseinreichung_form_' . get_current_user_id() . '_' . $token, true, 12 * HOUR_IN_SECONDS);
    return $token;
}

/** Liefert genau einen Abschlussmodus, mit nativer Veroeffentlichungsberechtigung. */
function beitragseinreichung_submission_status($action)
{
    if ($action === 'draft') {
        return 'draft';
    }
    if (get_option('beitragseinreichung_completion_mode', 'review') === 'publish') {
        return current_user_can('publish_posts') ? 'publish' : 'draft';
    }
    return 'in_verarbeitung';
}

/** Liefert die Beschriftung der einzigen primaeren Speicheraktion. */
function beitragseinreichung_completion_label()
{
    if (get_option('beitragseinreichung_completion_mode', 'review') === 'publish') {
        return current_user_can('publish_posts') ? 'Beitrag veröffentlichen' : 'Als Entwurf speichern';
    }
    return 'Zur Prüfung einreichen';
}
