jQuery(document).ready(function($){

    Dropzone.autoDiscover = false;

    let galleryName = '';
    let galleryDisplayName = '';
    let galleryPassword = '';
    let uploadedFiles = new Set(); // Track successfully uploaded files
    let totalFiles = 0;
    let failedFiles = [];
    let verificationAttempts = 0;
    const MAX_VERIFICATION_ATTEMPTS = 15;

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
        retryChunks: true,
        retryChunksLimit: 5,
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
                totalFiles++;
                if(!galleryName){
                    galleryDisplayName = prompt('Enter gallery display name:');
                    if(!galleryDisplayName){ 
                        myDropzone.removeFile(file); 
                        totalFiles--;
                        return; 
                    }
                    galleryName = galleryDisplayName.replace(/[^a-zA-Z0-9\s-]/g, '').replace(/\s+/g, '-');
                    galleryPassword = prompt('Enter gallery password (optional):','') || '';
                    uploadedFiles.clear();
                    failedFiles = [];
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

                // Check if all files uploaded successfully
                console.log('Upload complete. Total files:', totalFiles, 'Uploaded:', uploadedFiles.size, 'Failed:', failedFiles.length);
                
                if (failedFiles.length > 0) {
                    const retry = confirm(`${failedFiles.length} files failed to upload. Retry failed files?`);
                    if (retry) {
                        console.log('Retrying failed files:', failedFiles);
                        failedFiles.forEach(file => {
                            myDropzone.enqueueFile(file);
                        });
                        failedFiles = [];
                        return;
                    }
                }

                if (uploadedFiles.size === 0) {
                    alert('No files were uploaded successfully. Please try again.');
                    galleryName = '';
                    galleryDisplayName = '';
                    galleryPassword = '';
                    totalFiles = 0;
                    return;
                }

                // Verify count matches before proceeding
                if (uploadedFiles.size < totalFiles) {
                    const proceed = confirm(`Only ${uploadedFiles.size} of ${totalFiles} files uploaded. Proceed anyway?`);
                    if (!proceed) {
                        galleryName = '';
                        galleryDisplayName = '';
                        galleryPassword = '';
                        totalFiles = 0;
                        return;
                    }
                }

                // SERVER-SIDE VERIFICATION: Check which files actually made it to server
                console.log('Verifying uploads with server (attempt ' + (verificationAttempts + 1) + '/' + MAX_VERIFICATION_ATTEMPTS + ')...');
                verificationAttempts++;
                
                $.post(webcreate68_ajax.ajax_url, {
                    action: 'webcreate68_verify_uploads',
                    nonce: webcreate68_ajax.nonce,
                    gallery_name: galleryName,
                    uploaded_files: Array.from(uploadedFiles)
                }, function(verifyRes){
                    if (!verifyRes.success) {
                        alert('Upload verification failed: ' + verifyRes.data);
                        resetUploadState();
                        return;
                    }

                    const verification = verifyRes.data;
                    console.log('Verification result:', verification);

                    // Check if any files are missing on server
                    if (verification.missing_files.length > 0) {
                        console.warn(`Missing ${verification.missing_files.length} files:`, verification.missing_files);
                        
                        // Check if we've hit max retry attempts
                        if (verificationAttempts >= MAX_VERIFICATION_ATTEMPTS) {
                            alert(`Upload verification failed after ${MAX_VERIFICATION_ATTEMPTS} attempts!\n\n` +
                                  `Client uploaded: ${verification.client_count} files\n` +
                                  `Server received: ${verification.server_count} files\n` +
                                  `Still missing: ${verification.missing_files.length} files\n` +
                                  `Missing files: ${verification.missing_files.join(', ')}\n\n` +
                                  `Please try uploading these files manually.`);
                            resetUploadState();
                            return;
                        }
                        
                        // AUTO-RETRY: Find the original Dropzone file objects for missing files
                        const filesToRetry = myDropzone.files.filter(file => 
                            verification.missing_files.includes(file.name)
                        );
                        
                        if (filesToRetry.length === 0) {
                            alert(`Cannot find files to retry. Missing files: ${verification.missing_files.join(', ')}`);
                            resetUploadState();
                            return;
                        }
                        
                        console.log(`Auto-retrying ${filesToRetry.length} missing files...`);
                        alert(`Server is missing ${filesToRetry.length} files. Auto-retrying now...\n\n` +
                              `Files: ${verification.missing_files.slice(0, 5).join(', ')}` +
                              (verification.missing_files.length > 5 ? `\n...and ${verification.missing_files.length - 5} more` : ''));
                        
                        // Re-queue missing files
                        filesToRetry.forEach(file => {
                            file.status = Dropzone.QUEUED;
                            myDropzone.enqueueFile(file);
                        });
                        
                        return; // Exit, will verify again after retry completes
                    }

                    // All files verified - proceed with ZIP creation
                    console.log('All files verified on server. Creating ZIP...');
                    verificationAttempts = 0; // Reset for next upload
                    createZipAndFinish();
                });
            });

            function resetUploadState() {
                galleryName = '';
                galleryDisplayName = '';
                galleryPassword = '';
                totalFiles = 0;
                uploadedFiles.clear();
                failedFiles = [];
                verificationAttempts = 0;
            }

            function createZipAndFinish() {
                $.post(webcreate68_ajax.ajax_url, {
                    action:'webcreate68_create_zip',
                    nonce:webcreate68_ajax.nonce,
                    gallery_name: galleryName,
                    display_name: galleryDisplayName,
                    password: galleryPassword
                }, function(res){
                    if(res.success){
                        alert(`Upload complete! ${uploadedFiles.size} files uploaded successfully.`);
                        resetUploadState();
                        myDropzone.removeAllFiles();
                        // Refresh page to show new album
                        location.reload(); // <-- Added: refresh page after upload
                        // Alternatively, you can just call loadGalleries(); if you prefer not to reload
                        // loadGalleries();
                    } else alert('Error creating ZIP: '+res.data);
                });
            }

            this.on("error", function(file, error){
                console.error('Upload error for file:', file.name, error);
                failedFiles.push(file);
                
                // Automatic retry up to 5 times
                if (!file.retryCount) file.retryCount = 0;
                if (file.retryCount < 5) {
                    file.retryCount++;
                    console.log(`Retrying ${file.name} (attempt ${file.retryCount}/5)`);
                    setTimeout(() => {
                        myDropzone.enqueueFile(file);
                    }, 1000 * file.retryCount); // Exponential backoff
                } else {
                    alert(`Failed to upload ${file.name} after 5 attempts: ${error}`);
                }
            });

            this.on("success", function(file, response){
                if (response.success) {
                    uploadedFiles.add(file.name);
                    // Remove from failed list if it was there
                    failedFiles = failedFiles.filter(f => f.name !== file.name);
                    console.log('Successfully uploaded:', file.name, `(${uploadedFiles.size}/${totalFiles})`);
                }
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
                let html = '<div class="gallery-header"><div>Name</div><div>Password</div><div>Actions</div><div>Info</div></div>';
                res.data.forEach(g=>{
                    // XSS FIX: escape HTML in display_name and folder
                    const safeName = $('<div>').text(g.display_name).html();
                    const safeFolder = $('<div>').text(g.folder).html();
                    // Format ZIP size
                    let zipSizeStr = g.zip_size
                        ? (g.zip_size > 1024*1024
                            ? (g.zip_size/1024/1024).toFixed(2)+' MB'
                            : (g.zip_size/1024).toFixed(1)+' KB')
                        : null;
                    let infoStr = `${g.jpg_count} JPG${g.jpg_count!==1?'s':''}; `;
                    infoStr += zipSizeStr ? `.zip file size: ${zipSizeStr}` : 'No ZIP';
                    html += `<div class="gallery-item">
                        <input type="text" class="edit-display" data-folder="${safeFolder}" value="${safeName}">
                        <input type="password" class="edit-password" data-folder="${safeFolder}" placeholder="Password">
                        <button class="webcreate68-button save-meta" data-folder="${safeFolder}">Save</button>
                        <button class="webcreate68-button delete-gallery" data-folder="${safeFolder}">Delete</button>
                        <span style="margin-left:12px; font-size:13px; color:#555;">
                            ${infoStr}
                        </span>
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