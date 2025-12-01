<?php
/**
 * Gallery Display Module
 * Handles: Public gallery display, lightbox modal, password protection, ZIP downloads
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------
    Session Initialization
---------------------------------*/
add_action('init', 'wc68_start_session', 1);
function wc68_start_session() {
    if(!session_id() && !headers_sent()) {
        session_start();
    }
}

/* -------------------------------
    Shortcode: Client Galleries
---------------------------------*/
add_shortcode('client_galleries', function() {
    $gallery_dir = wc68_galleries_base_path();
    $dirs = array_filter(glob($gallery_dir . '*'), 'is_dir');
    ob_start();
    if (empty($dirs)) { echo "<p>No galleries yet.</p>"; return ob_get_clean(); }

    $placeholder_src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    echo '<div class="client-galleries">';
    foreach ($dirs as $dir) {
        $name = basename($dir);
        $meta_file = $dir . '/.meta.json';
        $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : ['display_name'=>$name,'password_hash'=>''];

        $imgs = glob("$dir/*.{jpg,jpeg,png,webp,gif}", GLOB_BRACE);
        sort($imgs, SORT_NATURAL);
        if (count($imgs) === 0) continue;

        $thumb_url = admin_url('admin-ajax.php') . '?action=wc68_get_thumbnail&gallery=' . urlencode($name) . '&file=' . urlencode(basename($imgs[0]));

        echo "<div class='gallery-wrapper'>";
        echo '<div class="gallery-title">' . esc_html($meta['display_name']) . '</div>';
        echo "<img src='" . esc_url($placeholder_src) . "' data-src='" . esc_url($thumb_url) . "' class='gallery-thumb' loading='lazy' data-gallery='" . esc_attr($name) . "' data-images='" . esc_attr(json_encode(array_map('basename',$imgs))) . "' style='width:200px;height:200px;object-fit:cover;cursor:pointer;margin:5px;'>";
        echo '<p style="text-align:center; margin-top: 10px;">';
        echo '<a href="#" class="wc68-zip-button" data-gallery="' . esc_attr($name) . '" style="display:inline-block;background:#555;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:bold;text-align:center;width:auto;">Download Originals (.zip)</a>';
        echo '</p>';
        echo "</div>";
    }
    echo '</div>';

    ?>
    <script>
    var wc68_ajax_url = '<?php echo admin_url("admin-ajax.php"); ?>';
    var wc68_nonce = '<?php echo wp_create_nonce("webcreate68_nonce"); ?>';

    // Lazy load: load 1 image at a time by display order, until all loaded
    document.addEventListener('DOMContentLoaded', function() {
        const galleryThumbs = Array.from(document.querySelectorAll('.client-galleries img[data-src]'));
        if ('IntersectionObserver' in window) {
            let loadedCount = 0;
            const batchSize = 1;

            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            loadedCount++;
                        }
                        observer.unobserve(img);
                    }
                });

                // After each batch, observe the next batch in display order
                let nextToObserve = [];
                for (let i = loadedCount; i < loadedCount + batchSize && i < galleryThumbs.length; i++) {
                    const img = galleryThumbs[i];
                    if (img && img.dataset.src) nextToObserve.push(img);
                }
                nextToObserve.forEach(img => observer.observe(img));
            }, { rootMargin: "100px" });

            // Start by observing the first batch
            for (let i = 0; i < Math.min(batchSize, galleryThumbs.length); i++) {
                observer.observe(galleryThumbs[i]);
            }
        } else {
            // Fallback: load all at once
            galleryThumbs.forEach(img => {
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
            });
        }
    });
    </script>
    <div id="gallery-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);justify-content:center;align-items:center;z-index:9999;overflow:auto;">
        <div id="gallery-modal-content" style="position:relative;max-width:90%;margin:auto;">
            <button id="gallery-close" style="position:absolute;top:10px;right:10px;font-size:24px;color:#fff;background:none;border:none;cursor:pointer;">&times;</button>
            <div id="gallery-password-form" style="text-align:center;margin-top:50px;">
                <p style="color:#fff;">Enter password to view gallery:</p>
                <input type="password" id="gallery-pass-input" style="padding:6px 12px;font-size:16px;">
                <button id="gallery-pass-submit" class="webcreate68-button">Submit</button>
            </div>
            <div id="gallery-images" style="display:none;text-align:center;">
                <button id="gallery-prev" class="webcreate68-button" style="position:absolute;top:50%;left:5px;">&#10094;</button>
                <div style="display:flex;flex-direction:column;align-items:center;">
                    <img id="gallery-current-image" src="" style="max-width:100%;max-height:60vh;margin:10px 0;">
                    <div style="position:relative; width:90%; max-width:800px; display:flex; align-items:center;">
                        <button id="thumb-prev" class="webcreate68-button" style="position:absolute; left:-35px; z-index:2;">&#10094;</button>
                        <div id="gallery-thumbnails" style="display:flex; overflow-x:auto; gap:5px; padding:5px 0; scroll-behavior:smooth;"></div>
                        <button id="thumb-next" class="webcreate68-button" style="position:absolute; right:-35px; z-index:2;">&#10095;</button>
                    </div>
                </div>
                <button id="gallery-next" class="webcreate68-button" style="position:absolute;top:50%;right:5px;">&#10095;</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        let modal = document.getElementById('gallery-modal');
        let content = document.getElementById('gallery-images');
        let passForm = document.getElementById('gallery-password-form');
        let currentImg = document.getElementById('gallery-current-image');
        let imgs = [], idx = 0;
        let currentGallery = '';
        const placeholderSrc = '<?php echo $placeholder_src; ?>';

        function showGallery(){
            // Only load the current image
            currentImg.src = wc68_ajax_url + '?action=wc68_get_image&gallery=' + encodeURIComponent(currentGallery) + '&file=' + encodeURIComponent(imgs[idx]);
            let thumbsContainer = document.getElementById('gallery-thumbnails');
            let thumbsInitialized = false;
            let lastGallery = '';

            function renderThumbnails() {
                thumbsContainer.innerHTML = '';
                const fragment = document.createDocumentFragment();
                imgs.forEach((file,i)=>{
                    let thumb = document.createElement('img');
                    thumb.src = placeholderSrc;
                    thumb.dataset.src = wc68_ajax_url + '?action=wc68_get_image&gallery=' + encodeURIComponent(currentGallery) + '&file=' + encodeURIComponent(file);
                    thumb.style.width='80px'; thumb.style.height='80px'; thumb.style.objectFit='cover';
                    thumb.style.cursor='pointer';
                    thumb.style.border = i===idx ? '2px solid #fff' : '1px solid #555';
                    thumb.dataset.index = i;
                    thumb.addEventListener('click',()=>{ 
                        idx=i; 
                        showGallery(); 
                    });
                    fragment.appendChild(thumb);
                });
                thumbsContainer.appendChild(fragment);

                // Load all thumbnails in order, not just visible ones
                const thumbImgs = Array.from(thumbsContainer.querySelectorAll('img[data-src]'));
                let thumbIndex = 0;
                function loadNextThumb() {
                    if (thumbIndex >= thumbImgs.length) return;
                    const img = thumbImgs[thumbIndex];
                    if (img && img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    thumbIndex++;
                    setTimeout(loadNextThumb, 80);
                }
                loadNextThumb();
                thumbsInitialized = true;
            }

            function updateActiveThumb() {
                const thumbImgs = thumbsContainer.querySelectorAll('img');
                thumbImgs.forEach((img, i) => {
                    img.style.border = (i === idx) ? '2px solid #fff' : '1px solid #555';
                });
                const activeThumb = thumbsContainer.querySelector(`img[data-index="${idx}"]`);
                if (activeThumb) {
                    setTimeout(() => {
                        const containerWidth = thumbsContainer.offsetWidth;
                        const thumbWidth = activeThumb.offsetWidth;
                        const scrollLeft = activeThumb.offsetLeft - (containerWidth / 2) + (thumbWidth / 2);
                        thumbsContainer.scrollTo({ left: scrollLeft, behavior: 'smooth' });
                    }, 1);
                }
            }

            // Only re-render thumbnails if gallery changed or not initialized
            if (!thumbsInitialized || lastGallery !== currentGallery) {
                renderThumbnails();
                lastGallery = currentGallery;
            }
            updateActiveThumb();

            // Disable prev/next buttons at ends
            document.getElementById('gallery-prev').disabled = (idx === 0);
            document.getElementById('gallery-next').disabled = (idx === imgs.length - 1);
        }

        document.querySelectorAll('.gallery-thumb').forEach(thumb=>{
            thumb.addEventListener('click',function(){
                imgs = JSON.parse(this.dataset.images);
                currentGallery = this.dataset.gallery;
                idx = 0;
                modal.style.display='flex';
                passForm.style.display='block';
                content.style.display='none';
            });
        });

        document.getElementById('gallery-pass-submit').addEventListener('click',function(){
            let val = document.getElementById('gallery-pass-input').value;
            jQuery.post(wc68_ajax_url, {action:'wc68_check_password', folder:currentGallery, password:val, nonce:wc68_nonce}, function(res){
                if(res.success){
                    passForm.style.display='none';
                    content.style.display='block';
                    showGallery();
                } else { alert('Incorrect password'); }
            });
        });

        document.getElementById('gallery-close').addEventListener('click', function(){
            modal.style.display='none';
            document.getElementById('gallery-pass-input').value='';
        });

        document.getElementById('gallery-prev').addEventListener('click',function(){ 
            if(idx > 0) { idx--; showGallery(); }
        });
        document.getElementById('gallery-next').addEventListener('click',function(){ 
            if(idx < imgs.length - 1) { idx++; showGallery(); }
        });
        
        document.getElementById('thumb-prev').addEventListener('click',()=>{ document.getElementById('gallery-thumbnails').scrollBy({left:-100,behavior:'smooth'}); });
        document.getElementById('thumb-next').addEventListener('click',()=>{ document.getElementById('gallery-thumbnails').scrollBy({left:100,behavior:'smooth'}); });

        document.querySelectorAll('.wc68-zip-button').forEach(btn=>{
            btn.addEventListener('click',function(e){
                e.preventDefault();
                let gallery=this.dataset.gallery;
                let pass=prompt("Enter password to download ZIP:");
                if(!pass) return;
                jQuery.post(wc68_ajax_url,{action:'wc68_check_password',folder:gallery,password:pass,nonce:wc68_nonce},function(res){
                    if(res.success){
                        window.location = wc68_ajax_url+'?action=wc68_get_zip&gallery='+encodeURIComponent(gallery);
                    } else { alert('Incorrect password'); }
                });
            });
        });

        document.addEventListener('keydown', function(e){
            if(modal.style.display !== 'flex') return;
            if(passForm.style.display === 'block') {
                 if (e.key === 'Escape') {
                    modal.style.display = 'none';
                    document.getElementById('gallery-pass-input').value='';
                 }
                 return;
            }
            
            switch(e.key){
                case 'ArrowLeft':
                    e.preventDefault();
                    if(idx > 0) {
                        idx--;
                        showGallery();
                    }
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    if(idx < imgs.length - 1) {
                        idx++;
                        showGallery();
                    }
                    break;
                case 'Escape':
                    modal.style.display = 'none';
                    document.getElementById('gallery-pass-input').value='';
                    break;
            }
        });
    });
    </script>
