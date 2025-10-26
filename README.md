# Newstyledirect GitHub Plugin Updater

A lightweight WordPress plugin will fetch update data to check plugins's available updates

## Features

- Automatic updates from GitHub releases using the Newstyledirect GitHub Plugin Updater core.
- Seamless update notifications within the WordPress admin.
- Secure API authentication with GitHub Personal Access Token.
- Easy integration and setup.

### Requirements

- WordPress 5.5 or higher
- PHP 7.0 or higher
- GitHub repository with release tags for your plugin

### Steps to Install

1. Download or clone this plugin into your WordPress plugins directory (`wp-content/plugins/your-plugin`).
2. Ensure the **Newstyledirect GitHub Plugin Updater** core file `class-newstyledirect-github-plugin-updater.php` is inside your plugin folder, e.g.:

   ```
   your-plugin/
   └── inc/github-updater/class-newstyledirect-github-plugin-updater.php
   ```

3. In your main plugin file (e.g., `your-plugin.php`), require the updater class:

   ```php
   require_once plugin_dir_path(__FILE__) . 'inc/github-updater/class-newstyledirect-github-plugin-updater.php';
   ```

4. Initialize the updater by adding this snippet to your main plugin file:

   ```php
   add_action('plugins_loaded', function () {
       if (!class_exists('NewstyledirectGhPluginUpdater')) {
           error_log('[Your Plugin] GitHub Updater core missing.');
           return;
       }

       $plugin_file = __FILE__;
       $plugin_data = get_plugin_data($plugin_file);

       $updater = new NewstyledirectGhPluginUpdater($plugin_file, $plugin_data);
       $updater->init();
   });
   ```

5. Add your GitHub Personal Access Token in your `wp-config.php` file to avoid API limits:

   ```php
   define('GHPU_AUTH_TOKEN', 'your_github_personal_access_token_here');
   ```

6. Verify your plugin headers (in your main plugin file) include accurate repository info so the updater can detect the GitHub repository:

/\*\*

- Plugin Name: Your Plugin Name
- Plugin URI: https://github.com/yourusername/your-plugin-repo
- Author: Your Name
- Author URI: https://github.com/yourusername
- Version: 1.0.0
  \*/

7. Activate the plugin from the WordPress admin dashboard.

---

## Usage

- Tag your GitHub releases properly with semantic versioning (e.g., `v1.0.0`).
- Push release notes and assets to GitHub for display in the WordPress plugin update popup.
- When a new release is published, WordPress will notify admins and allow automatic plugin updates.
