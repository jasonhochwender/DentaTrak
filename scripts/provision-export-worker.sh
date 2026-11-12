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
#   - IAM: custom role dtkExportJobRunner (run.jobs.run +
#     run.jobs.runWithOverrides - overrides are REQUIRED because
#     api/data-export-v2.php dispatches with an EXPORT_ID container
#     override; roles/run.invoker alone cannot do that) bound to the
#     app service account on dtk-export-worker only.
#   - Cloud Scheduler `dtk-prod-export-worker-sweep` (every 15 min) that
#     executes the job with EMPTY overrides (sweep + drain). Its OAuth
#     identity is the same compute SA, so the custom role's run.jobs.run
#     covers it; the scheduler service agent additionally needs
#     iam.serviceAccountTokenCreator on that SA - granted below and
#     treated as FATAL if it cannot be applied, because without it the
#     guaranteed cleanup/drain path silently never runs.
#
# This script must run BEFORE the new app revision receives traffic
# (cloudbuild orders it that way): every step is set -e fatal, so a
# provisioning failure aborts the build before promotion.
#
# Minimum IAM required:
#   - Job service account (compute SA today): roles/cloudsql.client,
#     storage read/write on the app bucket (objects under
#     practice-exports/), secretAccessor on every secret mounted via
#     --set-secrets.
#   - App service account: dtkExportJobRunner on dtk-export-worker only
#     (granted below) for jobs:run incl. EXPORT_ID overrides.
#   - Cloud Scheduler service agent: roles/iam.serviceAccountTokenCreator
#     on the OAuth service account (required grant below).
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

# python3 is used for JSON/YAML wrangling below. The cloud-sdk build
# image ships it only inside the bundled SDK runtime (not on PATH), so
# fall back to that location before giving up.
PYTHON_BIN="$(command -v python3 || command -v python || true)"
if [ -z "$PYTHON_BIN" ] && [ -x /usr/lib/google-cloud-sdk/platform/bundledpythonunix/bin/python3 ]; then
    PYTHON_BIN=/usr/lib/google-cloud-sdk/platform/bundledpythonunix/bin/python3
fi
[ -n "$PYTHON_BIN" ] || { echo "python3 is required"; exit 1; }

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

