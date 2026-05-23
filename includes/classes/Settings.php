<?php
/**
 * Settings storage and sanitization for the CODESM Decoupled Bundle plugin.
 *
 * All plugin configuration is stored in a single serialised array under
 * OPTION_NAME, organised into logical top-level sections:
 *
 *   site     — title, description, global JSON-LD
 *   contact  — phones (array), emails (array), locations (array of address + coordinates)
 *   social   — URLs for every supported social network
 *   gtm      — Google Tag Manager container ID
 *   scripts  — global and per-page script/JSON-LD injection rules
 *   build    — GitHub Actions credentials and auto-build configuration
 *
 * The public GET endpoint returns all sections except `build`, so GitHub
 * credentials are never exposed to unauthenticated requests.
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

namespace CODESM\DecoupledBundle;

if (!defined('ABSPATH')) exit;

/**
 * Class Settings
 */
class Settings {

    /**
     * WordPress option key for the entire settings array.
     *
     * @var string
     */
    const OPTION_NAME = 'codesm_decoupled_bundle_settings';

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Returns the full settings array including the sensitive `build` section.
     *
     * Uses array_replace_recursive so nested defaults are preserved even when
     * only some sub-keys have been saved.
     *
     * @return array<string, mixed>
     */
    public static function get(): array {
        $saved = get_option(self::OPTION_NAME, []);
        return array_replace_recursive(self::defaults(), is_array($saved) ? $saved : []);
    }

