<?php

define('ABSPATH', dirname(__DIR__));
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

$options = [];
$transients = [];
$metadata = [];
$posts = [];
$caps = ['beitragseinreichung_submit' => true, 'publish_posts' => true];
$user_id = 7;
$mail_calls = [];
$mail_fail = true;
$cron = [];
$insert_error = false;

class WP_Error
{
    public $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function add_action() {}
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, $deprecated = '', $autoload = false) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); return true; }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $transients; $transients[$key] = $value; return true; }
function delete_transient($key) { global $transients; unset($transients[$key]); return true; }
function get_current_user_id() { global $user_id; return $user_id; }
function current_user_can($cap, $id = 0) { global $caps; return !empty($caps[$cap]) || $cap === 'read_post'; }
function wp_generate_uuid4() { static $id = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$id); }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function sanitize_key($text) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($text)); }
function sanitize_title($text) { return strtolower(str_replace(' ', '-', $text)); }
function sanitize_textarea_field($text) { return strip_tags($text); }
function wp_unslash($text) { return $text; }
function wp_kses_post($text) { return $text; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_html_split($text) { return preg_split('/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_attachment_is_image($id) { return $id === 12; }
function wp_insert_post($data, $error = false) { global $posts, $insert_error; if ($insert_error) return new WP_Error('insert', 'Testfehler'); $id = count($posts) + 1; $posts[$id] = $data; return $id; }
function wp_update_post($data, $error = false) { global $posts; $posts[$data['ID']] = array_merge($posts[$data['ID']], $data); return $data['ID']; }
function update_post_meta($id, $key, $value) { global $metadata; $metadata[$id][$key] = $value; return true; }
function add_post_meta($id, $key, $value, $unique = false) { global $metadata; if ($unique && isset($metadata[$id][$key])) return false; return update_post_meta($id, $key, $value); }
function get_post_meta($id, $key, $single = false) { global $metadata; return $metadata[$id][$key] ?? ''; }
function set_post_thumbnail($id, $image) { return true; }
function beitrag_bereinige_titel_text($text) { return strip_tags($text); }
function beitrag_remove_emojis($text) { return $text; }
function beitrag_wandle_zu_gutenberg_blocks($text) { return $text; }
function beitragseinreichung_parse_gallery_ids($text) { return $text ? array_map('intval', explode(',', $text)) : []; }
function beitragseinreichung_get_default_tags() { return []; }
function beitragseinreichung_merge_tags($a, $b) { return is_array($b) ? $b : explode(',', $b); }
function beitragseinreichung_parse_tags($text) { return explode(',', $text); }
function beitragseinreichung_sende_beitrag_benachrichtigung() {}
function beitrag_ki_log_speichern() {}
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function wp_mail($email, $subject, $html, $headers) { global $mail_calls, $mail_fail; $mail_calls[] = $email; return !($mail_fail && $email === 'retry@example.test'); }
function wp_next_scheduled($hook, $args) { global $cron; return isset($cron[$hook]); }
function wp_schedule_single_event($time, $hook, $args) { global $cron; $cron[$hook] = $args; }
function plugin_dir_url($path) { return 'https://example.test/plugins/wp-ai-form/'; }
function esc_url($text) { return $text; }

require __DIR__ . '/test-helpers.php';
require dirname(__DIR__) . '/includes/core/submission-guards.php';
require dirname(__DIR__) . '/includes/core/submission-preview.php';
require dirname(__DIR__) . '/includes/core/post-handler.php';
require dirname(__DIR__) . '/includes/core/notification-delivery.php';
require dirname(__DIR__) . '/includes/formatting/symbols.php';

beitrag_test_assert(beitragseinreichung_ai_input_error('Zu kurz') !== '', 'Kurze KI-Eingabe wird akzeptiert.');
beitrag_test_assert_same('', beitragseinreichung_ai_input_error(str_repeat('Wörter ', 30)), 'Deutsche Wörter werden falsch gezählt.');
beitrag_test_assert(beitragseinreichung_ai_input_error(str_repeat('a ', 21000)) !== '', 'Überlange KI-Eingabe wird akzeptiert.');
$options['beitragseinreichung_ki_hourly_limit'] = 2;
beitrag_test_assert_same('', beitragseinreichung_reserve_ai_request(), 'Erste KI-Anfrage wird blockiert.');
beitrag_test_assert_same('', beitragseinreichung_reserve_ai_request(), 'Zweite KI-Anfrage wird blockiert.');
beitrag_test_assert(beitragseinreichung_reserve_ai_request() !== '', 'Anfragelimit wird umgangen.');
$user_id = 8;
beitrag_test_assert_same('', beitragseinreichung_reserve_ai_request(), 'Anfragelimit gilt versehentlich für alle Benutzer.');
$user_id = 7;

$preview = ['title' => 'Geprüfter Titel', 'content' => 'Geprüfter Text', 'excerpt' => 'Vorschau', 'tags' => 'Test', 'category_id' => 2, 'featured_image_id' => 12, 'gallery_ids' => '', 'ki_active' => false, 'model' => '', 'ai_hint' => '', 'style_group' => 'Test', 'original_title' => 'Titel', 'original_content' => 'Text'];
$token = beitragseinreichung_create_submission_token();
$preview_token = beitragseinreichung_store_submission_preview($preview);
$source = ['beitrag_submission_token' => $token, 'beitrag_preview_ready' => 1, 'beitrag_preview_token' => $preview_token, 'beitrag_preview_title' => 'Manipulierter Titel'];
$options['beitragseinreichung_completion_mode'] = 'publish';
$options['beitragseinreichung_require_featured_image'] = 1;
$id = beitragseinreichung_save_submission($source);
beitrag_test_assert_same(1, $id, 'Gültige Vorschau wird nicht gespeichert.');
beitrag_test_assert_same('Geprüfter Titel', $posts[1]['post_title'], 'Versteckte Formularfelder überschreiben die gespeicherte Vorschau.');
beitrag_test_assert_same('publish', $posts[1]['post_status'], 'Freigegebene Veröffentlichung funktioniert nicht.');
beitrag_test_assert_same(1, beitragseinreichung_save_submission($source), 'Doppelter POST liefert nicht die bestehende Quittung.');
beitrag_test_assert_same(1, count($posts), 'Doppelter POST erstellt zwei Beiträge.');

$source['beitrag_submission_token'] = beitragseinreichung_create_submission_token();
beitrag_test_assert_same('preview_expired', beitragseinreichung_save_submission($source)->code, 'Verbrauchte Vorschau wird über versteckte Felder wiederhergestellt.');
$source['beitrag_preview_token'] = beitragseinreichung_store_submission_preview($preview);
$user_id = 8;
$source['beitrag_submission_token'] = beitragseinreichung_create_submission_token();
beitrag_test_assert_same('preview_expired', beitragseinreichung_save_submission($source)->code, 'Fremde Benutzervorschau ist zugänglich.');
$user_id = 7;
$source['beitrag_submission_token'] = beitragseinreichung_create_submission_token();
$preview['featured_image_id'] = 0;
$source['beitrag_preview_token'] = beitragseinreichung_store_submission_preview($preview);
beitrag_test_assert_same('image_required', beitragseinreichung_save_submission($source)->code, 'Veröffentlichung ohne Pflichtbild ist möglich.');
$source['beitrag_save_action'] = 'draft';
beitrag_test_assert_same(2, beitragseinreichung_save_submission($source), 'Entwurf ohne Bild wird verhindert.');
beitrag_test_assert_same('draft', $posts[2]['post_status'], 'Entwurf wird veröffentlicht.');

$caps['publish_posts'] = false;
beitrag_test_assert_same('draft', beitragseinreichung_submission_status('complete'), 'Native Veröffentlichungsrechte werden umgangen.');
$options['beitragseinreichung_completion_mode'] = 'review';
beitrag_test_assert_same('in_verarbeitung', beitragseinreichung_submission_status('complete'), 'Prüfmodus ändert sein Verhalten.');
$source['beitrag_submission_token'] = beitragseinreichung_create_submission_token();
$source['beitrag_preview_token'] = beitragseinreichung_store_submission_preview($preview);
$insert_error = true;
beitrag_test_assert(is_wp_error(beitragseinreichung_save_submission($source)), 'Speicherfehler wird nicht zurückgegeben.');
beitrag_test_assert(is_array(beitragseinreichung_get_stored_submission_preview($source['beitrag_preview_token'])), 'Vorschau geht bei Speicherfehler verloren.');
$insert_error = false;
beitrag_test_assert_same(3, beitragseinreichung_save_submission($source), 'Nach Speicherfehler bleibt die Einreichung blockiert.');

beitragseinreichung_queue_notification(1, ['ok@example.test', 'retry@example.test', 'ok@example.test'], 'Betreff', '<p>Text</p>');
beitrag_test_assert_same(['ok@example.test', 'retry@example.test'], $mail_calls, 'Empfänger werden nicht getrennt oder doppelt versandt.');
$mail_fail = false;
beitragseinreichung_deliver_notification(1);
beitrag_test_assert_same(['ok@example.test', 'retry@example.test', 'retry@example.test'], $mail_calls, 'Erfolgreicher Empfänger wird erneut benachrichtigt.');
beitragseinreichung_deliver_notification(1);
beitrag_test_assert_same(3, count($mail_calls), 'Erfolgreicher Versand ist nicht idempotent.');
$mail_fail = true;
beitragseinreichung_queue_notification(2, ['retry@example.test'], 'Betreff', '<p>Text</p>');
beitragseinreichung_deliver_notification(2);
beitragseinreichung_deliver_notification(2);
beitragseinreichung_deliver_notification(2);
beitrag_test_assert_same(3, $metadata[2]['_beitragseinreichung_mail']['recipients']['retry@example.test']['attempts'], 'Fehlversuche sind unbegrenzt.');
$metadata[2]['_beitragseinreichung_mail']['recipients']['retry@example.test'] = ['state' => 'sending', 'attempts' => 1];
$count = count($mail_calls);
beitragseinreichung_deliver_notification(2);
beitrag_test_assert_same($count, count($mail_calls), 'Unklarer Versand wird blind wiederholt.');

beitrag_test_assert(is_wp_error(beitragseinreichung_parse_symbol_pool('test | ../../evil | Test | Test')), 'Unbekannter Symbolpfad wird akzeptiert.');
beitrag_test_assert(is_wp_error(beitragseinreichung_parse_symbol_pool("test | info | Test | Test\ntest | info | Test | Test")), 'Doppelte Symbolkennung wird akzeptiert.');
beitrag_test_assert_same('', beitragseinreichung_render_symbols('[[symbol:info]]'), 'Symbole sind standardmäßig aktiviert.');
$options['beitragseinreichung_symbols_enabled'] = 1;
$options['beitragseinreichung_ki_stilgruppen'] = [['label' => 'Test', 'symbols' => ['info']]];
beitrag_test_assert_same('[[symbol:info]] ', beitragseinreichung_filter_ai_symbols('[[symbol:info]] [[symbol:termin]]', 'Test'), 'KI umgeht die Stilgruppen-Freigabe.');
beitrag_test_assert(strpos(beitragseinreichung_render_symbols('[[symbol:info]]'), '/assets/lucide/icons/info.svg') !== false, 'Lokales Symbol wird nicht ausgegeben.');
beitrag_test_assert_same('', beitragseinreichung_render_symbols('[[symbol:../script]]'), 'Unbekannte Marker werden ausgegeben.');
beitrag_test_assert_same('<a title="[[symbol:info]]">Link</a>', beitragseinreichung_render_symbols('<a title="[[symbol:info]]">Link</a>'), 'Symbolmarker verändern HTML-Attribute.');

$decoded_pool = beitragseinreichung_symbol_pool_from_input(['symbol_pool_json' => json_encode(['wandern' => ['icon' => 'footprints', 'label' => 'Wandern', 'usage' => 'Spaziergänge', 'manual' => false, 'ai' => true, 'inline' => false, 'heading' => true]])]);
beitrag_test_assert_same(false, $decoded_pool['wandern']['manual'], 'Die manuelle Freigabe geht beim Speichern verloren.');
beitrag_test_assert_same(false, $decoded_pool['wandern']['inline'], 'Die Textfreigabe geht beim Speichern verloren.');
beitrag_test_assert(is_wp_error(beitragseinreichung_symbol_pool_from_input(['symbol_pool_json' => '{kaputt'])), 'Defektes Popup-JSON wird als leerer Pool gespeichert.');
beitrag_test_assert(is_wp_error(beitragseinreichung_symbol_pool_from_input(['symbol_pool_json' => '{"test":{"icon":"info","label":"Test","usage":"Test","ai":"false"}}'])), 'Eine uneindeutige Freigabe wird akzeptiert.');
$legacy_pool = beitragseinreichung_parse_symbol_pool('test | info | Test | Hinweise');
beitrag_test_assert_same(true, $legacy_pool['test']['ai'], 'Bestehende Symbole verlieren ihre KI-Freigabe.');
$options['beitragseinreichung_symbol_pool'] = $decoded_pool;
$options['beitragseinreichung_ki_stilgruppen'] = [['label' => 'Test', 'symbols' => ['wandern']]];
beitrag_test_assert_same("## [[symbol:wandern]] Wandern\n Absatz", beitragseinreichung_filter_ai_symbols("## [[symbol:wandern]] Wandern\n[[symbol:wandern]] Absatz", 'Test'), 'KI beachtet die Freigabe nur für Zwischenüberschriften nicht.');
beitrag_test_assert_same('<p></p>', beitragseinreichung_render_symbols('<p>[[symbol:wandern]]</p>'), 'Ein reines Überschriftensymbol wird im Fließtext gerendert.');
beitrag_test_assert(strpos(beitragseinreichung_render_symbols('<h2>[[symbol:wandern]] Wandern</h2>'), 'footprints.svg') !== false, 'Ein freigegebenes Überschriftensymbol verschwindet.');
$options['beitragseinreichung_symbol_pool']['wandern']['ai'] = false;
beitrag_test_assert_same([], beitragseinreichung_group_symbols('Test'), 'Die globale KI-Freigabe wird ignoriert.');

echo "Workflow-Test erfolgreich: Einmaltokens, Vorschauen, Rechte, Speicherfehler, KI-Grenzen, Mailwiederholung und Symbole.\n";
