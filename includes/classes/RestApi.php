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
    }

    // -------------------------------------------------------------------------
    // Permission callback
    // -------------------------------------------------------------------------

    /**
     * Permission callback for admin-only routes.
     *
     * @return bool True if the current user has the manage_options capability.
     */
    public static function require_admin(): bool {
        return current_user_can('manage_options');
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
     * @param  \WP_REST_Request $request Incoming REST request.
     * @return \WP_REST_Response         Non-sensitive settings object.
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
        $workflow_id = sanitize_text_field((string) ($request->get_param('workflow_id') ?? ''));
        $ref         = sanitize_text_field((string) ($request->get_param('ref')         ?? ''));

        if (empty($workflow_id) || !preg_match('/^\d+$/', $workflow_id)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('A valid workflow_id is required.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
            ], 400);
        }

        $result = BuildManager::trigger($workflow_id, 'manual', $ref);

        if (is_wp_error($result)) {
            $status = $result->get_error_code() === 'rate_limited' ? 429 : 500;
            return new \WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], $status);
        }

        $last_log  = BuildManager::get_log();
        $timestamp = $last_log[0]['dispatched_at'] ?? current_time('mysql');

        return new \WP_REST_Response([
            'success'     => true,
            'message'     => __('Build triggered successfully.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
            'timestamp'   => $timestamp,
            'workflow_id' => $workflow_id,
        ], 200);
    }

    /**
     * GET /branches — Admin endpoint returning all branches for the configured repo.
     *
     * Returns branch names with the repo's default branch flagged so the UI can
     * pre-select it. Used to populate the branch dropdown for workflow dispatch.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          { branches[], error: string|null }
     */
    public static function handle_get_branches(\WP_REST_Request $_request): \WP_REST_Response {
        return new \WP_REST_Response(BuildManager::get_branches(), 200);
    }

    /**
     * GET /workflows — Admin endpoint returning all workflows for the configured repo.
     *
     * Fetches the live workflow list from GitHub and merges it with the saved
     * auto/manual enabled state so the UI can render toggles immediately.
     *
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          { workflows[], error: string|null }
     */
    public static function handle_get_workflows(\WP_REST_Request $_request): \WP_REST_Response {
        return new \WP_REST_Response(BuildManager::get_workflows(), 200);
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
     * @param  \WP_REST_Request $_request Incoming REST request.
     * @return \WP_REST_Response          { runs[], local_log[], error: string|null }
     */
    public static function handle_get_builds(\WP_REST_Request $_request): \WP_REST_Response {
        return new \WP_REST_Response(BuildManager::get_builds(), 200);
    }
}
