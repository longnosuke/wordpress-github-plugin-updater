<?php
/**
 * Plugin Name: Newstyledirect GitHub Plugin Updater
 * Plugin URI: https://github.com/imtbndev/newstyledirect-github-plugin-updater
 * Description: Adds GitHub-based update support for specific plugins.
 * Version: 1.5.2
 * Author: Liam Nguyen
 * Author URI: https://github.com/longnosuke
 */

defined('ABSPATH') || exit;

$plugin_dir = basename(dirname(__FILE__));
$plugin_file = $plugin_dir . '/' . $plugin_dir . '.php';
$target_plugin_file = WP_PLUGIN_DIR . '/' . $plugin_file;

require_once __DIR__ . '/class-newstyledirect-github-plugin-updater.php';

add_action('plugins_loaded', function () use ($target_plugin_file) {
    if (!file_exists($target_plugin_file)) {
        error_log('[NewstyledirectGhPluginUpdater] Target plugin file not found: ' . $target_plugin_file);
        return;
    }
    $updater = new NewstyledirectGhPluginUpdater($target_plugin_file, get_plugin_data(__FILE__));
    $updater->init();
});