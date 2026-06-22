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
        add_action('admin_notices',         [self::class, 'maybe_show_maintenance_mode_notice']);
        add_action('admin_footer',          [self::class, 'output_countdown_script']);
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
     * Returns CSS rules for the maintenance mode notice.
     *
     * @return string
     */
    private static function get_admin_notice_styles(): string {
        return <<<CSS
            .codesm-decoupled-bundle-notice-title {
                font-weight: bold;
                margin: 10px 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .codesm-decoupled-bundle-notice-title .dashicons {
                width: 24px;
                height: 24px;
                font-size: 24px;
            }
            .codesm-decoupled-bundle-notice-message {
                margin: 5px 0;
            }
            .codesm-decoupled-bundle-notice-meta {
                margin: 5px 0;
                font-size: 0.9em;
                color: #666;
            }
            .codesm-decoupled-bundle-notice-actions {
                margin: 10px 0 0 0;
            }
            .codesm-decoupled-bundle-notice-hint {
                font-size: 0.9em;
                color: #666;
            }
            .codesm-decoupled-bundle-countdown {
                font-weight: 900;
                color: #000;
            }
        CSS;
    }

    /**
     * Returns JavaScript for live countdown timer in maintenance mode notice.
     *
     * @return string
     */
    private static function get_countdown_script(): string {
        return <<<JS
            (function() {
                function formatTimeRemaining(seconds) {
                    if (seconds <= 0) return '0 seconds';
                    const units = {
                        day: 86400,
                        hour: 3600,
                        minute: 60,
                        second: 1
                    };
                    const parts = [];
                    for (const [unit, value] of Object.entries(units)) {
                        const count = Math.floor(seconds / value);
                        if (count > 0) {
                            parts.push(count + ' ' + unit + (count > 1 ? 's' : ''));
                            seconds %= value;
                        }
                    }
                    return parts.slice(0, 2).join(' ');
                }

                function updateCountdowns() {
                    const notices = document.querySelectorAll('[data-codesm-decoupled-bundle-end-time]');
                    notices.forEach(notice => {
                        const endTime = parseInt(notice.getAttribute('data-codesm-decoupled-bundle-end-time'), 10);
                        const now = Math.floor(Date.now() / 1000);
                        const remaining = endTime - now;

                        const countdownEl = notice.querySelector('.codesm-decoupled-bundle-countdown');
                        if (!countdownEl) return;

                        if (remaining <= 0) {
                            countdownEl.textContent = 'ended';
                            return;
                        }

                        countdownEl.textContent = formatTimeRemaining(remaining);
                    });
                }

                function init() {
                    updateCountdowns();
                    setInterval(updateCountdowns, 1000);
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init);
                } else {
                    init();
                }
            })();
        JS;
    }

    /**
     * Shows a site-wide admin notice for maintenance mode status.
     *
     * Displays two types of notices:
     * - ACTIVE: Maintenance mode is currently active with countdown
     * - SCHEDULED: Maintenance mode is enabled but not yet in the active window
     *
     * Only visible to users with manage_options.
     *
     * @return void
     */
    public static function maybe_show_maintenance_mode_notice(): void {
        if (!current_user_can('manage_options')) return;

        $settings = Settings::get();
        $maintenance = $settings['maintenance'] ?? [];

        if (empty($maintenance['enabled'])) return;

        $now = current_time('timestamp');
        $from = (int) ($maintenance['from'] ?? 0);
        $to = (int) ($maintenance['to'] ?? 0);

        $is_active = Settings::is_maintenance_mode();
        $class = $is_active ? 'notice-warning' : 'notice-info';
        $icon_class = $is_active ? 'dashicons-warning' : 'dashicons-info';

        if ($is_active) {
            // ACTIVE: Show countdown
            $remaining = $to > 0 ? $to - $now : null;
            $countdown = $remaining ? self::format_time_remaining($remaining) : __('indefinite duration', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);

            $data_attr = $to > 0 ? ' data-codesm-decoupled-bundle-end-time="' . esc_attr((string) $to) . '"' : '';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible" role="status" aria-live="polite"' . $data_attr . '>';
            echo '<p class="codesm-decoupled-bundle-notice-title">';
            echo '<span class="dashicons ' . esc_attr($icon_class) . '"></span> ' . esc_html__('Maintenance Mode is ACTIVE', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            echo '</p>';
            echo '<p class="codesm-decoupled-bundle-notice-message">';
            echo esc_html__('The site is currently in maintenance mode.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            echo ' ' . esc_html__('Estimated duration:', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . ' <span class="codesm-decoupled-bundle-countdown">' . esc_html($countdown) . '</span>';
            echo '</p>';
            echo '<p class="codesm-decoupled-bundle-notice-meta">';
            echo esc_html__('Ends:', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . ' ';
            if ($to > 0) {
                echo '<code>' . esc_html(gmdate('Y-m-d H:i:s \U\T\C', $to)) . '</code>';
            } else {
                echo esc_html__('open-ended', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            }
            echo '</p>';
            echo '<p class="codesm-decoupled-bundle-notice-actions">';
            $settings_url = admin_url('admin.php?page=' . CODESM_DECOUPLED_BUNDLE_SLUG . '&tab=maintenance');
            echo '<a href="' . esc_url($settings_url) . '" class="button button-secondary">' . esc_html__('Manage Settings', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . '</a>';
            echo '</p>';
            echo '</div>';
        } else {
            // SCHEDULED: Show that it's not yet active
            $time_until = $from > 0 ? $from - $now : 0;
            $status = $from > 0
                ? sprintf(
                    /* translators: %s: time until maintenance starts */
                    esc_html__('Maintenance mode is scheduled but not yet active. It will start in %s.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                    '<strong>' . esc_html(self::format_time_remaining($time_until)) . '</strong>'
                )
                : esc_html__('Maintenance mode is enabled but has no scheduled time. It will not become active until configured.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);

            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible" role="status" aria-live="polite">';
            echo '<p class="codesm-decoupled-bundle-notice-title">';
            echo '<span class="dashicons ' . esc_attr($icon_class) . '"></span> ' . esc_html__('Maintenance Mode is SCHEDULED', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            echo '</p>';
            echo '<p class="codesm-decoupled-bundle-notice-message">';
            echo wp_kses_post($status);
            echo '</p>';
            if ($from > 0) {
                echo '<p class="codesm-decoupled-bundle-notice-meta">';
                echo esc_html__('Starts:', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . ' ';
                echo '<code>' . esc_html(gmdate('Y-m-d H:i:s \U\T\C', $from)) . '</code>';
                echo '</p>';
            }
            echo '<p class="codesm-decoupled-bundle-notice-actions">';
            $settings_url = admin_url('admin.php?page=' . CODESM_DECOUPLED_BUNDLE_SLUG . '&tab=maintenance');
            echo '<a href="' . esc_url($settings_url) . '" class="button button-secondary">' . esc_html__('Manage Settings', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . '</a>';
            echo ' ';
            echo '<span class="codesm-decoupled-bundle-notice-hint">' . esc_html__('(Consider disabling if no longer needed)', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN) . '</span>';
            echo '</p>';
            echo '</div>';
        }
    }

    /**
     * Outputs the countdown script in the admin footer.
     *
     * @return void
     */
    public static function output_countdown_script(): void {
        echo '<script>' . self::get_countdown_script() . '</script>';
    }

    /**
     * Formats a time duration in seconds to a human-readable string.
     *
     * @param  int $seconds Duration in seconds.
     * @return string Formatted duration (e.g., "2 hours 30 minutes").
     */
    private static function format_time_remaining(int $seconds): string {
        if ($seconds <= 0) {
            return __('0 seconds', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
        }

        $units = [
            'day'    => 86400,
            'hour'   => 3600,
            'minute' => 60,
        ];

        $parts = [];
        foreach ($units as $unit => $divisor) {
            if ($seconds >= $divisor) {
                $count = floor($seconds / $divisor);
                $seconds %= $divisor;
                $parts[] = $count . ' ' . _n($unit, $unit . 's', $count, CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            }
        }

        if ($seconds > 0) {
            $parts[] = $seconds . ' ' . _n('second', 'seconds', $seconds, CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
        }

        return implode(', ', array_slice($parts, 0, 2));
    }

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
            echo ': ' . wp_kses_post(implode(', ', $target_labels));
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

        wp_add_inline_style('common', self::get_admin_notice_styles());
        self::localize_data();
    }

    /**
     * Passes plugin data to the React app via the global codesmDecoupledBundle object.
     *
     * SECURITY: The GitHub token is never exposed to the frontend. Instead, we
     * pass a boolean flag so the UI knows whether to show a "token is set" state
     * vs an empty state. When saving, if the token field is empty, the existing
     * token is preserved; if it has a value, it's treated as a new token.
     *
     * @return void
     */
    private static function localize_data(): void {
        $settings = Settings::get();
        $safe_settings = $settings;

        // Replace token with a boolean flag: true if set, false if empty
        $safe_settings['build']['github_token'] = !empty($settings['build']['github_token']);

        wp_localize_script('codesm-decoupled-bundle-admin', 'codesmDecoupledBundle', [
            'restUrl'         => esc_url_raw(rest_url('codesm-decoupled-bundle/v1')),
            'restNonce'       => wp_create_nonce('wp_rest'),
            'initialSettings' => $safe_settings,
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
                'clearLogs'     => __('Clear Logs', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
            ],
        ]);
    }
}