    /**
     * Returns the settings safe for the public REST endpoint.
     *
     * The entire `build` section is removed — it contains GitHub credentials
     * that must never leave the server on an unauthenticated request.
     *
     * @return array<string, mixed>
     */
    public static function get_public(): array {
        $settings = self::get();
        unset($settings['build']);
        return $settings;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Sanitizes an incoming settings payload and persists it to the database.
     *
     * Script injection fields (JSON-LD, header/body scripts) are stored as-is
     * because they contain intentional HTML/JS authored by trusted admins who
     * hold the manage_options capability.
     *
     * @param  array<string, mixed> $input Raw input, typically a REST request body.
     * @return array<string, mixed>        The sanitized settings that were saved.
     */
    public static function save(array $input): array {
        $c = self::get(); // current, for fallback values

        $sanitized = [

            // ── Site ─────────────────────────────────────────────────────────
            'site' => [
                'title'       => sanitize_text_field($input['site']['title']             ?? $c['site']['title']),
                'description' => sanitize_textarea_field($input['site']['description']   ?? $c['site']['description']),
                'json_ld'     => self::sanitize_raw($input['site']['json_ld']            ?? $c['site']['json_ld']),
            ],

            // ── Contact ───────────────────────────────────────────────────────
            'contact' => [
                'phones'    => self::sanitize_phones($input['contact']['phones']       ?? $c['contact']['phones']),
                'emails'    => self::sanitize_emails($input['contact']['emails']       ?? $c['contact']['emails']),
                'locations' => self::sanitize_locations($input['contact']['locations'] ?? $c['contact']['locations']),
            ],

            // ── Social ────────────────────────────────────────────────────────
            'social' => self::sanitize_social($input['social'] ?? $c['social']),

            // ── GTM ───────────────────────────────────────────────────────────
            'gtm' => [
                'id' => sanitize_text_field($input['gtm']['id'] ?? $c['gtm']['id']),
            ],

            // ── Scripts ───────────────────────────────────────────────────────
            'scripts' => [
                'header'     => self::sanitize_raw($input['scripts']['header']     ?? $c['scripts']['header']),
                'body_start' => self::sanitize_raw($input['scripts']['body_start'] ?? $c['scripts']['body_start']),
                'body_end'   => self::sanitize_raw($input['scripts']['body_end']   ?? $c['scripts']['body_end']),
                'pages'      => self::sanitize_script_pages($input['scripts']['pages'] ?? $c['scripts']['pages']),
            ],

            // ── Build (sensitive) ─────────────────────────────────────────────
            'build' => [
                'github_token'     => sanitize_text_field($input['build']['github_token']     ?? $c['build']['github_token']),
                'github_repo'      => self::sanitize_github_repo($input['build']['github_repo'] ?? $c['build']['github_repo']),
                'auto_enabled'     => (bool) ($input['build']['auto_enabled']                 ?? $c['build']['auto_enabled']),
                'debounce_minutes' => max(1, (int) ($input['build']['debounce_minutes']       ?? $c['build']['debounce_minutes'])),
                'auto_targets'     => self::sanitize_auto_targets($input['build']['auto_targets'] ?? $c['build']['auto_targets']),
            ],
        ];

        update_option(self::OPTION_NAME, $sanitized);

        return $sanitized;
    }

    // -------------------------------------------------------------------------
    // Sanitization helpers
    // -------------------------------------------------------------------------

    /**
     * Trims a raw string without stripping HTML or JS tags.
     *
     * Used for script injection fields and JSON-LD. Appropriate only for
     * input that arrives from a trusted manage_options admin.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function sanitize_raw(mixed $value): string {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Sanitizes the phones array.
     *
     * Each entry must have a non-empty `number`. Entries without a number are
     * silently dropped. Labels are optional.
     *
     * @param  mixed $value  Raw phones array from REST input.
     * @return list<array{label: string, number: string}>
     */
    private static function sanitize_phones(mixed $value): array {
        if (!is_array($value)) return [];

        $sanitized = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) continue;

            $number = sanitize_text_field($entry['number'] ?? '');
            if (empty($number)) continue;

            $sanitized[] = [
                'label'  => sanitize_text_field($entry['label'] ?? ''),
                'number' => $number,
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitizes the emails array.
     *
     * Each entry must have a non-empty address. Label is optional.
     *
     * @param  mixed $value  Raw emails array from REST input.
     * @return list<array{label: string, address: string}>
     */
    private static function sanitize_emails(mixed $value): array {
        if (!is_array($value)) return [];

        $sanitized = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) continue;
            $address = sanitize_email($entry['address'] ?? '');
            if (empty($address)) continue;
            $sanitized[] = [
                'label'   => sanitize_text_field($entry['label'] ?? ''),
                'address' => $address,
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitizes the locations array.
     *
     * Each entry must have at least one non-empty address field or coordinate.
     * Label is optional.
     *
     * @param  mixed $value  Raw locations array from REST input.
     * @return list<array{label: string, address: array<string,string>, coordinates: array<string,string>}>
     */
    private static function sanitize_locations(mixed $value): array {
        if (!is_array($value)) return [];

        $sanitized = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) continue;

            $address = [
                'street'  => sanitize_text_field($entry['address']['street']  ?? ''),
                'city'    => sanitize_text_field($entry['address']['city']    ?? ''),
                'state'   => sanitize_text_field($entry['address']['state']   ?? ''),
                'zip'     => sanitize_text_field($entry['address']['zip']     ?? ''),
                'country' => sanitize_text_field($entry['address']['country'] ?? ''),
            ];
            $coordinates = [
                'lat' => sanitize_text_field($entry['coordinates']['lat'] ?? ''),
                'lng' => sanitize_text_field($entry['coordinates']['lng'] ?? ''),
            ];

            if (empty(array_filter($address)) && empty(array_filter($coordinates))) continue;

            $sanitized[] = [
                'label'       => sanitize_text_field($entry['label'] ?? ''),
                'address'     => $address,
                'coordinates' => $coordinates,
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitizes the social links array.
     *
     * Each entry must have a non-empty platform and a valid URL.
     * Entries missing either are silently dropped.
     * Multiple entries for the same platform are allowed.
     *
     * @param  mixed $value  Raw social array from REST input.
     * @return list<array{platform: string, url: string}>
     */
    private static function sanitize_social(mixed $value): array {
        if (!is_array($value)) return [];

        $sanitized = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) continue;
            $platform = sanitize_text_field($entry['platform'] ?? '');
            if (empty($platform)) continue;
            $url = esc_url_raw($entry['url'] ?? '');
            if (empty($url)) continue;
            $sanitized[] = ['platform' => $platform, 'url' => $url];
        }

        return $sanitized;
    }

    /**
     * Sanitizes the per-page script rules array.
     *
     * Entries missing a url_pattern or with an invalid pattern are silently
     * dropped. Patterns must start with '/' and contain only URL-safe characters
     * plus a trailing '/*' wildcard — no regex metacharacters accepted.
     *
     * @param  mixed $value  Raw pages array from REST input.
     * @return list<array<string, string>>
     */
    private static function sanitize_script_pages(mixed $value): array {
        if (!is_array($value)) return [];

        $sanitized = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) continue;

            $url_pattern = sanitize_text_field($entry['url_pattern'] ?? '');
            if (empty($url_pattern)) continue;

            // Allow only safe URL path characters: letters, digits, -, _, ., /
            // and an optional trailing /* wildcard. Reject regex metacharacters.
            if (!preg_match('#^/[a-zA-Z0-9\-_./]*(\*)?$#', $url_pattern)) continue;

            $sanitized[] = [
                'url_pattern' => $url_pattern,
                'json_ld'     => self::sanitize_raw($entry['json_ld']     ?? ''),
                'header'      => self::sanitize_raw($entry['header']      ?? ''),
                'body_start'  => self::sanitize_raw($entry['body_start']  ?? ''),
                'body_end'    => self::sanitize_raw($entry['body_end']    ?? ''),
            ];
        }

        return $sanitized;
    }

