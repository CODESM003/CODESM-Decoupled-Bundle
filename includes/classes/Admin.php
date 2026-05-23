<?php
/**
 * WordPress admin menu page and script enqueue for the CODESM Decoupled Bundle.
 *
 * Adds a top-level menu page, conditionally enqueues the compiled React admin
 * app only on that page, and passes all required data to JS via wp_localize_script.
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

namespace CODESM\DecoupledBundle;

if (!defined('ABSPATH')) exit;

/**
 * Class Admin
 *
 * The admin page renders a single div#codesm-decoupled-bundle-admin which the
 * React app mounts into. All initial state (settings, build log, i18n strings)
 * is injected via wp_localize_script so the React app has zero loading states
 * on first render.
 */
class Admin {

    /**
     * Registers WordPress admin hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action('admin_menu',            [self::class, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
        add_action('admin_notices',         [self::class, 'maybe_show_auto_build_notice']);
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    /**
     * Registers the top-level admin menu page.
     *
     * Uses the dashicons-rest-api icon and positions after the default
     * Settings group (position 60) to keep it out of the way.
     *
     * @return void
     */
    public static function add_menu_page(): void {
        add_menu_page(
            __('Decoupled Bundle', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
            __('Decoupled Bundle', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
            'manage_options',
            CODESM_DECOUPLED_BUNDLE_SLUG,
            [self::class, 'render_page'],
            'dashicons-rest-api',
            60
        );
    }

    /**
     * Outputs the React mount point.
     *
     * The React app takes over from here; all rendering happens in JS.
     *
     * @return void
     */
    public static function render_page(): void {
        echo '<h1 class="wp-heading-inline">' . esc_html__('CODESM Decoupled Bundle', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . '</h1>';
        echo '<hr class="wp-header-end">';
        echo '<div id="codesm-decoupled-bundle-admin"></div>';
    }

    // -------------------------------------------------------------------------
    // Admin notice
    // -------------------------------------------------------------------------

    /**
     * Shows a site-wide admin notice when an auto-build is pending.
     *
     * Only visible to users with manage_options. Links to the Deployments tab.
     *
     * @return void
     */
    public static function maybe_show_auto_build_notice(): void {
        if (!current_user_can('manage_options')) return;

        $next = wp_next_scheduled(BuildManager::CRON_HOOK);
        if (!$next) return;

        $settings = Settings::get();
        if (empty($settings['build']['auto_enabled'])) return;

        $targets = $settings['build']['auto_targets'] ?? [];
        $repo    = $settings['build']['github_repo']  ?? '';
        $time    = gmdate('M j, Y H:i', $next) . ' UTC';

        $target_labels = [];
        foreach ($targets as $t) {
            if (empty($t['ref']) || empty($t['workflows'])) continue;
            $count           = count($t['workflows']);
            $target_labels[] = '<code>' . esc_html($t['ref']) . '</code> (' . $count . ' ' . _n('workflow', 'workflows', $count, CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . ')';
        }

        $deployments_url = admin_url('admin.php?page=' . CODESM_DECOUPLED_BUNDLE_SLUG . '&tab=deployments');

        echo '<div class="notice notice-info is-dismissible">';
        echo '<p style="display:flex;align-items:center;justify-content:space-between;gap:16px;">';
        echo '<span>';
        /* translators: %s: scheduled date/time */
        printf(esc_html__('Next auto-build: %s', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN), '<strong>' . esc_html($time) . '</strong>');
        if (!empty($repo)) {
            echo ': <code>' . esc_html($repo) . '</code>';
        }
        if (!empty($target_labels)) {
            echo ': ' . implode(', ', $target_labels); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — labels are escaped above
        }
        echo '</span>';
        echo '<a href="' . esc_url($deployments_url) . '" class="button button-primary" style="flex-shrink:0;">' . esc_html__('View Deployments', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . '</a>';
        echo '</p>';
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Scripts & styles
    // -------------------------------------------------------------------------

    /**
     * Enqueues the compiled React admin bundle on the plugin page only.
     *
     * Bails early on any other admin screen to avoid polluting the global
     * admin asset stack. The build/index.asset.php file is generated by
     * @wordpress/scripts and contains the correct version hash and deps.
     *
     * @param  string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueue_scripts(string $hook): void {
        if ($hook !== 'toplevel_page_' . CODESM_DECOUPLED_BUNDLE_SLUG) return;

        $asset_file = CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'build/index.asset.php';
        if (!file_exists($asset_file)) return;

        $asset = require $asset_file;

        wp_enqueue_script(
            'codesm-decoupled-bundle-admin',
            CODESM_DECOUPLED_BUNDLE_URL . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        if (file_exists(CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'build/index.css')) {
            wp_enqueue_style(
                'codesm-decoupled-bundle-admin',
                CODESM_DECOUPLED_BUNDLE_URL . 'build/index.css',
                ['wp-components'],
                $asset['version']
            );
        }

        self::localize_data();
    }

    /**
     * Passes plugin data to the React app via the global codesmDecoupledBundle object.
     *
     * Initial settings and the dispatch log are pre-loaded here so the React app
     * renders without any loading spinners on page open. The builds endpoint
     * (which hits GitHub) is loaded lazily by the UI on demand.
     *
     * @return void
     */
    private static function localize_data(): void {
        wp_localize_script('codesm-decoupled-bundle-admin', 'codesmDecoupledBundle', [
            'restUrl'         => esc_url_raw(rest_url('codesm-decoupled-bundle/v1')),
            'restNonce'       => wp_create_nonce('wp_rest'),
            'initialSettings' => Settings::get(),
            'initialLog'      => BuildManager::get_log(),
            'i18n'            => [
                'pluginName'    => __('CODESM Decoupled Bundle', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'settingsSaved' => __('Settings saved successfully.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'saveError'     => __('Error saving settings. Please try again.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'buildSuccess'  => __('Build triggered successfully!', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'buildError'    => __('Error triggering build. Check your GitHub settings.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'saving'        => __('Saving…', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'triggering'    => __('Triggering…', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'refreshing'    => __('Refreshing…', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'saveSettings'  => __('Save Settings', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'triggerBuild'  => __('Trigger Build Now', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'refreshBuilds' => __('Refresh', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'neverBuilt'    => __('No build triggered yet.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'lastTriggered' => __('Last triggered:', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
            ],
        ]);
    }
}
