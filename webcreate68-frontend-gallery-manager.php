<?php
/**
 * Plugin Name: webcreate68 Frontend Gallery Manager
 * Description: Complete gallery system - upload, manage, display with lightbox & password protection
 * Version: 3.0
 * Author: P. Pace, Gemini, Claude
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('WC68_GALLERY_VERSION', '3.0');
define('WC68_GALLERY_PATH', plugin_dir_path(__FILE__));
define('WC68_GALLERY_URL', plugin_dir_url(__FILE__));

/* -------------------------------
   Shared Helper Functions
---------------------------------*/
function wc68_galleries_base_path() {
    // Store OUTSIDE web root for security - no web server config needed
    // Goes up from wp-content to site root, then up again to escape htdocs
    $wp_content = untrailingslashit(WP_CONTENT_DIR); // e.g., /home/user/htdocs/site.com/wp-content
    $site_root = dirname($wp_content); // /home/user/htdocs/site.com
    $htdocs = dirname($site_root); // /home/user/htdocs
    $user_home = dirname($htdocs); // /home/user
    return $user_home . '/front-end-managed-galleries/';
}

function wc68_galleries_base_url() {
    // Protected galleries have NO direct URL - served only through PHP
    return null;
}

function wc68_galleries_cache_path() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/front-end-gallery-public-thumbnails/';
}

/* -------------------------------
   Load Plugin Modules
---------------------------------*/
require_once WC68_GALLERY_PATH . 'includes/gallery-upload-management.php';
require_once WC68_GALLERY_PATH . 'includes/gallery-display.php';
