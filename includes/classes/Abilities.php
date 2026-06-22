<?php
/**
 * Abilities API registration.
 *
 * Registers plugin operations as discoverable abilities so that external
 * systems (AI agents, automation tools) can find and invoke them via the
 * WordPress Abilities API (requires WordPress 6.9+).
 *
 * Each ability maps 1-to-1 with an existing REST route; the execute
 * callbacks delegate directly to Settings and BuildManager so there is no
 * duplicated logic.
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

namespace CODESM\DecoupledBundle;

if (!defined('ABSPATH')) exit;

/**
 * Class Abilities
 */
class Abilities {

    /** Ability category slug. */
    const CATEGORY = 'codesm-decoupled-bundle';

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    /**
     * Registers the Abilities API hooks, guarded so the plugin degrades
     * gracefully on WordPress < 6.9.
     *
     * @return void
     */
    public static function init(): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        add_action('wp_abilities_api_categories_init', [self::class, 'register_category']);
        add_action('wp_abilities_api_init',            [self::class, 'register_abilities']);
    }

    // -------------------------------------------------------------------------
    // Category
    // -------------------------------------------------------------------------

    /**
     * Registers the plugin's ability category.
     *
     * @return void
     */
    public static function register_category(): void {
        wp_register_ability_category(
            self::CATEGORY,
            [
                'label'       => __('Decoupled Bundle', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description' => __('Manage site settings, script injection, and Astro build triggers for decoupled WordPress + Astro setups.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Abilities
    // -------------------------------------------------------------------------

    /**
     * Registers all plugin abilities.
     *
     * @return void
     */
    public static function register_abilities(): void {
        self::register_get_settings();
        self::register_save_settings();
        self::register_get_workflows();
        self::register_get_branches();
        self::register_trigger_build();
        self::register_get_builds();
        self::register_cancel_build();
        self::register_clear_logs();
        self::register_get_maintenance_status();
        self::register_set_maintenance_mode();
    }

    /**
     * Returns the public (non-sensitive) plugin settings consumed by Astro.
     *
     * No authentication required — mirrors the public GET /settings REST route.
     *
     * @return void
     */
    private static function register_get_settings(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/get-settings',
            [
                'label'               => __('Get Public Settings', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Returns the public site settings (site info, contact, social, GTM, script injection rules). GitHub credentials are never included.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'site'    => [
                            'type'        => 'object',
                            'description' => __('Site title, description, and title format settings.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'contact' => [
                            'type'        => 'object',
                            'description' => __('Phone numbers, email addresses, and physical locations.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'social'  => [
                            'type'        => 'array',
                            'description' => __('Social media platform/URL pairs.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'gtm'     => [
                            'type'        => 'object',
                            'description' => __('Google Tag Manager container ID.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'scripts' => [
                            'type'        => 'object',
                            'description' => __('Global and per-page HTML/JS injection rules.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                    ],
                ],
                'permission_callback' => '__return_true',
                'execute_callback'    => [Settings::class, 'get_public'],
                'meta'                => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
            ]
        );
    }

    /**
     * Saves the full plugin settings (including build configuration).
     *
     * Requires manage_options — mirrors the admin POST /settings REST route.
     *
     * @return void
     */
    private static function register_save_settings(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/save-settings',
            [
                'label'               => __('Save Settings', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Saves the full plugin settings including site info, contact, social, GTM, script injection rules, and GitHub build configuration.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'site'    => ['type' => 'object'],
                        'contact' => ['type' => 'object'],
                        'social'  => ['type' => 'array'],
                        'gtm'     => ['type' => 'object'],
                        'scripts' => ['type' => 'object'],
                        'build'   => ['type' => 'object'],
                    ],
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'success'  => ['type' => 'boolean'],
                        'settings' => ['type' => 'object'],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => static function (array $input): array {
                    $saved = Settings::save($input);
                    return ['success' => true, 'settings' => $saved];
                },
                'meta'                => ['show_in_rest' => true, 'annotations' => ['idempotent' => true]],
            ]
        );
    }

    /**
     * Returns all available GitHub workflows for the configured repository.
     *
     * Requires manage_options — mirrors the admin GET /workflows REST route.
     * Useful for agents to discover which workflows can be triggered.
     *
     * @return void
     */
    private static function register_get_workflows(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/get-workflows',
            [
                'label'               => __('Get Available Workflows', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Returns all workflow_dispatch-enabled workflows in the GitHub repository. Use to discover which workflows can be triggered.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'workflows' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'       => ['type' => 'string'],
                                    'name'     => ['type' => 'string'],
                                    'filename' => ['type' => 'string'],
                                    'state'    => ['type' => 'string'],
                                    'html_url' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'error'     => ['type' => ['string', 'null']],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => [BuildManager::class, 'get_workflows'],
                'meta'                => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
            ]
        );
    }

    /**
     * Returns all available branches for the configured repository.
     *
     * Requires manage_options — mirrors the admin GET /branches REST route.
     * Useful for agents to discover which branches are available for deployment.
     *
     * @return void
     */
    private static function register_get_branches(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/get-branches',
            [
                'label'               => __('Get Available Branches', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Returns all branches in the GitHub repository. Use to discover which branches are available for workflow dispatch.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'branches' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'name'    => ['type' => 'string'],
                                    'default' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                        'error'    => ['type' => ['string', 'null']],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => [BuildManager::class, 'get_branches'],
                'meta'                => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
            ]
        );
    }

    /**
     * Manually dispatches a GitHub Actions workflow.
     *
     * Requires manage_options — mirrors the admin POST /trigger-build REST route.
     *
     * @return void
     */
    private static function register_trigger_build(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/trigger-build',
            [
                'label'               => __('Trigger Build', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Manually dispatches a GitHub Actions workflow. Subject to a 60-second per-workflow rate limit.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [
                    'type'       => 'object',
                    'required'   => ['workflow_id'],
                    'properties' => [
                        'workflow_id' => [
                            'type'        => 'string',
                            'pattern'     => '^\d+$',
                            'description' => __('Numeric GitHub workflow ID to dispatch.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'ref'         => [
                            'type'        => 'string',
                            'description' => __('Branch or tag ref to run the workflow against. Defaults to the repo default branch.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                    ],
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'success'     => ['type' => 'boolean'],
                        'message'     => ['type' => 'string'],
                        'workflow_id' => ['type' => 'string'],
                        'timestamp'   => ['type' => 'string'],
                        'error_code'  => ['type' => ['string', 'null']],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => static function (array $input): array {
                    return BuildManager::trigger_manual(
                        (string) ($input['workflow_id'] ?? ''),
                        (string) ($input['ref']         ?? ''),
                        true
                    );
                },
                'meta'                => ['show_in_rest' => true, 'annotations' => []],
            ]
        );
    }

    /**
     * Returns GitHub workflow runs merged with the local dispatch log.
     *
     * Requires manage_options — mirrors the admin GET /builds REST route.
     *
     * @return void
     */
    private static function register_get_builds(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/get-builds',
            [
                'label'               => __('Get Build History', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Returns the 15 most recent GitHub Actions runs merged with the local dispatch log, including trigger source, status, duration, and a direct link to GitHub.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'runs'      => [
                            'type'  => 'array',
                            'items' => ['type' => 'object'],
                        ],
                        'local_log' => [
                            'type'  => 'array',
                            'items' => ['type' => 'object'],
                        ],
                        'error'     => [
                            'type' => ['string', 'null'],
                        ],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => [BuildManager::class, 'get_builds'],
                'meta'                => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
            ]
        );
    }

    /**
     * Cancels the pending auto-build cron event.
     *
     * Requires manage_options — mirrors the admin POST /cancel-build REST route.
     *
     * @return void
     */
    private static function register_cancel_build(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/cancel-build',
            [
                'label'               => __('Cancel Pending Build', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Cancels the debounced auto-build cron event if one is currently scheduled.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'success' => ['type' => 'boolean'],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => static function (): array {
                    BuildManager::cancel_scheduled_build();
                    return ['success' => true];
                },
                'meta'                => ['show_in_rest' => true, 'annotations' => ['destructive' => true]],
            ]
        );
    }

    /**
     * Clears the local dispatch log.
     *
     * Requires manage_options — mirrors the admin POST /clear-logs REST route.
     * Useful for maintenance and privacy — removes build history stored in WordPress.
     *
     * @return void
     */
    private static function register_clear_logs(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/clear-logs',
            [
                'label'               => __('Clear Build Logs', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Clears the local WordPress dispatch log. Useful for maintenance and privacy — does not affect GitHub action history.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'success' => ['type' => 'boolean'],
                        'cleared' => [
                            'type'        => 'boolean',
                            'description' => __('True if logs were present and deleted, false if the log was already empty.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => static function (): array {
                    $cleared = BuildManager::clear_logs();
                    return ['success' => true, 'cleared' => $cleared];
                },
                'meta'                => ['show_in_rest' => true, 'annotations' => ['destructive' => true]],
            ]
        );
    }

    /**
     * Returns the current maintenance mode status.
     *
     * Public — no authentication required. Returns whether maintenance mode is active
     * and the scheduled time window.
     *
     * @return void
     */
    private static function register_get_maintenance_status(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/get-maintenance-status',
            [
                'label'               => __('Get Maintenance Mode Status', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Returns the current maintenance mode status including enabled state and scheduled time window.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'enabled'   => ['type' => 'boolean'],
                        'active'    => [
                            'type'        => 'boolean',
                            'description' => __('True if maintenance mode is currently active based on scheduled times.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'from'      => ['type' => 'integer'],
                        'to'        => ['type' => 'integer'],
                        'now'       => ['type' => 'integer'],
                    ],
                ],
                'permission_callback' => '__return_true',
                'execute_callback'    => static function (): array {
                    $settings = Settings::get();
                    $maintenance = $settings['maintenance'] ?? [];
                    return [
                        'enabled' => (bool) ($maintenance['enabled'] ?? false),
                        'active'  => Settings::is_maintenance_mode(),
                        'from'    => (int) ($maintenance['from'] ?? 0),
                        'to'      => (int) ($maintenance['to'] ?? 0),
                        'now'     => current_time('timestamp'),
                    ];
                },
                'meta'                => ['show_in_rest' => true, 'annotations' => ['readonly' => true]],
            ]
        );
    }

    /**
     * Sets the maintenance mode configuration.
     *
     * Requires manage_options — allows admins to enable/disable maintenance mode
     * and set the scheduled time window.
     *
     * @return void
     */
    private static function register_set_maintenance_mode(): void {
        wp_register_ability(
            'codesm-decoupled-bundle/set-maintenance-mode',
            [
                'label'               => __('Set Maintenance Mode', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'description'         => __('Enables or disables maintenance mode and sets the scheduled time window. The site can notify visitors during maintenance periods.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                'category'            => self::CATEGORY,
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'enabled' => [
                            'type'        => 'boolean',
                            'description' => __('Enable or disable maintenance mode.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'from'    => [
                            'type'        => 'integer',
                            'description' => __('Unix timestamp when maintenance mode starts (0 = immediately).', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                        'to'      => [
                            'type'        => 'integer',
                            'description' => __('Unix timestamp when maintenance mode ends (0 = open-ended).', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        ],
                    ],
                ],
                'output_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'success'      => ['type' => 'boolean'],
                        'message'      => ['type' => 'string'],
                        'maintenance'  => ['type' => 'object'],
                    ],
                ],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'execute_callback'    => static function (array $input): array {
                    $settings = Settings::get();
                    $settings['maintenance']['enabled'] = (bool) ($input['enabled'] ?? false);
                    $settings['maintenance']['from'] = max(0, (int) ($input['from'] ?? 0));
                    $settings['maintenance']['to'] = max(0, (int) ($input['to'] ?? 0));

                    $saved = Settings::save($settings);
                    return [
                        'success'     => true,
                        'message'     => __('Maintenance mode updated.', CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN),
                        'maintenance' => $saved['maintenance'] ?? [],
                    ];
                },
                'meta'                => ['show_in_rest' => true, 'annotations' => ['idempotent' => true]],
            ]
        );
    }
}