<?php
return ob_get_clean();
});

/* -------------------------------
    Serve PUBLIC thumbnails (Cached)
---------------------------------*/
add_action('wp_ajax_wc68_get_thumbnail','wc68_get_thumbnail');
add_action('wp_ajax_nopriv_wc68_get_thumbnail','wc68_get_thumbnail');
function wc68_get_thumbnail(){
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }
    if ( ! function_exists( 'wp_get_image_editor' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
    }

    $gallery = sanitize_text_field($_GET['gallery'] ?? '');
    $gallery = basename($gallery);
    $file = sanitize_text_field($_GET['file'] ?? '');
    $file = basename($file);
    
    $original_path = wc68_galleries_base_path() . $gallery . '/' . $file;
    if (!file_exists($original_path)) wp_die('Original file not found', 'Not Found', ['response' => 404]);
    
    $cache_dir = wc68_galleries_cache_path();
    $cache_file_name = md5($gallery . '/' . $file) . '_thumb_200.jpg';
    $cache_path = $cache_dir . $cache_file_name;
    
    if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir); 
    }

    if (!file_exists($cache_path)) {
        $editor = wp_get_image_editor($original_path);
        
        if (!is_wp_error($editor)) {
            $editor->resize(200, 200, true);
            $editor->set_quality(80);
            $editor->save($cache_path);
            $path_to_serve = $cache_path;
        } else {
            $path_to_serve = $original_path;
        }
    } else {
        $path_to_serve = $cache_path;
    }
    
    $mime = wp_check_filetype($path_to_serve)['type'] ?? 'image/jpeg';
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path_to_serve));
    readfile($path_to_serve);
    exit;
}

