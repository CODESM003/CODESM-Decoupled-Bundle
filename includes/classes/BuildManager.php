<?php
/**
 * GitHub Actions build trigger and deployment log management.
 *
 * Handles dispatching workflow_dispatch events to GitHub, maintaining a local
 * dispatch log, scheduling debounced auto-builds via WP Cron, and fetching
 * live workflow run and workflow-list data from the GitHub REST API.
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

namespace CODESM\DecoupledBundle;

if (!defined('ABSPATH')) exit;

/**
 * Class BuildManager
 *
 * Trigger types:
 *  - 'manual'  → triggered by the admin clicking the trigger button for a specific workflow
 *  - 'auto'    → triggered by WP Cron after a debounce window expires (all auto-enabled workflows)
 */
class BuildManager {

    /**
     * WordPress option key for the local dispatch log array.
     *
     * @var string
     */
    const BUILDS_LOG_KEY = 'codesm_decoupled_bundle_builds_log';

    /**
     * WP Cron action hook name.
     *
     * @var string
     */
    const CRON_HOOK = 'codesm_decoupled_bundle_auto_build';

    /**
     * Maximum number of log entries to keep in the database.
     *
     * @var int
     */
    const MAX_LOG_ENTRIES = 20;

    /**
     * Transient key prefix for per-workflow manual trigger rate limiting.
     *
     * Full key: RATE_LIMIT_PREFIX . $workflow_id
     *
     * @var string
     */
    const RATE_LIMIT_PREFIX = 'codesm_decoupled_bundle_rl_';

    /**
     * Minimum seconds between manual dispatches of the same workflow.
     *
     * @var int
     */
    const RATE_LIMIT_SECONDS = 60;

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    /**
     * Registers all WordPress hooks managed by this class.
     *
     * @return void
     */
    public static function init(): void {
        // Trigger debounce when a post/page goes live or is removed from live
        add_action('transition_post_status', [self::class, 'on_post_status_changed'], 10, 3);

        // Trigger debounce when core WP settings change
        add_action('updated_option', [self::class, 'on_option_updated'], 10, 3);

        // Cron handler — fires after the debounce window expires
        add_action(self::CRON_HOOK, [self::class, 'handle_auto_build']);
    }

    // -------------------------------------------------------------------------
    // Content-change hooks
    // -------------------------------------------------------------------------

    /**
     * Schedules a debounced build when a post's visibility changes in a way that
     * affects the public Astro frontend.
     *
     * Triggers on:
     *  - Any post going to `publish` or `private` (new content live)
     *  - A `publish`/`private` post being moved to `trash` (content removed)
     *
     * Does NOT trigger on:
     *  - Drafts being trashed (never visible on the frontend)
     *  - Revisions or autosaves
     *
     * @param  string   $new_status New post status.
     * @param  string   $old_status Previous post status.
     * @param  \WP_Post $post       The post object.
     * @return void
     */
    public static function on_post_status_changed(string $new_status, string $old_status, \WP_Post $post): void {
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) return;

        $live_statuses  = ['publish', 'private'];
        $going_live     = in_array($new_status, $live_statuses, true);
        $being_removed  = $new_status === 'trash' && in_array($old_status, $live_statuses, true);

        if (!$going_live && !$being_removed) return;

        $settings = Settings::get();
        if (empty($settings['build']['auto_enabled'])) return;
        if (!self::has_auto_workflows($settings)) return;

