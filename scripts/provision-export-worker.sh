#!/bin/bash
# ===========================================
# Provision the dedicated data-export Cloud Run Job (idempotent)
# ===========================================
# Creates/updates `dtk-export-worker`, a Cloud Run Job that runs
# `php api/export-worker.php` against the same image, service account,
# VPC connector, and Cloud SQL attachment as dtk-app-prod. Environment
# variables are copied from the running service: plain values via a
# temporary --env-vars-file (never echoed into build logs) and secret
# references via --set-secrets.
#
# Also provisions:
#   - IAM: roles/run.invoker on the job for the app service account so
#     api/data-export-v2.php can wake the job via the Run jobs:run API.
#   - Cloud Scheduler `dtk-prod-export-worker-sweep` (every 15 min) that
#     executes the job in --sweep-only mode: expires artifacts, recovers
#     stale claims, fails abandoned rows, and drains any queued exports
#     whose dispatch failed. This is the guaranteed recovery path; the
#     per-request jobs:run kick is only a latency optimization.
#
# Minimum IAM required:
#   - Job service account (compute SA today): roles/cloudsql.client,
#     storage read/write on the app bucket (objects under
#     practice-exports/), secretAccessor on every secret mounted via
#     --set-secrets.
#   - App service account: roles/run.invoker on dtk-export-worker only
#     (granted below) so api/data-export-v2.php can call jobs:run.
#   - Cloud Scheduler service agent: roles/iam.serviceAccountTokenCreator
#     on the OAuth service account (best-effort grant below).
# A dedicated service account for the job is recommended future
# hardening; the compute SA is used today to match dtk-app-prod.
#
# Usage: ./scripts/provision-export-worker.sh
# Requires: gcloud authenticated, project dtk-prod-core, python3, gsutil.
# ===========================================

set -e
set -o pipefail

PROJECT="dtk-prod-core"
REGION="us-east1"
SERVICE="dtk-app-prod"
JOB_NAME="dtk-export-worker"
SCHEDULER_NAME="dtk-prod-export-worker-sweep"
SERVICE_ACCOUNT="1029275239454-compute@developer.gserviceaccount.com"
SCHEDULE="*/15 * * * *"
TIME_ZONE="America/New_York"

command -v python3 >/dev/null || { echo "python3 is required"; exit 1; }

IMAGE=$(gcloud run services describe "$SERVICE" \
    --region "$REGION" --project "$PROJECT" \
    --format='value(spec.template.spec.containers[0].image)')
[ -n "$IMAGE" ] || { echo "Could not resolve image for ${SERVICE}"; exit 1; }
echo "Using image: ${IMAGE}"

# ---------- Build env file + secrets list from the live service ----------
SERVICE_JSON=$(gcloud run services describe "$SERVICE" \
    --region "$REGION" --project "$PROJECT" --format=json)

ENV_FILE=$(mktemp /tmp/export-worker-env.XXXXXX.yaml)
chmod 600 "$ENV_FILE"
trap 'rm -f "$ENV_FILE"' EXIT

SECRETS_CSV=$(SERVICE_JSON="$SERVICE_JSON" python3 - "$ENV_FILE" <<'PY'
import json, os, sys
svc = json.loads(os.environ["SERVICE_JSON"])
container = svc["spec"]["template"]["spec"]["containers"][0]
env = container.get("env", [])
plain, secrets = [], []
for e in env:
    name = e.get("name")
    if not name:
        continue
    vfk = e.get("valueFrom", {}).get("secretKeyRef")
    if vfk:
        secrets.append(f"{name}={vfk['name']}:{vfk.get('key','latest')}")
    elif "value" in e:
        plain.append((name, e["value"]))
with open(sys.argv[1], "w") as f:
    for k, v in plain:
        # YAML double-quoted scalar; escape backslash and quote.
        f.write(f'{k}: "{str(v).replace(chr(92), chr(92)*2).replace(chr(34), chr(92)+chr(34))}"\n')
print(",".join(secrets))
PY
)

echo "Copied $(grep -c ':' "$ENV_FILE" || true) plain env vars; $( [ -n "$SECRETS_CSV" ] && echo yes || echo no ) secret refs."

# ---------- Deploy / update the job ----------
DEPLOY_ARGS=(
    run jobs deploy "$JOB_NAME"
    --image "$IMAGE"
    --region "$REGION"
    --project "$PROJECT"
    --command php
    --args "api/export-worker.php,--limit=10"
    --service-account "$SERVICE_ACCOUNT"
    --vpc-connector "dtk-vpc-prod"
    --set-cloudsql-instances "dtk-prod-core:us-east1:dtk-sql-prod"
    --tasks 1
    --parallelism 1
    --max-retries 0
    --task-timeout 1800s
    --memory 1Gi
    --cpu 1
    --env-vars-file "$ENV_FILE"
    --quiet
)
if [ -n "$SECRETS_CSV" ]; then
    DEPLOY_ARGS+=(--set-secrets "$SECRETS_CSV")
fi

gcloud "${DEPLOY_ARGS[@]}"

# ---------- App service: job name for best-effort dispatch kicks ----------
# EXPORT_RUN_JOB lets api/data-export-v2.php wake the job via jobs:run.
# Without it requests still queue safely - the 15-min scheduler sweep is
# the guaranteed drain. This is an env-only service update.
gcloud run services update "$SERVICE" \
    --region "$REGION" --project "$PROJECT" \
    --update-env-vars "EXPORT_RUN_JOB=${JOB_NAME}" --quiet

# ---------- IAM: let the app service account run the job ----------
gcloud run jobs add-iam-policy-binding "$JOB_NAME" \
    --region "$REGION" --project "$PROJECT" \
    --member "serviceAccount:${SERVICE_ACCOUNT}" \
    --role "roles/run.invoker" --quiet

