import * as THREE from 'three';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { PLYLoader } from 'three/addons/loaders/PLYLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

(function() {
  'use strict';

  const SUPPORTED_3D = { stl: true, obj: true, ply: true };
  const SUPPORTED_IMAGE = { jpg: true, jpeg: true, png: true, webp: true };
  const SUPPORTED_PDF = { pdf: true };
  const SUPPORTED_TYPES = Object.assign({}, SUPPORTED_3D, SUPPORTED_IMAGE, SUPPORTED_PDF);

  const IMAGE_TYPE_MAP = {
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    webp: 'image/webp'
  };

  let pdfjsLib = null;
  let pdfWorkerConfigured = false;

  let scene, camera, renderer, controls, currentObject, animationId;
  let initialCamera, initialTarget;
  let currentData = null;
  let currentMode = null;

  // Image viewer state
  let imageStage = null;
  let imageEl = null;
  let imageUrl = null;
  let imageScale = 1;
  let imagePanX = 0;
  let imagePanY = 0;
  let isImageDragging = false;
  let imageDragStartX = 0;
  let imageDragStartY = 0;
  let imagePanStartX = 0;
  let imagePanStartY = 0;
  let imageInitialPinchDistance = 0;
  let imageInitialPinchScale = 1;

  // PDF viewer state
  let pdfDoc = null;
  let pdfLoadingTask = null;
  let pdfPageNum = 1;
  let pdfNumPages = 0;
  let pdfZoomScale = 1;
  let pdfBaseScale = 1;
  let pdfCanvas = null;
  let pdfCtx = null;
  let pdfRenderTask = null;
  let pdfStage = null;
  let pdfResizeTimeout = null;

  const modal = document.getElementById('attachmentViewerModal');
  if (!modal) return;

  const titleEl = modal.querySelector('.attachment-viewer-title');
  const typeEl = modal.querySelector('.attachment-viewer-type');
  const canvasContainer = modal.querySelector('.attachment-viewer-canvas');
  const loadingEl = modal.querySelector('.attachment-viewer-loading');
  const errorEl = modal.querySelector('.attachment-viewer-error');

  const closeBtn = modal.querySelector('.attachment-viewer-close');
  const downloadBtn = modal.querySelector('.attachment-viewer-download');
  const fullscreenBtn = modal.querySelector('.attachment-viewer-fullscreen');
  const resetBtn = modal.querySelector('.attachment-viewer-reset');
  const fitBtn = modal.querySelector('.attachment-viewer-fit');
  const zoomInBtn = modal.querySelector('.attachment-viewer-zoom-in');
  const zoomOutBtn = modal.querySelector('.attachment-viewer-zoom-out');
  const prevBtn = modal.querySelector('.attachment-viewer-prev');
  const nextBtn = modal.querySelector('.attachment-viewer-next');
  const pageInfo = modal.querySelector('.attachment-viewer-page-info');
  const positionEl = modal.querySelector('.attachment-viewer-position');
  const navPrevBtn = modal.querySelector('.attachment-viewer-nav-prev');
  const navNextBtn = modal.querySelector('.attachment-viewer-nav-next');
  const errorTextEl = modal.querySelector('.attachment-viewer-error-text');
  const errorDownloadBtn = modal.querySelector('.attachment-viewer-error-download');

  // Ordered list of the case's attachments currently represented by the
  // viewer plus the index being shown. Entries are
  // { storagePath, fileName, fileType } - one signed fetch per entry, so
  // navigation never preloads or bypasses per-file authorization.
  let attachmentList = [];
  let attachmentIndex = 0;
  // Sequence token: a stale fetch that resolves after the user has moved
  // on must not overwrite the current attachment.
  let attachmentLoadSeq = 0;

  // Swipe state for touch navigation between attachments.
  let navTouchStartX = 0;
  let navTouchStartY = 0;
  let navTouchActive = false;

  if (closeBtn) closeBtn.addEventListener('click', closeViewer);
  if (downloadBtn) downloadBtn.addEventListener('click', downloadCurrent);
  if (fullscreenBtn) fullscreenBtn.addEventListener('click', toggleFullscreen);
  if (resetBtn) resetBtn.addEventListener('click', resetView);
  if (fitBtn) fitBtn.addEventListener('click', fitToView);
  if (zoomInBtn) zoomInBtn.addEventListener('click', zoomIn);
  if (zoomOutBtn) zoomOutBtn.addEventListener('click', zoomOut);
  if (prevBtn) prevBtn.addEventListener('click', pdfPrevPage);
  if (nextBtn) nextBtn.addEventListener('click', pdfNextPage);
  if (navPrevBtn) navPrevBtn.addEventListener('click', function() { navigateAttachment(-1); });
  if (navNextBtn) navNextBtn.addEventListener('click', function() { navigateAttachment(1); });
  if (errorDownloadBtn) errorDownloadBtn.addEventListener('click', downloadCurrent);

  modal.addEventListener('click', function(e) {
    if (e.target === modal) closeViewer();
  });

  document.addEventListener('keydown', function(e) {
    if (!modal || modal.style.display !== 'flex') return;
    // This listener is registered before the case modal's Escape handler, so
    // stopImmediatePropagation is what prevents one Escape from closing the
    // underlying modal as well as the viewer.
    if (e.key === 'Escape') {
      e.stopImmediatePropagation();
      closeViewer();
      return;
    }
    // Arrow-key navigation between attachments. Never steal keys from a
    // focused form control inside the viewer.
    const tag = e.target && e.target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (e.key === 'ArrowLeft') {
      e.preventDefault();
      e.stopImmediatePropagation();
      navigateAttachment(-1);
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      e.stopImmediatePropagation();
      navigateAttachment(1);
    }
  });

  // Horizontal swipe navigation. Deliberately skipped for the 3D mode
  // (OrbitControls owns single-finger drags) and for zoomed images, where
  // a horizontal touch is a pan. At scale 1 an image swipe only pans
  // harmlessly, so it doubles as a natural "pull to next" gesture.
  if (canvasContainer) {
    canvasContainer.addEventListener('touchstart', function(e) {
      if (e.touches.length !== 1) {
        navTouchActive = false;
        return;
      }
      if (currentMode === '3d') {
        navTouchActive = false;
        return;
      }
      if (currentMode === 'image' && imageScale > 1) {
        navTouchActive = false;
        return;
      }
      navTouchActive = true;
      navTouchStartX = e.touches[0].clientX;
      navTouchStartY = e.touches[0].clientY;
    }, { passive: true });
    canvasContainer.addEventListener('touchend', function(e) {
      if (!navTouchActive || !e.changedTouches || e.changedTouches.length !== 1) return;
      navTouchActive = false;
      const dx = e.changedTouches[0].clientX - navTouchStartX;
      const dy = e.changedTouches[0].clientY - navTouchStartY;
      if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
        navigateAttachment(dx < 0 ? 1 : -1);
      }
    });
    canvasContainer.addEventListener('touchcancel', function() {
      navTouchActive = false;
    });
  }

  document.addEventListener('mousemove', onImageMouseMove);
  document.addEventListener('mouseup', onImageMouseUp);

  // Trigger a resize/refit after entering or exiting fullscreen so the
  // renderer/pdf/image fit the restored modal dimensions.
  ['fullscreenchange', 'webkitfullscreenchange'].forEach(function(eventName) {
    document.addEventListener(eventName, function() {
      window.dispatchEvent(new Event('resize'));
      if (currentMode === 'image') imageFit();
      else if (currentMode === 'pdf') pdfFit();
    });
  });

  function getCsrfToken() {
    return window.csrfToken || ((document.querySelector('meta[name="csrf-token"]') || {}).content || '');
  }

  function fetchSignedUrl(storagePath, fileName) {
    return fetch('api/download-signed-url.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken()
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        storage_path: storagePath,
        filename: fileName || ''
      })
    })
    .then(function(response) {
      if (!response.ok) {
        return response.json().then(function(data) {
          throw new Error(data.error || data.message || t('attachments.viewer.download_url_failed'));
        });
      }
      return response.json();
    });
  }

  function fetchAttachmentContent(storagePath) {
    return fetch('api/attachment-content.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken()
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        storage_path: storagePath
      })
    })
    .then(function(response) {
      if (!response.ok) {
        return response.json().then(function(data) {
          throw new Error(data.error || data.message || t('attachments.viewer.unable_to_load'));
        }).catch(function() {
          throw new Error(t('attachments.viewer.unable_to_load'));
        });
      }
      return response.arrayBuffer();
    });
  }

  function downloadAttachment(storagePath, fileName) {
    if (!storagePath) return;
    fetchSignedUrl(storagePath, fileName)
      .then(function(data) {
        if (data.success && data.signed_url) {
          // The signed URL carries Content-Disposition: attachment, so a
          // programmatic navigation triggers the download in place without
          // a popup-blocked window.open or a discarded new tab.
          var a = document.createElement('a');
          a.href = data.signed_url;
          a.download = fileName || '';
          a.rel = 'noopener';
          document.body.appendChild(a);
          a.click();
          a.remove();
        } else {
          throw new Error(data.error || t('attachments.viewer.download_url_failed'));
        }
      })
      .catch(function(error) {
        if (typeof window.showToast === 'function') {
          window.showToast(t('attachments.download_failed', {message: error.message}), 'error');
        }
      });
  }

  function downloadCurrent() {
    if (!currentData) return;
    downloadAttachment(currentData.storagePath, currentData.fileName);
  }

  function setError(message) {
    if (loadingEl) loadingEl.style.display = 'none';
    if (errorEl) {
      if (errorTextEl) {
        errorTextEl.textContent = message || t('attachments.viewer.preview_failed');
      } else {
        errorEl.textContent = message || t('attachments.viewer.preview_failed');
      }
      // The header Download button is always present; mirror it inside the
      // error state so a failed preview still offers a clear way out.
      if (errorDownloadBtn) {
        errorDownloadBtn.style.display = currentData && currentData.storagePath ? 'inline-flex' : 'none';
      }
      errorEl.style.display = 'flex';
    }
  }

  function getCanvasSize() {
    let width = canvasContainer.clientWidth;
    let height = canvasContainer.clientHeight;
    if (width === 0 || height === 0) {
      width = canvasContainer.parentElement ? canvasContainer.parentElement.clientWidth : 640;
      height = canvasContainer.parentElement ? canvasContainer.parentElement.clientHeight : 480;
    }
    if (width === 0 || height === 0) {
      width = 640;
      height = 480;
    }
    return { width, height };
  }

  function setControlVisibility(mode) {
    const isImage = mode === 'image';
    const isPdf = mode === 'pdf';
    const is3d = mode === '3d';
    if (zoomInBtn) zoomInBtn.style.display = isImage || isPdf ? 'inline-flex' : 'none';
    if (zoomOutBtn) zoomOutBtn.style.display = isImage || isPdf ? 'inline-flex' : 'none';
    // Header prev/next are PDF *page* controls - separate from the
    // attachment-level navigation overlaid on the canvas.
    if (prevBtn) prevBtn.style.display = isPdf ? 'inline-flex' : 'none';
    if (nextBtn) nextBtn.style.display = isPdf ? 'inline-flex' : 'none';
    if (pageInfo) pageInfo.style.display = isPdf ? 'inline-block' : 'none';
    if (resetBtn) resetBtn.style.display = isImage || isPdf || is3d ? 'inline-flex' : 'none';
    if (fitBtn) fitBtn.style.display = isImage || isPdf || is3d ? 'inline-flex' : 'none';
  }

  function zoomIn() {
    if (currentMode === 'image') imageZoom(1.2);
    else if (currentMode === 'pdf') pdfZoom(1.2);
  }

  function zoomOut() {
    if (currentMode === 'image') imageZoom(0.8);
    else if (currentMode === 'pdf') pdfZoom(0.8);
  }

  // ------------------ 3D viewer ------------------

  function createNeutralMaterial() {
    return new THREE.MeshStandardMaterial({
      color: 0x94a3b8,
      metalness: 0.05,
      roughness: 0.35,
      flatShading: false,
      side: THREE.DoubleSide
    });
  }

  function init3DScene() {
    disposeViewer();
    currentMode = '3d';
    setControlVisibility('3d');

    const { width, height } = getCanvasSize();

    scene = new THREE.Scene();
    scene.background = new THREE.Color(0xf3f4f6);

    camera = new THREE.PerspectiveCamera(45, width / height || 1, 0.1, 10000);
    camera.position.set(0, 0, 5);

    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
    renderer.setSize(width, height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.domElement.tabIndex = -1;
    canvasContainer.appendChild(renderer.domElement);

    scene.add(new THREE.HemisphereLight(0xffffff, 0x475569, 1.2));

    const keyLight = new THREE.DirectionalLight(0xffffff, 1.2);
    keyLight.position.set(5, 10, 7);
    scene.add(keyLight);

    const fillLight = new THREE.DirectionalLight(0xffffff, 0.6);
    fillLight.position.set(-5, 0, -7);
    scene.add(fillLight);

    const rimLight = new THREE.DirectionalLight(0xffffff, 0.5);
    rimLight.position.set(0, -5, -2);
    scene.add(rimLight);

    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.05;
    controls.target.set(0, 0, 0);
    controls.update();

    window.addEventListener('resize', onWindowResize);
    animate();
  }

  function buildSTL(buffer) {
    const loader = new STLLoader();
    const geometry = loader.parse(buffer);
    if (!geometry || !geometry.attributes || !geometry.attributes.position) {
      throw new Error(t('attachments.viewer.invalid_stl'));
    }
    geometry.computeBoundingBox();
    const center = new THREE.Vector3();
    geometry.boundingBox.getCenter(center);
    geometry.translate(-center.x, -center.y, -center.z);

    const material = createNeutralMaterial();
    const mesh = new THREE.Mesh(geometry, material);
    scene.add(mesh);
    return mesh;
  }

  function buildOBJ(buffer) {
    const text = new TextDecoder().decode(buffer);
    const loader = new OBJLoader();
    const group = loader.parse(text);
    if (!group || !group.children || group.children.length === 0) {
      throw new Error(t('attachments.viewer.invalid_obj'));
    }

    const box = new THREE.Box3().setFromObject(group);
    const center = new THREE.Vector3();
    box.getCenter(center);
    group.position.sub(center);

    const neutralMaterial = createNeutralMaterial();
    group.traverse(function(child) {
      if (child.isMesh && child.geometry) {
        child.material = neutralMaterial;
        if (!child.geometry.attributes.normal) {
          child.geometry.computeVertexNormals();
        }
      }
    });

    scene.add(group);
    return group;
  }

  function buildPLY(buffer) {
    const loader = new PLYLoader();
    const geometry = loader.parse(buffer);
    if (!geometry || !geometry.attributes || !geometry.attributes.position) {
      throw new Error(t('attachments.viewer.invalid_ply'));
    }
    geometry.computeBoundingBox();
    const center = new THREE.Vector3();
    geometry.boundingBox.getCenter(center);
    geometry.translate(-center.x, -center.y, -center.z);
    geometry.computeVertexNormals();

    const hasColor = !!geometry.attributes.color;
    const material = hasColor
      ? new THREE.MeshStandardMaterial({
          vertexColors: true,
          metalness: 0.05,
          roughness: 0.35,
          flatShading: false,
          side: THREE.DoubleSide
        })
      : createNeutralMaterial();

    const mesh = new THREE.Mesh(geometry, material);
    scene.add(mesh);
    return mesh;
  }

  function build3DModel(buffer, ext) {
    if (ext === 'stl') return buildSTL(buffer);
    if (ext === 'obj') return buildOBJ(buffer);
    if (ext === 'ply') return buildPLY(buffer);
    throw new Error(t('attachments.viewer.unsupported_3d_format'));
  }

  function fitCameraToObject(object) {
    if (!camera || !controls || !object) return;
    const box = new THREE.Box3().setFromObject(object);
    const size = new THREE.Vector3();
    box.getSize(size);
    const center = new THREE.Vector3();
    box.getCenter(center);
    const maxDim = Math.max(size.x, size.y, size.z);

    const fov = camera.fov * (Math.PI / 180);
    const distance = maxDim > 0 ? (maxDim / 2) / Math.tan(fov / 2) * 1.8 : 5;
    camera.position.set(center.x, center.y, center.z + distance);
    camera.near = maxDim > 0 ? maxDim / 100 : 0.1;
    camera.far = maxDim > 0 ? maxDim * 100 : 1000;
    camera.updateProjectionMatrix();
    controls.target.copy(center);
    controls.minDistance = maxDim > 0 ? maxDim * 0.1 : 0.5;
    controls.maxDistance = maxDim > 0 ? maxDim * 100 : 1000;
    controls.update();
  }

  function render3D(buffer, ext) {
    console.log('render3D:', ext, buffer.byteLength, 'bytes');
    init3DScene();

    currentObject = build3DModel(buffer, ext);
    fitCameraToObject(currentObject);

    initialCamera = camera.position.clone();
    initialTarget = controls.target.clone();

    if (loadingEl) loadingEl.style.display = 'none';
    if (errorEl) errorEl.style.display = 'none';

    requestAnimationFrame(onWindowResize);
  }

  function onWindowResize() {
    if (!camera || !renderer || !canvasContainer) return;
    const { width, height } = getCanvasSize();
    if (width === 0 || height === 0) return;
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    renderer.setSize(width, height);
  }

  function animate() {
    if (!renderer) return;
    animationId = requestAnimationFrame(animate);
    if (controls) controls.update();
    renderer.render(scene, camera);
  }

  function disposeObject(obj, disposedMats) {
    if (!obj) return;
    if (obj.geometry) obj.geometry.dispose();
    if (obj.material) {
      const materials = Array.isArray(obj.material) ? obj.material : [obj.material];
      materials.forEach(function(m) {
        if (!m) return;
        if (!disposedMats.has(m)) {
          m.dispose();
          disposedMats.add(m);
        }
      });
    }
    if (obj.children) {
      obj.children.forEach(function(child) {
        disposeObject(child, disposedMats);
      });
    }
  }

  function dispose3D() {
    if (animationId) {
      cancelAnimationFrame(animationId);
      animationId = null;
    }
    window.removeEventListener('resize', onWindowResize);

    if (controls) {
      controls.dispose();
      controls = null;
    }

    if (currentObject) {
      if (scene) scene.remove(currentObject);
      disposeObject(currentObject, new Set());
      currentObject = null;
    }

    if (renderer) {
      renderer.dispose();
      if (renderer.domElement && renderer.domElement.parentNode) {
        renderer.domElement.parentNode.removeChild(renderer.domElement);
      }
      renderer = null;
    }

    scene = null;
    camera = null;
    initialCamera = null;
    initialTarget = null;
  }

  // ------------------ Image viewer ------------------

  function getImageMimeType(ext, fileType) {
    if (fileType && fileType.indexOf('image/') === 0) return fileType;
    return IMAGE_TYPE_MAP[ext] || 'image/jpeg';
  }

  function updateImageTransform() {
    if (!imageEl) return;
    imageEl.style.transform = 'translate(' + imagePanX + 'px, ' + imagePanY + 'px) scale(' + imageScale + ')';
  }

  function imageZoom(factor) {
    if (!imageEl) return;
    imageScale = Math.max(0.5, Math.min(5, imageScale * factor));
    updateImageTransform();
  }

  function imageFit() {
    imageScale = 1;
    imagePanX = 0;
    imagePanY = 0;
    updateImageTransform();
  }

  function getDistance(p1, p2) {
    const dx = p1.clientX - p2.clientX;
    const dy = p1.clientY - p2.clientY;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function onImageWheel(e) {
    if (!imageEl) return;
    e.preventDefault();
    if (e.deltaY < 0) imageZoom(1.1);
    else imageZoom(0.9);
  }

  function onImageMouseDown(e) {
    if (!imageEl) return;
    if (e.button !== 0) return;
    isImageDragging = true;
    imageDragStartX = e.clientX;
    imageDragStartY = e.clientY;
    imagePanStartX = imagePanX;
    imagePanStartY = imagePanY;
    if (imageStage) imageStage.classList.add('panning');
  }

  function onImageMouseMove(e) {
    if (!isImageDragging || !imageEl) return;
    imagePanX = imagePanStartX + (e.clientX - imageDragStartX);
    imagePanY = imagePanStartY + (e.clientY - imageDragStartY);
    updateImageTransform();
  }

  function onImageMouseUp() {
    isImageDragging = false;
    if (imageStage) imageStage.classList.remove('panning');
  }

  function onImageTouchStart(e) {
    if (!imageEl) return;
    e.preventDefault();

    if (e.touches.length === 1) {
      isImageDragging = true;
      imageDragStartX = e.touches[0].clientX;
      imageDragStartY = e.touches[0].clientY;
      imagePanStartX = imagePanX;
      imagePanStartY = imagePanY;
    } else if (e.touches.length === 2) {
      isImageDragging = false;
      imageInitialPinchDistance = getDistance(e.touches[0], e.touches[1]);
      imageInitialPinchScale = imageScale;
    }
  }

  function onImageTouchMove(e) {
    if (!imageEl) return;
    e.preventDefault();

    if (e.touches.length === 1 && isImageDragging) {
      imagePanX = imagePanStartX + (e.touches[0].clientX - imageDragStartX);
      imagePanY = imagePanStartY + (e.touches[0].clientY - imageDragStartY);
      updateImageTransform();
    } else if (e.touches.length === 2) {
      const newDistance = getDistance(e.touches[0], e.touches[1]);
      if (imageInitialPinchDistance > 0) {
        imageScale = Math.max(0.5, Math.min(5, imageInitialPinchScale * (newDistance / imageInitialPinchDistance)));
        updateImageTransform();
      }
    }
  }

  function onImageTouchEnd() {
    isImageDragging = false;
    imageInitialPinchDistance = 0;
  }

  function renderImage(buffer, fileName, fileType) {
    console.log('renderImage:', fileName, buffer.byteLength, 'bytes');
    disposeViewer();
    currentMode = 'image';
    setControlVisibility('image');

    const ext = (fileName || '').split('.').pop().toLowerCase();
    const mime = getImageMimeType(ext, fileType);
    const blob = new Blob([buffer], { type: mime });
    imageUrl = URL.createObjectURL(blob);

    imageStage = document.createElement('div');
    imageStage.className = 'attachment-viewer-image-stage';

    imageEl = document.createElement('img');
    imageEl.className = 'attachment-viewer-image';
    imageEl.src = imageUrl;
    imageEl.alt = fileName || t('attachments.viewer.attachment');
    imageEl.style.transform = 'translate(0px, 0px) scale(1)';

    imageScale = 1;
    imagePanX = 0;
    imagePanY = 0;

    imageEl.addEventListener('load', function() {
      if (loadingEl) loadingEl.style.display = 'none';
      if (errorEl) errorEl.style.display = 'none';
    });

    imageEl.addEventListener('error', function() {
      setError(t('attachments.viewer.display_image_error'));
    });

    imageStage.addEventListener('wheel', onImageWheel, { passive: false });
    imageStage.addEventListener('mousedown', onImageMouseDown);
    imageStage.addEventListener('touchstart', onImageTouchStart, { passive: false });
    imageStage.addEventListener('touchmove', onImageTouchMove, { passive: false });
    imageStage.addEventListener('touchend', onImageTouchEnd);
    imageStage.addEventListener('touchcancel', onImageTouchEnd);

    imageStage.appendChild(imageEl);
    canvasContainer.appendChild(imageStage);

    if (loadingEl) loadingEl.style.display = 'flex';
    if (errorEl) errorEl.style.display = 'none';
  }

  function disposeImage() {
    if (imageStage) {
      imageStage.removeEventListener('wheel', onImageWheel);
      imageStage.removeEventListener('mousedown', onImageMouseDown);
      imageStage.removeEventListener('touchstart', onImageTouchStart);
      imageStage.removeEventListener('touchmove', onImageTouchMove);
      imageStage.removeEventListener('touchend', onImageTouchEnd);
      imageStage.removeEventListener('touchcancel', onImageTouchEnd);
      imageStage = null;
    }
    imageEl = null;

    if (imageUrl) {
      URL.revokeObjectURL(imageUrl);
      imageUrl = null;
    }

    isImageDragging = false;
    imageInitialPinchDistance = 0;
  }

  // ------------------ PDF viewer ------------------

  function getPdfjsLib() {
    if (!window.pdfjsLib) {
      throw new Error(t('attachments.viewer.pdf_not_loaded'));
    }
    if (!pdfWorkerConfigured) {
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'js/pdfjs-worker.js';
      pdfWorkerConfigured = true;
    }
    return window.pdfjsLib;
  }

  function cancelPdfRender() {
    if (pdfRenderTask) {
      try { pdfRenderTask.cancel(); } catch (e) {}
      pdfRenderTask = null;
    }
  }

  function disposePdf() {
    cancelPdfRender();

    if (pdfResizeTimeout) {
      clearTimeout(pdfResizeTimeout);
      pdfResizeTimeout = null;
    }

    if (pdfDoc) {
      try { pdfDoc.destroy(); } catch (e) {}
      pdfDoc = null;
    }

    if (pdfLoadingTask) {
      try { pdfLoadingTask.destroy(); } catch (e) {}
      pdfLoadingTask = null;
    }

    pdfPageNum = 1;
    pdfNumPages = 0;
    pdfZoomScale = 1;
    pdfBaseScale = 1;
    pdfCanvas = null;
    pdfCtx = null;
    pdfStage = null;
  }

  function updatePdfPageInfo() {
    if (!pageInfo) return;
    if (pdfNumPages > 0) {
      pageInfo.textContent = t('attachments.viewer.page_info_of', {page: pdfPageNum, total: pdfNumPages});
    } else {
      pageInfo.textContent = t('attachments.viewer.page_info', {page: 1});
    }
    if (prevBtn) prevBtn.disabled = pdfPageNum <= 1;
    if (nextBtn) nextBtn.disabled = pdfPageNum >= pdfNumPages || pdfNumPages === 0;
  }

  function pdfZoom(factor) {
    if (!pdfDoc || pdfNumPages === 0) return;
    pdfZoomScale = Math.max(0.25, Math.min(4, pdfZoomScale * factor));
    pdfRenderCurrentPage();
  }

  function pdfFit() {
    pdfZoomScale = 1;
    pdfRenderCurrentPage();
  }

  function pdfRenderPage(num) {
    if (!pdfDoc) return;
    cancelPdfRender();

    if (num < 1 || num > pdfNumPages) return;
    pdfPageNum = num;
    updatePdfPageInfo();

    pdfDoc.getPage(num).then(function(page) {
      if (!pdfCanvas) return;

      const dpr = Math.min(window.devicePixelRatio, 2);
      const unscaledViewport = page.getViewport({ scale: 1 });

      // Calculate base scale to fit the page width within the container, leaving padding.
      const { width: containerWidth } = getCanvasSize();
      const padding = 40;
      const fitWidth = Math.max(containerWidth - padding, 1);
      const fitScale = fitWidth / Math.max(unscaledViewport.width, 1);
      pdfBaseScale = fitScale;

      const scale = pdfBaseScale * pdfZoomScale * dpr;
      const viewport = page.getViewport({ scale: scale });

      pdfCanvas.width = viewport.width;
      pdfCanvas.height = viewport.height;
      pdfCanvas.style.width = (viewport.width / dpr) + 'px';
      pdfCanvas.style.height = (viewport.height / dpr) + 'px';

      const renderContext = {
        canvasContext: pdfCtx,
        viewport: viewport,
        background: 'white'
      };

      pdfRenderTask = page.render(renderContext);
      pdfRenderTask.promise.then(function() {
        pdfRenderTask = null;
        if (loadingEl) loadingEl.style.display = 'none';
        if (errorEl) errorEl.style.display = 'none';
      }).catch(function(err) {
        if (err && err.name === 'RenderingCancelledException') return;
        pdfRenderTask = null;
        setError(t('attachments.viewer.pdf_render_error'));
      });
    }).catch(function(err) {
      console.error('PDF page load error:', err);
      setError(t('attachments.viewer.pdf_load_error', {message: err.message || t('common.unknown_error')}));
    });
  }

  function pdfRenderCurrentPage() {
    pdfRenderPage(pdfPageNum);
  }

  function pdfPrevPage() {
    if (pdfPageNum > 1) {
      pdfPageNum--;
      updatePdfPageInfo();
      pdfRenderCurrentPage();
    }
  }

  function pdfNextPage() {
    if (pdfPageNum < pdfNumPages) {
      pdfPageNum++;
      updatePdfPageInfo();
      pdfRenderCurrentPage();
    }
  }

  function onPdfResize() {
    if (currentMode !== 'pdf' || !pdfDoc) return;
    if (pdfResizeTimeout) clearTimeout(pdfResizeTimeout);
    pdfResizeTimeout = setTimeout(function() {
      pdfRenderCurrentPage();
    }, 150);
  }

  function renderPdf(buffer) {
    console.log('renderPdf:', buffer.byteLength, 'bytes');
    disposeViewer();
    currentMode = 'pdf';
    setControlVisibility('pdf');

    const lib = getPdfjsLib();
    const uint8 = new Uint8Array(buffer);

    pdfLoadingTask = lib.getDocument({ data: uint8 });
    pdfLoadingTask.promise.then(function(doc) {
      pdfDoc = doc;
      pdfNumPages = doc.numPages || 0;
      pdfPageNum = 1;
      pdfZoomScale = 1;
      pdfBaseScale = 1;

      pdfStage = document.createElement('div');
      pdfStage.className = 'attachment-viewer-pdf-stage';

      pdfCanvas = document.createElement('canvas');
      pdfCanvas.className = 'attachment-viewer-pdf-canvas';
      pdfCtx = pdfCanvas.getContext('2d');

      pdfStage.appendChild(pdfCanvas);
      canvasContainer.appendChild(pdfStage);

      window.addEventListener('resize', onPdfResize);

      updatePdfPageInfo();
      pdfRenderCurrentPage();
    }).catch(function(err) {
      console.error('PDF load error:', err);
      const msg = err && err.name === 'PasswordException'
        ? t('attachments.viewer.pdf_password_protected')
        : t('attachments.viewer.pdf_load_failed', {message: err.message || t('attachments.viewer.unable_to_load')});
      setError(msg);
    });
  }

  // ------------------ Shared lifecycle ------------------

  function disposeViewer() {
    disposeImage();
    disposePdf();
    dispose3D();
    currentMode = null;
    if (canvasContainer) canvasContainer.innerHTML = '';
  }

  function closeViewer() {
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
    document.documentElement.style.overflow = '';
    disposeViewer();
    const fsElement = document.fullscreenElement || document.webkitFullscreenElement;
    if (fsElement) {
      const exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (typeof exit === 'function') {
        try { exit.call(document); } catch (e) {}
      }
    }
    currentData = null;
    attachmentList = [];
    attachmentIndex = 0;
    attachmentLoadSeq++;
    navTouchActive = false;
  }

  function toggleFullscreen() {
    if (!modal) return;
    const fsElement = document.fullscreenElement || document.webkitFullscreenElement;
    if (!fsElement) {
      const request = modal.requestFullscreen || modal.webkitRequestFullscreen;
      if (typeof request === 'function') {
        request.call(modal).catch(function(err) {
          if (typeof window.showToast === 'function') {
            window.showToast(t('attachments.viewer.fullscreen_not_supported', {message: err.message}), 'error');
          }
        });
      }
    } else {
      const exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (typeof exit === 'function') exit.call(document);
    }
  }

  function resetView() {
    if (currentMode === 'image') imageFit();
    else if (currentMode === 'pdf') pdfFit();
    else if (camera && controls && initialCamera && initialTarget) {
      camera.position.copy(initialCamera);
      controls.target.copy(initialTarget);
      controls.update();
    }
  }

  function fitToView() {
    if (currentMode === 'image') imageFit();
    else if (currentMode === 'pdf') pdfFit();
    else fitCameraToObject(currentObject);
  }

  /**
   * Render the empty-state for a file type the viewer cannot display. The
   * attachment stays in the navigation sequence - the user can keep moving
   * through the set or download the file from here.
   */
  function renderUnsupported() {
    disposeViewer();
    currentMode = 'unsupported';
    setControlVisibility('unsupported');

    const stage = document.createElement('div');
    stage.className = 'attachment-viewer-unsupported';

    const icon = document.createElement('div');
    icon.className = 'attachment-viewer-unsupported-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';

    const name = document.createElement('div');
    name.className = 'attachment-viewer-unsupported-name';
    name.textContent = currentData.fileName || t('attachments.viewer.attachment');

    const msg = document.createElement('p');
    msg.className = 'attachment-viewer-unsupported-msg';
    msg.textContent = t('attachments.viewer.preview_unavailable_toast');

    const dl = document.createElement('button');
    dl.type = 'button';
    dl.className = 'attachment-viewer-btn';
    dl.textContent = t('attachments.viewer.download_file');
    dl.addEventListener('click', downloadCurrent);

    stage.appendChild(icon);
    stage.appendChild(name);
    stage.appendChild(msg);
    stage.appendChild(dl);
    canvasContainer.appendChild(stage);

    if (loadingEl) loadingEl.style.display = 'none';
    if (errorEl) errorEl.style.display = 'none';
  }

  /** Reflect the current position in the header and nav buttons. */
  function updateNavState() {
    const total = attachmentList.length;
    if (positionEl) {
      if (total > 1) {
        positionEl.textContent = t('attachments.viewer.attachment_position', {
          current: attachmentIndex + 1,
          total: total
        });
        positionEl.style.display = '';
      } else {
        positionEl.style.display = 'none';
      }
    }
    const showNav = total > 1;
    if (navPrevBtn) {
      navPrevBtn.style.display = showNav ? 'flex' : 'none';
      navPrevBtn.disabled = attachmentIndex <= 0;
    }
    if (navNextBtn) {
      navNextBtn.style.display = showNav ? 'flex' : 'none';
      navNextBtn.disabled = attachmentIndex >= total - 1;
    }
  }

  /**
   * Load and render attachmentList[attachmentIndex]. Clears the previous
   * attachment first so nothing stale shows while the fetch is in flight.
   */
  function loadCurrentAttachment() {
    const item = attachmentList[attachmentIndex];
    if (!item) return;

    const ext = (item.fileName || '').split('.').pop().toLowerCase();
    currentData = {
      storagePath: item.storagePath,
      fileName: item.fileName,
      fileType: item.fileType,
      ext: ext
    };

    titleEl.textContent = item.fileName || t('attachments.viewer.attachment');
    typeEl.textContent = ext.toUpperCase();
    updateNavState();

    // disposeViewer() clears the canvas and resets image zoom/pan, PDF
    // zoom/page state, and any 3D scene - each attachment opens fresh.
    disposeViewer();
    setControlVisibility(null); // hide per-mode controls while loading
    if (errorEl) errorEl.style.display = 'none';

    if (!SUPPORTED_TYPES[ext]) {
      renderUnsupported();
      return;
    }

    loadingEl.style.display = 'flex';
    const seq = ++attachmentLoadSeq;

    fetchAttachmentContent(item.storagePath)
      .then(function(buffer) {
        if (seq !== attachmentLoadSeq) return; // user already moved on
        if (SUPPORTED_IMAGE[ext]) {
          renderImage(buffer, item.fileName, item.fileType);
        } else if (SUPPORTED_PDF[ext]) {
          renderPdf(buffer);
        } else {
          render3D(buffer, ext);
        }
      })
      .catch(function(error) {
        if (seq !== attachmentLoadSeq) return;
        console.error('Attachment viewer error:', error);
        // Keep the viewer open: the error state offers Download and the
        // attachment nav controls remain usable.
        setError(error.message || t('attachments.viewer.preview_failed'));
      });
  }

  /** Move to the previous (-1) or next (1) attachment, clamped at the ends. */
  function navigateAttachment(delta) {
    const target = attachmentIndex + delta;
    if (target < 0 || target >= attachmentList.length) return;
    attachmentIndex = target;
    loadCurrentAttachment();
  }

  /**
   * Open the viewer.
   *   openAttachmentViewer(storagePath, fileName, fileType)
   *   openAttachmentViewer(storagePath, fileName, fileType, attachments)
   * `attachments` is the ordered list for the case
   * ([{storagePath, fileName, fileType}]); the entry matching storagePath
   * (falling back to fileName) becomes the initial position. When omitted,
   * the viewer represents just the one file and navigation is hidden.
   */
  window.openAttachmentViewer = function(storagePath, fileName, fileType, attachments) {
    if (!modal || !titleEl || !typeEl || !canvasContainer || !loadingEl) {
      if (typeof window.showToast === 'function') {
        window.showToast(t('attachments.viewer.not_ready'), 'error');
      }
      return;
    }

    if (Array.isArray(attachments) && attachments.length > 0) {
      attachmentList = attachments.filter(function(a) { return a && a.storagePath; });
    } else {
      attachmentList = [{ storagePath: storagePath, fileName: fileName, fileType: fileType }];
    }

    attachmentIndex = attachmentList.findIndex(function(a) {
      return a.storagePath === storagePath;
    });
    if (attachmentIndex < 0 && fileName) {
      attachmentIndex = attachmentList.findIndex(function(a) { return a.fileName === fileName; });
    }
    if (attachmentIndex < 0) attachmentIndex = 0;

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';

    loadCurrentAttachment();
  };

  window.isAttachmentViewable = function(fileName) {
    return !!SUPPORTED_TYPES[(fileName || '').split('.').pop().toLowerCase()];
  };

  /**
   * Fetch an attachment's bytes through the authorized content endpoint and
   * return a blob object URL suitable for <img> thumbnails. Rejects on
   * failure so callers can render their own fallback. Callers own the
   * returned URL and must revokeObjectURL() it when done.
   */
  window.getAttachmentObjectUrl = function(storagePath) {
    return fetchAttachmentContent(storagePath).then(function(buffer) {
      return URL.createObjectURL(new Blob([buffer]));
    });
  };
})();
