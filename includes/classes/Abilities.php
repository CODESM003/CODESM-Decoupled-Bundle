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
        self::register_trigger_build();
        self::register_get_builds();
        self::register_cancel_build();
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
                        (string) ($input['ref']         ?? '')
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
}
