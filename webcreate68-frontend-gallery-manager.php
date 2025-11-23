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
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/front-end-managed-galleries/';
}

function wc68_galleries_base_url() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['baseurl'] . '/front-end-managed-galleries/';
}

function wc68_galleries_cache_path() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/front-end-gallery-cache/';
}

/* -------------------------------
   Load Plugin Modules
---------------------------------*/
require_once WC68_GALLERY_PATH . 'includes/gallery-upload-management.php';
require_once WC68_GALLERY_PATH . 'includes/gallery-display.php';
