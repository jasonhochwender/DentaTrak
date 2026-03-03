/**
 * GCS Direct Upload Module
 * 
 * Handles uploading files directly to Google Cloud Storage via signed URLs.
 * This bypasses Cloud Run's 32MB request body limit entirely.
 * 
 * Flow:
 * 1. For each file, request a signed PUT URL from the backend
 * 2. Upload the file directly to GCS using the signed URL
 * 3. Collect all storage_paths for case submission
 * 4. Submit case metadata (no binary data) to backend
 * 5. Backend verifies uploads exist in GCS before saving
 */

(function() {
  'use strict';
  
  console.log('[GCS-Upload] Module loaded');

  // --- Session keepalive during uploads ---
  var KEEPALIVE_INTERVAL_MS = 45000; // Ping every 45 seconds
  var keepaliveIntervalId = null;
  
  function startKeepalive() {
    if (keepaliveIntervalId) return; // Already running
    
    console.log('[GCS-Upload] Starting session keepalive (every 45s)');
    keepaliveIntervalId = setInterval(function() {
      fetch('api/ping.php', {
        method: 'GET',
        credentials: 'same-origin'
      })
      .then(function(response) {
        if (!response.ok) {
          console.warn('[GCS-Upload] Keepalive ping failed:', response.status);
        }
      })
      .catch(function(err) {
        console.warn('[GCS-Upload] Keepalive ping error:', err.message);
      });
    }, KEEPALIVE_INTERVAL_MS);
  }
  
  function stopKeepalive() {
    if (keepaliveIntervalId) {
      console.log('[GCS-Upload] Stopping session keepalive');
      clearInterval(keepaliveIntervalId);
      keepaliveIntervalId = null;
    }
  }

  // --- Type-specific per-file limits (must mirror appConfig.php) ---
  var FILE_SIZE_LIMITS = {
    // 3D scan files
    stl:  250 * 1024 * 1024,
    obj:  250 * 1024 * 1024,
    ply:  250 * 1024 * 1024,
    dcm:  250 * 1024 * 1024,
    // Images
    jpg:  25 * 1024 * 1024,
    jpeg: 25 * 1024 * 1024,
    png:  25 * 1024 * 1024,
    gif:  25 * 1024 * 1024,
    webp: 25 * 1024 * 1024,
    tiff: 25 * 1024 * 1024,
    tif:  25 * 1024 * 1024,
    bmp:  25 * 1024 * 1024,
    svg:  10 * 1024 * 1024,
    // Documents
    pdf:  50 * 1024 * 1024,
    zip:  250 * 1024 * 1024
  };
  var DEFAULT_MAX_FILE_SIZE = 100 * 1024 * 1024;
  var GCS_MAX_TOTAL_SIZE = 1024 * 1024 * 1024;  // 1 GB per case
  var GCS_MAX_FILE_COUNT = 15;
  var MAX_RETRIES = 2;
  var CONCURRENT_UPLOADS = 3;

  // Friendly category labels for error messages
  var FILE_CATEGORY_LABELS = {
    stl: 'STL files', obj: '3D scan files', ply: '3D scan files', dcm: 'DICOM files',
    jpg: 'Images', jpeg: 'Images', png: 'Images', gif: 'Images',
    webp: 'Images', tiff: 'Images', tif: 'Images', bmp: 'Images', svg: 'SVG files',
    pdf: 'PDF documents', zip: 'ZIP archives'
  };

  /**
   * Get the max allowed size for a given filename
   * @param {string} filename
   * @returns {number} Max size in bytes
   */
  function getMaxSizeForFile(filename) {
    var ext = (filename.split('.').pop() || '').toLowerCase();
    return FILE_SIZE_LIMITS[ext] || DEFAULT_MAX_FILE_SIZE;
  }

  /**
   * Get a human-friendly category label for a file extension
   * @param {string} ext
   * @returns {string}
   */
  function getCategoryLabel(ext) {
    return FILE_CATEGORY_LABELS[ext] || 'Files of type .' + ext;
  }

  /**
   * Upload all files from the case form to GCS via signed URLs
   * 
   * @param {HTMLFormElement} form - The case form element
   * @param {string} caseId - The case ID ("new" for new cases, or existing case ID)
   * @param {string} csrfToken - CSRF token for API calls
   * @param {function} onProgress - Progress callback: function(uploaded, total, currentFileName)
   * @returns {Promise<Array>} Array of uploaded file metadata objects
   */
  function uploadFilesToGCS(form, caseId, csrfToken, onProgress) {
    console.log('[GCS-Upload] uploadFilesToGCS called', { caseId: caseId });
    return new Promise(function(resolve, reject) {
      // Collect all files from attachment inputs
      var fileInputs = form.querySelectorAll('.attachment-input');
      console.log('[GCS-Upload] Found', fileInputs.length, 'file inputs');
      var allFiles = [];
      var totalSize = 0;

      fileInputs.forEach(function(input) {
        var uploadType = input.dataset.type || 'photos';
        var files = input._accumulatedFiles || [];
        console.log('[GCS-Upload] Input', uploadType, '- _accumulatedFiles:', (input._accumulatedFiles || []).length, ', input.files:', (input.files ? input.files.length : 0));
        
        // Also check the input.files directly
        if (files.length === 0 && input.files && input.files.length > 0) {
          files = Array.from(input.files);
        }

        files.forEach(function(file) {
          allFiles.push({
            file: file,
            uploadType: uploadType,
            fileName: file.name,
            contentType: file.type || 'application/octet-stream',
            fileSize: file.size
          });
          totalSize += file.size;
        });
      });

      console.log('[GCS-Upload] Total files collected:', allFiles.length, 'Total size:', totalSize);
      
      // No files to upload
      if (allFiles.length === 0) {
        console.log('[GCS-Upload] No files to upload, resolving empty');
        resolve([]);
        return;
      }

      // --- Enforce file count ---
      if (allFiles.length > GCS_MAX_FILE_COUNT) {
        reject(new Error('Maximum ' + GCS_MAX_FILE_COUNT + ' files per case. You selected ' + allFiles.length + '.'));
        return;
      }

      // --- Enforce per-file type-specific size limits ---
      var oversized = [];
      allFiles.forEach(function(f) {
        var limit = getMaxSizeForFile(f.fileName);
        if (f.fileSize > limit) {
          var ext = (f.fileName.split('.').pop() || '').toLowerCase();
          oversized.push({
            name: f.fileName,
            size: f.fileSize,
            limit: limit,
            label: getCategoryLabel(ext)
          });
        }
      });
      if (oversized.length > 0) {
        // Group by category for a cleaner message
        var seen = {};
        var msgs = [];
        oversized.forEach(function(o) {
          if (!seen[o.label]) {
            seen[o.label] = true;
            msgs.push(o.label + ' must be under ' + formatFileSize(o.limit));
          }
        });
        var names = oversized.map(function(o) { return o.name + ' (' + formatFileSize(o.size) + ')'; });
        reject(new Error(msgs.join('. ') + '. Over-limit files: ' + names.join(', ')));
        return;
      }

      // --- Enforce total size ---
      if (totalSize > GCS_MAX_TOTAL_SIZE) {
        reject(new Error('Total case upload cannot exceed ' + formatFileSize(GCS_MAX_TOTAL_SIZE) + '. Current total: ' + formatFileSize(totalSize) + '. Please remove some files.'));
        return;
      }

      // Upload all files with controlled concurrency (max 3 parallel)
      var uploaded = [];
      var errors = [];
      var queue = allFiles.slice();
      var activeCount = 0;
      var totalCount = allFiles.length;
      var completedCount = 0;
      
      // Generate unique IDs for each file for tracking
      queue.forEach(function(item, idx) {
        item.fileId = 'file_' + idx + '_' + Date.now();
      });
      
      console.log('[GCS-Upload] Starting upload queue:', totalCount, 'files, max', CONCURRENT_UPLOADS, 'concurrent');
      
      // Start session keepalive to prevent timeout during long uploads
      startKeepalive();

      function checkCompletion() {
        if (completedCount === totalCount) {
          // Stop keepalive when all uploads are done
          stopKeepalive();
          
          if (errors.length > 0) {
            var failedNames = errors.map(function(e) { return e.fileName; }).join(', ');
            console.error('[GCS-Upload] Upload batch failed:', errors.length, 'errors');
            reject(new Error('Failed to upload: ' + failedNames + '. ' + errors[0].error));
          } else {
            console.log('[GCS-Upload] All', totalCount, 'files uploaded successfully');
            resolve(uploaded);
          }
        }
      }

      // Retry backoff delays: 3s for first retry, 10s for second
      var RETRY_DELAYS = [3000, 10000];
      
      function startNextUpload() {
        // Don't start more if we're at capacity or queue is empty
        if (activeCount >= CONCURRENT_UPLOADS || queue.length === 0) {
          return;
        }
        
        var item = queue.shift();
        activeCount++;
        
        // Track retry count per file
        item.retryCount = item.retryCount || 0;
        
        var retryLabel = item.retryCount > 0 ? ' (retry ' + item.retryCount + '/' + MAX_RETRIES + ')' : '';
        console.log('[GCS-Upload] [' + item.fileId + '] Starting upload:', item.fileName, '(' + formatFileSize(item.fileSize) + ')' + retryLabel + ' - Active:', activeCount);

        uploadSingleFile(item, caseId, csrfToken)
          .then(function(result) {
            console.log('[GCS-Upload] [' + item.fileId + '] Upload complete:', item.fileName);
            uploaded.push(result);
            completedCount++;
            activeCount--;
            if (onProgress) {
              onProgress(completedCount, totalCount, result.original_filename);
            }
            // Start next upload if available
            startNextUpload();
            checkCompletion();
          })
          .catch(function(err) {
            activeCount--;
            
            // Check if we should retry (only for stall/network errors, not HTTP errors)
            var isRetryable = err.message.indexOf('stalled') !== -1 || 
                              err.message.indexOf('Network error') !== -1;
            
            if (isRetryable && item.retryCount < MAX_RETRIES) {
              item.retryCount++;
              var delay = RETRY_DELAYS[item.retryCount - 1] || 10000;
              console.log('[GCS-Upload] [' + item.fileId + '] Will retry in ' + (delay / 1000) + 's (attempt ' + item.retryCount + '/' + MAX_RETRIES + ')');
              
              setTimeout(function() {
                // Re-add to queue for retry
                queue.push(item);
                startNextUpload();
              }, delay);
            } else {
              // No more retries or non-retryable error
              console.error('[GCS-Upload] [' + item.fileId + '] Upload failed permanently:', item.fileName, '-', err.message);
              errors.push({ fileName: item.fileName, error: err.message });
              completedCount++;
              // Start next upload even on error (don't block queue)
              startNextUpload();
              checkCompletion();
            }
          });
        
        // Immediately try to start more uploads up to concurrency limit
        startNextUpload();
      }

      // Kick off initial batch (up to CONCURRENT_UPLOADS)
      startNextUpload();
    });
  }

  /**
   * Upload a single file to GCS via signed URL
   * No auto-retry to avoid duplicate uploads - caller handles errors
   * 
   * @param {Object} fileInfo - File info object {file, uploadType, fileName, contentType, fileSize, fileId}
   * @param {string} caseId - Case ID
   * @param {string} csrfToken - CSRF token
   * @returns {Promise<Object>} Uploaded file metadata
   */
  function uploadSingleFile(fileInfo, caseId, csrfToken) {
    var fileId = fileInfo.fileId || 'unknown';
    console.log('[GCS-Upload] [' + fileId + '] Requesting signed URL for:', fileInfo.fileName, '(' + fileInfo.fileSize + ' bytes)');
    // Step 1: Get signed URL from backend
    return fetch('api/upload-signed-url.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        filename: fileInfo.fileName,
        content_type: fileInfo.contentType,
        file_size: fileInfo.fileSize,
        case_id: caseId,
        upload_type: fileInfo.uploadType
      })
    })
    .then(function(response) {
      console.log('[GCS-Upload] [' + fileId + '] Signed URL response status:', response.status);
      if (!response.ok) {
        return response.json().then(function(data) {
          console.error('[GCS-Upload] [' + fileId + '] Signed URL error:', data);
          throw new Error(data.error || 'Failed to get upload URL (status ' + response.status + ')');
        });
      }
      return response.json();
    })
    .then(function(data) {
      if (!data.success || !data.signed_url) {
        console.error('[GCS-Upload] [' + fileId + '] Invalid signed URL response:', data);
        throw new Error(data.error || 'Failed to get signed upload URL');
      }

      console.log('[GCS-Upload] [' + fileId + '] Got signed URL, starting PUT to GCS:', data.storage_path);
      
      // Step 2: Upload directly to GCS using XMLHttpRequest with stall-based timeout
      return new Promise(function(resolveUpload, rejectUpload) {
        var xhr = new XMLHttpRequest();
        var uploadStartTime = Date.now();
        var lastProgressAt = Date.now();
        var lastLoadedBytes = 0;
        var isCompleted = false;
        var stallCheckInterval = null;
        
        // Timeout constants
        var STALL_TIMEOUT_MS = 90000;      // 90 seconds without progress = stalled
        var MAX_TOTAL_MS = 45 * 60 * 1000; // 45 minutes absolute ceiling
        var STALL_CHECK_INTERVAL_MS = 5000; // Check every 5 seconds
        
        function cleanup() {
          isCompleted = true;
          if (stallCheckInterval) {
            clearInterval(stallCheckInterval);
            stallCheckInterval = null;
          }
        }
        
        function logThroughput(success) {
          var durationMs = Date.now() - uploadStartTime;
          var durationSec = durationMs / 1000;
          var sizeMB = fileInfo.fileSize / (1024 * 1024);
          var avgMbps = (fileInfo.fileSize * 8 / 1000000) / durationSec;
          console.log('[GCS-Upload] [' + fileId + '] Throughput: ' + sizeMB.toFixed(1) + 'MB in ' + durationSec.toFixed(1) + 's = ' + avgMbps.toFixed(2) + ' Mbps' + (success ? ' (success)' : ' (failed)'));
        }
        
        // Stall detection: check if progress has stopped
        stallCheckInterval = setInterval(function() {
          if (isCompleted) return;
          
          var now = Date.now();
          var timeSinceProgress = now - lastProgressAt;
          var totalElapsed = now - uploadStartTime;
          
          // Check absolute ceiling first
          if (totalElapsed > MAX_TOTAL_MS) {
            console.error('[GCS-Upload] [' + fileId + '] Upload exceeded maximum time (45 minutes)');
            cleanup();
            xhr.abort();
            logThroughput(false);
            rejectUpload(new Error('Upload exceeded maximum allowed time (45 minutes). Please try with a smaller file or better connection.'));
            return;
          }
          
          // Check for stall (no progress)
          if (timeSinceProgress > STALL_TIMEOUT_MS) {
            console.error('[GCS-Upload] [' + fileId + '] Upload stalled - no progress for ' + Math.round(timeSinceProgress / 1000) + ' seconds');
            cleanup();
            xhr.abort();
            logThroughput(false);
            rejectUpload(new Error('Upload stalled (no progress for 90 seconds). Check your connection and retry.'));
            return;
          }
        }, STALL_CHECK_INTERVAL_MS);
        
        // Throttle progress logs to avoid console spam (log every 10%)
        var lastLoggedPercent = 0;
        xhr.upload.addEventListener('progress', function(e) {
          if (e.lengthComputable) {
            // Update stall detection if bytes increased
            if (e.loaded > lastLoadedBytes) {
              lastProgressAt = Date.now();
              lastLoadedBytes = e.loaded;
            }
            
            var percent = Math.round((e.loaded / e.total) * 100);
            if (percent >= lastLoggedPercent + 10 || percent === 100) {
              console.log('[GCS-Upload] [' + fileId + '] Progress:', percent + '%', '(' + Math.round(e.loaded / 1024 / 1024) + 'MB / ' + Math.round(e.total / 1024 / 1024) + 'MB)');
              lastLoggedPercent = percent;
            }
          }
        });
        
        xhr.addEventListener('load', function() {
          cleanup();
          console.log('[GCS-Upload] [' + fileId + '] GCS PUT response status:', xhr.status);
          if (xhr.status >= 200 && xhr.status < 300) {
            logThroughput(true);
            console.log('[GCS-Upload] [' + fileId + '] PUT complete - file uploaded to GCS');
            resolveUpload({
              storage_path: data.storage_path,
              original_filename: fileInfo.fileName,
              content_type: fileInfo.contentType,
              file_size: fileInfo.fileSize,
              upload_type: fileInfo.uploadType
            });
          } else {
            logThroughput(false);
            console.error('[GCS-Upload] [' + fileId + '] GCS PUT failed:', xhr.status, xhr.statusText);
            rejectUpload(new Error('Upload failed (HTTP status ' + xhr.status + '). Please retry.'));
          }
        });
        
        xhr.addEventListener('error', function() {
          cleanup();
          logThroughput(false);
          console.error('[GCS-Upload] [' + fileId + '] GCS PUT network error');
          rejectUpload(new Error('Network error during upload. Check your connection and retry.'));
        });
        
        xhr.addEventListener('abort', function() {
          // Abort is handled by stall detection, don't double-reject
          if (!isCompleted) {
            cleanup();
            logThroughput(false);
            console.error('[GCS-Upload] [' + fileId + '] Upload aborted');
          }
        });
        
        xhr.open('PUT', data.signed_url);
        xhr.setRequestHeader('Content-Type', fileInfo.contentType);
        xhr.timeout = 0; // Disable built-in timeout - we use stall detection instead
        console.log('[GCS-Upload] [' + fileId + '] Starting XHR PUT (stall timeout: 90s, max: 45min)');
        xhr.send(fileInfo.file);
      });
    });
  }

  /**
   * Check if a form has any files to upload
   * @param {HTMLFormElement} form
   * @returns {boolean}
   */
  function formHasFiles(form) {
    var fileInputs = form.querySelectorAll('.attachment-input');
    var hasFiles = false;

    fileInputs.forEach(function(input) {
      var files = input._accumulatedFiles || [];
      if (files.length === 0 && input.files && input.files.length > 0) {
        files = Array.from(input.files);
      }
      if (files.length > 0) {
        hasFiles = true;
      }
    });

    return hasFiles;
  }

  /**
   * Get total file size from form
   * @param {HTMLFormElement} form
   * @returns {number} Total size in bytes
   */
  function getFormTotalFileSize(form) {
    var fileInputs = form.querySelectorAll('.attachment-input');
    var totalSize = 0;

    fileInputs.forEach(function(input) {
      var files = input._accumulatedFiles || [];
      if (files.length === 0 && input.files && input.files.length > 0) {
        files = Array.from(input.files);
      }
      files.forEach(function(file) {
        totalSize += file.size;
      });
    });

    return totalSize;
  }

  /**
   * Format file size for display
   * @param {number} bytes
   * @returns {string}
   */
  function formatFileSize(bytes) {
    if (bytes >= 1024 * 1024 * 1024) {
      return (bytes / (1024 * 1024 * 1024)).toFixed(1) + 'GB';
    }
    if (bytes >= 1024 * 1024) {
      return (bytes / (1024 * 1024)).toFixed(1) + 'MB';
    }
    if (bytes >= 1024) {
      return (bytes / 1024).toFixed(1) + 'KB';
    }
    return bytes + ' bytes';
  }

  // Expose public API
  window.GCSUpload = {
    uploadFilesToGCS: uploadFilesToGCS,
    formHasFiles: formHasFiles,
    getFormTotalFileSize: getFormTotalFileSize,
    formatFileSize: formatFileSize,
    getMaxSizeForFile: getMaxSizeForFile,
    FILE_SIZE_LIMITS: FILE_SIZE_LIMITS,
    MAX_TOTAL_SIZE: GCS_MAX_TOTAL_SIZE,
    MAX_FILE_COUNT: GCS_MAX_FILE_COUNT
  };

})();
