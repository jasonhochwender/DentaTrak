#!/bin/bash
# ===========================================
# Provision the integration event worker schedule (idempotent)
# ===========================================
# Creates (or updates, preserving auth headers) the Cloud Scheduler job that
# POSTs to /api/integration-event-worker.php on dtk-app-prod every minute.
#
# Auth uses the shared QUEUE_WORKER_TOKEN header pattern; the token value is
# read from Secret Manager (dtk-prod-queue-worker-token) and never printed.
#
# Usage: ./scripts/provision-integration-worker.sh
# Requires: gcloud authenticated, project dtk-prod-core.
# ===========================================

set -e
set -o pipefail

PROJECT="dtk-prod-core"
REGION="us-east1"
JOB_NAME="dtk-prod-integration-event-worker"
SERVICE_HOST="dtk-app-prod-1029275239454.us-east1.run.app"
URI="https://${SERVICE_HOST}/api/integration-event-worker.php"
SCHEDULE="* * * * *"
TIME_ZONE="America/New_York"
SECRET="dtk-prod-queue-worker-token"

if gcloud scheduler jobs describe "$JOB_NAME" \
    --location "$REGION" --project "$PROJECT" >/dev/null 2>&1; then
    echo "Scheduler job ${JOB_NAME} exists; updating schedule/URI and refreshing auth header."
    # Refresh the header too - if QUEUE_WORKER_TOKEN rotated, a preserved
    # stale header would make every tick fail auth until fixed manually.
    TOKEN=$(gcloud secrets versions access latest \
        --secret "$SECRET" --project "$PROJECT")
    gcloud scheduler jobs update http "$JOB_NAME" \
        --location "$REGION" \
        --project "$PROJECT" \
        --schedule "$SCHEDULE" \
        --time-zone "$TIME_ZONE" \
        --uri "$URI" \
        --http-method POST \
        --attempt-deadline 60s \
        --update-headers "X-Queue-Worker-Token=${TOKEN}" \
        --quiet
    unset TOKEN
else
    echo "Scheduler job ${JOB_NAME} missing; creating."
    TOKEN=$(gcloud secrets versions access latest \
        --secret "$SECRET" --project "$PROJECT")
    gcloud scheduler jobs create http "$JOB_NAME" \
        --location "$REGION" \
        --project "$PROJECT" \
        --schedule "$SCHEDULE" \
        --time-zone "$TIME_ZONE" \
        --uri "$URI" \
        --http-method POST \
        --headers "X-Queue-Worker-Token=${TOKEN},User-Agent=Google-Cloud-Scheduler" \
        --attempt-deadline 60s \
        --max-retry-attempts 2 \
        --min-backoff 10s \
        --max-backoff 3600s \
        --max-doublings 5 \
        --quiet
    unset TOKEN
fi

echo "Done. Verify with:"
echo "  gcloud scheduler jobs describe ${JOB_NAME} --location ${REGION} --project ${PROJECT}"
