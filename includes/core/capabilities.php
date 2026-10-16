<?php

defined('ABSPATH') || exit;

/**
 * Registriert die Plugin-Capabilities fuer Standardrollen.
 */
function beitragseinreichung_register_role_capabilities()
{
    $roles = ['administrator', 'editor', 'author'];
    foreach ($roles as $role_name) {
        $role = get_role($role_name);
        if (!$role) continue;

        // Basisrechte zuweisen
        $role->add_cap('beitragseinreichung_submit');
        $role->add_cap('beitragseinreichung_view_log');
        if ($role_name === 'administrator') {
            $role->add_cap('beitragseinreichung_settings');
            $role->add_cap('beitragseinreichung_admin');
            $role->add_cap('beitragseinreichung_delete_log');
        }
    }
}

add_action('members_register_cap_groups', 'register_ai_beitragseinreichung_cap_group');
/**
 * Registriert die Capability-Gruppe fuer das Members-Plugin.
 */
function register_ai_beitragseinreichung_cap_group()
{
    members_register_cap_group('ai_beitragseinreichung', [
        'label' => __('AI Beitragseinreichung', 'ai-beitragseinreichung'),
        'icon' => 'dashicons-edit',
        'priority' => 10,
    ]);
}

add_action('members_register_caps', 'register_ai_beitragseinreichung_caps');
/**
 * Registriert die Plugin-Capabilities fuer das Members-Plugin.
 */
function register_ai_beitragseinreichung_caps()
{
    members_register_cap('beitragseinreichung_submit', [
        'label' => __('Beiträge einreichen', 'ai-beitragseinreichung'),
        'group' => 'ai_beitragseinreichung',
    ]);
    foreach (['beitragseinreichung_view_log' => 'KI-Protokoll lesen', 'beitragseinreichung_delete_log' => 'KI-Protokoll löschen'] as $cap => $label) {
        members_register_cap($cap, ['label' => $label, 'group' => 'ai_beitragseinreichung']);
    }
    members_register_cap('beitragseinreichung_settings', [
        'label' => __('Einstellungen verwalten', 'ai-beitragseinreichung'),
        'group' => 'ai_beitragseinreichung',
    ]);
    members_register_cap('beitragseinreichung_admin', [
        'label' => __('Erweiterte Admin-Rechte (API-Key etc.)', 'ai-beitragseinreichung'),
        'group' => 'ai_beitragseinreichung',
    ]);
}

/** Migriert neue Rechte einmalig und respektiert danach manuelle Rollenaenderungen. */
add_action('admin_init', function () {
    if (get_option('beitragseinreichung_caps_version') === '1.3.0') {
        return;
    }
    foreach (wp_roles()->roles as $name => $data) {
        $role = get_role($name);
        if (!empty($data['capabilities']['beitragseinreichung_submit'])) {
            $role->add_cap('beitragseinreichung_view_log');
        }
        if (!empty($data['capabilities']['beitragseinreichung_admin'])) {
            $role->add_cap('beitragseinreichung_delete_log');
        }
    }
    update_option('beitragseinreichung_caps_version', '1.3.0', false);
});
