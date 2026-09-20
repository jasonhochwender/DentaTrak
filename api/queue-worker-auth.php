<?php
/**
 * Shared queue-worker token authentication.
 *
 * Validates the X-Queue-Worker-Token request header against the QUEUE_WORKER_TOKEN
 * process environment variable. Exits with a JSON 401/403 on failure.
 */

function requireQueueWorkerToken(): void {
    // getenv() covers real env vars (Cloud Run secretKeyRef); getEnvVar()
    // additionally resolves $_ENV so local .env development works too.
    $workerToken = getEnvVar('QUEUE_WORKER_TOKEN') ?? '';

    $submittedToken = $_SERVER['HTTP_X_QUEUE_WORKER_TOKEN'] ?? '';

    if ($submittedToken === '') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($workerToken === '' || !hash_equals($workerToken, $submittedToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}
