<?php
/**
 * REST API route registration and request handling.
 *
 * Registers all routes under the 'codesm-decoupled-bundle/v1' namespace.
 * Public routes are consumed by Astro at build time; admin routes require
 * the manage_options capability and a valid WP nonce.
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

namespace CODESM\DecoupledBundle;

if (!defined('ABSPATH')) exit;

/**
 * Class RestApi
 *
 * Route overview:
 *
 *  GET  /settings      — Public. Returns non-sensitive settings for Astro.
 *  POST /settings      — Admin. Saves the full settings payload.
 *  POST /trigger-build — Admin. Immediately dispatches a GitHub Actions build.
 *  GET  /builds        — Admin. Returns GitHub workflow runs + local dispatch log.
 */
class RestApi {

    /**
     * REST namespace used for all routes.
     *
     * @var string
     */
    const NAMESPACE = 'codesm-decoupled-bundle/v1';

    /**
     * Transient key prefix for API rate limiting (per user, per minute).
     *
     * @var string
     */
    const RATE_LIMIT_TRANSIENT_PREFIX = 'codesm_decoupled_bundle_api_rl_';

    /**
     * Transient key prefix for branches cache (per user).
     *
     * @var string
     */
    const BRANCHES_CACHE_PREFIX = 'codesm_decoupled_bundle_branches_';

    /**
     * Transient key prefix for workflows cache (per user).
     *
     * @var string
     */
    const WORKFLOWS_CACHE_PREFIX = 'codesm_decoupled_bundle_workflows_';

    /**
     * Transient key prefix for builds cache (per user).
     *
     * @var string
     */
    const BUILDS_CACHE_PREFIX = 'codesm_decoupled_bundle_builds_';

    /**
     * Cache duration for GitHub data (1 minute).
     *
     * @var int
     */
    const GITHUB_DATA_CACHE_DURATION = 60;

