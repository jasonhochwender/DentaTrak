#!/bin/bash
# =====================================================
# GCS Bucket CORS Configuration Script
# =====================================================
# Run this ONCE to configure CORS on your GCS bucket.
# This allows the browser to upload files directly to GCS
# using signed PUT URLs.
#
# Usage:
#   bash scripts/setup-gcs-cors.sh
#
# Or run the gcloud command manually:
#   gcloud storage buckets update gs://$GCS_BUCKET_NAME --cors-file=gcs-cors.json
# =====================================================

if [ -z "$GCS_BUCKET_NAME" ]; then
    echo "ERROR: GCS_BUCKET_NAME environment variable is not set."
    echo "Usage: GCS_BUCKET_NAME=dtk-prod-case-files bash scripts/setup-gcs-cors.sh"
    exit 1
fi

BUCKET_NAME="$GCS_BUCKET_NAME"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CORS_FILE="$SCRIPT_DIR/../gcs-cors.json"

echo "Setting CORS configuration on gs://$BUCKET_NAME ..."
echo "Using CORS file: $CORS_FILE"

gcloud storage buckets update "gs://$BUCKET_NAME" --cors-file="$CORS_FILE"

if [ $? -eq 0 ]; then
    echo ""
    echo "CORS configured successfully!"
    echo ""
    echo "Verify with:"
    echo "  gcloud storage buckets describe gs://$BUCKET_NAME --format='json(cors)'"
else
    echo ""
    echo "ERROR: Failed to set CORS. Make sure:"
    echo "  1. You are authenticated: gcloud auth login"
    echo "  2. The bucket exists: gs://$BUCKET_NAME"
    echo "  3. You have storage.buckets.update permission"
fi