/* -------------------------------
    Password Check
---------------------------------*/
add_action('wp_ajax_wc68_check_password','wc68_check_password');
add_action('wp_ajax_nopriv_wc68_check_password','wc68_check_password');
function wc68_check_password(){
    check_ajax_referer('webcreate68_nonce','nonce');
    $folder = sanitize_text_field($_POST['folder'] ?? '');
    $folder = basename($folder);
    $password = sanitize_text_field($_POST['password'] ?? '');
    $meta_file = wc68_galleries_base_path() . $folder . '/.meta.json';
    if(!file_exists($meta_file)) wp_send_json_error('Gallery not found');
    $meta = json_decode(file_get_contents($meta_file),true);
    if(!isset($meta['password_hash']) || !password_verify($password,$meta['password_hash'])){
        wp_send_json_error('Incorrect password');
    }
    if (!isset($_SESSION['wc68_verified'])) {
        $_SESSION['wc68_verified'] = [];
    }
    $_SESSION['wc68_verified'][$folder]=true;
    wp_send_json_success();
}

/* -------------------------------
    Serve protected images
---------------------------------*/
add_action('wp_ajax_wc68_get_image','wc68_get_image');
add_action('wp_ajax_nopriv_wc68_get_image','wc68_get_image');
function wc68_get_image(){
    $gallery = sanitize_text_field($_GET['gallery'] ?? '');
    $gallery = basename($gallery);
    $file = sanitize_text_field($_GET['file'] ?? '');
    $file = basename($file);
    
    if(!isset($_SESSION['wc68_verified'][$gallery])) wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
    
    $path = wc68_galleries_base_path() . $gallery . '/' . $file;
    if(!file_exists($path)) wp_die('Not found', 'Not Found', ['response' => 404]);

    // Prevent Cloudflare and browser caching of protected images
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $mime = wp_check_filetype($path)['type'] ?? 'application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    readfile($path);
    exit;
}

