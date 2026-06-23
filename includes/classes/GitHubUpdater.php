<?php
/**
 * GitHub-based plugin updates for public repositories.
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

namespace CODESM\DecoupledBundle;

if (!defined('ABSPATH')) exit;

class GitHubUpdater {
    /**
     * GitHub repository owner/name.
     */
    private const GITHUB_REPO = 'CODESM003/CODESM-Decoupled-Bundle';

    /**
     * Initializes update hooks.
     */
    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_updates']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 10, 3);
    }

    /**
     * Checks for plugin updates from GitHub and injects into WordPress transient.
     *
     * @param mixed $transient Current update transient value.
     * @return mixed Modified transient with update info if available.
     */
    public static function check_for_updates($transient): mixed {
        if (!is_object($transient)) {
            $transient = (object) ['checked' => [], 'response' => []];
        }

        if (!isset($transient->checked)) {
            $transient->checked = [];
        }
        if (!isset($transient->response)) {
            $transient->response = [];
        }

        $plugin_file = plugin_basename(CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'codesm-decoupled-bundle.php');
        $current_version = $transient->checked[$plugin_file] ?? '0.0.0';
        $latest_release = self::get_latest_release();

        if ($latest_release && version_compare($latest_release['version'], $current_version, '>')) {
            $transient->response[$plugin_file] = (object) [
                'id'           => 'codesm-decoupled-bundle',
                'slug'         => 'codesm-decoupled-bundle',
                'plugin'       => $plugin_file,
                'new_version'  => $latest_release['version'],
                'url'          => $latest_release['url'],
                'package'      => $latest_release['download_url'],
                'tested'       => '6.9',
                'requires'     => '6.9',
                'requires_php' => '8.0',
                'icons'        => [],
            ];
        }

        return $transient;
    }

    /**
     * Provides plugin info for the update modal/details view.
     *
     * @param false|object|array $response API response object.
     * @param string            $action    The type of information being requested.
     * @param object            $args      Plugin API arguments.
     * @return false|object Modified response or false.
     */
    public static function plugin_info($response, $action, $args): false|object {
        if ($action !== 'plugin_information' || $args->slug !== 'codesm-decoupled-bundle') {
            return $response;
        }

        $latest_release = self::get_latest_release();
        if (!$latest_release) {
            return $response;
        }

        return (object) [
            'name'           => 'CODESM Decoupled Bundle',
            'slug'           => 'codesm-decoupled-bundle',
            'version'        => $latest_release['version'],
            'author'         => 'Kavit Trivedi',
            'author_profile' => 'https://codesm.com',
            'requires'       => '6.9',
            'requires_php'   => '8.0',
            'download_link'  => $latest_release['download_url'],
            'sections'       => [
                'description' => 'Site settings, contact info, GTM config, global/per-page script injection, and Astro build triggers for decoupled WordPress + Astro setups.',
                'changelog'   => $latest_release['body'] ?? 'See GitHub for full changelog.',
            ],
        ];
    }

    /**
     * Fetches the latest release from GitHub API with caching.
     *
     * Respects the prerelease_enabled setting:
     * - If disabled: only returns stable releases (prerelease: false)
     * - If enabled: returns any release (stable or prerelease)
     *
     * @return array{version: string, url: string, download_url: string, body: string}|null
     */
    private static function get_latest_release() {
        $settings = Settings::get();
        $prerelease_enabled = (bool) ($settings['plugin']['prerelease_enabled'] ?? false);
        $cache_key = 'codesm_decoupled_bundle_github_latest' . ($prerelease_enabled ? '_pre' : '');
        $cached = get_transient($cache_key);

        if ($cached) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases',
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/vnd.github+json'],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return null;
        }

        $releases = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) {
            return null;
        }

        $latest = null;
        foreach ($releases as $release) {
            if (!isset($release['tag_name'])) continue;
            if (!$prerelease_enabled && $release['prerelease']) continue;

            $download_url = self::get_release_asset_url($release) ?? ($release['zipball_url'] ?? '');
            $data = [
                'version'      => ltrim($release['tag_name'], 'v'),
                'url'          => $release['html_url'] ?? '',
                'download_url' => $download_url,
                'body'         => self::parse_markdown($release['body'] ?? ''),
            ];

            if (!$latest || version_compare($data['version'], $latest['version'], '>')) {
                $latest = $data;
            }
        }

        if ($latest) {
            set_transient($cache_key, $latest, 5 * MINUTE_IN_SECONDS);
            return $latest;
        }

        return null;
    }

    /**
     * Fetches latest stable and prerelease versions for the admin panel.
     *
     * @return array{stable: array|null, prerelease: array|null, error: string|null}
     */
    public static function get_version_info(): array {
        $cache_key = 'codesm_decoupled_bundle_version_info';
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases',
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/vnd.github+json'],
            ]
        );

        if (is_wp_error($response)) {
            $result = ['stable' => null, 'prerelease' => null, 'error' => 'Failed to fetch releases from GitHub.'];
            set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
            return $result;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $result = ['stable' => null, 'prerelease' => null, 'error' => "GitHub API error: HTTP $status"];
            set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
            return $result;
        }

        $releases = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) {
            $result = ['stable' => null, 'prerelease' => null, 'error' => 'Invalid response from GitHub.'];
            set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
            return $result;
        }

        $stable = null;
        $prerelease = null;

        foreach ($releases as $release) {
            if (!isset($release['tag_name'])) continue;

            $download_url = self::get_release_asset_url($release) ?? ($release['zipball_url'] ?? '');
            $data = [
                'version'      => ltrim($release['tag_name'], 'v'),
                'url'          => $release['html_url'] ?? '',
                'download_url' => $download_url,
                'body'         => self::parse_markdown($release['body'] ?? ''),
            ];

            if ($release['prerelease'] && !$prerelease) {
                $prerelease = $data;
            } elseif (!$release['prerelease'] && !$stable) {
                $stable = $data;
            }

            if ($stable && $prerelease) break;
        }

        $result = ['stable' => $stable, 'prerelease' => $prerelease, 'error' => null];
        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);

        return $result;
    }

    /**
     * Fixes the extracted folder name from GitHub zipballs.
     *
     * GitHub extracts to CODESM003-CODESM-Decoupled-Bundle-<hash>, but WordPress
     * expects codesm-decoupled-bundle. Rename during extraction.
     *
     * @param string $source Path to extracted folder.
     * @param string $remote_source Remote source path.
     * @param object $upgrader Upgrader object.
     * @param array  $hook_extra Extra hook data.
     * @return string Corrected source path.
     */
    /**
     * Finds the release asset download URL.
     *
     * @param array $release GitHub release data.
     * @return string|null Download URL of the asset, or null if not found.
     */
    private static function get_release_asset_url(array $release): ?string {
        if (!isset($release['assets']) || !is_array($release['assets'])) {
            return null;
        }

        foreach ($release['assets'] as $asset) {
            if (($asset['name'] ?? '') === 'codesm-decoupled-bundle.zip') {
                return $asset['browser_download_url'] ?? null;
            }
        }

        return null;
    }

    /**
     * Converts GitHub markdown to basic HTML for display.
     *
     * @param string $markdown Markdown text from GitHub release.
     * @return string HTML-safe text.
     */
    private static function parse_markdown(string $markdown): string {
        $html = wp_kses_post($markdown);
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/<li>(.+?)<\/li>/s', '<ul><li>$1</li></ul>', $html);
        $html = preg_replace('/<\/ul>\s*<ul>/', '', $html);
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = nl2br($html);
        return $html;
    }
}