    /**
     * API rate limit: maximum requests per minute per user.
     *
     * @var int
     */
    const RATE_LIMIT_PER_MINUTE = 30;

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    /**
     * Registers the rest_api_init hook.
     *
     * @return void
     */
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Registers all plugin REST routes.
     *
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'handle_get_settings'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'handle_save_settings'],
                'permission_callback' => [self::class, 'require_admin'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/trigger-build', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_trigger_build'],
            'permission_callback' => [self::class, 'require_admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/workflows', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_get_workflows'],
            'permission_callback' => [self::class, 'require_admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/branches', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_get_branches'],
            'permission_callback' => [self::class, 'require_admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/builds', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_get_builds'],
            'permission_callback' => [self::class, 'require_admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/cancel-build', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_cancel_build'],
            'permission_callback' => [self::class, 'require_admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/clear-logs', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_clear_logs'],
            'permission_callback' => [self::class, 'require_admin'],
        ]);

        register_rest_route(self::NAMESPACE, '/update-info', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle_get_update_info'],
            'permission_callback' => [self::class, 'require_admin'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Permission callback
    // -------------------------------------------------------------------------

    /**
     * Permission callback for admin-only routes.
     *
     * Checks both capability and nonce (CSRF protection).
     * WordPress automatically includes X-WP-Nonce header from wp_localize_script.
     *
     * @return bool True if the current user has manage_options and nonce is valid.
     */
    public static function require_admin(): bool {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $nonce = sanitize_text_field($_SERVER['HTTP_X_WP_NONCE'] ?? '');
        return wp_verify_nonce($nonce, 'wp_rest') !== false;
    }

    /**
     * Checks if the current user has exceeded the API rate limit.
     *
     * Rate limit: 30 requests per minute per user.
     *
     * @return bool True if within limit, false if exceeded.
     */
    private static function check_rate_limit(): bool {
        $user_id = get_current_user_id();
        $minute  = (int) (time() / 60);
        $key     = "codesm_decoupled_bundle_api_rl_{$user_id}_{$minute}";
        $count   = (int) get_transient($key);

        if ($count >= 30) {
            return false;
        }

        set_transient($key, $count + 1, 61);
        return true;
    }

    // -------------------------------------------------------------------------
    // Route handlers
    // -------------------------------------------------------------------------

    /**
     * GET /settings — Public endpoint consumed by Astro at build time.
     *
     * Returns all non-sensitive settings (site info, contact, GTM, script
     * injection rules). GitHub credentials are stripped before the response.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          Non-sensitive settings object.
     */
    public static function handle_get_settings(\WP_REST_Request $_request): \WP_REST_Response {
        return new \WP_REST_Response(Settings::get_public(), 200);
    }

    /**
     * POST /settings — Admin endpoint to persist settings.
     *
     * Accepts a JSON body matching the settings schema. All values are
     * sanitized before being written. Returns the full saved settings
     * (including build credentials) so the admin UI can update in place.
     *
     * @param  \WP_REST_Request $request Incoming REST request with JSON body.
     * @return \WP_REST_Response         Success flag + full saved settings.
     */
    public static function handle_save_settings(\WP_REST_Request $request): \WP_REST_Response {
        $saved = Settings::save($request->get_json_params() ?? []);

        // If auto-build was just enabled, make sure the debounce is set up
        if (!empty($saved['build']['auto_enabled'])) {
            BuildManager::init();
        }

        return new \WP_REST_Response(['success' => true, 'settings' => $saved], 200);
    }

    /**
     * POST /trigger-build — Admin endpoint for a manual build dispatch.
     *
     * Expects a JSON body with `workflow_id` (numeric GitHub workflow ID).
     * Immediately sends a workflow_dispatch event to GitHub Actions.
     * Returns a 400 for an invalid/missing workflow_id, 429 if rate-limited,
     * or 500 on GitHub API failure.
     *
     * @param  \WP_REST_Request $request Incoming REST request with JSON body.
     * @return \WP_REST_Response         Success flag, message, and timestamp.
     */
    public static function handle_trigger_build(\WP_REST_Request $request): \WP_REST_Response {
        $result = BuildManager::trigger_manual(
            (string) ($request->get_param('workflow_id') ?? ''),
            (string) ($request->get_param('ref')         ?? '')
        );

        if (!$result['success']) {
            $status = match ($result['error_code'] ?? '') {
                'invalid_workflow' => 400,
                'rate_limited'     => 429,
                default            => 500,
            };
            return new \WP_REST_Response(['success' => false, 'message' => $result['message']], $status);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * GET /branches — Admin endpoint returning all branches for the configured repo.
     *
     * Returns branch names with the repo's default branch flagged so the UI can
     * pre-select it. Used to populate the branch dropdown for workflow dispatch.
     * Results are cached for 10 minutes to avoid excessive API calls.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          { branches[], error: string|null }
     */
    public static function handle_get_branches(\WP_REST_Request $_request): \WP_REST_Response {
        if (!self::check_rate_limit()) {
            return new \WP_REST_Response(['error' => __('API rate limit exceeded. Maximum 30 requests per minute.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)], 429);
        }

        $cache_key = 'codesm_decoupled_bundle_branches_' . get_current_user_id();
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['branches'])) {
            return new \WP_REST_Response($cached, 200);
        }

        $data = BuildManager::get_branches();
        set_transient($cache_key, $data, 600);
        return new \WP_REST_Response($data, 200);
    }

    /**
     * GET /workflows — Admin endpoint returning all workflows for the configured repo.
     *
     * Fetches the live workflow list from GitHub and merges it with the saved
     * auto/manual enabled state so the UI can render toggles immediately.
     * Results are cached for 10 minutes to avoid excessive API calls.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          { workflows[], error: string|null }
     */
    public static function handle_get_workflows(\WP_REST_Request $_request): \WP_REST_Response {
        if (!self::check_rate_limit()) {
            return new \WP_REST_Response(['error' => __('API rate limit exceeded. Maximum 30 requests per minute.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)], 429);
        }

        $cache_key = 'codesm_decoupled_bundle_workflows_' . get_current_user_id();
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['workflows'])) {
            return new \WP_REST_Response($cached, 200);
        }

        $data = BuildManager::get_workflows();
        set_transient($cache_key, $data, 600);
        return new \WP_REST_Response($data, 200);
    }

    /**
     * POST /cancel-build — Admin endpoint to cancel the pending auto-build cron event.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          Success flag.
     */
    public static function handle_cancel_build(\WP_REST_Request $_request): \WP_REST_Response {
        BuildManager::cancel_scheduled_build();
        return new \WP_REST_Response(['success' => true], 200);
    }

    /**
     * GET /builds — Admin endpoint returning GitHub workflow runs + local log.
     *
     * Fetches the 15 most recent runs across all workflows in the repo and merges
     * them with our local dispatch log so the UI can display source (WP Manual,
     * WP Auto, Code Push, etc.), status, duration, and a direct link to GitHub.
     *
     * Results are cached for 5 minutes to avoid hammering the GitHub API.
     *
     * @param  \WP_REST_Request $request Incoming REST request.
     * @return \WP_REST_Response         { runs[], local_log[], error: string|null }
     */
    public static function handle_get_builds(\WP_REST_Request $request): \WP_REST_Response {
        if (!self::check_rate_limit()) {
            return new \WP_REST_Response(['error' => __('API rate limit exceeded. Maximum 30 requests per minute.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)], 429);
        }

        $skip_cache = !empty($request->get_param('_t'));
        $cache_key = 'codesm_decoupled_bundle_builds_' . get_current_user_id();

        if (!$skip_cache) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['runs'])) {
                return new \WP_REST_Response($cached, 200);
            }
        }

        $data = BuildManager::get_builds();
        set_transient($cache_key, $data, 60);
        return new \WP_REST_Response($data, 200);
    }

    /**
     * POST /clear-logs — Admin endpoint to clear the local dispatch log.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response           { success: bool, cleared: bool }
     */
    public static function handle_clear_logs(\WP_REST_Request $_request): \WP_REST_Response {
        $cleared = BuildManager::clear_logs();
        wp_cache_flush();

        // Invalidate the builds cache for all users so fresh data is fetched
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            'codesm_decoupled_bundle_builds_%'
        ));

        return new \WP_REST_Response(['success' => true, 'cleared' => $cleared], 200);
    }

    /**
     * GET /update-info — Admin endpoint to fetch latest stable and prerelease versions.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          { stable: {...}, prerelease: {...}, error: ?string }
     */
    public static function handle_get_update_info(\WP_REST_Request $_request): \WP_REST_Response {
        $info = GitHubUpdater::get_version_info();
        return new \WP_REST_Response($info, 200);
    }
}
