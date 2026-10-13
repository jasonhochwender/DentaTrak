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

  // Extensions the server accepts for case attachments. Mirrors the extension
  // lists in api/appConfig.php + api/upload-signed-url.php so obviously-wrong
  // files are rejected in the browser instead of round-tripping to a 400 that
  // used to surface as "check your internet connection".
  var ALLOWED_EXTENSIONS = [
    'stl', 'obj', 'ply', 'dcm',
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg',
    'pdf', 'zip'
  ];

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

  // Friendly category labels for error messages (translation keys under
  // attachments.file_categories)
  var FILE_CATEGORY_KEYS = {
    stl: 'stl', obj: '3d_scan', ply: '3d_scan', dcm: 'dicom',
    jpg: 'images', jpeg: 'images', png: 'images', gif: 'images',
    webp: 'images', tiff: 'images', tif: 'images', bmp: 'images', svg: 'svg',
    pdf: 'pdf', zip: 'zip'
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
    var key = FILE_CATEGORY_KEYS[ext];
    if (key) {
      var label = t('attachments.file_categories.' + key);
      if (label) { return label; }
    }
    return t('attachments.file_categories.generic', { ext: ext }) || ('Files of type .' + ext);
  }

  /**
   * Lowercased file extension (without dot), empty string when absent.
   * @param {string} filename
   * @returns {string}
   */
  function fileExt(filename) {
    var parts = (filename || '').split('.');
    return parts.length > 1 ? (parts.pop() || '').toLowerCase() : '';
  }

  // MIME types the server accepts for 3D-scan extensions (mirrors
  // appConfig gcs.allowed_mime_types entries that can describe a scan).
  var SCAN_ACCEPTED_TYPES = [
    'model/stl',
    'application/sla',
    'application/vnd.ms-pki.stl',
    'application/x-navistyle',
    'model/obj',
    'application/x-tgif',
    'application/octet-stream'
  ];

  /**
   * Normalize a browser-reported MIME type for upload. STL/OBJ/PLY files are
   * legitimately ASCII text, and OS/browser detection reports them
   * unpredictably - text/plain on Windows, model/x.stl-ascii /
   * model/x.stl-binary on Linux, or nothing at all - none of which survive
   * the server's MIME allowlist intact. The extension allowlist (client +
   * server) is the real type gate, so map any unrecognized scan type to
   * generic binary. This MUST mirror the server's normalization exactly:
   * the signed URL binds the declared content type, so the PUT has to send
   * the same value or GCS rejects it with a signature mismatch.
   * @param {string} contentType - file.type (may be empty)
   * @param {string} filename
   * @returns {string}
   */
  function normalizeContentType(contentType, filename) {
    var SCAN_TEXT_OK = ['stl', 'obj', 'ply', 'dcm'];
    var ct = contentType || 'application/octet-stream';
    if (SCAN_TEXT_OK.indexOf(fileExt(filename)) !== -1 &&
        SCAN_ACCEPTED_TYPES.indexOf(ct) === -1) {
      return 'application/octet-stream';
    }
    return ct;
  }

  /**
   * Fire-and-forget upload-failure report so production failures can be
   * correlated with server logs via request_id. Only sanitized enum/numeric
   * fields are sent - never filenames, file contents, or patient data.
   * @param {Object} info - {request_id?, stage, http_status?, error_class, file_size?, ext?, upload_type?}
   */
  function reportUploadFailure(info) {
    try {
      var meta = document.querySelector('meta[name="csrf-token"]');
      var token = meta ? meta.getAttribute('content') : null;
      if (!token) { return; }
      fetch('api/log-upload-error.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': token
        },
        credentials: 'same-origin',
        keepalive: true,
        body: JSON.stringify({
          request_id: info.request_id || null,
          stage: info.stage || 'unknown',
          http_status: info.http_status || 0,
          error_class: info.error_class || 'unknown',
          file_size: info.file_size || 0,
          ext: info.ext || '',
          upload_type: info.upload_type || ''
        })
      }).catch(function() { /* reporting must never break uploads */ });
    } catch (e) { /* ignore */ }
  }

  /**
   * Map a signed-URL error response to a localized message. The server sends
   * a machine-readable error_code (plus English fallback text); known codes
   * map to client-side translations so the toast is never a misleading
   * "check your internet connection".
   * @param {number} status - HTTP status
   * @param {Object} data - Parsed response body (may be empty)
   * @returns {string} Localized user-facing message
   */
  function signedUrlErrorMessage(status, data) {
    var code = (data && data.error_code) || '';
    var serverMsg = (data && (data.message || data.error)) || '';
    if (status === 401) {
      return t('attachments.upload_errors.session_expired');
    }
    if (status === 403 || status === 404) {
      return serverMsg || t('attachments.upload_errors.access_denied');
    }
    if (code) {
      var params = {};
      if (data.limit) { params.limit = data.limit; }
      if (data.ext) { params.ext = data.ext; }
      var localized = t('attachments.upload_errors.' + code, params);
      if (localized) { return localized; }
    }
    if (status === 413) {
      return t('attachments.upload_errors.payload_too_large');
    }
    if (status >= 500) {
      return t('attachments.upload_errors.server_error', { status: status });
    }
    return serverMsg || t('attachments.upload_errors.unknown', { status: status });
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
            contentType: normalizeContentType(file.type, file.name),
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
        reportUploadFailure({ stage: 'client-validation', error_class: 'too_many', file_size: 0, ext: '', upload_type: allFiles[0].uploadType });
        var vErr = new Error(t('attachments.upload_max_count', { max: GCS_MAX_FILE_COUNT, count: allFiles.length }));
        vErr.uploadStage = 'client-validation';
        reject(vErr);
        return;
      }

      // --- Reject unsupported extensions before any upload begins ---
      var unsupported = allFiles.filter(function(f) {
        var ext = (f.fileName.split('.').pop() || '').toLowerCase();
        return ALLOWED_EXTENSIONS.indexOf(ext) === -1;
      });
      if (unsupported.length > 0) {
        unsupported.forEach(function(f) {
          reportUploadFailure({ stage: 'client-validation', error_class: 'unsupported_type', file_size: f.fileSize, ext: fileExt(f.fileName), upload_type: f.uploadType });
        });
        var badNames = unsupported.map(function(f) { return f.fileName; }).join(', ');
        var vErr = new Error(t('attachments.upload_unsupported_type', { files: badNames }));
        vErr.uploadStage = 'client-validation';
        reject(vErr);
        return;
      }

      // --- Reject empty/unreadable files before any upload begins ---
      // A File can report size 0 when the browser has the name but not the
      // content (empty export, cloud-synced placeholder, stale picker
      // reference). Uploading one can only fail - tell the user to re-select.
      var unreadable = allFiles.filter(function(f) { return !f.fileSize || f.fileSize <= 0; });
      if (unreadable.length > 0) {
        unreadable.forEach(function(f) {
          reportUploadFailure({ stage: 'client-validation', error_class: 'empty_file', file_size: 0, ext: fileExt(f.fileName), upload_type: f.uploadType });
        });
        var emptyNames = unreadable.map(function(f) { return f.fileName; }).join(', ');
        var vErr = new Error(t('attachments.upload_empty_file', { files: emptyNames }));
        vErr.uploadStage = 'client-validation';
        reject(vErr);
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
            msgs.push(t('attachments.upload_category_limit', { category: o.label, limit: formatFileSize(o.limit) }));
          }
        });
        var names = oversized.map(function(o) { return o.name + ' (' + formatFileSize(o.size) + ')'; });
        oversized.forEach(function(f) {
          reportUploadFailure({ stage: 'client-validation', error_class: 'too_large', file_size: f.size, ext: fileExt(f.name), upload_type: '' });
        });
        var vErr = new Error(t('attachments.upload_over_limit_files', { messages: msgs.join('. '), files: names.join(', ') }));
        vErr.uploadStage = 'client-validation';
        reject(vErr);
        return;
      }

      // --- Enforce total size ---
      if (totalSize > GCS_MAX_TOTAL_SIZE) {
        reportUploadFailure({ stage: 'client-validation', error_class: 'total_exceeded', file_size: totalSize, ext: '', upload_type: '' });
        var vErr = new Error(t('attachments.upload_total_exceeded', { max: formatFileSize(GCS_MAX_TOTAL_SIZE), total: formatFileSize(totalSize) }));
        vErr.uploadStage = 'client-validation';
        reject(vErr);
        return;
      }

      // Upload all files with controlled concurrency (max 3 parallel).
      // Files tagged with __gcsUploadedResult already succeeded on a previous
      // submit attempt - reuse their storage metadata instead of re-uploading.
      var uploaded = [];
      var errors = [];
      var queue = [];
      allFiles.forEach(function(item) {
        if (item.file && item.file.__gcsUploadedResult) {
          console.log('[GCS-Upload] Reusing prior upload for:', item.fileName);
          uploaded.push(item.file.__gcsUploadedResult);
        } else {
          queue.push(item);
        }
      });
      var activeCount = 0;
      var totalCount = allFiles.length;
      var completedCount = uploaded.length;
      
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
            var batchError = new Error(t('attachments.upload_failed_files', { files: failedNames, error: errors[0].error }));
            // Structured failure info so the caller can classify instead of
            // guessing from the message text.
            batchError.uploadStage = 'batch';
            batchError.uploadErrors = errors;
            batchError.uploadedFiles = uploaded;
            if (errors[0].sessionExpired) { batchError.sessionExpired = true; }
            reject(batchError);
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
            // Tag the File so a resubmit can skip re-uploading it.
            if (item.file) { item.file.__gcsUploadedResult = result; }
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
            var isRetryable = err.uploadRetryable === true;
            
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
              console.error('[GCS-Upload] [' + item.fileId + '] Upload failed permanently:', item.fileName, '-', err.message, '(stage:', err.uploadStage || '?', 'status:', err.uploadHttpStatus || 0, 'request:', err.uploadRequestId || '-', ')');
              errors.push({
                fileName: item.fileName,
                error: err.message,
                stage: err.uploadStage || 'unknown',
                httpStatus: err.uploadHttpStatus || 0,
                errorClass: err.uploadErrorClass || 'unknown',
                requestId: err.uploadRequestId || null,
                sessionExpired: err.sessionExpired === true
              });
              completedCount++;
              // Start next upload even on error (don't block queue)
              startNextUpload();
              checkCompletion();
            }
          });
        
        // Immediately try to start more uploads up to concurrency limit
        startNextUpload();
      }

      // Kick off initial batch (up to CONCURRENT_UPLOADS). checkCompletion
      // also covers the all-files-already-uploaded retry path where the
      // queue starts empty.
      startNextUpload();
      checkCompletion();
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
  /**
   * Report every failed attempt (including each retry) so the server log
   * shows per-attempt failures correlatable by request_id. Errors marked
   * skipUploadReport (e.g. already-reported client validation) pass through.
   */
  function uploadSingleFile(fileInfo, caseId, csrfToken) {
    return uploadSingleFileAttempt(fileInfo, caseId, csrfToken).catch(function(err) {
      if (err && !err.skipUploadReport) {
        reportUploadFailure({
          request_id: err.uploadRequestId || null,
          stage: err.uploadStage || 'unknown',
          http_status: err.uploadHttpStatus || 0,
          error_class: err.uploadErrorClass || 'unknown',
          file_size: fileInfo.fileSize || 0,
          ext: fileExt(fileInfo.fileName),
          upload_type: fileInfo.uploadType || ''
        });
      }
      throw err;
    });
  }

  function uploadSingleFileAttempt(fileInfo, caseId, csrfToken) {
    var fileId = fileInfo.fileId || 'unknown';
    // Defend direct callers (e.g. comment images) the same way - keep the
    // signed-URL request, PUT header, and returned metadata consistent.
    fileInfo.contentType = normalizeContentType(fileInfo.contentType, fileInfo.fileName);
    console.log('[GCS-Upload] [' + fileId + '] Requesting signed URL for:', fileInfo.fileName, '(' + fileInfo.fileSize + ' bytes)');

    function makeStageError(message, stage, status, errorClass, requestId) {
      var err = new Error(message);
      err.uploadStage = stage;
      err.uploadHttpStatus = status || 0;
      err.uploadErrorClass = errorClass || 'unknown';
      err.uploadRequestId = requestId || null;
      if (errorClass === 'session_expired') { err.sessionExpired = true; }
      return err;
    }

    // Step 1: Get signed URL from backend (30s ceiling so a stalled request
    // cannot leave the submit spinner running forever)
    var signedUrlController = new AbortController();
    var signedUrlTimeoutId = setTimeout(function() { signedUrlController.abort(); }, 30000);

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
      }),
      signal: signedUrlController.signal
    })
    .then(function(response) {
      clearTimeout(signedUrlTimeoutId);
      console.log('[GCS-Upload] [' + fileId + '] Signed URL response status:', response.status);
      if (!response.ok) {
        // Response may be non-JSON (proxy HTML error) - parse defensively.
        return response.json().catch(function() { return {}; }).then(function(data) {
          console.error('[GCS-Upload] [' + fileId + '] Signed URL error:', response.status, data);
          var requestId = data.request_id || null;
          var errorClass = 'unknown';
          var retryable = false;
          if (response.status === 401) {
            errorClass = 'session_expired';
          } else if (response.status === 403 || response.status === 404) {
            errorClass = 'access_denied';
          } else if (response.status === 413) {
            errorClass = 'payload_too_large';
          } else if (response.status === 400) {
            errorClass = 'validation';
          } else if (response.status >= 500 || response.status === 408 || response.status === 429) {
            errorClass = 'server_error';
            retryable = true;
          }
          var err = makeStageError(signedUrlErrorMessage(response.status, data), 'signed-url', response.status, errorClass, requestId);
          err.uploadRetryable = retryable;
          throw err;
        });
      }
      return response.json();
    }, function(networkErr) {
      clearTimeout(signedUrlTimeoutId);
      // fetch() rejected - AbortError (timeout) or a genuine network failure.
      var err;
      if (networkErr && networkErr.name === 'AbortError') {
        console.error('[GCS-Upload] [' + fileId + '] Signed URL request timed out');
        err = makeStageError(t('attachments.upload_timeout'), 'signed-url', 0, 'timeout', null);
      } else {
        console.error('[GCS-Upload] [' + fileId + '] Signed URL request network failure:', networkErr && networkErr.message);
        err = makeStageError(t('attachments.upload_network_error'), 'signed-url', 0, 'network', null);
      }
      err.uploadRetryable = true;
      throw err;
    })
    .then(function(data) {
      if (!data.success || !data.signed_url) {
        console.error('[GCS-Upload] [' + fileId + '] Invalid signed URL response:', data);
        var badErr = makeStageError(signedUrlErrorMessage(200, data), 'signed-url', 200, 'invalid_response', data.request_id || null);
        throw badErr;
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
            rejectUpload(makeStageError(t('attachments.upload_timeout'), 'gcs-put', 0, 'timeout', data.request_id));
            return;
          }

          // Check for stall (no progress)
          if (timeSinceProgress > STALL_TIMEOUT_MS) {
            console.error('[GCS-Upload] [' + fileId + '] Upload stalled - no progress for ' + Math.round(timeSinceProgress / 1000) + ' seconds');
            cleanup();
            xhr.abort();
            logThroughput(false);
            var stalledError = makeStageError(t('attachments.upload_stalled'), 'gcs-put', 0, 'stalled', data.request_id);
            stalledError.uploadRetryable = true;
            rejectUpload(stalledError);
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
            console.error('[GCS-Upload] [' + fileId + '] GCS PUT failed:', xhr.status, xhr.statusText, '(request:', data.request_id || '-', ')');
            // Transient GCS rejections (expired URL 403, rate limit, server
            // errors) are retried with a fresh signed URL by the caller.
            var putError = makeStageError(t('attachments.upload_http_failed', { status: xhr.status }), 'gcs-put', xhr.status, 'put_rejected', data.request_id);
            putError.uploadRetryable = (xhr.status === 403 || xhr.status === 408 || xhr.status === 429 || xhr.status >= 500);
            rejectUpload(putError);
          }
        });

        xhr.addEventListener('error', function() {
          cleanup();
          logThroughput(false);
          console.error('[GCS-Upload] [' + fileId + '] GCS PUT network error');
          var networkError = makeStageError(t('attachments.upload_network_error'), 'gcs-put', 0, 'network', data.request_id);
          networkError.uploadRetryable = true;
          rejectUpload(networkError);
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
        // PUT the content type the URL was signed for (echoed back by the
        // endpoint) - a mismatch fails the V4 signature check with a 403.
        xhr.setRequestHeader('Content-Type', data.content_type || fileInfo.contentType);
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
    uploadSingleFile: uploadSingleFile,
    formHasFiles: formHasFiles,
    getFormTotalFileSize: getFormTotalFileSize,
    formatFileSize: formatFileSize,
    getMaxSizeForFile: getMaxSizeForFile,
    FILE_SIZE_LIMITS: FILE_SIZE_LIMITS,
    MAX_TOTAL_SIZE: GCS_MAX_TOTAL_SIZE,
    MAX_FILE_COUNT: GCS_MAX_FILE_COUNT
  };

})();
