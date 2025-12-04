# WP Git Sync

A WordPress plugin that automatically synchronizes your WordPress site with a GitHub repository. **Works with or without Git installed** — uses GitHub's API as a fallback for managed/shared hosting.

![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-blue)
![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple)
![License](https://img.shields.io/badge/License-GPL%20v2-green)
[![Sponsor](https://img.shields.io/badge/Sponsor-❤-ea4aaa)](https://github.com/sponsors/apple-techie)

## ✨ Features

- 🚀 **No Git Required**: Works on any hosting — uses GitHub API when git isn't installed
- 🔄 **Auto-Sync**: Automatically pushes when plugins/themes change
- ⚡ **Background Sync**: Handles large codebases (WooCommerce, etc.) without timeouts
- 📦 **Auto-Create Repos**: Creates private GitHub repositories automatically
- 🎛️ **Admin Dashboard**: Beautiful UI showing status, commits, and sync controls
- 🔐 **Secure**: All secrets stored in WordPress database, no env vars needed
- 📡 **Webhook Support**: Receives GitHub webhooks for notifications

## 🎯 Two Sync Modes

| Mode | When Used | Capabilities |
|------|-----------|--------------|
| **⚡ API Mode** | Git not installed | Push files to GitHub via API |
| **🔧 Git Mode** | Git available | Full two-way sync (push + pull) |

The plugin automatically detects which mode to use. **API Mode works great for managed WordPress hosting** like WP Engine, Kinsta, Flywheel, etc.

## Installation

1. Download or clone this repository
2. Upload the `wp-git-sync` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

## Quick Start

### Step 1: Create a GitHub Personal Access Token (PAT)

> ⚠️ **Important**: Your PAT must have the correct permissions or the plugin won't work!

#### Option A: Classic Token (Recommended - Simpler)

1. Go to [GitHub Settings → Tokens (classic)](https://github.com/settings/tokens/new?scopes=repo&description=WP%20Git%20Sync) ← This link pre-selects the required scope
2. Give it a name like "WP Git Sync"
3. Set expiration (or "No expiration" for convenience)
4. **Required scope**: ✅ `repo` — Full control of private repositories
5. Click **"Generate token"**
6. **Copy the token immediately!** (You won't see it again)

#### Option B: Fine-grained Token (More Secure)

1. Go to [GitHub Settings → Fine-grained tokens](https://github.com/settings/personal-access-tokens/new)
2. Give it a name like "WP Git Sync"
3. Set expiration
4. **Resource owner**: Select your account
5. **Repository access**: "All repositories" or select specific repos
6. **Permissions required**:
   | Permission | Access Level | Why Needed |
   |------------|--------------|------------|
   | **Contents** | Read and Write | Push/pull commits |
   | **Metadata** | Read | Access repo info |
   | **Administration** | Read and Write | Create new repos |
7. Click **"Generate token"**
8. **Copy the token immediately!**

### Step 2: Configure the Plugin

1. In WordPress admin, go to **Git Sync → Settings**
2. Fill in:
   - **Personal Access Token**: Your GitHub PAT
   - **GitHub Username**: Your GitHub username
   - **Repository Name**: Name for your repo (e.g., `my-wordpress-site`)
   - **Author Name**: Name for commits
   - **Author Email**: Email for commits
3. Click **Save Settings**

### Step 3: Initialize Repository

1. Go to **Git Sync → Dashboard**
2. You'll see the **Setup Wizard**
3. Check the options:
   - ✅ Create GitHub repository if it doesn't exist
   - ✅ Make repository private (recommended)
4. Click **"Initialize Repository"**

That's it! The plugin will create a private GitHub repo and push your files.

### Step 4: Set Up Webhook (Optional)

To get notified when someone pushes to GitHub:

1. Go to **Git Sync → Settings**
2. Copy your **Webhook URL** and **Webhook Secret**
3. Go to your GitHub repo → **Settings** → **Webhooks** → **Add webhook**
4. Configure:
   - **Payload URL**: Your webhook URL
   - **Content type**: `application/json`
   - **Secret**: Your webhook secret
   - **Events**: Select "Just the push event"
5. Click **Add webhook**

## API Mode vs Git Mode

### ⚡ API Mode (No Git Required)

When git isn't installed, the plugin uses GitHub's REST API:

**How it works:**
1. Scans configured directories for files
2. Creates blobs for each file via GitHub API
3. Creates a tree with all files
4. Creates a commit pointing to the tree
5. Updates the branch reference

**Pros:**
- Works on any PHP hosting
- No server dependencies
- Direct API communication

**Limitations:**
- Push-only (can't pull from GitHub)
- Skips binary files (images, fonts, etc.)
- Skips files >10MB

### 🔧 Git Mode (Git Installed)

When git is available, the plugin uses native commands:

**How it works:**
- Uses standard `git add`, `commit`, `push`, `pull`
- Full two-way synchronization
- Handles binary files normally

## Configuration Reference

| Field | Description | Example |
|-------|-------------|---------|
| **Personal Access Token** | GitHub PAT with `repo` scope (see permissions below) | `ghp_xxxxxxxxxxxx` |
| **GitHub Username** | Your GitHub username | `your-username` |
| **Repository Name** | Repo name (not full URL) | `my-wordpress-site` |
| **Branch** | Branch to sync with | `main` |
| **Author Name** | Commit author name | `WordPress Server` |
| **Author Email** | Commit author email | `server@yoursite.com` |
| **Webhook Secret** | For verifying GitHub webhooks | (auto-generated) |
| **Repository Path** | Path to scan for files | `/var/www/html` |
| **Paths to Sync** | Directories to include | `wp-content/plugins/` |
| **Auto-Sync** | Sync on plugin/theme changes | Enabled/Disabled |

## Paths to Sync

By default, the plugin syncs:
```
wp-content/plugins/
wp-content/themes/
wp-content/mu-plugins/
```

You can customize this in **Settings → Paths to Sync**. One path per line, relative to your WordPress root.

## Security

### Required PAT Permissions

| Token Type | Required Permissions |
|------------|---------------------|
| **Classic** | `repo` scope |
| **Fine-grained** | Contents (RW), Metadata (R), Administration (RW) |

### Best Practices

1. **Use a dedicated PAT** - Create a token just for this plugin
2. **Set expiration** - Consider rotating tokens periodically
3. **Use HTTPS** - Always use HTTPS for your webhook URL
4. **Keep WordPress secure** - The PAT is only as safe as your WordPress
5. **Monitor token usage** - Check GitHub's token activity logs

## Troubleshooting

### "No Git? No Problem!"

If you see "API Mode" in the dashboard, that's normal! The plugin detected that git isn't installed and is using GitHub's API instead. Everything works, you just can't pull changes.

### Large Sites / WooCommerce

For sites with many files (WooCommerce stores, etc.), use **Background Sync**:

1. Click "Background Sync (Large Sites)" instead of "Quick Sync"
2. Files are processed in batches of 50
3. A progress bar shows real-time status
4. No timeout issues — takes as long as needed
5. Smart SHA comparison skips unchanged files

### Quick Sync seems slow

Quick sync is designed for small changes. For large sites, use Background Sync. Subsequent syncs are faster due to SHA comparison (unchanged files are skipped).

### Files not syncing

1. Check **Settings → Paths to Sync** includes your directories
2. Binary files (images, fonts) are skipped in API mode
3. Files over 10MB are skipped
4. Check that directories exist and are readable

### Webhook not working

1. Verify webhook URL is publicly accessible
2. Check the secret matches in both places
3. Note: In API mode, webhooks are received but can't trigger pulls

### "Failed to create repository"

1. Verify your PAT has `repo` scope
2. Check username is correct
3. Ensure repo name doesn't contain special characters

## How It Works

### API Mode Flow

```
Plugin/Theme Change Detected
         ↓
    Scan directories for files
         ↓
    Create blob for each file (GitHub API)
         ↓
    Create tree with all blobs
         ↓
    Create commit
         ↓
    Update branch reference
```

### Git Mode Flow

```
Plugin/Theme Change Detected
         ↓
    git add -A
         ↓
    git commit -m "Auto-sync"
         ↓
    git push
         ↓
    git pull (if webhook triggered)
```

## Hooks Triggering Auto-Sync

The plugin syncs automatically on:

- `upgrader_process_complete` - Plugin/theme installs & updates
- `activated_plugin` - Plugin activation
- `deactivated_plugin` - Plugin deactivation
- `switch_theme` - Theme changes
- `deleted_plugin` - Plugin deletion
- `deleted_theme` - Theme deletion

## Default .gitignore

The plugin creates a `.gitignore` optimized for WordPress:

```gitignore
# Uploads (too large for git)
wp-content/uploads/

# Cache and backups
wp-content/cache/
wp-content/backup*/
wp-content/upgrade/

# Debug log
wp-content/debug.log
error_log

# Development files
node_modules/

# OS files
.DS_Store
Thumbs.db
```

## Changelog

### 2.2.0
- ⚡ **New**: Background sync for large codebases
- ⚡ **New**: Batch processing (50 files at a time)
- ⚡ **New**: Real-time progress indicator with AJAX polling
- 🚀 **Improved**: Smart SHA comparison skips unchanged files
- 🚀 **Improved**: Much faster syncs for large sites

### 2.1.0
- 🔧 **Fixed**: Empty repository sync using Contents API
- 🔧 **Fixed**: Better error messages
- ✨ **New**: File scan preview in setup wizard
- ✨ **New**: Debug output for troubleshooting

### 2.0.0
- ✨ **New**: API Mode - works without git installed!
- ✨ **New**: Configurable paths to sync
- ✨ **New**: Skips binary files in API mode
- ✨ **New**: Mode indicator in dashboard
- 🔄 **Changed**: Auto-detects best sync method
- 📝 **Improved**: Better error messages

### 1.1.0
- Auto-create GitHub repositories via API
- One-click repository initialization wizard
- Auto-generate WordPress-optimized `.gitignore`

### 1.0.0
- Initial release

## License

GPL v2 or later - see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
