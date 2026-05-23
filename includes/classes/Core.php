<?php
/**
 * Plugin bootstrap — wires up all sub-systems.
 *
 * Core is intentionally thin: it does nothing more than call init() on each
 * specialised class. Business logic lives in Settings, BuildManager, RestApi,
 * and Admin.
 *
 * @package CODESM\DecoupledBundle
 */

declare(strict_types=1);

namespace CODESM\DecoupledBundle;

if (!defined('ABSPATH')) exit;

/**
 * Class Core
 *
 * Entry point called from the main plugin file via plugins_loaded.
 */
class Core {

    /**
     * Boots all plugin sub-systems.
     *
     * Calling order matters: Settings has no dependencies, BuildManager and
     * RestApi both depend on Settings, and Admin depends on both.
     *
     * @return void
     */
    public static function init(): void {
        BuildManager::init();
        RestApi::init();
        Admin::init();
    }
}