    /**
     * Validates and sanitizes a GitHub repository slug ("owner/repo").
     *
     * Accepts only alphanumeric characters, hyphens, underscores, and dots in
     * each segment. Returns empty string if the format is invalid so the UI can
     * prompt the user rather than silently storing a malformed value.
     *
     * @param  mixed  $value
     * @return string
     */
    private static function sanitize_github_repo(mixed $value): string {
        $repo = sanitize_text_field((string) $value);
        return preg_match('/^[\w.\-]+\/[\w.\-]+$/', $repo) ? $repo : '';
    }

    /**
     * Sanitizes the auto_targets array.
     *
     * Each entry must have a non-empty ref (branch name) and a workflows array
     * of numeric GitHub workflow IDs. Entries missing a ref are dropped.
     *
     * @param  mixed $value  Raw auto_targets array from REST input.
     * @return list<array{ref: string, workflows: list<string>}>
     */
    private static function sanitize_auto_targets(mixed $value): array {
        if (!is_array($value)) return [];

        $sanitized = [];
        foreach ($value as $target) {
            if (!is_array($target)) continue;
            $ref = sanitize_text_field($target['ref'] ?? '');
            if (empty($ref)) continue;

            $workflows = [];
            foreach ((array) ($target['workflows'] ?? []) as $id) {
                $id = sanitize_text_field((string) $id);
                if (preg_match('/^\d+$/', $id)) {
                    $workflows[] = $id;
                }
            }

            $sanitized[] = ['ref' => $ref, 'workflows' => array_values(array_unique($workflows))];
        }

        return $sanitized;
    }

    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    /**
     * Returns the full default settings tree.
     *
     * Site title and description seed from WordPress globals so the plugin is
     * immediately useful after activation without any manual configuration.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array {
        return [

            'site' => [
                'title'       => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'json_ld'     => '',
            ],

            'contact' => [
                'phones'    => [],
                'emails'    => [],
                'locations' => [],
            ],

            'social' => [],

            'gtm' => [
                'id' => '',
            ],

            'scripts' => [
                'header'     => '',
                'body_start' => '',
                'body_end'   => '',
                // Per-page rules: [{ url_pattern, json_ld, header, body_start, body_end }]
                'pages'      => [],
            ],

            'build' => [
                'github_token'     => '',
                'github_repo'      => '',
                'auto_enabled'     => false,
                'debounce_minutes' => 5,
                // Each target: { ref: string, workflows: string[] }
                'auto_targets'     => [],
            ],
        ];
    }
}