# ---------- Scheduler: periodic sweep + queue drain ----------
RUN_URI="https://run.googleapis.com/v2/projects/${PROJECT}/locations/${REGION}/jobs/${JOB_NAME}:run"
# Empty overrides: run the job's default args (sweep + drain up to 10
# queued exports). This is the guaranteed recovery path when the
# per-request jobs:run kick fails or a worker dies mid-export.
SCHED_BODY='{}'
PROJECT_NUMBER=$(gcloud projects describe "$PROJECT" --format='value(projectNumber)')
SCHED_SA="service-${PROJECT_NUMBER}@gcp-sa-cloudscheduler.iam.gserviceaccount.com"
# Best-effort: scheduler service agent needs token-creator on the OAuth SA.
gcloud iam service-accounts add-iam-policy-binding "$SERVICE_ACCOUNT" \
    --project "$PROJECT" \
    --member "serviceAccount:${SCHED_SA}" \
    --role "roles/iam.serviceAccountTokenCreator" --quiet 2>/dev/null \
    || echo "WARN: could not grant token-creator to ${SCHED_SA}; scheduler OAuth may fail until granted manually."

if gcloud scheduler jobs describe "$SCHEDULER_NAME" \
    --location "$REGION" --project "$PROJECT" >/dev/null 2>&1; then
    echo "Scheduler job ${SCHEDULER_NAME} exists; updating."
    gcloud scheduler jobs update http "$SCHEDULER_NAME" \
        --location "$REGION" --project "$PROJECT" \
        --schedule "$SCHEDULE" --time-zone "$TIME_ZONE" \
        --uri "$RUN_URI" --http-method POST \
        --oauth-service-account-email "$SERVICE_ACCOUNT" \
        --oauth-token-scope "https://www.googleapis.com/auth/cloud-platform" \
        --message-body "$SCHED_BODY" \
        --headers "Content-Type=application/json" \
        --attempt-deadline 60s \
        --max-retry-attempts 1 \
        --quiet
else
    echo "Scheduler job ${SCHEDULER_NAME} missing; creating."
    gcloud scheduler jobs create http "$SCHEDULER_NAME" \
        --location "$REGION" --project "$PROJECT" \
        --schedule "$SCHEDULE" --time-zone "$TIME_ZONE" \
        --uri "$RUN_URI" --http-method POST \
        --oauth-service-account-email "$SERVICE_ACCOUNT" \
        --oauth-token-scope "https://www.googleapis.com/auth/cloud-platform" \
        --message-body "$SCHED_BODY" \
        --headers "Content-Type=application/json" \
        --attempt-deadline 60s \
        --max-retry-attempts 1 \
        --quiet
fi

# ---------- GCS lifecycle: expire practice-exports/ artifacts ----------
# Backstop for the worker sweeper: delete export artifacts 8 days after
# upload (exports expire at 7 days). Existing bucket rules are preserved -
# we read the current lifecycle config, append our rule if absent, and
# write the merged result back.
BUCKET_NAME=$(SERVICE_JSON="$SERVICE_JSON" python3 -c '
import json, os
env = json.loads(os.environ["SERVICE_JSON"])["spec"]["template"]["spec"]["containers"][0].get("env", [])
print(next((e.get("value", "") for e in env if e.get("name") == "GCS_BUCKET_NAME"), ""))
')
if [ -n "$BUCKET_NAME" ]; then
    LIFECYCLE_FILE=$(mktemp /tmp/export-lifecycle.XXXXXX.json)
    chmod 600 "$LIFECYCLE_FILE"
    trap 'rm -f "$ENV_FILE" "$LIFECYCLE_FILE"' EXIT
    gsutil lifecycle get "gs://${BUCKET_NAME}" > "$LIFECYCLE_FILE" 2>/dev/null \
        || printf '{"lifecycle":{"rule":[]}}\n' > "$LIFECYCLE_FILE"
    if BUCKET_LC="$LIFECYCLE_FILE" python3 - <<'PY'
import json, os, sys
path = os.environ["BUCKET_LC"]
try:
    cfg = json.load(open(path))
except Exception:
    cfg = {"lifecycle": {"rule": []}}
rules = cfg.setdefault("lifecycle", {}).setdefault("rule", [])
new_rule = {"action": {"type": "Delete"},
            "condition": {"age": 8, "matchesPrefix": ["practice-exports/"]}}
for r in rules:
    c = r.get("condition", {})
    if r.get("action", {}).get("type") == "Delete" and c.get("matchesPrefix") == ["practice-exports/"]:
        sys.exit(0)  # already present
rules.append(new_rule)
json.dump(cfg, open(path, "w"))
PY
    then
        if gsutil lifecycle set "$LIFECYCLE_FILE" "gs://${BUCKET_NAME}" 2>/dev/null; then
            echo "GCS lifecycle rule ensured for gs://${BUCKET_NAME} (practice-exports/, 8d)."
        else
            echo "WARN: could not set GCS lifecycle rule on gs://${BUCKET_NAME}; sweeper remains the cleanup path."
        fi
    else
        echo "WARN: could not merge GCS lifecycle config for gs://${BUCKET_NAME}."
    fi
    rm -f "$LIFECYCLE_FILE"
else
    echo "WARN: GCS_BUCKET_NAME not found on ${SERVICE}; skipping lifecycle rule."
fi

echo "Done. Verify with:"
echo "  gcloud run jobs describe ${JOB_NAME} --region ${REGION} --project ${PROJECT}"
echo "  gcloud scheduler jobs describe ${SCHEDULER_NAME} --location ${REGION} --project ${PROJECT}"
