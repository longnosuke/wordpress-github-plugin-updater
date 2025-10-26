<?php
/**
 * Plugin Name: WordPress GitHub Plugin Updater
 * Description: Adds GitHub-based update support for specific plugins.
 * Version: 1.0.0
 * Author: Liam Nguyen
 * Author URI: https://github.com/longnosuke
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-wordpress-github-plugin-updater.php';

add_action( 'init', function () {
    if ( class_exists('WordPressGhPluginUpdater') ) {
        $plugin_file = __FILE__;
        $plugin_data = get_plugin_data( $plugin_file );
        $github_repo = 'ORGANIZATION/NAME_OF_YOUR_REPO';

        $updater = new WordPressGhPluginUpdater( $plugin_file, $plugin_data, $github_repo );
        $updater->init();
    }
} );

