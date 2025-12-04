<?php
/**
 * Plugin Name:       WP Git Sync
 * Plugin URI:        https://github.com/apple-techie/wp-git-sync
 * Description:       Automatically sync your WordPress site with GitHub. Works with or without Git installed - uses GitHub API as fallback for managed hosting.
 * Version:           2.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Apple Techie
 * Author URI:        https://github.com/apple-techie
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-git-sync
 *
 * @package WP_Git_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version constant.
define( 'WP_GIT_SYNC_VERSION', '2.1.0' );

class WP_Git_Sync {
    
    private static $instance = null;
    private $option_group = 'wp_git_sync_settings';
    private $option_name = 'wp_git_sync_options';
    private $git_available = null;
    private $last_scan_debug = [];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
        
        // Auto-sync hooks
        add_action('upgrader_process_complete', [$this, 'trigger_sync'], 10, 2);
        add_action('activated_plugin', [$this, 'trigger_sync']);
        add_action('deactivated_plugin', [$this, 'trigger_sync']);
        add_action('switch_theme', [$this, 'trigger_sync']);
        add_action('deleted_plugin', [$this, 'trigger_sync']);
        add_action('deleted_theme', [$this, 'trigger_sync']);
        
        // AJAX handlers
        add_action('wp_ajax_wp_git_sync_run', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_wp_git_sync_test', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_wp_git_sync_init', [$this, 'ajax_init_repository']);
    }
    
    // ===========================================
    // GIT AVAILABILITY CHECK
    // ===========================================
    
    /**
     * Check if git command is available on the server
     */
    public function is_git_available() {
        if ($this->git_available !== null) {
            return $this->git_available;
        }
        
        $output = shell_exec('which git 2>/dev/null') ?? '';
        $this->git_available = !empty(trim($output));
        
        // Double check by trying to run git
        if (!$this->git_available) {
            $version = shell_exec('git --version 2>/dev/null') ?? '';
            $this->git_available = strpos($version, 'git version') !== false;
        }
        
        return $this->git_available;
    }
    
    /**
     * Get sync mode - 'git' or 'api'
     */
    public function get_sync_mode() {
        return $this->is_git_available() ? 'git' : 'api';
    }
    
    public function create_admin_menu() {
        add_menu_page(
            'Git Sync',
            'Git Sync',
            'manage_options',
            'wp-git-sync',
            [$this, 'render_dashboard_page'],
            'dashicons-cloud-saved',
            99
        );
        
        add_submenu_page(
            'wp-git-sync',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'wp-git-sync',
            [$this, 'render_dashboard_page']
        );
        
        add_submenu_page(
            'wp-git-sync',
            'Settings',
            'Settings',
            'manage_options',
            'wp-git-sync-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting($this->option_group, $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_options']
        ]);
        
        // GitHub Settings Section
        add_settings_section(
            'wp_git_sync_github',
            'GitHub Configuration',
            [$this, 'render_github_section'],
            'wp-git-sync-settings'
        );
        
        add_settings_field('github_pat', 'Personal Access Token (PAT)', [$this, 'render_pat_field'], 'wp-git-sync-settings', 'wp_git_sync_github');
        add_settings_field('github_username', 'GitHub Username', [$this, 'render_username_field'], 'wp-git-sync-settings', 'wp_git_sync_github');
        add_settings_field('github_repo', 'Repository Name', [$this, 'render_repo_field'], 'wp-git-sync-settings', 'wp_git_sync_github');
        add_settings_field('github_branch', 'Branch', [$this, 'render_branch_field'], 'wp-git-sync-settings', 'wp_git_sync_github');
        
        // Git Identity Section
        add_settings_section(
            'wp_git_sync_identity',
            'Commit Identity',
            [$this, 'render_identity_section'],
            'wp-git-sync-settings'
        );
        
        add_settings_field('author_name', 'Author Name', [$this, 'render_author_name_field'], 'wp-git-sync-settings', 'wp_git_sync_identity');
        add_settings_field('author_email', 'Author Email', [$this, 'render_author_email_field'], 'wp-git-sync-settings', 'wp_git_sync_identity');
        
        // Webhook Section
        add_settings_section(
            'wp_git_sync_webhook',
            'Webhook Configuration',
            [$this, 'render_webhook_section'],
            'wp-git-sync-settings'
        );
        
        add_settings_field('webhook_secret', 'Webhook Secret', [$this, 'render_webhook_secret_field'], 'wp-git-sync-settings', 'wp_git_sync_webhook');
        
        // Advanced Section
        add_settings_section(
            'wp_git_sync_advanced',
            'Advanced Settings',
            [$this, 'render_advanced_section'],
            'wp-git-sync-settings'
        );
        
        add_settings_field('repo_path', 'Repository Path', [$this, 'render_repo_path_field'], 'wp-git-sync-settings', 'wp_git_sync_advanced');
        add_settings_field('auto_sync', 'Auto-Sync on Changes', [$this, 'render_auto_sync_field'], 'wp-git-sync-settings', 'wp_git_sync_advanced');
        add_settings_field('sync_paths', 'Paths to Sync', [$this, 'render_sync_paths_field'], 'wp-git-sync-settings', 'wp_git_sync_advanced');
    }
    
    public function sanitize_options($input) {
        $sanitized = [];
        
        $sanitized['github_pat'] = sanitize_text_field($input['github_pat'] ?? '');
        $sanitized['github_username'] = sanitize_text_field($input['github_username'] ?? '');
        $sanitized['github_repo'] = sanitize_text_field($input['github_repo'] ?? '');
        $sanitized['github_branch'] = sanitize_text_field($input['github_branch'] ?? 'main');
        $sanitized['author_name'] = sanitize_text_field($input['author_name'] ?? '');
        $sanitized['author_email'] = sanitize_email($input['author_email'] ?? '');
        $sanitized['webhook_secret'] = sanitize_text_field($input['webhook_secret'] ?? '');
        $sanitized['repo_path'] = sanitize_text_field($input['repo_path'] ?? ABSPATH);
        $sanitized['auto_sync'] = isset($input['auto_sync']) ? 1 : 0;
        $sanitized['sync_paths'] = sanitize_textarea_field($input['sync_paths'] ?? "wp-content/plugins/\nwp-content/themes/\nwp-content/mu-plugins/");
        
        return $sanitized;
    }
    
    public function get_options() {
        $defaults = [
            'github_pat' => '',
            'github_username' => '',
            'github_repo' => '',
            'github_branch' => 'main',
            'author_name' => '',
            'author_email' => '',
            'webhook_secret' => wp_generate_password(32, false),
            'repo_path' => ABSPATH,
            'auto_sync' => 1,
            'sync_paths' => "wp-content/plugins/\nwp-content/themes/\nwp-content/mu-plugins/",
        ];
        
        return wp_parse_args(get_option($this->option_name, []), $defaults);
    }
    
    // ===========================================
    // GITHUB API METHODS
    // ===========================================
    
    /**
     * Make a GitHub API request
     */
    private function github_api($method, $endpoint, $body = null) {
        $options = $this->get_options();
        
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'token ' . $options['github_pat'],
                'User-Agent' => 'WP-Git-Sync/2.0',
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 60,
        ];
        
        if ($body !== null) {
            $args['body'] = json_encode($body);
        }
        
        $url = 'https://api.github.com' . $endpoint;
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return [
            'code' => $code,
            'body' => $body,
        ];
    }
    
    /**
     * Check if GitHub repository exists
     */
    public function github_repo_exists() {
        $options = $this->get_options();
        
        if (empty($options['github_pat']) || empty($options['github_username']) || empty($options['github_repo'])) {
            return null;
        }
        
        $result = $this->github_api('GET', "/repos/{$options['github_username']}/{$options['github_repo']}");
        
        return isset($result['code']) && $result['code'] === 200;
    }
    
    /**
     * Create GitHub repository
     */
    public function create_github_repo($private = true) {
        $options = $this->get_options();
        
        if (empty($options['github_pat']) || empty($options['github_repo'])) {
            return ['success' => false, 'message' => 'GitHub PAT and repository name are required.'];
        }
        
        if ($this->github_repo_exists()) {
            return ['success' => true, 'message' => 'Repository already exists on GitHub.', 'already_exists' => true];
        }
        
        $result = $this->github_api('POST', '/user/repos', [
            'name' => $options['github_repo'],
            'description' => 'WordPress site synced via WP Git Sync',
            'private' => $private,
            'auto_init' => false,
        ]);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return ['success' => true, 'message' => 'Repository created successfully!'];
        } elseif (isset($result['code']) && $result['code'] === 422) {
            // May already exist
            if ($this->github_repo_exists()) {
                return ['success' => true, 'message' => 'Repository already exists.', 'already_exists' => true];
            }
            return ['success' => false, 'message' => 'Validation error: ' . ($result['body']['message'] ?? 'Unknown')];
        } else {
            return ['success' => false, 'message' => 'GitHub API error: ' . ($result['body']['message'] ?? 'Unknown error')];
        }
    }
    
    /**
     * Get the latest commit SHA for a branch
     */
    private function get_branch_sha() {
        $options = $this->get_options();
        $result = $this->github_api('GET', "/repos/{$options['github_username']}/{$options['github_repo']}/git/refs/heads/{$options['github_branch']}");
        
        if (isset($result['code']) && $result['code'] === 200) {
            return $result['body']['object']['sha'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Get tree SHA from a commit
     */
    private function get_tree_sha($commit_sha) {
        $options = $this->get_options();
        $result = $this->github_api('GET', "/repos/{$options['github_username']}/{$options['github_repo']}/git/commits/{$commit_sha}");
        
        if (isset($result['code']) && $result['code'] === 200) {
            return $result['body']['tree']['sha'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Get full recursive tree from GitHub
     */
    private function get_remote_tree_recursive($tree_sha) {
        $options = $this->get_options();
        // Use recursive=1 to get all files
        $result = $this->github_api('GET', "/repos/{$options['github_username']}/{$options['github_repo']}/git/trees/{$tree_sha}?recursive=1");
        
        if (isset($result['code']) && $result['code'] === 200) {
            return $result['body']['tree'] ?? [];
        }
        
        return [];
    }

    /**
     * Check if a path is managed by our sync configuration
     */
    private function is_path_managed($path, $sync_paths) {
        foreach ($sync_paths as $sync_path) {
            $sync_path = trim($sync_path, '/');
            if (empty($sync_path)) continue;
            
            // Exact match (file or folder root)
            if ($path === $sync_path) {
                return true;
            }
            
            // Child of folder
            if (strpos($path, $sync_path . '/') === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Create a blob for file content
     */
    private function create_blob($content) {
        $options = $this->get_options();
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/blobs", [
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return $result['body']['sha'];
        }
        
        return null;
    }
    
    /**
     * Create a tree with files
     */
    private function create_tree($base_tree, $tree_items) {
        $options = $this->get_options();
        
        $body = ['tree' => $tree_items];
        if ($base_tree) {
            $body['base_tree'] = $base_tree;
        }
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/trees", $body);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return $result['body']['sha'];
        }
        
        return null;
    }
    
    /**
     * Create a commit
     */
    private function create_commit($tree_sha, $parent_sha, $message) {
        $options = $this->get_options();
        
        $body = [
            'message' => $message,
            'tree' => $tree_sha,
            'author' => [
                'name' => $options['author_name'] ?: 'WP Git Sync',
                'email' => $options['author_email'] ?: 'git-sync@wordpress.local',
                'date' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
        
        if ($parent_sha) {
            $body['parents'] = [$parent_sha];
        }
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/commits", $body);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return $result['body']['sha'];
        }
        
        return null;
    }
    
    /**
     * Update branch reference to point to new commit
     */
    private function update_branch_ref($commit_sha, $force = false) {
        $options = $this->get_options();
        
        $result = $this->github_api('PATCH', "/repos/{$options['github_username']}/{$options['github_repo']}/git/refs/heads/{$options['github_branch']}", [
            'sha' => $commit_sha,
            'force' => $force,
        ]);
        
        return isset($result['code']) && $result['code'] === 200;
    }
    
    /**
     * Create branch reference (for new repos)
     */
    private function create_branch_ref($commit_sha) {
        $options = $this->get_options();
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/refs", [
            'ref' => "refs/heads/{$options['github_branch']}",
            'sha' => $commit_sha,
        ]);
        
        return isset($result['code']) && $result['code'] === 201;
    }
    
    /**
     * Get list of commits via API
     */
    private function get_commits_api($per_page = 15, $page = 1) {
        $options = $this->get_options();
        
        $result = $this->github_api('GET', "/repos/{$options['github_username']}/{$options['github_repo']}/commits?sha={$options['github_branch']}&per_page={$per_page}&page={$page}");
        
        if (isset($result['code']) && $result['code'] === 200) {
            $commits = [];
            foreach ($result['body'] as $commit) {
                $commits[] = [
                    'sha' => substr($commit['sha'], 0, 7),
                    'full_sha' => $commit['sha'],
                    'message' => $commit['commit']['message'],
                    'author' => $commit['commit']['author']['name'],
                    'date' => human_time_diff(strtotime($commit['commit']['author']['date'])) . ' ago',
                ];
            }
            return $commits;
        }
        
        return [];
    }
    
    // ===========================================
    // FILE SCANNING
    // ===========================================
    
    /**
     * Get files to sync based on configured paths
     */
    private function get_files_to_sync() {
        $options = $this->get_options();
        $repo_path = rtrim($options['repo_path'], '/');
        $sync_paths_raw = $options['sync_paths'];
        
        // Handle both \n and \r\n line endings
        $sync_paths = array_filter(array_map('trim', preg_split('/[\r\n]+/', $sync_paths_raw)));
        
        $files = [];
        $ignore_patterns = $this->get_ignore_patterns();
        
        // Debug: store what paths we're checking
        $this->last_scan_debug = [
            'repo_path' => $repo_path,
            'sync_paths' => $sync_paths,
            'checked_paths' => [],
        ];
        
        foreach ($sync_paths as $path) {
            $path = trim($path, '/');
            if (empty($path)) continue;
            
            $full_path = $repo_path . '/' . $path;
            
            $this->last_scan_debug['checked_paths'][] = [
                'path' => $path,
                'full_path' => $full_path,
                'is_file' => is_file($full_path),
                'is_dir' => is_dir($full_path),
                'exists' => file_exists($full_path),
            ];
            
            if (is_file($full_path)) {
                // Single file
                if (!$this->should_ignore($path, $ignore_patterns)) {
                    $files[$path] = $full_path;
                }
            } elseif (is_dir($full_path)) {
                // Directory - scan recursively
                $this->scan_directory($full_path, $repo_path, $files, $ignore_patterns);
            }
        }
        
        // Always include .gitignore if it exists
        $gitignore_path = $repo_path . '/.gitignore';
        if (file_exists($gitignore_path)) {
            $files['.gitignore'] = $gitignore_path;
        }
        
        $this->last_scan_debug['files_found'] = count($files);
        
        return $files;
    }
    
    /**
     * Get debug info from last scan
     */
    public function get_last_scan_debug() {
        return $this->last_scan_debug ?? [];
    }
    
    /**
     * Recursively scan directory for files
     */
    private function scan_directory($dir, $base_path, &$files, $ignore_patterns) {
        $items = @scandir($dir);
        if (!$items) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $full_path = $dir . '/' . $item;
            $rel_path = ltrim(str_replace($base_path, '', $full_path), '/');
            
            if ($this->should_ignore($rel_path, $ignore_patterns)) {
                continue;
            }
            
            if (is_file($full_path)) {
                // Skip very large files (>10MB)
                if (filesize($full_path) > 10 * 1024 * 1024) {
                    continue;
                }
                // Skip binary files based on extension
                $ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
                $binary_exts = ['png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'mp3', 'mp4', 'avi', 'mov', 'zip', 'tar', 'gz', 'rar', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                if (in_array($ext, $binary_exts)) {
                    continue;
                }
                $files[$rel_path] = $full_path;
            } elseif (is_dir($full_path)) {
                $this->scan_directory($full_path, $base_path, $files, $ignore_patterns);
            }
        }
    }
    
    /**
     * Get ignore patterns
     */
    private function get_ignore_patterns() {
        return [
            'node_modules',
            '.git',
            '.svn',
            '.DS_Store',
            'Thumbs.db',
            '*.log',
            'wp-content/uploads',
            'wp-content/cache',
            'wp-content/backup*',
            'wp-content/upgrade',
            'wp-content/debug.log',
            'error_log',
        ];
    }
    
    /**
     * Check if path should be ignored
     */
    private function should_ignore($path, $patterns) {
        foreach ($patterns as $pattern) {
            // Direct match
            if (strpos($path, $pattern) !== false) {
                return true;
            }
            // Glob-style match
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }
    
    // ===========================================
    // SYNC OPERATIONS (API MODE)
    // ===========================================
    
    /**
     * Sync files to GitHub using API (no git required)
     */
    public function sync_via_api($message = 'Auto-sync from WordPress') {
        $options = $this->get_options();
        
        // Validate settings
        if (empty($options['github_pat']) || empty($options['github_username']) || empty($options['github_repo'])) {
            return ['success' => false, 'message' => 'GitHub settings not configured.'];
        }
        
        // Get files to sync
        $files = $this->get_files_to_sync();
        
        if (empty($files)) {
            return ['success' => false, 'message' => 'No files found to sync. Check your "Paths to Sync" setting. Current paths: ' . $options['sync_paths']];
        }
        
        // Check if repo is empty (no commits yet)
        $branch_sha = $this->get_branch_sha();
        $is_empty_repo = ($branch_sha === null);
        
        // For empty repos, use Contents API which handles initial commits properly
        if ($is_empty_repo) {
            return $this->sync_to_empty_repo($files, $message);
        }
        
        // For existing repos, use the faster Git Data API
        return $this->sync_to_existing_repo($files, $message, $branch_sha);
    }
    
    /**
     * Sync files to an empty repository using Contents API
     * This handles the initial commit properly
     */
    private function sync_to_empty_repo($files, $message) {
        $options = $this->get_options();
        $file_count = 0;
        $failed_files = [];
        
        // For empty repos, we need to create files one by one using Contents API
        // The first file creates the initial commit, subsequent files update
        foreach ($files as $rel_path => $full_path) {
            $content = @file_get_contents($full_path);
            if ($content === false) {
                $failed_files[] = $rel_path . ' (unreadable)';
                continue;
            }
            
            $result = $this->create_or_update_file_via_contents_api($rel_path, $content, $message);
            
            if ($result['success']) {
                $file_count++;
            } else {
                $failed_files[] = $rel_path . ' (' . $result['error'] . ')';
            }
            
            // Limit files for initial sync to avoid timeouts
            if ($file_count >= 100) {
                break;
            }
        }
        
        if ($file_count === 0) {
            $error_details = !empty($failed_files) ? ' Failed: ' . implode(', ', array_slice($failed_files, 0, 3)) : '';
            return ['success' => false, 'message' => 'No files could be uploaded.' . $error_details];
        }
        
        $result_message = "Synced {$file_count} files to GitHub.";
        if (!empty($failed_files)) {
            $result_message .= ' (' . count($failed_files) . ' skipped)';
        }
        if ($file_count >= 100 && count($files) > 100) {
            $result_message .= ' Run sync again for remaining files.';
        }
        
        return [
            'success' => true,
            'message' => $result_message,
            'files_synced' => $file_count,
            'files_skipped' => count($failed_files),
        ];
    }
    
    /**
     * Create or update a file using the Contents API
     * This works even on empty repos
     */
    private function create_or_update_file_via_contents_api($path, $content, $message) {
        $options = $this->get_options();
        
        // First, check if file exists to get its SHA (needed for updates)
        $existing = $this->github_api('GET', "/repos/{$options['github_username']}/{$options['github_repo']}/contents/{$path}?ref={$options['github_branch']}");
        
        $body = [
            'message' => $message . ' - ' . basename($path),
            'content' => base64_encode($content),
            'branch' => $options['github_branch'],
        ];
        
        // Add committer info
        if (!empty($options['author_name']) && !empty($options['author_email'])) {
            $body['committer'] = [
                'name' => $options['author_name'],
                'email' => $options['author_email'],
            ];
        }
        
        // If file exists, include its SHA for update
        if (isset($existing['code']) && $existing['code'] === 200 && isset($existing['body']['sha'])) {
            $body['sha'] = $existing['body']['sha'];
        }
        
        $result = $this->github_api('PUT', "/repos/{$options['github_username']}/{$options['github_repo']}/contents/{$path}", $body);
        
        if (isset($result['code']) && ($result['code'] === 200 || $result['code'] === 201)) {
            return ['success' => true];
        }
        
        $error = $result['body']['message'] ?? ($result['error'] ?? 'Unknown error');
        return ['success' => false, 'error' => $error];
    }
    
    /**
     * Sync files to existing repository using Git Data API
     * Uses fetch-merge-replace strategy to handle deletions and optimizations
     */
    private function sync_to_existing_repo($files, $message, $branch_sha) {
        $options = $this->get_options();
        
        // 1. Get current remote tree (recursive) to handle deletions and optimizations
        $base_tree_sha = $this->get_tree_sha($branch_sha);
        $remote_tree_raw = $this->get_remote_tree_recursive($base_tree_sha);
        
        // Create a map of remote paths to their data for quick lookup
        $remote_files = [];
        foreach ($remote_tree_raw as $item) {
            if ($item['type'] === 'blob') {
                $remote_files[$item['path']] = $item;
            }
        }
        
        // Parse sync paths to check what is managed
        $sync_paths_raw = $options['sync_paths'];
        $sync_paths = array_filter(array_map('trim', preg_split('/[\r\n]+/', $sync_paths_raw)));
        // Always include .gitignore in managed paths if it exists locally
        if (isset($files['.gitignore'])) {
             $sync_paths[] = '.gitignore';
        }
        
        // 2. Build tree items
        $tree_items = [];
        $processed_paths = [];
        $file_count = 0;
        $blob_count = 0; // Count actual API uploads
        $failed_files = [];
        
        // 2a. Process Local Files (Add/Update)
        foreach ($files as $rel_path => $full_path) {
            $content = @file_get_contents($full_path);
            if ($content === false) {
                $failed_files[] = $rel_path . ' (unreadable)';
                continue;
            }
            
            // Skip empty files (same as before)
            if (strlen($content) === 0) {
                continue;
            }
            
            // Optimization: Calculate Git SHA to see if we can skip upload
            $local_sha = sha1('blob ' . strlen($content) . "\0" . $content);
            
            if (isset($remote_files[$rel_path]) && $remote_files[$rel_path]['sha'] === $local_sha) {
                // File hasn't changed! Reuse SHA.
                $tree_items[] = [
                    'path' => $rel_path,
                    'mode' => $remote_files[$rel_path]['mode'],
                    'type' => 'blob',
                    'sha' => $local_sha,
                ];
            } else {
                // File changed or new -> Upload Blob
                $blob_result = $this->create_blob_with_error($content);
                if (!$blob_result['success']) {
                    $failed_files[] = $rel_path . ' (' . $blob_result['error'] . ')';
                    continue;
                }
                
                $tree_items[] = [
                    'path' => $rel_path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => $blob_result['sha'],
                ];
                $blob_count++;
            }
            
            $processed_paths[$rel_path] = true;
            $file_count++;
        }
        
        // 2b. Process Remote Files (Preserve unmanaged, Drop managed-but-missing)
        foreach ($remote_files as $path => $item) {
            // If we already processed this path (it was local), skip
            if (isset($processed_paths[$path])) {
                continue;
            }
            
            // Check if this path is managed by our sync config
            if ($this->is_path_managed($path, $sync_paths)) {
                // It IS managed, but was NOT in local $files.
                // This means it was DELETED locally.
                // We do NOT add it to tree_items, effectively deleting it.
                continue;
            }
            
            // It is NOT managed (e.g. README.md). Preserve it.
            $tree_items[] = [
                'path' => $path,
                'mode' => $item['mode'],
                'type' => 'blob',
                'sha' => $item['sha'],
            ];
        }
        
        if (empty($tree_items) && empty($failed_files)) {
             return ['success' => true, 'message' => 'No files to sync (repo matches local).', 'files_synced' => 0];
        }
        
        if (empty($tree_items)) {
            $error_details = !empty($failed_files) ? ' Failed files: ' . implode(', ', array_slice($failed_files, 0, 5)) : '';
            return ['success' => false, 'message' => 'No files could be processed.' . $error_details];
        }
        
        // Create tree (Pass NULL as base_tree to define exact state)
        $tree_result = $this->create_tree_with_error(null, $tree_items);
        if (!$tree_result['success']) {
            return ['success' => false, 'message' => 'Failed to create tree: ' . $tree_result['error']];
        }
        
        // Create commit
        $commit_result = $this->create_commit_with_error($tree_result['sha'], $branch_sha, $message);
        if (!$commit_result['success']) {
            return ['success' => false, 'message' => 'Failed to create commit: ' . $commit_result['error']];
        }
        
        // Update branch reference
        $ref_result = $this->update_branch_ref_with_error($commit_result['sha']);
        if (!$ref_result['success']) {
            return ['success' => false, 'message' => 'Failed to update branch: ' . $ref_result['error']];
        }
        
        $result_message = "Synced {$file_count} files to GitHub ({$blob_count} uploaded).";
        if (!empty($failed_files)) {
            $result_message .= ' (' . count($failed_files) . ' files skipped)';
        }
        
        return [
            'success' => true,
            'message' => $result_message,
            'commit' => substr($commit_result['sha'], 0, 7),
            'files_synced' => $file_count,
            'blobs_uploaded' => $blob_count,
            'files_skipped' => count($failed_files),
        ];
    }
    
    /**
     * Create blob with detailed error reporting
     */
    private function create_blob_with_error($content) {
        $options = $this->get_options();
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/blobs", [
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return ['success' => true, 'sha' => $result['body']['sha']];
        }
        
        $error = $result['body']['message'] ?? ($result['error'] ?? 'Unknown error (code: ' . ($result['code'] ?? 'none') . ')');
        return ['success' => false, 'error' => $error];
    }
    
    /**
     * Create tree with detailed error reporting
     */
    private function create_tree_with_error($base_tree, $tree_items) {
        $options = $this->get_options();
        
        $body = ['tree' => $tree_items];
        if ($base_tree) {
            $body['base_tree'] = $base_tree;
        }
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/trees", $body);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return ['success' => true, 'sha' => $result['body']['sha']];
        }
        
        $error = $result['body']['message'] ?? ($result['error'] ?? 'Unknown error (code: ' . ($result['code'] ?? 'none') . ')');
        return ['success' => false, 'error' => $error];
    }
    
    /**
     * Create commit with detailed error reporting
     */
    private function create_commit_with_error($tree_sha, $parent_sha, $message) {
        $options = $this->get_options();
        
        $body = [
            'message' => $message,
            'tree' => $tree_sha,
            'author' => [
                'name' => $options['author_name'] ?: 'WP Git Sync',
                'email' => $options['author_email'] ?: 'git-sync@wordpress.local',
                'date' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
        
        // Only add parents if there's an existing commit
        if ($parent_sha) {
            $body['parents'] = [$parent_sha];
        } else {
            $body['parents'] = []; // Empty array for initial commit
        }
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/commits", $body);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return ['success' => true, 'sha' => $result['body']['sha']];
        }
        
        $error = $result['body']['message'] ?? ($result['error'] ?? 'Unknown error (code: ' . ($result['code'] ?? 'none') . ')');
        return ['success' => false, 'error' => $error];
    }
    
    /**
     * Update branch ref with detailed error reporting
     */
    private function update_branch_ref_with_error($commit_sha) {
        $options = $this->get_options();
        
        $result = $this->github_api('PATCH', "/repos/{$options['github_username']}/{$options['github_repo']}/git/refs/heads/{$options['github_branch']}", [
            'sha' => $commit_sha,
            'force' => true,
        ]);
        
        if (isset($result['code']) && $result['code'] === 200) {
            return ['success' => true];
        }
        
        $error = $result['body']['message'] ?? ($result['error'] ?? 'Unknown error (code: ' . ($result['code'] ?? 'none') . ')');
        return ['success' => false, 'error' => $error];
    }
    
    /**
     * Create branch ref with detailed error reporting
     */
    private function create_branch_ref_with_error($commit_sha) {
        $options = $this->get_options();
        
        $result = $this->github_api('POST', "/repos/{$options['github_username']}/{$options['github_repo']}/git/refs", [
            'ref' => "refs/heads/{$options['github_branch']}",
            'sha' => $commit_sha,
        ]);
        
        if (isset($result['code']) && $result['code'] === 201) {
            return ['success' => true];
        }
        
        // If ref already exists, try updating instead
        if (isset($result['code']) && $result['code'] === 422) {
            return $this->update_branch_ref_with_error($commit_sha);
        }
        
        $error = $result['body']['message'] ?? ($result['error'] ?? 'Unknown error (code: ' . ($result['code'] ?? 'none') . ')');
        return ['success' => false, 'error' => $error];
    }
    
    /**
     * Initialize repository (API mode - no git required)
     */
    public function init_repo_via_api($create_github_repo = true, $private = true) {
        $steps = [];
        $options = $this->get_options();
        
        // Validate settings
        if (empty($options['github_pat'])) {
            return ['success' => false, 'message' => 'GitHub Personal Access Token is required.', 'steps' => $steps];
        }
        if (empty($options['github_username'])) {
            return ['success' => false, 'message' => 'GitHub username is required.', 'steps' => $steps];
        }
        if (empty($options['github_repo'])) {
            return ['success' => false, 'message' => 'Repository name is required.', 'steps' => $steps];
        }
        
        // Step 1: Create GitHub repo if needed
        if ($create_github_repo) {
            $result = $this->create_github_repo($private);
            $steps[] = ['step' => 'Create GitHub Repository', 'result' => $result];
            if (!$result['success']) {
                return ['success' => false, 'message' => $result['message'], 'steps' => $steps];
            }
        }
        
        // Step 2: Create .gitignore locally
        $gitignore_path = rtrim($options['repo_path'], '/') . '/.gitignore';
        if (!file_exists($gitignore_path)) {
            $written = @file_put_contents($gitignore_path, $this->get_default_gitignore());
            $steps[] = [
                'step' => 'Create .gitignore',
                'result' => ['success' => $written !== false, 'message' => $written !== false ? 'Created successfully' : 'Failed to write file (check permissions)']
            ];
        } else {
            $steps[] = ['step' => 'Create .gitignore', 'result' => ['success' => true, 'message' => 'Already exists']];
        }
        
        // Step 2.5: Scan for files first to provide feedback
        $files = $this->get_files_to_sync();
        $scan_debug = $this->get_last_scan_debug();
        
        $scan_result = [
            'success' => count($files) > 0,
            'message' => count($files) > 0 
                ? 'Found ' . count($files) . ' files to sync'
                : 'No files found! Repo path: ' . $options['repo_path'] . ', Paths checked: ' . implode(', ', array_column($scan_debug['checked_paths'] ?? [], 'path'))
        ];
        $steps[] = ['step' => 'Scan Files', 'result' => $scan_result];
        
        if (count($files) === 0) {
            // Provide debug info
            $debug_info = "Repo path: {$options['repo_path']}\n";
            $debug_info .= "Sync paths:\n" . $options['sync_paths'] . "\n\n";
            $debug_info .= "Paths checked:\n";
            foreach ($scan_debug['checked_paths'] ?? [] as $check) {
                $debug_info .= "  - {$check['path']}: " . ($check['is_dir'] ? 'DIR' : ($check['is_file'] ? 'FILE' : 'NOT FOUND')) . "\n";
            }
            
            return [
                'success' => false, 
                'message' => 'No files found to sync. Check your "Paths to Sync" setting.',
                'steps' => $steps,
                'debug' => $debug_info
            ];
        }
        
        // Step 3: Initial sync via API
        $result = $this->sync_via_api('Initial commit via WP Git Sync');
        $steps[] = ['step' => 'Push Files to GitHub', 'result' => $result];
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'], 'steps' => $steps];
        }
        
        return ['success' => true, 'message' => 'Repository initialized and synced! ' . $result['message'], 'steps' => $steps];
    }
    
    // ===========================================
    // SYNC OPERATIONS (GIT MODE)
    // ===========================================
    
    /**
     * Check if directory is a git repository
     */
    public function is_git_initialized($path = null) {
        $options = $this->get_options();
        $repo_path = $path ?? $options['repo_path'];
        return is_dir($repo_path . '/.git');
    }
    
    /**
     * Check if remote origin is configured
     */
    public function has_remote_origin($path = null) {
        $options = $this->get_options();
        $repo_path = $path ?? $options['repo_path'];
        
        $remote = trim($this->run_git_command('remote get-url origin', $repo_path));
        return !empty($remote) && strpos($remote, 'fatal:') === false;
    }
    
    /**
     * Run sync using native git
     */
    public function sync_via_git() {
        $options = $this->get_options();
        $repo_path = $options['repo_path'];
        $branch = $options['github_branch'];
        $username = $options['github_username'];
        $repo = $options['github_repo'];
        $pat = $options['github_pat'];
        $author_name = $options['author_name'];
        $author_email = $options['author_email'];
        
        $remote_url = "https://{$username}:{$pat}@github.com/{$username}/{$repo}.git";
        
        // Check for lock file
        if (file_exists($repo_path . '/.git/index.lock')) {
            return ['success' => false, 'message' => 'Git is currently locked. Another process may be running.'];
        }
        
        // Configure identity
        $this->run_git_command('config user.email ' . escapeshellarg($author_email), $repo_path);
        $this->run_git_command('config user.name ' . escapeshellarg($author_name), $repo_path);
        
        // Check for local changes
        $status = $this->run_git_command('status --porcelain', $repo_path);
        
        if (!empty(trim($status))) {
            // Stage and commit local changes
            $this->run_git_command('add -A', $repo_path);
            $this->run_git_command('commit -m "Auto-sync: Server detected changes"', $repo_path);
            $this->run_git_command('push ' . escapeshellarg($remote_url) . ' ' . escapeshellarg($branch), $repo_path);
            $this->run_git_command('update-ref refs/remotes/origin/' . escapeshellarg($branch) . ' ' . escapeshellarg($branch), $repo_path);
        }
        
        // Pull remote changes
        $this->run_git_command('pull --no-edit ' . escapeshellarg($remote_url) . ' ' . escapeshellarg($branch), $repo_path);
        
        // Push any merge results
        $this->run_git_command('push ' . escapeshellarg($remote_url) . ' ' . escapeshellarg($branch), $repo_path);
        $this->run_git_command('update-ref refs/remotes/origin/' . escapeshellarg($branch) . ' ' . escapeshellarg($branch), $repo_path);
        
        return ['success' => true, 'message' => 'Repository synchronized successfully.'];
    }
    
    /**
     * Initialize repo using native git
     */
    public function init_repo_via_git($create_github_repo = true, $private = true) {
        $steps = [];
        $options = $this->get_options();
        $repo_path = $options['repo_path'];
        $branch = $options['github_branch'];
        $username = $options['github_username'];
        $repo = $options['github_repo'];
        $pat = $options['github_pat'];
        
        // Validate
        if (empty($pat) || empty($username) || empty($repo)) {
            return ['success' => false, 'message' => 'GitHub settings incomplete.', 'steps' => $steps];
        }
        
        // Step 1: Create GitHub repo
        if ($create_github_repo) {
            $result = $this->create_github_repo($private);
            $steps[] = ['step' => 'Create GitHub Repository', 'result' => $result];
            if (!$result['success']) {
                return ['success' => false, 'message' => $result['message'], 'steps' => $steps];
            }
        }
        
        // Step 2: Git init
        if (!$this->is_git_initialized()) {
            $this->run_git_command('init', $repo_path);
            $this->run_git_command('config user.email ' . escapeshellarg($options['author_email']), $repo_path);
            $this->run_git_command('config user.name ' . escapeshellarg($options['author_name']), $repo_path);
        }
        $steps[] = ['step' => 'Initialize Local Git', 'result' => ['success' => $this->is_git_initialized(), 'message' => $this->is_git_initialized() ? 'Initialized' : 'Failed']];
        
        // Step 3: Create .gitignore
        $gitignore_path = $repo_path . '/.gitignore';
        if (!file_exists($gitignore_path)) {
            file_put_contents($gitignore_path, $this->get_default_gitignore());
        }
        $steps[] = ['step' => 'Create .gitignore', 'result' => ['success' => true, 'message' => 'Created']];
        
        // Step 4: Setup remote
        $remote_url = "https://{$username}:{$pat}@github.com/{$username}/{$repo}.git";
        if ($this->has_remote_origin()) {
            $this->run_git_command('remote set-url origin ' . escapeshellarg($remote_url), $repo_path);
        } else {
            $this->run_git_command('remote add origin ' . escapeshellarg($remote_url), $repo_path);
        }
        $steps[] = ['step' => 'Configure Remote', 'result' => ['success' => true, 'message' => 'Configured']];
        
        // Step 5: Initial commit and push
        $this->run_git_command('add -A', $repo_path);
        $this->run_git_command('commit -m "Initial commit via WP Git Sync"', $repo_path);
        $this->run_git_command('branch -M ' . escapeshellarg($branch), $repo_path);
        $push_result = $this->run_git_command('push -u ' . escapeshellarg($remote_url) . ' ' . escapeshellarg($branch), $repo_path);
        
        $push_success = strpos($push_result, 'fatal:') === false && strpos($push_result, 'error:') === false;
        $steps[] = ['step' => 'Initial Commit & Push', 'result' => ['success' => $push_success, 'message' => $push_success ? 'Pushed successfully' : $push_result]];
        
        return ['success' => $push_success, 'message' => $push_success ? 'Repository initialized!' : 'Push failed', 'steps' => $steps];
    }
    
    private function run_git_command($command, $repo_path) {
        $full_command = "cd " . escapeshellarg($repo_path) . " && git " . $command . " 2>&1";
        return shell_exec($full_command) ?? '';
    }
    
    // ===========================================
    // UNIFIED SYNC METHODS
    // ===========================================
    
    /**
     * Run sync (auto-selects method based on git availability)
     */
    public function run_sync() {
        if ($this->is_git_available() && $this->is_git_initialized()) {
            return $this->sync_via_git();
        }
        return $this->sync_via_api();
    }
    
    /**
     * Initialize repository (auto-selects method)
     */
    public function full_init_repository($create_github_repo = true, $private = true) {
        if ($this->is_git_available()) {
            return $this->init_repo_via_git($create_github_repo, $private);
        }
        return $this->init_repo_via_api($create_github_repo, $private);
    }
    
    /**
     * Trigger sync (called by hooks)
     */
    public function trigger_sync() {
        $options = $this->get_options();
        
        if (!$options['auto_sync']) {
            return;
        }
        
        if (empty($options['github_pat']) || empty($options['github_username']) || empty($options['github_repo'])) {
            return;
        }
        
        // Run sync (will use API if git not available)
        $this->run_sync();
    }
    
    /**
     * Get commit history (auto-selects method)
     */
    private function get_commit_history($per_page = 15, $page = 1) {
        // Always use API for commits - it's more reliable
        return $this->get_commits_api($per_page, $page);
    }
    
    // ===========================================
    // SECTION RENDERERS
    // ===========================================
    
    public function render_github_section() {
        echo '<p>Enter your GitHub credentials. Create a Personal Access Token at <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a>.</p>';
        echo '<div style="background: #fff8e5; border: 1px solid #ffcc00; border-radius: 4px; padding: 12px 15px; margin: 10px 0 20px 0;">';
        echo '<strong style="color: #735c0f;">⚠️ Required PAT Permissions:</strong>';
        echo '<ul style="margin: 8px 0 0 20px; color: #735c0f;">';
        echo '<li><code>repo</code> — Full control of private repositories (required for creating repos, pushing, and pulling)</li>';
        echo '</ul>';
        echo '<p style="margin: 10px 0 0 0; font-size: 12px; color: #735c0f;">For Fine-grained tokens: Select your account, choose "All repositories" or specific repos, and grant <strong>Read and Write</strong> access to: Contents, Metadata, and Administration (for repo creation).</p>';
        echo '</div>';
    }
    
    public function render_identity_section() {
        echo '<p>Commits will be attributed to this identity.</p>';
    }
    
    public function render_webhook_section() {
        $webhook_url = home_url('/wp-json/wp-git-sync/v1/webhook');
        
        echo '<div style="background: #f0f6fc; border: 1px solid #0969da; border-radius: 6px; padding: 16px; margin-bottom: 20px;">';
        echo '<h4 style="margin: 0 0 12px 0; color: #0969da;">📡 Your Webhook URL</h4>';
        echo '<div style="display: flex; gap: 10px; align-items: center;">';
        echo '<input type="text" value="' . esc_attr($webhook_url) . '" readonly style="flex: 1; font-family: monospace; padding: 8px 12px; background: #fff; border: 1px solid #d0d7de; border-radius: 4px;" id="webhook-url-field">';
        echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'webhook-url-field\').value); this.textContent=\'Copied!\'; setTimeout(() => this.textContent=\'Copy\', 2000);">Copy</button>';
        echo '</div>';
        echo '<p style="margin: 12px 0 0 0; font-size: 13px; color: #57606a;"><strong>Setup:</strong> Go to your GitHub repo → Settings → Webhooks → Add webhook. Paste this URL, set Content-Type to <code>application/json</code>, enter your secret below, and select "Just the push event".</p>';
        echo '</div>';
    }
    
    public function render_advanced_section() {
        // Show sync mode
        $mode = $this->get_sync_mode();
        $mode_label = $mode === 'git' ? '✓ Git Available' : '⚡ API Mode (No Git)';
        $mode_color = $mode === 'git' ? '#2e7d32' : '#0969da';
        $mode_desc = $mode === 'git' 
            ? 'Using native git commands for full two-way sync.' 
            : 'Git not installed. Using GitHub API for push-only sync.';
        
        echo '<div style="background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 4px; padding: 12px 15px; margin-bottom: 20px;">';
        echo '<strong style="color: ' . esc_attr( $mode_color ) . ';">' . esc_html( $mode_label ) . '</strong>';
        echo '<p style="margin: 5px 0 0 0; font-size: 13px; color: #57606a;">' . esc_html( $mode_desc ) . '</p>';
        echo '</div>';
    }
    
    // Field Renderers
    public function render_pat_field() {
        $options = $this->get_options();
        $value = $options['github_pat'];
        
        echo '<input type="password" name="' . esc_attr( $this->option_name ) . '[github_pat]" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="new-password">';
        echo ' <a href="https://github.com/settings/tokens/new?scopes=repo&description=WP%20Git%20Sync" target="_blank" class="button button-small">Create Token →</a>';
        if ($value) {
            echo '<p class="description" style="color: #2e7d32;">✓ Token configured (ending in ' . esc_html(substr($value, -4)) . ')</p>';
        } else {
            echo '<p class="description">Required. Must have <code>repo</code> scope. See permission requirements above.</p>';
        }
    }
    
    public function render_username_field() {
        $options = $this->get_options();
        echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[github_username]" value="' . esc_attr( $options['github_username'] ) . '" class="regular-text" placeholder="your-username">';
    }
    
    public function render_repo_field() {
        $options = $this->get_options();
        echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[github_repo]" value="' . esc_attr( $options['github_repo'] ) . '" class="regular-text" placeholder="my-wordpress-site">';
        echo '<p class="description">Just the repository name. Will be created if it doesn\'t exist.</p>';
    }
    
    public function render_branch_field() {
        $options = $this->get_options();
        echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[github_branch]" value="' . esc_attr( $options['github_branch'] ) . '" class="regular-text" placeholder="main">';
    }
    
    public function render_author_name_field() {
        $options = $this->get_options();
        echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[author_name]" value="' . esc_attr( $options['author_name'] ) . '" class="regular-text" placeholder="WordPress Server">';
    }
    
    public function render_author_email_field() {
        $options = $this->get_options();
        echo '<input type="email" name="' . esc_attr( $this->option_name ) . '[author_email]" value="' . esc_attr( $options['author_email'] ) . '" class="regular-text" placeholder="server@example.com">';
    }
    
    public function render_webhook_secret_field() {
        $options = $this->get_options();
        echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[webhook_secret]" value="' . esc_attr( $options['webhook_secret'] ) . '" class="regular-text" style="font-family: monospace;">';
        echo '<button type="button" class="button" onclick="this.previousElementSibling.value = \'' . esc_js( wp_generate_password( 32, false ) ) . '\'">Generate New</button>';
        echo '<p class="description">Use this same secret in your GitHub webhook settings.</p>';
    }
    
    public function render_repo_path_field() {
        $options = $this->get_options();
        echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[repo_path]" value="' . esc_attr( $options['repo_path'] ) . '" class="regular-text" style="font-family: monospace;">';
        echo '<p class="description">Path to scan for files. Default: WordPress installation directory.</p>';
    }
    
    public function render_auto_sync_field() {
        $options = $this->get_options();
        echo '<label><input type="checkbox" name="' . esc_attr( $this->option_name ) . '[auto_sync]" value="1" ' . checked( 1, $options['auto_sync'], false ) . '> Automatically sync when plugins/themes are installed, updated, or deleted</label>';
    }
    
    public function render_sync_paths_field() {
        $options = $this->get_options();
        echo '<textarea name="' . esc_attr( $this->option_name ) . '[sync_paths]" rows="5" class="large-text code">' . esc_textarea( $options['sync_paths'] ) . '</textarea>';
        echo '<p class="description">Paths to sync (one per line, relative to repo path). Only these directories/files will be pushed to GitHub.</p>';
    }
    
    /**
     * Get default .gitignore content
     */
    private function get_default_gitignore() {
        $gitignore = "# Uploads (too large for git)\n";
        $gitignore .= "wp-content/uploads/\n\n";
        $gitignore .= "# Cache and backups\n";
        $gitignore .= "wp-content/cache/\n";
        $gitignore .= "wp-content/backup*/\n";
        $gitignore .= "wp-content/upgrade/\n";
        $gitignore .= "wp-content/backups/\n\n";
        $gitignore .= "# Debug log\n";
        $gitignore .= "wp-content/debug.log\n";
        $gitignore .= "error_log\n\n";
        $gitignore .= "# Development files\n";
        $gitignore .= "node_modules/\n";
        $gitignore .= ".sass-cache/\n";
        $gitignore .= "*.map\n\n";
        $gitignore .= "# OS files\n";
        $gitignore .= ".DS_Store\n";
        $gitignore .= "Thumbs.db\n\n";
        $gitignore .= "# IDE\n";
        $gitignore .= ".idea/\n";
        $gitignore .= ".vscode/\n";
        $gitignore .= "*.sublime-*\n\n";
        $gitignore .= "# Misc\n";
        $gitignore .= "*.log\n";
        $gitignore .= "*.swp\n";
        $gitignore .= "*.bak\n";
        return $gitignore;
    }
    
    // ===========================================
    // ADMIN STYLES
    // ===========================================
    
    public function enqueue_admin_styles($hook) {
        if (strpos($hook, 'wp-git-sync') === false) return;
        
        wp_add_inline_style('wp-admin', '
            .wp-git-sync-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .wp-git-sync-card h2 {
                margin-top: 0;
                padding-bottom: 12px;
                border-bottom: 1px solid #eee;
            }
            .wp-git-sync-status-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 14px;
                border-radius: 4px;
                font-weight: 500;
                margin-bottom: 15px;
            }
            .wp-git-sync-status-badge.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .wp-git-sync-status-badge.warning {
                background: #fff3cd;
                color: #856404;
                border: 1px solid #ffeeba;
            }
            .wp-git-sync-status-badge.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .wp-git-sync-status-badge.info {
                background: #cce5ff;
                color: #004085;
                border: 1px solid #b8daff;
            }
            .wp-git-sync-metrics {
                display: flex;
                gap: 30px;
                margin: 20px 0;
            }
            .wp-git-sync-metric {
                text-align: center;
            }
            .wp-git-sync-metric-value {
                font-size: 32px;
                font-weight: 700;
                color: #1d2327;
                line-height: 1;
            }
            .wp-git-sync-metric-label {
                font-size: 12px;
                color: #646970;
                text-transform: uppercase;
                margin-top: 4px;
            }
            .wp-git-sync-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 20px;
            }
            @media (max-width: 960px) {
                .wp-git-sync-grid {
                    grid-template-columns: 1fr;
                }
            }
            .wp-git-sync-log {
                background: #1d2327;
                color: #c3c4c7;
                font-family: Consolas, Monaco, monospace;
                font-size: 12px;
                padding: 15px;
                border-radius: 4px;
                max-height: 200px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-break: break-all;
            }
            .wp-git-sync-commit-table {
                width: 100%;
            }
            .wp-git-sync-commit-table th {
                text-align: left;
                padding: 12px 8px;
                border-bottom: 2px solid #c3c4c7;
                font-weight: 600;
            }
            .wp-git-sync-commit-table td {
                padding: 10px 8px;
                border-bottom: 1px solid #eee;
            }
            .wp-git-sync-sha {
                font-family: Consolas, Monaco, monospace;
                font-size: 12px;
                background: #f0f0f1;
                padding: 3px 8px;
                border-radius: 3px;
                text-decoration: none;
                color: #2271b1;
            }
            .wp-git-sync-sha:hover {
                background: #2271b1;
                color: #fff;
            }
            .wp-git-sync-setup-card {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                border-radius: 8px;
                padding: 30px;
                margin-bottom: 20px;
            }
            .wp-git-sync-setup-card h2 {
                color: #fff;
                border: none;
                margin: 0 0 15px 0;
                padding: 0;
            }
            .wp-git-sync-setup-card p {
                opacity: 0.9;
                margin-bottom: 20px;
            }
            .wp-git-sync-setup-card .button {
                background: #fff;
                color: #667eea;
                border: none;
                font-weight: 600;
            }
            .wp-git-sync-setup-card .button:hover {
                background: #f0f0f0;
                color: #764ba2;
            }
            .wp-git-sync-checklist {
                list-style: none;
                padding: 0;
                margin: 0 0 20px 0;
            }
            .wp-git-sync-checklist li {
                padding: 10px 0;
                border-bottom: 1px solid rgba(255,255,255,0.2);
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .wp-git-sync-checklist li:last-child {
                border-bottom: none;
            }
            .wp-git-sync-check {
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
            }
            .wp-git-sync-check.done {
                background: rgba(255,255,255,0.3);
            }
            .wp-git-sync-check.pending {
                background: rgba(0,0,0,0.2);
            }
            .wp-git-sync-mode-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .wp-git-sync-mode-badge.api {
                background: #e3f2fd;
                color: #1565c0;
            }
            .wp-git-sync-mode-badge.git {
                background: #e8f5e9;
                color: #2e7d32;
            }
        ');
    }
    
    // ===========================================
    // PAGE RENDERERS
    // ===========================================
    
    public function render_dashboard_page() {
        $options = $this->get_options();
        
        // Check if configured
        $is_configured = !empty($options['github_pat']) && !empty($options['github_username']) && !empty($options['github_repo']);
        
        if (!$is_configured) {
            $this->render_setup_notice();
            return;
        }
        
        // Check repository status
        $github_exists = $this->github_repo_exists();
        $sync_mode = $this->get_sync_mode();
        
        // Handle initialization
        if (isset($_POST['wp_git_sync_init']) && check_admin_referer('wp_git_sync_init_action')) {
            $create_repo = isset($_POST['create_github_repo']);
            $private = isset($_POST['private_repo']);
            $result = $this->full_init_repository($create_repo, $private);
            
            if ($result['success']) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> ' . esc_html($result['message']) . '</p></div>';
                $github_exists = true;
            } else {
                echo '<div class="notice notice-error"><p><strong>Error:</strong> ' . esc_html($result['message']) . '</p>';
                if (!empty($result['steps'])) {
                    echo '<ul style="margin-left: 20px; margin-bottom: 10px;">';
                    foreach ($result['steps'] as $step) {
                        $icon = $step['result']['success'] ? '✓' : '✗';
                        $color = $step['result']['success'] ? '#2e7d32' : '#d32f2f';
                        echo '<li style="color: ' . esc_attr( $color ) . ';">' . esc_html( $icon ) . ' <strong>' . esc_html( $step['step'] ) . ':</strong> ' . esc_html( $step['result']['message'] ) . '</li>';
                    }
                    echo '</ul>';
                }
                if (!empty($result['debug'])) {
                    echo '<details style="margin-top: 10px;"><summary style="cursor: pointer; color: #666;">Show Debug Info</summary>';
                    echo '<pre style="background: #f5f5f5; padding: 10px; margin-top: 10px; overflow-x: auto; font-size: 11px;">' . esc_html($result['debug']) . '</pre>';
                    echo '</details>';
                }
                echo '</div>';
            }
        }
        
        // Show setup wizard if repo doesn't exist
        if (!$github_exists) {
            $this->render_init_wizard();
            return;
        }
        
        // Handle manual sync
        if (isset($_POST['wp_git_sync_manual']) && check_admin_referer('wp_git_sync_manual_action')) {
            $result = $this->run_sync();
            if ($result['success']) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Sync Complete!</strong> ' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Sync Failed:</strong> ' . esc_html($result['message']) . '</p></div>';
            }
        }
        
        // Get commit history
        $per_page = 15;
        $page = isset($_GET['git_page']) ? max(1, intval($_GET['git_page'])) : 1;
        $commits = $this->get_commit_history($per_page, $page);
        
        ?>
        <div class="wrap">
            <h1 style="display: flex; align-items: center; gap: 10px;">
                <span class="dashicons dashicons-cloud-saved" style="font-size: 30px; width: 30px; height: 30px;"></span>
                Git Sync Dashboard
                <span class="wp-git-sync-mode-badge <?php echo esc_attr( $sync_mode ); ?>">
                    <?php echo $sync_mode === 'api' ? '⚡ API Mode' : '🔧 Git Mode'; ?>
                </span>
            </h1>
            
            <?php if ($sync_mode === 'api'): ?>
            <div class="notice notice-info" style="margin: 15px 0;">
                <p><strong>Running in API Mode</strong> — Git is not installed on this server. The plugin is using GitHub's API directly to sync files. This works great for pushing changes, but won't pull changes from GitHub (use webhooks for that).</p>
            </div>
            <?php endif; ?>
            
            <div class="wp-git-sync-grid" style="margin-top: 20px;">
                <div>
                    <!-- Status Card -->
                    <div class="wp-git-sync-card">
                        <h2>Sync Status</h2>
                        
                        <div class="wp-git-sync-status-badge success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            Connected to GitHub
                        </div>
                        
                        <p style="color: #646970; margin: 0;">
                            Repository: <a href="https://github.com/<?php echo esc_attr($options['github_username']); ?>/<?php echo esc_attr($options['github_repo']); ?>" target="_blank">
                                <?php echo esc_html($options['github_username'] . '/' . $options['github_repo']); ?>
                            </a><br>
                            Branch: <code><?php echo esc_html($options['github_branch']); ?></code><br>
                            Mode: <?php echo $sync_mode === 'api' ? 'GitHub API (push-only)' : 'Native Git (full sync)'; ?>
                        </p>
                    </div>
                    
                    <!-- Commit History Card -->
                    <div class="wp-git-sync-card">
                        <h2>Recent Commits</h2>
                        
                        <?php if (!empty($commits)): ?>
                            <table class="wp-git-sync-commit-table">
                                <thead>
                                    <tr>
                                        <th>SHA</th>
                                        <th>Message</th>
                                        <th>Author</th>
                                        <th>When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($commits as $commit): 
                                        $link = 'https://github.com/' . $options['github_username'] . '/' . $options['github_repo'] . '/commit/' . ($commit['full_sha'] ?? $commit['sha']);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url($link); ?>" target="_blank" class="wp-git-sync-sha">
                                                <?php echo esc_html($commit['sha']); ?>
                                                <span class="dashicons dashicons-external" style="font-size: 12px; vertical-align: middle;"></span>
                                            </a>
                                        </td>
                                        <td><?php echo esc_html(substr($commit['message'], 0, 50) . (strlen($commit['message']) > 50 ? '...' : '')); ?></td>
                                        <td><?php echo esc_html($commit['author']); ?></td>
                                        <td><?php echo esc_html($commit['date']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <?php if ($page > 1): ?>
                                        <a class="button" href="?page=wp-git-sync&amp;git_page=<?php echo intval( $page - 1 ); ?>">← Previous</a>
                                    <?php endif; ?>
                                </div>
                                <span style="color: #646970;">Page <?php echo intval( $page ); ?></span>
                                <div>
                                    <?php if (count($commits) >= $per_page): ?>
                                        <a class="button" href="?page=wp-git-sync&amp;git_page=<?php echo intval( $page + 1 ); ?>">Next →</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <p style="color: #646970;">No commits found. Push some changes to see them here.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <!-- Actions Card -->
                    <div class="wp-git-sync-card">
                        <h2>Actions</h2>
                        
                        <form method="post" style="margin-top: 10px;">
                            <?php wp_nonce_field('wp_git_sync_manual_action'); ?>
                            <button type="submit" name="wp_git_sync_manual" class="button button-primary button-hero" style="width: 100%;">
                                <span class="dashicons dashicons-update" style="margin-top: 5px;"></span>
                                Sync Now
                            </button>
                        </form>
                        
                        <p style="text-align: center; color: #646970; font-size: 12px; margin-top: 10px;">
                            <?php echo $sync_mode === 'api' ? 'Pushes local changes to GitHub.' : 'Commits, pushes, and pulls changes.'; ?>
                        </p>
                    </div>
                    
                    <!-- Webhook Card -->
                    <div class="wp-git-sync-card">
                        <h2>Webhook URL</h2>
                        <?php $webhook_url = home_url('/wp-json/wp-git-sync/v1/webhook'); ?>
                        <p style="margin-bottom: 10px; font-size: 12px; color: #646970;">Add this to GitHub to sync when code is pushed:</p>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" value="<?php echo esc_attr($webhook_url); ?>" readonly style="flex: 1; font-family: monospace; font-size: 11px; padding: 6px 8px;" id="webhook-url-dashboard">
                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('webhook-url-dashboard').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
                        </div>
                    </div>
                    
                    <!-- Quick Links Card -->
                    <div class="wp-git-sync-card">
                        <h2>Quick Links</h2>
                        <p>
                            <a href="https://github.com/<?php echo esc_attr($options['github_username']); ?>/<?php echo esc_attr($options['github_repo']); ?>" target="_blank" class="button" style="width: 100%; text-align: center; margin-bottom: 8px;">
                                View on GitHub
                            </a>
                        </p>
                        <p>
                            <a href="https://github.com/<?php echo esc_attr($options['github_username']); ?>/<?php echo esc_attr($options['github_repo']); ?>/settings/hooks" target="_blank" class="button" style="width: 100%; text-align: center; margin-bottom: 8px;">
                                Manage Webhooks
                            </a>
                        </p>
                        <p>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-git-sync-settings' ) ); ?>" class="button" style="width: 100%; text-align: center;">
                                Settings
                            </a>
                        </p>
                    </div>
                    
                    <!-- Info Card -->
                    <div class="wp-git-sync-card">
                        <h2>Configuration</h2>
                        <table style="width: 100%; font-size: 13px;">
                            <tr>
                                <td style="padding: 5px 0; color: #646970;">Auto-Sync</td>
                                <td style="padding: 5px 0; text-align: right;">
                                    <?php echo $options['auto_sync'] ? '<span style="color: #2e7d32;">● Enabled</span>' : '<span style="color: #d32f2f;">● Disabled</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 5px 0; color: #646970;">Sync Mode</td>
                                <td style="padding: 5px 0; text-align: right;"><?php echo $sync_mode === 'api' ? 'API' : 'Git'; ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 5px 0; color: #646970;">Path</td>
                                <td style="padding: 5px 0; text-align: right; font-family: monospace; font-size: 10px;"><?php echo esc_html(substr($options['repo_path'], -25)); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function render_setup_notice() {
        ?>
        <div class="wrap">
            <h1>Git Sync Dashboard</h1>
            <div class="wp-git-sync-card" style="max-width: 600px; margin-top: 20px;">
                <div style="text-align: center; padding: 40px 20px;">
                    <span class="dashicons dashicons-cloud-saved" style="font-size: 64px; width: 64px; height: 64px; color: #2271b1;"></span>
                    <h2 style="margin: 20px 0 10px;">Welcome to WP Git Sync!</h2>
                    <p style="color: #646970; margin-bottom: 30px;">
                        Connect your WordPress site to GitHub for automatic version control.
                    </p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-git-sync-settings' ) ); ?>" class="button button-primary button-hero">
                        Configure Settings
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function render_init_wizard() {
        $options = $this->get_options();
        $sync_mode = $this->get_sync_mode();
        ?>
        <div class="wrap">
            <h1 style="display: flex; align-items: center; gap: 10px;">
                <span class="dashicons dashicons-cloud-saved" style="font-size: 30px; width: 30px; height: 30px;"></span>
                Git Sync Setup
                <span class="wp-git-sync-mode-badge <?php echo esc_attr( $sync_mode ); ?>">
                    <?php echo $sync_mode === 'api' ? '⚡ API Mode' : '🔧 Git Mode'; ?>
                </span>
            </h1>
            
            <?php if ($sync_mode === 'api'): ?>
            <div class="notice notice-info" style="margin: 15px 0;">
                <p><strong>No Git? No Problem!</strong> — Git is not installed on this server, but that's okay! The plugin will use GitHub's API directly to push your files. Everything will work, you just won't be able to pull changes (use webhooks for notifications instead).</p>
            </div>
            <?php endif; ?>
            
            <div class="wp-git-sync-setup-card" style="max-width: 700px; margin-top: 20px;">
                <h2>🚀 Initialize Your Repository</h2>
                <p>Your WordPress site will be connected to GitHub. This wizard will set everything up automatically.</p>
                
                <ul class="wp-git-sync-checklist">
                    <li>
                        <span class="wp-git-sync-check pending">1</span>
                        <span>Create GitHub Repository: <code><?php echo esc_html($options['github_username'] . '/' . $options['github_repo']); ?></code></span>
                    </li>
                    <li>
                        <span class="wp-git-sync-check pending">2</span>
                        <span>Create .gitignore with WordPress defaults</span>
                    </li>
                    <li>
                        <span class="wp-git-sync-check pending">3</span>
                        <span>Push your files to GitHub</span>
                    </li>
                </ul>
                
                <form method="post">
                    <?php wp_nonce_field('wp_git_sync_init_action'); ?>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="create_github_repo" value="1" checked>
                            <span>Create GitHub repository if it doesn't exist</span>
                        </label>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="private_repo" value="1" checked>
                            <span>Make repository private (recommended)</span>
                        </label>
                    </div>
                    
                    <button type="submit" name="wp_git_sync_init" class="button button-hero" style="background: #fff; color: #667eea; border: none; font-weight: 600;">
                        <span class="dashicons dashicons-controls-play" style="margin-top: 5px;"></span>
                        Initialize Repository
                    </button>
                    
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-git-sync-settings' ) ); ?>" class="button button-hero" style="background: rgba(255,255,255,0.2); color: #fff; border: none; margin-left: 10px;">
                        Edit Settings
                    </a>
                </form>
            </div>
            
            <div class="wp-git-sync-card" style="max-width: 700px;">
                <h2>📁 Files to be Synced</h2>
                <p style="color: #646970;">Based on your settings, these directories will be pushed to GitHub:</p>
                <pre style="background: #f6f8fa; padding: 15px; border-radius: 4px; overflow-x: auto;"><?php echo esc_html($options['sync_paths']); ?></pre>
                
                <?php
                // Test scan to show what files would be synced
                $files = $this->get_files_to_sync();
                $scan_debug = $this->get_last_scan_debug();
                ?>
                
                <div style="background: <?php echo count($files) > 0 ? '#e8f5e9' : '#ffebee'; ?>; border-radius: 4px; padding: 15px; margin-top: 15px;">
                    <strong style="color: <?php echo count($files) > 0 ? '#2e7d32' : '#c62828'; ?>;">
                        <?php echo count($files) > 0 ? '✓' : '✗'; ?> 
                        Found <?php echo count($files); ?> files to sync
                    </strong>
                    
                    <?php if (count($files) > 0): ?>
                        <details style="margin-top: 10px;">
                            <summary style="cursor: pointer; color: #666; font-size: 13px;">Show files (first 50)</summary>
                            <pre style="background: #fff; padding: 10px; margin-top: 10px; max-height: 200px; overflow-y: auto; font-size: 11px;"><?php 
                                $file_list = array_keys($files);
                                echo esc_html(implode("\n", array_slice($file_list, 0, 50)));
                                if (count($files) > 50) {
                                    echo "\n... and " . (count($files) - 50) . " more files";
                                }
                            ?></pre>
                        </details>
                    <?php else: ?>
                        <div style="margin-top: 10px; font-size: 13px; color: #c62828;">
                            <p><strong>Paths checked:</strong></p>
                            <ul style="margin: 5px 0 0 20px;">
                                <?php foreach ($scan_debug['checked_paths'] ?? [] as $check): ?>
                                    <li>
                                        <code><?php echo esc_html($check['full_path']); ?></code>
                                        → <?php echo $check['is_dir'] ? '📁 Directory found' : ($check['is_file'] ? '📄 File found' : '❌ Not found'); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top: 10px;">
                                <strong>Tip:</strong> Make sure the paths in "Paths to Sync" match your WordPress directory structure.
                                Your repo path is: <code><?php echo esc_html($options['repo_path']); ?></code>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <p style="font-size: 12px; color: #646970; margin-top: 15px;">
                    You can change paths in <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-git-sync-settings' ) ); ?>">Settings → Paths to Sync</a>
                </p>
            </div>
        </div>
        <?php
    }
    
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Git Sync Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_group);
                do_settings_sections('wp-git-sync-settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }
    
    // ===========================================
    // AJAX HANDLERS
    // ===========================================
    
    public function ajax_run_sync() {
        check_ajax_referer('wp_git_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $result = $this->run_sync();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('wp_git_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $exists = $this->github_repo_exists();
        
        if ($exists === null) {
            wp_send_json_error(['message' => 'Could not connect to GitHub.']);
        } elseif ($exists) {
            wp_send_json_success(['message' => 'Connection successful! Repository exists.']);
        } else {
            wp_send_json_success(['message' => 'Connection successful! Repository does not exist yet.']);
        }
    }
    
    public function ajax_init_repository() {
        check_ajax_referer('wp_git_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $create_repo = isset($_POST['create_repo']) && $_POST['create_repo'] === 'true';
        $private = isset($_POST['private']) && $_POST['private'] === 'true';
        
        $result = $this->full_init_repository($create_repo, $private);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}

// Initialize plugin
WP_Git_Sync::get_instance();

// Register REST API endpoint for webhook
add_action('rest_api_init', function() {
    register_rest_route('wp-git-sync/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => 'wp_git_sync_handle_webhook',
        'permission_callback' => '__return_true',
    ]);
});

function wp_git_sync_handle_webhook(WP_REST_Request $request) {
    $plugin = WP_Git_Sync::get_instance();
    $options = $plugin->get_options();
    
    // Verify webhook secret
    $signature = $request->get_header('X-Hub-Signature-256');
    $payload = $request->get_body();
    
    if (!empty($options['webhook_secret'])) {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $options['webhook_secret']);
        
        if (!hash_equals($expected, $signature ?? '')) {
            return new WP_REST_Response(['error' => 'Invalid signature'], 403);
        }
    }
    
    // Check branch
    $data = $request->get_json_params();
    $expected_ref = 'refs/heads/' . $options['github_branch'];
    
    if (isset($data['ref']) && $data['ref'] === $expected_ref) {
        // Only sync if git is available (API mode can't pull)
        if ($plugin->is_git_available()) {
            $plugin->run_sync();
            return new WP_REST_Response(['status' => 'Sync triggered'], 200);
        }
        return new WP_REST_Response(['status' => 'Webhook received (API mode - manual pull required)'], 200);
    }
    
    return new WP_REST_Response(['status' => 'Ignored (wrong branch)'], 200);
}
