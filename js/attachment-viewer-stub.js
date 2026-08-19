// Attachment Viewer fallback stub
// This script is loaded before attachment-viewer.js. If the viewer module fails to
// initialize, these stubs keep the View link from silently doing nothing and
// provide a useful error message to the user while still allowing download.
(function() {
  'use strict';

  function notify(message) {
    if (typeof window !== 'undefined' && typeof window.showToast === 'function') {
      window.showToast(message, 'error');
    }
    console.error('[AttachmentViewerStub]', message);
  }

  function openAttachmentViewer(storagePath, fileName, fileType) {
    console.error('[AttachmentViewerStub] openAttachmentViewer called but the attachment viewer module did not initialize.');
    notify('Unable to open this attachment preview. You can still download the file.');
  }

  function isAttachmentViewable(fileName) {
    return false;
  }

  if (typeof window.openAttachmentViewer !== 'function') {
    window.openAttachmentViewer = openAttachmentViewer;
  }
  if (typeof window.isAttachmentViewable !== 'function') {
    window.isAttachmentViewable = isAttachmentViewable;
  }
})();
