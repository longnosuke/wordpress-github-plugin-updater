<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WordPressGhPluginUpdater
{
    private $file;
    private $basename;
    private $github_response;
    private $plugin_slug;
    protected $plugin_file;
    protected $plugin_data;
    protected $gh_username;
    protected $repo_owner;
    protected $repo_name;
    protected $releases_api_url;

    public function __construct($plugin_file, $plugin_data, $github_repo) {
        $this->plugin_file = $plugin_file;
        $this->plugin_data = $plugin_data;

        $this->basename = plugin_basename($this->plugin_file);
        $this->plugin_slug = current(explode('/', $this->basename));

        // Expecting format "owner/repo"
        [$this->repo_owner, $this->repo_name] = explode('/', trim($github_repo));

        $this->releases_api_url = "https://api.github.com/repos/{$this->repo_owner}/{$this->repo_name}/releases/latest";

        $author_uri = $plugin_data['AuthorURI'] ?? '';
        $this->gh_username = basename(parse_url($author_uri, PHP_URL_PATH));
    }

    public function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'update']);
        add_filter('http_request_args', [$this, 'set_header_token'], 10, 2);
        add_filter('upgrader_source_selection', [$this, 'fix_directory_name'], 20, 4);
    }

    public function update($transient)
    {
        // Only proceed if this is a valid transient object
        if (!is_object($transient) || empty($this->basename)) {
            return $transient;
        }

        // If we're checking for plugin information
        if (!isset($transient->checked)) {
            return $transient;
        }

        $this->load_required_data();

        // If we couldn't get GitHub data, don't modify the transient
        if (empty($this->github_response) || !isset($this->github_response['tag_name'])) {
            return $transient;
        }

        // Get versions for comparison
        $current_version = $transient->checked[$this->basename] ?? ($this->plugin_data['Version'] ?? '0.0.0');
        $current_version = $this->clean_version($current_version);
        $new_version = $this->clean_version($this->github_response['tag_name'] ?? '0.0.0');
        $url = "https://github.com/{$this->repo_owner}/{$this->repo_name}";

//        This part is for debug only
//        error_log('--- GITHUB UPDATER DEBUG ---');
//        error_log('Basename: ' . $this->basename);
//        error_log('Current Version (raw): ' . ($transient->checked[$this->basename] ?? 'missing'));
//        error_log('Plugin Data Version: ' . ($this->plugin_data['Version'] ?? 'missing'));
//        error_log('GitHub Tag Name: ' . ($this->github_response['tag_name'] ?? 'missing'));
//        error_log("Cleaned Current Version: $current_version | Cleaned New Version: $new_version");
//        error_log("Repo URL: $url");
//        error_log('-----------------------------');

        // Validate version strings
        if (!preg_match('/^\d+(\.\d+)*$/', $current_version) || !preg_match('/^\d+(\.\d+)*$/', $new_version)) {
            return $transient;
        }

        if (version_compare($new_version, $current_version, 'gt')) {
            // Make sure we have a secure download URL
            if (empty($this->github_response['zipball_url'])) {
                return $transient;
            }

            $zipball_url = $this->github_response['zipball_url'] ?? '';
            // Only accept GitHub URLs
            if (strpos($zipball_url, 'https://api.github.com/') !== 0) {
                return $transient;
            }

            $plugin = (object)[
                'slug'         => sanitize_key($this->plugin_slug),
                'plugin'       => sanitize_file_name($this->basename),
                'new_version'  => sanitize_text_field($new_version),
                'tested'       => sanitize_text_field($this->plugin_data['TestedUpTo'] ?? get_bloginfo('version')),
                'package'      => esc_url_raw($zipball_url),
                'url'          => esc_url_raw($url),
            ];
            $transient->response[$this->basename] = $plugin;
        } else {
            unset($transient->response[$this->basename]);
            if (!empty($this->plugin_data)) {
                $transient->no_update[$this->basename] = $this->plugin_data;
            }
        }

        return $transient;
    }

    public function set_header_token(array $parsed_args, string $url): array
    {
        // Validate URL first
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return $parsed_args;
        }

        // Only add token for GitHub API requests
        if ('api.github.com' === $host && defined('GHPU_AUTH_TOKEN') && !empty(GHPU_AUTH_TOKEN)) {
            // Sanitize and validate token format
            $token = preg_replace('/[^a-zA-Z0-9_\-]/', '', GHPU_AUTH_TOKEN);
            if (!empty($token) && $token === GHPU_AUTH_TOKEN) {
                $parsed_args['headers']['Authorization'] = 'Bearer ' . $token;
            }
        }

        // Check if we're requesting a zipball URL
        if (
            !empty($this->github_response['zipball_url']) &&
            strpos($url, $this->github_response['zipball_url']) === 0 &&
            defined('GHPU_AUTH_TOKEN') &&
            !empty(GHPU_AUTH_TOKEN)
        ) {
            // Sanitize and validate token format
            $token = preg_replace('/[^a-zA-Z0-9_\-]/', '', GHPU_AUTH_TOKEN);
            if (!empty($token) && $token === GHPU_AUTH_TOKEN) {
                $parsed_args['headers']['Authorization'] = 'Bearer ' . $token;
            }
        }

        return $parsed_args;
    }

    private function load_required_data(): void
    {
        $this->get_repository_info();
        $this->get_plugin_data();
    }

    private function get_repository_info(): void
    {
        if ($this->github_response !== null) {
            return;
        }

        // Check for cached response first
        $transient_key = 'nsd_gh_' . md5($this->plugin_slug);
        $cached = get_transient($transient_key);

        if (false !== $cached) {
            $this->github_response = $cached;
            return;
        }

        // Validate repository info exists
        if (empty($this->repo_owner) || empty($this->repo_name)) {
            $this->github_response = [];
            return;
        }

        $args = [
            'method'    => 'GET',
            'timeout'   => 10,
            'headers'   => [
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'Accept'     => 'application/vnd.github.v3+json',
            ],
            'sslverify' => true,
        ];

        // Only add token if it exists and is properly formatted
        if (defined('GHPU_AUTH_TOKEN') && !empty(GHPU_AUTH_TOKEN)) {
            // Sanitize and validate token format
            $token = preg_replace('/[^a-zA-Z0-9_\-]/', '', GHPU_AUTH_TOKEN);
            if (!empty($token) && $token === GHPU_AUTH_TOKEN) {
                $args['headers']['Authorization'] = 'token ' . $token;
            }
        }

        $uri = esc_url_raw($this->releases_api_url);

        $response = wp_remote_get($uri, $args);

        if (is_wp_error($response)) {
            $this->github_response = [];
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->github_response = [];
            return;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            $this->github_response = [];
            return;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->github_response = [];
            return;
        }

        $this->github_response = isset($data[0]) ? $data[0] : ($data ?? []);

        // Cache for 6 hours
        set_transient($transient_key, $this->github_response, 6 * HOUR_IN_SECONDS);
    }

    private function get_plugin_data(): void
    {
        if ($this->plugin_data === null) {
            $this->plugin_data = get_plugin_data($this->plugin_file);
        }
    }

    private function clean_version(string $version): string
    {
        return ltrim($version, 'v');
    }

    public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ): string {
        // Only handle this plugin update.
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $source;
        }

        // Get plugin folder name from basename
        $plugin_dir    = dirname( $this->basename );
        $plugin_folder = $plugin_dir === '.' ? basename( $this->plugin_file, '.php' ) : $plugin_dir;
        $plugin_folder = sanitize_file_name( $plugin_folder );

        if ( empty( $plugin_folder ) ) {
            return $source;
        }

        $new_source = trailingslashit( $remote_source ) . $plugin_folder;

        // Move the extracted folder to the desired plugin folder name.
        $result = move_dir( $source, $new_source, true );

        // If successful, return new folder path..
        if ( ! is_wp_error( $result ) ) {
            return $new_source;
        }

        // If failed, return original.
        return $source;
    }

}