SECRETS_CSV=$(SERVICE_JSON="$SERVICE_JSON" "$PYTHON_BIN" - "$ENV_FILE" <<'PY'
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

# ---------- IAM: job runner role with override permission ----------
# jobs:run WITH container overrides (EXPORT_ID) requires
# run.jobs.runWithOverrides. No predefined role grants that narrowly,
# so we maintain a purpose-built custom role and bind it to the job
# resource only. run.jobs.run (no overrides) is included so the same
# binding also authorizes the scheduler's plain jobs:run calls - the
# scheduler authenticates as this same service account.
CUSTOM_ROLE_ID="dtkExportJobRunner"
CUSTOM_ROLE="projects/${PROJECT}/roles/${CUSTOM_ROLE_ID}"
if ! gcloud iam roles describe "$CUSTOM_ROLE_ID" --project "$PROJECT" >/dev/null 2>&1; then
    echo "Creating custom role ${CUSTOM_ROLE_ID}."
    gcloud iam roles create "$CUSTOM_ROLE_ID" \
        --project "$PROJECT" \
        --title "DentaTrak Export Job Runner" \
        --description "Allows jobs:run on dtk-export-worker including EXPORT_ID container overrides" \
        --permissions "run.jobs.run,run.jobs.runWithOverrides" \
        --stage GA --quiet
fi

gcloud run jobs add-iam-policy-binding "$JOB_NAME" \
    --region "$REGION" --project "$PROJECT" \
    --member "serviceAccount:${SERVICE_ACCOUNT}" \
    --role "$CUSTOM_ROLE" --quiet

# NOTE: EXPORT_RUN_JOB is set on the app service at deploy time by
# cloudbuild.yaml (--update-env-vars on the staged revision), NOT here -
# a `gcloud run services update` would create and promote a new
# revision outside the staged-verify-promote flow.

# ---------- Scheduler: periodic sweep + queue drain ----------
RUN_URI="https://run.googleapis.com/v2/projects/${PROJECT}/locations/${REGION}/jobs/${JOB_NAME}:run"
# Empty overrides: run the job's default args (sweep + drain up to 10
# queued exports). This is the guaranteed recovery path when the
# per-request jobs:run kick fails or a worker dies mid-export.
SCHED_BODY='{}'
PROJECT_NUMBER=$(gcloud projects describe "$PROJECT" --format='value(projectNumber)')
SCHED_SA="service-${PROJECT_NUMBER}@gcp-sa-cloudscheduler.iam.gserviceaccount.com"
# REQUIRED: without token-creator on the OAuth SA the scheduler cannot
# mint tokens and the sweep/drain path silently never executes. Fail
# the build loudly rather than warn.
if ! gcloud iam service-accounts add-iam-policy-binding "$SERVICE_ACCOUNT" \
    --project "$PROJECT" \
    --member "serviceAccount:${SCHED_SA}" \
    --role "roles/iam.serviceAccountTokenCreator" --quiet; then
    echo "FATAL: could not grant iam.serviceAccountTokenCreator on ${SERVICE_ACCOUNT} to ${SCHED_SA}." >&2
    echo "The export sweep scheduler would be unable to authenticate; aborting before traffic promotion." >&2
    exit 1
fi

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
BUCKET_NAME=$(SERVICE_JSON="$SERVICE_JSON" "$PYTHON_BIN" -c '
import json, os
env = json.loads(os.environ["SERVICE_JSON"])["spec"]["template"]["spec"]["containers"][0].get("env", [])
print(next((e.get("value", "") for e in env if e.get("name") == "GCS_BUCKET_NAME"), ""))
')
if [ -z "$BUCKET_NAME" ]; then
    echo "FATAL: GCS_BUCKET_NAME not found on ${SERVICE}; export artifacts have nowhere to live." >&2
    exit 1
fi

LIFECYCLE_FILE=$(mktemp /tmp/export-lifecycle.XXXXXX.json)
chmod 600 "$LIFECYCLE_FILE"
trap 'rm -f "$ENV_FILE" "$LIFECYCLE_FILE"' EXIT
if ! gsutil lifecycle get "gs://${BUCKET_NAME}" > "$LIFECYCLE_FILE" 2>/dev/null; then
    printf '{"lifecycle":{"rule":[]}}\n' > "$LIFECYCLE_FILE"
fi
if BUCKET_LC="$LIFECYCLE_FILE" "$PYTHON_BIN" - <<'PY'
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
        # The lifecycle backstop is part of the export contract (8-day
        # artifact deletion). If it cannot be applied the release is
        # operationally incomplete - fail before traffic promotion.
        if ! gsutil lifecycle set "$LIFECYCLE_FILE" "gs://${BUCKET_NAME}"; then
            echo "FATAL: could not set GCS lifecycle rule on gs://${BUCKET_NAME}." >&2
            exit 1
        fi
        echo "GCS lifecycle rule ensured for gs://${BUCKET_NAME} (practice-exports/, 8d)."
    else
        echo "FATAL: could not merge GCS lifecycle config for gs://${BUCKET_NAME}." >&2
        exit 1
    fi
rm -f "$LIFECYCLE_FILE"

# ---------- Post-provision verification (must succeed pre-promotion) ----------
gcloud run jobs describe "$JOB_NAME" \
    --region "$REGION" --project "$PROJECT" --format='value(name)' >/dev/null
gcloud scheduler jobs describe "$SCHEDULER_NAME" \
    --location "$REGION" --project "$PROJECT" --format='value(name)' >/dev/null
gcloud run jobs get-iam-policy "$JOB_NAME" \
    --region "$REGION" --project "$PROJECT" --format=json | \
    "$PYTHON_BIN" -c 'import json,sys; p=json.load(sys.stdin); print("job IAM bindings:", len(p.get("bindings",[])))'
echo "Export worker provisioning verified: job, IAM, scheduler, lifecycle."

echo "Done. The app revision may now be promoted."
