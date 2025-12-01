<?php
/**
 * Upload & Management Module
 * Handles: Dropzone uploads, ZIP creation, gallery CRUD operations
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------
   Shortcode: Gallery Manager UI
---------------------------------*/
add_shortcode('manage_galleries_upload', function() {
    if (!current_user_can('edit_pages')) return '<p>Access denied.</p>';
    
    // Enqueue scripts for this shortcode
    wp_enqueue_style('dropzone-css', WC68_GALLERY_URL . 'assets/dropzone.min.css', [], WC68_GALLERY_VERSION);
    wp_enqueue_script('dropzone-js', WC68_GALLERY_URL . 'assets/dropzone.min.js', ['jquery'], WC68_GALLERY_VERSION, true);
    wp_enqueue_script(
        'webcreate68-gallery-manager',
        WC68_GALLERY_URL . 'assets/webcreate68-gallery-manager.js',
        ['dropzone-js','jquery'],
        WC68_GALLERY_VERSION,
        true
    );
    wp_localize_script('webcreate68-gallery-manager', 'webcreate68_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('webcreate68_nonce')
    ]);

    ob_start(); ?>

<div id="webcreate68-gallery-manager">

    <div id="dz-total-progress-container" style="display:none;">
        <div id="dz-total-progress"></div>
    </div>

    <form id="upload-section">
        <div class="dz-message">Drag & Drop JPGs here or click to browse</div>
    </form>

    <div id="gallery-list"></div>

    <style>
    #upload-section {
        border: 2px dashed #999;
        border-radius: 8px;
        background: #f9f9f9;
        padding: 40px;
        text-align: center;
        font-size: 16px;
        color: #666;
        margin-bottom: 20px;
        transition: background 0.3s, border-color 0.3s;
    }
    #upload-section.dz-started { background: #f0f0f0; }
    #upload-section .dz-message { font-size: 18px; font-weight: bold; color: #333; }
    #upload-section .dz-preview { display: none !important; }
    #dz-total-progress-container {
        width: 100%;
        background: #eee;
        border-radius: 6px;
        margin-bottom: 20px;
        height: 8px;
        overflow: hidden;
    }
    #dz-total-progress {
        width: 0%;
        height: 100%;
        background: #4caf50;
        transition: width 0.2s;
    }
    .webcreate68-button { background:#dcdcdc; border:1px solid #ccc; color:#000; font-weight:bold; padding:6px 12px; border-radius:6px; cursor:pointer; margin-left:4px; }
    .webcreate68-button:hover { background:#cfcfcf; }
    .gallery-item { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
    .gallery-item input[type=text], .gallery-item input[type=password] { width:260px; }
    .gallery-header { display:flex; font-weight:bold; gap:8px; margin-bottom:10px; }
    .gallery-header div { width:260px; display:inline-block; }
    </style>
</div>

<?php
    return ob_get_clean();
});

/* -------------------------------
   AJAX Handlers
---------------------------------*/
// Upload images (SECURED: JPG-only validation)
add_action('wp_ajax_webcreate68_upload_images', 'webcreate68_upload_images');
function webcreate68_upload_images() {
    set_time_limit(60);
    check_ajax_referer('webcreate68_nonce','nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error('Access denied.');

    $gallery_name = sanitize_file_name($_POST['gallery_name'] ?? '');
    $display_name = wp_unslash(trim($_POST['display_name'] ?? $_POST['gallery_name'] ?? ''));
    if (!$gallery_name) wp_send_json_error('Missing gallery name.');

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        wp_send_json_error('No file uploaded.');
    }

    // Check file size (50MB max)
    $max_size = 50 * 1024 * 1024;
    if ($_FILES['file']['size'] > $max_size) {
        wp_send_json_error('File too large. Maximum 50MB.');
    }

    // ENFORCE JPG ONLY
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $allowed_mimes = ['jpg|jpeg|jpe' => 'image/jpeg'];
    $file_info = wp_check_filetype_and_ext($_FILES['file']['tmp_name'], $_FILES['file']['name'], $allowed_mimes);
    
    if (!$file_info['ext'] || $file_info['type'] !== 'image/jpeg') {
        wp_send_json_error('Only JPG files allowed.');
    }

    $base = wc68_galleries_base_path();
    wp_mkdir_p($base);
    $gallery_path = trailingslashit($base . $gallery_name);
    wp_mkdir_p($gallery_path);

    $filename = wp_unique_filename($gallery_path, sanitize_file_name($_FILES['file']['name']));
    $dest = $gallery_path . $filename;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        wp_send_json_error('Failed to save file.');
    }

    chmod($dest, 0644);
    wp_send_json_success(['file' => $filename]);
}

