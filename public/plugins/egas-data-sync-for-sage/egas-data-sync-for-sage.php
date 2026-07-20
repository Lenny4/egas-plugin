<?php
/**
 * Plugin Name: Egas – Synchronization Tool For Sage
 * Plugin URI: https://egas-solutions.com/
 * Description: Synchronize Sage ERP data with your WordPress website and WooCommerce store.
 * Version: 1.0.1
 * Author: Egas Solutions
 * Copyright: © 2026 Egas Solutions
 *
 * Requires at least: 6.9
 * Tested up to: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 *
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Text Domain: egas
 * Domain Path: /lang/
 *
 * @package Egas
 */

use Egas\Sage;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (WP_DEBUG) {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        if (is_string($errline) && str_contains($errline, Sage::TOKEN)) {
            throw new ErrorException(esc_attr($errstr), 0, esc_attr($errno), esc_attr($errfile), esc_attr($errline));
        }
    });
}

$egas = Sage::getInstance(__FILE__);
if (!$egas->isWooCommerceActive()) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('Egas a besoin de Woocommerce pour fonctionner.', 'egas-data-sync-for-sage') .
            '</p></div>';
    });
} else {
    $egas->registerHooks();
}

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Lenny4/egas-plugin/',
    __FILE__,
    'egas-data-sync-for-sage'
);

$updateChecker->setBranch('main');
