=== WP Git Sync ===
Contributors: appletechie
Donate link: https://github.com/sponsors/apple-techie
Tags: git, github, sync, backup, version-control
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically sync your WordPress site with GitHub. Works with or without Git installed - uses GitHub API as fallback.

== Description ==

WP Git Sync automatically synchronizes your WordPress plugins and themes with a GitHub repository. Perfect for version control, backup, and deployment workflows.

**Key Features:**

* 🚀 **No Git Required** - Works on any hosting using GitHub's API
* 🔄 **Auto-Sync** - Automatically pushes when plugins/themes change
* 📦 **Auto-Create Repos** - Creates private GitHub repositories automatically
* 🎛️ **Admin Dashboard** - Beautiful UI showing status, commits, and sync controls
* 🔐 **Secure** - All credentials stored securely in WordPress database
* 📡 **Webhook Support** - Receives GitHub webhooks for notifications

**Two Sync Modes:**

1. **API Mode** - When Git is not installed, uses GitHub's REST API (works on managed hosting like WP Engine, Kinsta, Flywheel)
2. **Git Mode** - When Git is available, uses native commands for full two-way sync

**Use Cases:**

* Keep your custom plugins/themes in version control
* Backup your WordPress customizations to GitHub
* Deploy changes from GitHub to your WordPress site
* Collaborate on plugin/theme development

== Installation ==

1. Upload the `wp-git-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Git Sync' → 'Settings' to configure your GitHub credentials
4. Create a GitHub Personal Access Token with 'repo' scope
5. Click 'Initialize Repository' to start syncing

== Frequently Asked Questions ==

= Do I need Git installed on my server? =

No! WP Git Sync works with or without Git. If Git isn't available, it uses GitHub's API directly.

= What GitHub permissions do I need? =

For Classic tokens: `repo` scope
For Fine-grained tokens: Contents (Read/Write), Metadata (Read), Administration (Read/Write)

= Will this work on managed WordPress hosting? =

Yes! The plugin automatically detects if Git is available and falls back to the GitHub API if not. This works on WP Engine, Kinsta, Flywheel, and other managed hosts.

= What files are synced? =

By default: `wp-content/plugins/`, `wp-content/themes/`, and `wp-content/mu-plugins/`. You can customize this in Settings.

= Is my GitHub token secure? =

Yes. Your Personal Access Token is stored in the WordPress database using the Options API, protected by your WordPress installation's security.

= Can I sync to a private repository? =

Yes! The plugin creates private repositories by default, and you can sync to any private repo you have access to.

== Screenshots ==

1. Dashboard showing sync status and commit history
2. Settings page for GitHub configuration
3. Repository initialization wizard

== Changelog ==

= 2.2.1 =
* Fixed: Background sync now properly uses base_tree for incremental updates
* Fixed: Only changed files are included in tree (prevents GitHub API limits)
* Fixed: Added missing get_remote_tree_recursive() and is_path_managed() methods
* Fixed: Better error messages with detailed debugging info
* Fixed: Clear & Retry button for stuck sync jobs

= 2.2.0 =
* New: Background sync for large codebases (WooCommerce, etc.)
* New: AJAX polling with real-time progress indicator
* New: Batch processing (50 files at a time) to avoid timeouts
* New: Smart SHA comparison to skip unchanged files
* Improved: Much faster syncs for sites with many files

= 2.1.0 =
* Fixed: Empty repository sync now works correctly using Contents API
* Fixed: Better error messages for failed syncs
* Added: File scan preview in setup wizard
* Added: Debug output for troubleshooting
* Improved: Handling of initial commits on new repositories

= 2.0.0 =
* New: API Mode - works without Git installed
* New: Configurable paths to sync
* New: Automatic detection of sync mode
* Improved: Setup wizard with progress indicators

= 1.1.0 =
* New: Auto-create GitHub repositories via API
* New: One-click repository initialization
* New: WordPress-optimized .gitignore generation

= 1.0.0 =
* Initial release
* Admin dashboard with status and commit history
* Settings page for configuration
* GitHub webhook support
* Auto-sync on plugin/theme changes

== Upgrade Notice ==

= 2.2.0 =
Major performance update! Background sync now handles large sites (WooCommerce, etc.) without timing out.

= 2.1.0 =
Fixes critical issue with syncing to empty/new repositories. Update recommended.

= 2.0.0 =
Major update adds support for hosting without Git installed. Now works on managed WordPress hosting.

== Privacy Policy ==

WP Git Sync stores your GitHub Personal Access Token in your WordPress database. This token is used solely to communicate with GitHub's API to sync your files. No data is sent to any third parties other than GitHub.

The plugin communicates with:
* GitHub API (api.github.com) - For repository operations
* Your configured GitHub repository - For file sync

