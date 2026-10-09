document.addEventListener('DOMContentLoaded', function () {

    // Configuration from PHP
    if (typeof ProcessWire === 'undefined') return;
    const config = ProcessWire.config.InputfieldFileVideoPoster;
    if (!config) return;

    // Helper to generate thumbnail
    const generateThumbnail = (fileOrUrl) => {
        return new Promise((resolve, reject) => {
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.muted = true;
            video.playsInline = true;
            video.crossOrigin = "anonymous"; // Needed if video is served from different origin/domain

            let url;
            let isBlob = false;

            if (fileOrUrl instanceof File) {
                url = URL.createObjectURL(fileOrUrl);
                isBlob = true;
            } else {
                url = fileOrUrl;
            }

            video.src = url;

            video.onloadedmetadata = () => {
                // Seek to 1 second or 10% of duration if short
                let seekTime = 1.0;
                if (video.duration < 2) {
                    seekTime = video.duration / 2;
                }
                video.currentTime = seekTime;
            };

            video.onseeked = () => {
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;

                let dataUrl;
                try {
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    dataUrl = canvas.toDataURL('image/webp', 0.8);
                } catch (err) {
                    reject(err);
                    return;
                }

                // Cleanup with a slight delay to prevent ERR_FILE_NOT_FOUND
                if (isBlob) {
                    setTimeout(() => {
                        URL.revokeObjectURL(url);
                    }, 100);
                }

                resolve(dataUrl);
            };

            video.onerror = (e) => {
                reject(e);
            };
        });
    };

    const uploadThumbnail = (filename, dataUrl, pageId, container) => {
        // If pageId is not provided, try to find it in the DOM
        if (!pageId) {
            pageId = document.getElementById('Inputfield_id').value;
        }

        const formData = new FormData();
        formData.append('page_id', pageId);
        formData.append('filename', filename);
        formData.append('image', dataUrl);

        fetch(config.uploadUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('VideoPoster: Thumbnail uploaded successfully', data);

                    if (data.toolbarMarkup && container) {
                        // Replace the container contents
                        if (container.classList.contains('video-poster-actions')) {
                            container.outerHTML = data.toolbarMarkup;
                        } else {
                            // Fallback
                            container.innerHTML = data.toolbarMarkup;
                        }

                        // Re-initialize listeners on the new markup
                        // Since we replaced the HTML, we need to find the new elements in the document
                        // But we don't have a direct reference to the new DOM node easily if we used outerHTML.
                        // However, initVideoPoster relies on finding .video-poster-generate.
                        // Let's just re-init on the whole document or try to scope it.
                        // Scoping to document is safe enough as init checks for data-video-poster-init logic if added, 
                        // but for links we aren't using that yet.
                        // Let's just re-run initVideoPoster(document) or better, find the new link.

                        // We can also just attach listener directly if we parse the HTML into an element first.
                        // But for simplicity, let's call initVideoPoster again on the document, it should be idempotent for inputs, 
                        // and we need to handle the links correctly.

                        initVideoPoster(document);
                    }

                    if (typeof ProcessWire !== 'undefined' && ProcessWire.alert) {
                        ProcessWire.alert('Thumbnail generated and saved.');
                    }
                } else {
                    console.error('VideoPoster: Upload failed', data.message);
                    if (typeof ProcessWire !== 'undefined' && ProcessWire.alert) {
                        ProcessWire.alert('Thumbnail upload failed: ' + data.message);
                    }
                }
            })
            .catch(error => {
                console.error('VideoPoster: Error uploading thumbnail', error);
            });
    };

    // Handler for manual generation via link
    const handleGenerateClick = (e) => {
        e.preventDefault();
        const link = e.currentTarget;
        const url = link.dataset.url;
        const container = link.closest('.video-poster-actions');

        // Fall back to /site/assets/files/PAGEID/FILENAME for toolbars rendered before 1.0.1
        const parts = url.split('/');
        const filename = link.dataset.filename || parts.pop();
        const pageId = link.dataset.pageId || parts.pop();

        // Add spinner or loading state
        const icon = link.querySelector('i');
        //const originalClass = icon.className;
        icon.className = 'fa fa-spinner fa-spin';

        generateThumbnail(url)
            .then(dataUrl => {
                uploadThumbnail(filename, dataUrl, pageId, container);
                // Icon update is handled by markup replacement
            })
            .catch(err => {
                console.error('VideoPoster: Failed to generate from URL', err);
                icon.className = 'fa fa-exclamation-triangle';
                alert('Could not load video to generate thumbnail. CORS issues likely if testing locally/external.');
            });
    };

    const initVideoPoster = (root) => {
        if (!root) root = document;
        // Handle jQuery objects
        if (root instanceof jQuery) root = root[0];

        // Attach to generate links
        const links = root.querySelectorAll('.video-poster-generate');
        links.forEach(link => {
            if (!link.dataset.videoPosterInit) {
                link.dataset.videoPosterInit = 'true';
                link.addEventListener('click', handleGenerateClick);
            }
            // Items rendered by an upload come back with data-auto
            if (link.dataset.auto && !link.dataset.videoPosterAuto) {
                link.dataset.videoPosterAuto = 'true';
                link.click();
            }
        });
    };

    // Initialize on load
    initVideoPoster(document);

    // Listen for Repeater reloads
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('reloaded', '.InputfieldRepeaterItem', function (event) {
            initVideoPoster(event.currentTarget);
        });

        // Items added by an upload render with the real page id and stored filename
        jQuery(document).on('AjaxUploadDone', function (event) {
            initVideoPoster(event.target);
        });
    }
});
