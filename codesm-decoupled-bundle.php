<?php
/**
 * Plugin Name: CODESM Decoupled Bundle
 * Plugin URI:  https://codesm.com
 * Description: Site settings, contact info, GTM config, global/per-page script injection, and Astro build triggers for decoupled WordPress + Astro setups.
 * Version:     0.0.1-prerelease-rc10
 * Author:      Kavit Trivedi
 * Author URI:  https://codesm.com
 * Text Domain: codesm-decoupled-bundle
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/** @var string Plugin version. */
define('CODESM_DECOUPLED_BUNDLE_VERSION',        '0.0.1-prerelease-rc10');

/** @var string Absolute path to the plugin directory, with trailing slash. */
define('CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH', plugin_dir_path(__FILE__));

/** @var string URL to the plugin directory, with trailing slash. */
define('CODESM_DECOUPLED_BUNDLE_URL',            plugin_dir_url(__FILE__));

/** @var string Admin menu page slug. */
define('CODESM_DECOUPLED_BUNDLE_SLUG',           'codesm-decoupled-bundle');

/** @var string Text domain for i18n. */
define('CODESM_DECOUPLED_BUNDLE_TEXT_DOMAIN',    'codesm-decoupled-bundle');

require_once CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'includes/classes/Settings.php';
require_once CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'includes/classes/BuildManager.php';
require_once CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'includes/classes/RestApi.php';
require_once CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'includes/classes/Admin.php';
require_once CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'includes/classes/Abilities.php';
require_once CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'includes/classes/GitHubUpdater.php';
require_once CODESM_DECOUPLED_BUNDLE_DIRECTORY_PATH . 'includes/classes/Core.php';

add_action('plugins_loaded', static function (): void {
    CODESM\DecoupledBundle\Core::init();
});

register_activation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    CODESM\DecoupledBundle\BuildManager::cancel_scheduled_build();
    flush_rewrite_rules();
});