// Verify uploaded files against client list
add_action('wp_ajax_webcreate68_verify_uploads', 'webcreate68_verify_uploads');
function webcreate68_verify_uploads() {
    check_ajax_referer('webcreate68_nonce','nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error('Access denied.');

    $gallery_name = sanitize_file_name($_POST['gallery_name'] ?? '');
    $client_files = $_POST['uploaded_files'] ?? [];
    
    if (!$gallery_name) wp_send_json_error('Missing gallery name.');
    if (!is_array($client_files)) wp_send_json_error('Invalid file list.');

    $gallery_path = wc68_galleries_base_path() . $gallery_name;
    if (!is_dir($gallery_path)) wp_send_json_error('Gallery folder not found.');

    // Get actual files on server
    $server_files = glob($gallery_path . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
    $server_basenames = array_map('basename', $server_files);

    // Normalize filenames to lowercase for comparison
    $client_files_lc = array_map('strtolower', $client_files);
    $server_basenames_lc = array_map('strtolower', $server_basenames);

    // Find missing files
    $missing_files_lc = array_diff($client_files_lc, $server_basenames_lc);
    $extra_files_lc = array_diff($server_basenames_lc, $client_files_lc);

    // Map back to original client/server filenames for reporting
    $missing_files = array_values(array_intersect($client_files, $missing_files_lc));
    $extra_files = array_values(array_intersect($server_basenames, $extra_files_lc));

    wp_send_json_success([
        'client_count' => count($client_files),
        'server_count' => count($server_basenames),
        'missing_files' => $missing_files,
        'extra_files' => $extra_files,
        'verified' => count($missing_files_lc) === 0
    ]);
}

// Create ZIP + shrink images
add_action('wp_ajax_webcreate68_create_zip', 'webcreate68_create_zip');
function webcreate68_create_zip() {
    set_time_limit(600);
    @ini_set('memory_limit', '512M');
    
    check_ajax_referer('webcreate68_nonce','nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error('Access denied.');

    $gallery_name = sanitize_file_name($_POST['gallery_name'] ?? '');
    $display_name = wp_unslash(trim($_POST['display_name'] ?? $_POST['gallery_name'] ?? ''));
    if (!$gallery_name) wp_send_json_error('Missing gallery name.');

    $gallery_path = wc68_galleries_base_path() . $gallery_name;
    if (!is_dir($gallery_path)) wp_send_json_error('Gallery folder not found.');

    $uploaded_files = glob($gallery_path . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
    if (!$uploaded_files) wp_send_json_error('No images to zip.');

    // Create ZIP
    $zip_file = $gallery_path . '/' . $gallery_name . '.zip';
    $zip = new ZipArchive;
    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        foreach ($uploaded_files as $file) $zip->addFile($file, basename($file));
        $zip->close();
    } else wp_send_json_error('Failed to create ZIP.');

    // Save metadata with hashed password
    $meta_file = $gallery_path . '/.meta.json';
    $password_hash = !empty($_POST['password']) ? password_hash(sanitize_text_field($_POST['password']), PASSWORD_DEFAULT) : '';
    file_put_contents($meta_file, json_encode([
        'display_name' => $display_name,
        'password_hash' => $password_hash,
        'created' => current_time('mysql')
    ]));

    // Resize JPGs to max 3480x2160
    foreach ($uploaded_files as $file) {
        $image_info = @getimagesize($file);
        if (!$image_info) continue;
        
        list($width, $height) = $image_info;
        $max_width = 3480;
        $max_height = 2160;
        $ratio = min($max_width/$width, $max_height/$height, 1);
        if ($ratio >= 1) continue;

        $new_width = intval($width * $ratio);
        $new_height = intval($height * $ratio);

        $src = @imagecreatefromjpeg($file);
        if (!$src) continue;
        
        $dst = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($dst, $src, 0,0,0,0, $new_width, $new_height, $width, $height);
        imagejpeg($dst, $file, 100);
        imagedestroy($src);
        imagedestroy($dst);
        
        // Brief pause to prevent CPU from maxing out
        usleep(500000); // 0.5 second delay between images
    }

    wp_send_json_success();
}

// Get gallery list
add_action('wp_ajax_webcreate68_get_list', 'webcreate68_get_list');
function webcreate68_get_list() {
    check_ajax_referer('webcreate68_nonce','nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error('Access denied.');

    $gallery_dir = wc68_galleries_base_path();
    wp_mkdir_p($gallery_dir);

    $dirs = array_filter(glob($gallery_dir . '*'), 'is_dir');
    $list = [];
    foreach ($dirs as $dir) {
        $name = basename($dir);
        $meta_file = $dir . '/.meta.json';
        $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : ['display_name'=>$name,'password_hash'=>''];
        
        // Count JPG files
        $jpgs = glob($dir . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
        $jpg_count = $jpgs ? count($jpgs) : 0;

        // Get ZIP size
        $zip_file = $dir . '/' . $name . '.zip';
        $zip_size = (file_exists($zip_file)) ? filesize($zip_file) : 0;

        $list[] = [
            'folder' => $name,
            'display_name' => $meta['display_name'] ?? $name,
            'has_password' => !empty($meta['password_hash']),
            'jpg_count' => $jpg_count,
            'zip_size' => $zip_size
        ];
    }
    wp_send_json_success($list);
}

// Save meta
add_action('wp_ajax_webcreate68_save_meta', 'webcreate68_save_meta');
function webcreate68_save_meta() {
    check_ajax_referer('webcreate68_nonce','nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error('Access denied.');

    $folder = sanitize_file_name($_POST['folder'] ?? '');
    $display_name = wp_unslash(trim($_POST['display_name'] ?? ''));
    $password = sanitize_text_field($_POST['password'] ?? '');
    if (!$folder) wp_send_json_error('Folder missing.');

    $meta_file = wc68_galleries_base_path() . $folder . '/.meta.json';
    $password_hash = $password ? password_hash($password, PASSWORD_DEFAULT) : '';
    file_put_contents($meta_file, json_encode([
        'display_name'=>$display_name,
        'password_hash'=>$password_hash
    ]));

    wp_send_json_success();
}

// Delete gallery
add_action('wp_ajax_webcreate68_delete_gallery', 'webcreate68_delete_gallery');
function webcreate68_delete_gallery() {
    check_ajax_referer('webcreate68_nonce','nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error('Access denied.');

    $folder = sanitize_file_name($_POST['folder'] ?? '');
    if (!$folder) wp_send_json_error('Folder missing.');

    // Get list of files before deleting to clean up cache
    $dir = wc68_galleries_base_path() . $folder;
    $files_to_cache_clean = [];
    if (is_dir($dir)) {
        $files_to_cache_clean = glob($dir . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
    }

    // Delete gallery folder
    if (is_dir($dir)) {
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        rmdir($dir);
    }

    // Delete cached thumbnails for this gallery
    $cache_dir = wc68_galleries_cache_path();
    if (is_dir($cache_dir)) {
        foreach ($files_to_cache_clean as $original_file) {
            $cache_filename = md5($folder . '/' . basename($original_file)) . '_thumb_200.jpg';
            $cache_path = $cache_dir . $cache_filename;
            if (file_exists($cache_path)) {
                @unlink($cache_path);
            }
        }
    }

    wp_send_json_success();
}