/* -------------------------------
    Serve ZIP with chunked download
---------------------------------*/
add_action('wp_ajax_wc68_get_zip','wc68_get_zip');
add_action('wp_ajax_nopriv_wc68_get_zip','wc68_get_zip');
function wc68_get_zip(){
    $gallery = sanitize_text_field($_GET['gallery'] ?? '');
    $gallery = basename($gallery);
    
    if(!isset($_SESSION['wc68_verified'][$gallery])) wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
    
    $zip_path = wc68_galleries_base_path() . $gallery . '/' . $gallery . '.zip';
    if(!file_exists($zip_path)) wp_die('ZIP not found', 'Not Found', ['response' => 404]);

    @set_time_limit(0);
    @ini_set('memory_limit','4G');
    
    while (ob_get_level()) {
        @ob_end_clean();
    }
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.basename($zip_path).'"');
    header('Content-Length: '.filesize($zip_path));
    $chunk_size = 1024*1024;
    $handle = fopen($zip_path,'rb');
    while(!feof($handle)){
        echo fread($handle,$chunk_size);
        flush();
    }
    fclose($handle);
    exit;
}

/* -------------------------------
    Styles
---------------------------------*/
add_action('wp_head', function(){
    echo '<style>
    .webcreate68-button,a.webcreate68-button,button.webcreate68-button{background:#555;border:1px solid #444;color:#ccc;font-weight:bold;padding:6px 12px;border-radius:6px;cursor:pointer;text-decoration:none;display:inline-block;transition:background-color .2s;}
    .webcreate68-button:hover,a.webcreate68-button:hover,button.webcreate68-button:hover{background:#333;color:#ccc;}
    .client-galleries{display:flex;flex-direction:column;gap:60px;align-items:center;}
    .gallery-wrapper{display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;cursor:pointer;text-align:center;}
    .gallery-wrapper img.gallery-thumb {width: 200px;height: 200px;object-fit: cover;cursor: pointer;margin-top: 10px;transition: opacity 0.3s ease;}
    .gallery-wrapper .gallery-title {font-size: 18px;font-weight: bold;margin: 5px 0;}
    .gallery-wrapper::after {content: "Click to Open";position: absolute;top: 50%;left: 50%;transform: translate(-50%, -50%);font-size: 12px;color: #fff;background: rgba(0, 0, 0, 0.6);padding: 8px;border-radius: 6px;display: flex;align-items: center;justify-content: center;gap: 8px;font-weight: bold;opacity: 0.8;pointer-events: none;}
    .gallery-wrapper img.gallery-thumb:hover {opacity: 0.7;}
    .gallery-wrapper .wc68-zip-button {margin-top: 10px;background: #555;color: #e0e0e0 !important;padding: 10px 20px;border-radius: 6px;text-decoration: none;font-weight: bold;text-align: center;cursor: pointer;transition: background-color 0.2s ease;border: none;}
    .wc68-zip-button:hover {background: #333 !important;}
    #gallery-thumbnails::-webkit-scrollbar {height: 8px;}
    #gallery-thumbnails::-webkit-scrollbar-thumb {background: #444;border-radius: 4px;}
    #gallery-thumbnails::-webkit-scrollbar-track {background: #222;}
    #gallery-close {position: absolute;top: 10px;right: 30px;font-size: 28px;color: #fff;background: rgba(0,0,0,0.4);border: none;cursor: pointer;z-index: 1001;padding: 4px 8px;border-radius: 4px;}
    </style>';
});
