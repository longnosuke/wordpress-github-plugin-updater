<?php

class NewstyledirectGhPluginUpdater
{
    private $file;
    private $basename;
    private $active = false;
    private $github_response;
    private $plugin_slug;
    protected $plugin_file;
    protected $plugin_data;
    protected $gh_username;
    protected $repo_owner;
    protected $repo_name;
    protected $releases_api_url;

    public function __construct($plugin_file, $plugin_data) {
        $this->plugin_file = $plugin_file;
        $this->plugin_data = $plugin_data;

        $this->basename = plugin_basename($this->plugin_file);
        $this->plugin_slug = current(explode('/', $this->basename));

        $gh_repo_url = $plugin_data['PluginURI'] ?? '';
        $path = parse_url($gh_repo_url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $segments = explode('/', trim($path, '/'));
            $this->repo_owner = $segments[0] ?? null;
            $this->repo_name = $segments[1] ?? null;
        } else {
            $this->repo_owner = null;
            $this->repo_name = null;
        }
        
        $this->releases_api_url = "https://api.github.com/repos/{$this->repo_owner}/{$this->repo_name}/releases";

        $author_uri = $plugin_data['AuthorURI'] ?? '';
        $this->gh_username = basename(parse_url($author_uri, PHP_URL_PATH));
    }

    public function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'update']);
        add_filter('http_request_args', [$this, 'set_header_token'], 10, 2);
        add_filter('plugins_api', [$this, 'plugin_popup'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_directory_name'], 20, 4);
    }

    public function update($transient)
    {
        $this->load_required_data();

        $current_version = $transient->checked[$this->basename] ?? ($this->plugin_data['Version'] ?? '0.0.0');
        $new_version = $this->clean_version($this->github_response['tag_name'] ?? '0.0.0');

        if (version_compare($new_version, $current_version, 'gt')) {
            $plugin = (object)[
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->basename,
                'new_version'  => $new_version,
                'tested'       => $this->plugin_data['TestedUpTo'] ?? get_bloginfo('version'),
                'package'      => $this->github_response['zipball_url'] ?? '',
                'url'          => $this->plugin_data['PluginURI'] ?? '',
            ];
            $transient->response[$this->basename] = $plugin;
        } else {
            unset($transient->response[$this->basename]);
            $transient->no_update[$this->basename] = $this->plugin_data;
        }

        return $transient;
    }

    public function plugin_popup($result, string $action, object $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $this->load_required_data();

        if (empty($this->github_response) || empty($this->plugin_data)) {
            return $result;
        }

        return (object)[
            'name'              => $this->plugin_data['Name'],
            'slug'              => $this->plugin_slug,
            'requires'          => $this->plugin_data['RequiresWP'] ?? null,
            'tested'            => $this->plugin_data['TestedUpTo'] ?? get_bloginfo('version'),
            'version'           => $this->clean_version($this->github_response['tag_name']),
            'author'            => $this->plugin_data['AuthorName'] ?? $this->plugin_data['Author'],
            'author_profile'    => $this->plugin_data['AuthorURI'] ?? null,
            'last_updated'      => $this->github_response['published_at'] ?? '',
            'homepage'          => $this->plugin_data['PluginURI'] ?? null,
            'short_description' => $this->plugin_data['Description'] ?? '',
            'sections' => [
                'Description' => $this->plugin_data['Description'] ?? 'No description available.',
                'Updates'     => $this->github_response['body'] ?? 'No update details available.',
            ],
            'download_link'     => $this->github_response['zipball_url'] ?? '',
        ];
    }

    public function set_header_token(array $parsed_args, string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ('api.github.com' === $host) {
            $parsed_args['headers']['Authorization'] = 'token ' . GHPU_AUTH_TOKEN;
        }

        if (
            !empty($this->github_response['zipball_url']) &&
            strpos($url, $this->github_response['zipball_url']) === 0
        ) {
            $parsed_args['headers']['Authorization'] = 'token ' . GHPU_AUTH_TOKEN;
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

        $args = [
            'method'    => 'GET',
            'timeout'   => 10,
            'headers'   => [
                'Authorization' => 'token ' . GHPU_AUTH_TOKEN,
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'Accept'        => 'application/vnd.github.v3+json',
            ],
            'sslverify' => true,
        ];

        $uri = $this->releases_api_url;

        $response = wp_remote_get($uri, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->github_response = [];
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $this->github_response = is_array($data) && isset($data[0]) ? $data[0] : ($data ?? []);
    }

    private function get_plugin_data(): void
    {
        if ($this->plugin_data === null) {
            $this->plugin_data = get_plugin_data($this->file);
        }
    }

    private function clean_version(string $version): string
    {
        return ltrim($version, 'v');
    }

    public function fix_directory_name($source, $remote_source, $upgrader, $hook_extra): string
    {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }

        global $wp_filesystem;

        $plugin_dir = dirname($this->basename);
        $plugin_folder = $plugin_dir === '.' ? basename($this->file, '.php') : $plugin_dir;

        $new_source = trailingslashit($remote_source) . $plugin_folder;

        if ($wp_filesystem->exists($new_source)) {
            $wp_filesystem->delete($new_source, true);
        }

        return $wp_filesystem->move($source, $new_source) ? $new_source : $source;
    }
}
