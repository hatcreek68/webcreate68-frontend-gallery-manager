jQuery(document).ready(function($){

    Dropzone.autoDiscover = false;

    let galleryName = '';
    let galleryDisplayName = '';
    let galleryPassword = '';

    const dz = new Dropzone("#upload-section", {
        url: webcreate68_ajax.ajax_url,
        paramName: 'file',
        uploadMultiple: false,
        maxFilesize: 50,
        parallelUploads: 5,
        acceptedFiles: 'image/jpeg,image/jpg', // CLIENT-SIDE JPG ONLY
        clickable: ".dz-message",
        headers: { 'X-WP-Nonce': webcreate68_ajax.nonce },
        previewsContainer: false,
        init: function() {
            const myDropzone = this;
            const progressContainer = document.getElementById("dz-total-progress-container");
            const progressBar = document.getElementById("dz-total-progress");

            progressContainer.style.display = "none";

            this.on("uploadprogress", function() {
                let totalBytesSent = 0;
                let totalBytes = 0;

                myDropzone.files.forEach(file => {
                    totalBytesSent += file.upload.bytesSent;
                    totalBytes += file.size;
                });

                const progress = (totalBytesSent / totalBytes) * 100;
                progressBar.style.width = progress + "%";
            });

            this.on("addedfile", function(file) {
                if(!galleryName){
                    galleryDisplayName = prompt('Enter gallery display name:');
                    if(!galleryDisplayName){ myDropzone.removeFile(file); return; }
                    galleryName = galleryDisplayName.replace(/[^a-zA-Z0-9\s-]/g, '').replace(/\s+/g, '-');
                    galleryPassword = prompt('Enter gallery password (optional):','') || '';
                }
            });

            this.on("sending", function(file, xhr, formData){
                progressContainer.style.display = "block";
                formData.append('action','webcreate68_upload_images');
                formData.append('gallery_name', galleryName);
                formData.append('display_name', galleryDisplayName);
                formData.append('password', galleryPassword);
                formData.append('nonce', webcreate68_ajax.nonce);
            });

            this.on("queuecomplete", function(){
                progressContainer.style.display = "none";
                progressBar.style.width = "0%";

                $.post(webcreate68_ajax.ajax_url, {
                    action:'webcreate68_create_zip',
                    nonce:webcreate68_ajax.nonce,
                    gallery_name: galleryName,
                    display_name: galleryDisplayName,
                    password: galleryPassword
                }, function(res){
                    if(res.success){
                        alert('Upload complete!');
                        galleryName = '';
                        galleryDisplayName = '';
                        galleryPassword = '';
                        myDropzone.removeAllFiles();
                        loadGalleries();
                    } else alert('Error creating ZIP: '+res.data);
                });
            });

            this.on("error", function(file, error){
                alert('Upload error: '+error);
            });
        }
    });

    function loadGalleries(){
        $.post(webcreate68_ajax.ajax_url, { action:'webcreate68_get_list', nonce:webcreate68_ajax.nonce }, function(res){
            console.log('Gallery list response:', res);
            if(res.success){
                console.log('Found ' + res.data.length + ' galleries');
                if(res.data.length === 0) {
                    $('#gallery-list').html('<p>No galleries found. Upload your first gallery above!</p>');
                    return;
                }
                let html = '<div class="gallery-header"><div>Name</div><div>Password</div><div>Actions</div></div>';
                res.data.forEach(g=>{
                    // XSS FIX: escape HTML in display_name and folder
                    const safeName = $('<div>').text(g.display_name).html();
                    const safeFolder = $('<div>').text(g.folder).html();
                    html += `<div class="gallery-item">
                        <input type="text" class="edit-display" data-folder="${safeFolder}" value="${safeName}">
                        <input type="password" class="edit-password" data-folder="${safeFolder}" placeholder="Password">
                        <button class="webcreate68-button save-meta" data-folder="${safeFolder}">Save</button>
                        <button class="webcreate68-button delete-gallery" data-folder="${safeFolder}">Delete</button>
                    </div>`;
                });
                $('#gallery-list').html(html);
            } else {
                console.error('Error loading galleries:', res);
                $('#gallery-list').html('<p>Error loading galleries: ' + (res.data || 'Unknown error') + '</p>');
            }
        }).fail(function(xhr, status, error){
            console.error('AJAX failed:', status, error);
            $('#gallery-list').html('<p>AJAX Error: ' + error + '</p>');
        });
    }

    loadGalleries();

    $('#gallery-list').on('click','.save-meta', function(){
        const folder = $(this).data('folder');
        const display_name = $(this).siblings('.edit-display').val();
        const password = $(this).siblings('.edit-password').val();

        $.post(webcreate68_ajax.ajax_url, {
            action:'webcreate68_save_meta',
            nonce:webcreate68_ajax.nonce,
            folder,
            display_name,
            password
        }, function(res){
            if(res.success) alert('Saved!');
            else alert('Error: '+res.data);
            loadGalleries();
        });
    });

    $('#gallery-list').on('click','.delete-gallery', function(){
        if(!confirm('Delete this gallery? This cannot be undone.')) return;
        const folder = $(this).data('folder');

        $.post(webcreate68_ajax.ajax_url, {
            action:'webcreate68_delete_gallery',
            nonce:webcreate68_ajax.nonce,
            folder
        }, function(res){
            if(res.success) loadGalleries();
            else alert('Error deleting gallery.');
        });
    });
});