        self::schedule_debounced_build($settings);
    }

    /**
     * Schedules a debounced build when a watched core WordPress option changes.
     *
     * Only reacts to options that would meaningfully affect the static Astro
     * frontend: site name, tagline, and URL settings.
     * Ignores changes to our own option to prevent infinite loops.
     *
     * @param  string $option    The option name being updated.
     * @param  mixed  $old_value Previous value.
     * @param  mixed  $new_value New value.
     * @return void
     */
    public static function on_option_updated(string $option, mixed $old_value, mixed $new_value): void {
        if ($option === Settings::OPTION_NAME) return;

        $watched = ['blogname', 'blogdescription', 'siteurl', 'home'];
        if (!in_array($option, $watched, true)) return;

        $settings = Settings::get();
        if (empty($settings['build']['auto_enabled'])) return;
        if (!self::has_auto_workflows($settings)) return;

        self::schedule_debounced_build($settings);
    }

    // -------------------------------------------------------------------------
    // Cron / debounce
    // -------------------------------------------------------------------------

    /**
     * WP Cron callback. Fires once the debounce window has elapsed.
     *
     * Dispatches all workflows that have auto: true.
     *
     * @return void
     */
    public static function handle_auto_build(): void {
        self::trigger_auto_builds();
    }

    /**
     * Triggers all workflows across all configured auto_targets.
     *
     * Each target dispatches its workflows to its own branch. Errors per-workflow
     * are logged and do not abort remaining targets or workflows.
     *
     * @return void
     */
    public static function trigger_auto_builds(): void {
        $settings     = Settings::get();
        $auto_targets = $settings['build']['auto_targets'] ?? [];

        if (empty($auto_targets)) {
            error_log('[CODESM Decoupled Bundle] Auto-build skipped: no targets configured.');
            return;
        }

        foreach ($auto_targets as $target) {
            $ref       = $target['ref']       ?? '';
            $workflows = $target['workflows'] ?? [];

            if (empty($ref) || empty($workflows)) continue;

            foreach ($workflows as $workflow_id) {
                self::trigger($workflow_id, 'auto', $ref);
            }
        }
    }

    /**
     * Cancels any existing scheduled build event.
     *
     * Called on plugin deactivation to prevent orphaned cron entries.
     *
     * @return void
     */
    public static function cancel_scheduled_build(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Schedules a single cron event after the configured debounce delay.
     *
     * If an event is already scheduled, it is cancelled first so that rapid
     * successive changes only result in a single burst of builds at the end.
     *
     * @param  array<string, mixed> $settings Full plugin settings array.
     * @return void
     */
    private static function schedule_debounced_build(array $settings): void {
        $delay = max(60, (int) ($settings['build']['debounce_minutes'] ?? 5) * 60);

        self::cancel_scheduled_build();

        wp_schedule_single_event(time() + $delay, self::CRON_HOOK);
    }

    // -------------------------------------------------------------------------
    // GitHub dispatch
    // -------------------------------------------------------------------------

    /**
     * Dispatches a workflow_dispatch event to GitHub Actions for a specific workflow.
     *
     * Manual triggers are rate-limited per workflow (once per RATE_LIMIT_SECONDS)
     * to prevent accidental API spam. Auto triggers bypass the rate limit because
     * the cron debounce already enforces a minimum interval.
     *
     * On success GitHub responds with HTTP 204 No Content. The local dispatch
     * log is updated regardless of success or failure.
     *
     * @param  string         $workflow_id  Numeric GitHub workflow ID.
     * @param  string         $trigger      'manual' or 'auto'.
     * @param  string         $ref          Branch or tag to dispatch on. Falls back to saved github_ref then 'main'.
     * @return bool|\WP_Error True on success, WP_Error with client-safe message on failure.
     */
    public static function trigger(string $workflow_id, string $trigger = 'manual', string $ref = ''): bool|\WP_Error {
        if (empty($workflow_id) || !preg_match('/^\d+$/', $workflow_id)) {
            return new \WP_Error('invalid_workflow', __('Invalid workflow ID.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN));
        }

        // Per-workflow rate limit for manual triggers
        $rl_key = self::RATE_LIMIT_PREFIX . $workflow_id;
        if ($trigger === 'manual' && get_transient($rl_key)) {
            $error = __('A build was triggered recently for this workflow. Please wait before triggering again.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            return new \WP_Error('rate_limited', $error);
        }

        $settings = Settings::get();
        $token    = $settings['build']['github_token'] ?? '';
        $repo     = $settings['build']['github_repo']  ?? '';
        // Use caller-supplied ref, fall back to saved setting, then 'main'
        if (empty($ref)) {
            $ref = $settings['build']['github_ref'] ?? '';
        }

        if (empty($token) || empty($repo)) {
            $error = __('GitHub configuration is incomplete. Please check plugin settings.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            self::log_dispatch($workflow_id, $trigger, false, $error);
            return new \WP_Error('missing_config', $error);
        }

        $url      = "https://api.github.com/repos/{$repo}/actions/workflows/{$workflow_id}/dispatches";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization'        => "Bearer {$token}",
                'Accept'               => 'application/vnd.github+json',
                'Content-Type'         => 'application/json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'CODESM-Decoupled-Bundle/' . CODESM_DECOUPLED_BUNDLE_VERSION,
            ],
            'body'    => wp_json_encode(['ref' => $ref]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $internal = $response->get_error_message();
            error_log("[CODESM Decoupled Bundle] Dispatch failed (workflow {$workflow_id}): {$internal}");
            $client_error = __('Could not reach GitHub. Please check your network and try again.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            self::log_dispatch($workflow_id, $trigger, false, $internal);
            return new \WP_Error('request_failed', $client_error);
        }

        $code = wp_remote_retrieve_response_code($response);

        // GitHub returns 204 No Content on a successful dispatch
        if ($code !== 204) {
            $body     = json_decode(wp_remote_retrieve_body($response), true);
            $internal = $body['message'] ?? "GitHub API returned status {$code}.";
            error_log("[CODESM Decoupled Bundle] Dispatch error (workflow {$workflow_id}): {$internal}");
            $client_message = $code === 401 || $code === 403
                ? __('GitHub rejected the request. Please verify your Personal Access Token.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)
                : sprintf(
                    /* translators: %d: HTTP status code */
                    __('Build dispatch failed (status %d). Check your GitHub settings.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                    $code
                );
            self::log_dispatch($workflow_id, $trigger, false, $internal);
            return new \WP_Error('github_api_error', $client_message);
        }

        if ($trigger === 'manual') {
            set_transient($rl_key, 1, self::RATE_LIMIT_SECONDS);
        }

        self::log_dispatch($workflow_id, $trigger, true);

        return true;
    }

    // -------------------------------------------------------------------------
    // GitHub workflow list
    // -------------------------------------------------------------------------

    /**
     * Fetches all workflows for the configured repo from GitHub.
     *
     * Merges live GitHub data with the saved enabled/disabled state so the UI
     * can render toggles without a round-trip to settings.
     *
     * @return array{
     *   workflows: list<array<string, mixed>>,
     *   error: string|null
     * }
     */
    public static function get_workflows(): array {
        $settings = Settings::get();
        $token    = $settings['build']['github_token'] ?? '';
        $repo     = $settings['build']['github_repo']  ?? '';

        if (empty($token) || empty($repo)) {
            return ['workflows' => [], 'error' => __('GitHub is not configured.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)];
        }

        $url      = "https://api.github.com/repos/{$repo}/actions/workflows?per_page=100";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization'        => "Bearer {$token}",
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'CODESM-Decoupled-Bundle/' . CODESM_DECOUPLED_BUNDLE_VERSION,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[CODESM Decoupled Bundle] Workflow list fetch failed: ' . $response->get_error_message());
            return ['workflows' => [], 'error' => __('Could not reach GitHub.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $internal = $body['message'] ?? "GitHub API returned status {$code}.";
            error_log('[CODESM Decoupled Bundle] Workflow list error: ' . $internal);
            $client_error = $code === 401 || $code === 403
                ? __('GitHub rejected the request. Please verify your Personal Access Token.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)
                : __('Could not load workflows. Check your GitHub settings.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            return ['workflows' => [], 'error' => $client_error];
        }

        $workflows = [];
        foreach ($body['workflows'] ?? [] as $wf) {
            $id = (string) ($wf['id'] ?? '');

            $html_url = $wf['html_url'] ?? '';
            if (!str_starts_with($html_url, 'https://github.com/')) {
                $html_url = '';
            }

            $workflows[] = [
                'id'       => $id,
                'name'     => $wf['name']  ?? '',
                'filename' => basename($wf['path'] ?? ''),
                'state'    => $wf['state'] ?? 'unknown', // active | disabled_manually | disabled_inactivity
                'html_url' => $html_url,
            ];
        }

        return ['workflows' => $workflows, 'error' => null];
    }

    /**
     * Fetches all branches for the configured repo from GitHub.
     *
     * Returns branch names sorted with the default branch first so the UI can
     * pre-select the most likely deployment target.
     *
     * @return array{
     *   branches: list<array{name: string, default: bool}>,
     *   error: string|null
     * }
     */
    public static function get_branches(): array {
        $settings = Settings::get();
        $token    = $settings['build']['github_token'] ?? '';
        $repo     = $settings['build']['github_repo']  ?? '';

        if (empty($token) || empty($repo)) {
            return ['branches' => [], 'error' => __('GitHub is not configured.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)];
        }

        // Fetch the repo default branch and branches list in parallel via two calls
        $repo_response = wp_remote_get("https://api.github.com/repos/{$repo}", [
            'headers' => [
                'Authorization'        => "Bearer {$token}",
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'CODESM-Decoupled-Bundle/' . CODESM_DECOUPLED_BUNDLE_VERSION,
            ],
            'timeout' => 15,
        ]);

        $default_branch = 'main';
        if (!is_wp_error($repo_response) && wp_remote_retrieve_response_code($repo_response) === 200) {
            $repo_data      = json_decode(wp_remote_retrieve_body($repo_response), true);
            $default_branch = $repo_data['default_branch'] ?? 'main';
        }

        $url      = "https://api.github.com/repos/{$repo}/branches?per_page=100";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization'        => "Bearer {$token}",
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'CODESM-Decoupled-Bundle/' . CODESM_DECOUPLED_BUNDLE_VERSION,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[CODESM Decoupled Bundle] Branches fetch failed: ' . $response->get_error_message());
            return ['branches' => [], 'error' => __('Could not reach GitHub.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $internal = $body['message'] ?? "GitHub API returned status {$code}.";
            error_log('[CODESM Decoupled Bundle] Branches fetch error: ' . $internal);
            $client_error = $code === 401 || $code === 403
                ? __('GitHub rejected the request. Please verify your Personal Access Token.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)
                : __('Could not load branches. Check your GitHub settings.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            return ['branches' => [], 'error' => $client_error];
        }

        $branches = [];
        foreach ($body as $branch) {
            $name       = $branch['name'] ?? '';
            $is_default = $name === $default_branch;
            $branches[] = ['name' => $name, 'default' => $is_default];
        }

        // Sort: default branch first, then alphabetical
        usort($branches, function (array $a, array $b): int {
            if ($a['default'] !== $b['default']) return $a['default'] ? -1 : 1;
            return strcmp($a['name'], $b['name']);
        });

        return ['branches' => $branches, 'error' => null];
    }

    // -------------------------------------------------------------------------
    // GitHub runs fetch
    // -------------------------------------------------------------------------

    /**
     * Fetches the 15 most recent workflow runs across all workflows in the repo.
     *
     * Merges with the local dispatch log to label which runs were initiated
     * from WordPress (manual or auto) vs triggered by other events (push, etc.).
     *
     * @return array{
     *   runs: list<array<string, mixed>>,
     *   local_log: list<array<string, mixed>>,
     *   error: string|null
     * }
     */
    public static function get_builds(): array {
        $settings  = Settings::get();
        $token     = $settings['build']['github_token'] ?? '';
        $repo      = $settings['build']['github_repo']  ?? '';
        $local_log = self::get_log();

        $next = wp_next_scheduled(self::CRON_HOOK);
        $next_scheduled         = $next ? gmdate('Y-m-d\TH:i:s\Z', $next) : null;
        $next_scheduled_targets = $next
            ? array_map(fn($t) => ['ref' => $t['ref'], 'workflow_count' => count($t['workflows'] ?? [])], $settings['build']['auto_targets'] ?? [])
            : [];

        if (empty($token) || empty($repo)) {
            return ['runs' => [], 'local_log' => $local_log, 'error' => __('GitHub is not configured.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN), 'next_scheduled' => $next_scheduled, 'next_scheduled_targets' => $next_scheduled_targets];
        }

        $url      = "https://api.github.com/repos/{$repo}/actions/runs?per_page=15";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization'        => "Bearer {$token}",
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'CODESM-Decoupled-Bundle/' . CODESM_DECOUPLED_BUNDLE_VERSION,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[CODESM Decoupled Bundle] Runs fetch failed: ' . $response->get_error_message());
            return ['runs' => [], 'local_log' => $local_log, 'error' => __('Could not reach GitHub.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN), 'next_scheduled' => $next_scheduled, 'next_scheduled_targets' => $next_scheduled_targets];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['workflow_runs'])) {
            $internal = $body['message'] ?? "GitHub API returned status {$code}.";
            error_log('[CODESM Decoupled Bundle] Runs fetch error: ' . $internal);
            $client_error = $code === 401 || $code === 403
                ? __('GitHub rejected the request. Please verify your Personal Access Token.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN)
                : __('Could not load deployment history. Check your GitHub settings.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN);
            return ['runs' => [], 'local_log' => $local_log, 'error' => $client_error, 'next_scheduled' => $next_scheduled, 'next_scheduled_targets' => $next_scheduled_targets];
        }

        $runs = array_map(
            fn(array $run): array => self::map_run($run, $local_log),
            $body['workflow_runs']
        );

        return [
            'runs'               => $runs,
            'local_log'          => $local_log,
            'error'              => null,
            'next_scheduled'     => $next_scheduled,
            'next_scheduled_targets' => $next_scheduled_targets,
        ];
    }

    // -------------------------------------------------------------------------
    // Local dispatch log
    // -------------------------------------------------------------------------

    /**
     * Prepends a new entry to the local dispatch log.
     *
     * Trims the log to MAX_LOG_ENTRIES so the option row stays small.
     *
     * @param  string $workflow_id GitHub workflow ID that was dispatched.
     * @param  string $trigger     'manual' or 'auto'.
     * @param  bool   $dispatch_ok Whether the GitHub API call succeeded.
     * @param  string $error       Internal error message if dispatch_ok is false.
     * @return void
     */
    private static function log_dispatch(string $workflow_id, string $trigger, bool $dispatch_ok, string $error = ''): void {
        $log = self::get_log();

        array_unshift($log, [
            'dispatched_at'     => current_time('mysql'),
            'dispatched_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'workflow_id'       => $workflow_id,
            'trigger'           => $trigger,
            'dispatch_ok'       => $dispatch_ok,
            'error'             => $error,
        ]);

        update_option(self::BUILDS_LOG_KEY, array_slice($log, 0, self::MAX_LOG_ENTRIES));
    }

    /**
     * Returns the raw local dispatch log array.
     *
     * @return list<array<string, mixed>>
     */
    public static function get_log(): array {
        return get_option(self::BUILDS_LOG_KEY, []);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true if at least one auto_target has a ref and at least one workflow.
     *
     * @param  array<string, mixed> $settings Full plugin settings.
     * @return bool
     */
    private static function has_auto_workflows(array $settings): bool {
        foreach ($settings['build']['auto_targets'] ?? [] as $target) {
            if (!empty($target['ref']) && !empty($target['workflows'])) return true;
        }
        return false;
    }

    /**
     * Maps a raw GitHub API workflow run object to a clean, UI-friendly shape.
     *
     * Enriches the run with a human-readable source label derived by
     * cross-referencing against the local dispatch log (±60 second window,
     * matching on both time and workflow_id).
     *
     * @param  array<string, mixed>      $run       Raw GitHub API run object.
     * @param  list<array<string,mixed>> $local_log Our local dispatch log.
     * @return array<string, mixed>                 Normalised run record.
     */
    private static function map_run(array $run, array $local_log): array {
        $run_created    = strtotime($run['created_at'] ?? '');
        $run_wf_id      = (string) ($run['workflow_id'] ?? '');
        $wp_source      = null;

        // Cross-reference: match on workflow_id AND timestamp within ±60 s
        if (($run['event'] ?? '') === 'workflow_dispatch' && $run_created !== false) {
            foreach ($local_log as $entry) {
                if (($entry['workflow_id'] ?? '') !== $run_wf_id) continue;
                $ts       = $entry['dispatched_at_utc'] ?? $entry['dispatched_at'] ?? '';
                $log_time = $ts ? strtotime($ts) : false;
                if ($log_time !== false && abs($run_created - $log_time) <= 60) {
                    $wp_source = $entry['trigger'] ?? null; // 'manual' | 'auto'
                    break;
                }
            }
        }

        // Human-readable source label
        $event  = $run['event'] ?? '';
        $source = match (true) {
            $wp_source === 'manual'   => 'WP Manual',
            $wp_source === 'auto'     => 'WP Auto',
            $event === 'push'         => 'Code Push',
            $event === 'schedule'     => 'Scheduled',
            $event === 'pull_request' => 'Pull Request',
            default                   => ucfirst(str_replace('_', ' ', $event)),
        };

        // Human-readable duration (only available once the run has completed)
        $duration = null;
        $started  = $run['run_started_at'] ?? null;
        if ($started && ($run['status'] ?? '') === 'completed') {
            $t_start = strtotime($started);
            $t_end   = strtotime($run['updated_at'] ?? '');
            if ($t_start !== false && $t_end !== false) {
                $seconds  = $t_end - $t_start;
                $duration = $seconds >= 60
                    ? floor($seconds / 60) . 'm ' . ($seconds % 60) . 's'
                    : $seconds . 's';
            }
        }

        // Only allow github.com links through
        $html_url = $run['html_url'] ?? '';
        if (!str_starts_with($html_url, 'https://github.com/')) {
            $html_url = '';
        }

        return [
            'id'          => $run['id']          ?? null,
            'workflow_id' => $run_wf_id,
            'run_number'  => $run['run_number']   ?? null,
            'name'        => $run['name']         ?? '',
            'status'      => $run['status']       ?? '',   // queued | in_progress | completed
            'conclusion'  => $run['conclusion']   ?? null, // success | failure | cancelled | timed_out | null
            'event'       => $event,
            'source'      => $source,
            'head_branch' => $run['head_branch']  ?? '',
            'head_sha'    => substr($run['head_sha'] ?? '', 0, 7),
            'created_at'  => $run['created_at']   ?? '',
            'started_at'  => $started,
            'updated_at'  => $run['updated_at']   ?? '',
            'duration'    => $duration,
            'html_url'    => $html_url,
        ];
    }
}
