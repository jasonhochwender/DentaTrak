#!/bin/bash
# ===========================================
# Production Deployment Script for Dentatrak
# ===========================================
# Usage: ./scripts/deploy.sh user@server /var/www/dentatrak
# ===========================================
#
# IMPORTANT: This script will NOT deploy if tests fail.
# Tests are a mandatory gate - no overrides allowed.
# ===========================================

set -e
set -o pipefail

# Validate arguments
if [ -z "$1" ] || [ -z "$2" ]; then
    echo "Usage: $0 <user@server> <remote_path>"
    echo "Example: $0 deploy@prod.dentatrak.com /var/www/dentatrak"
    exit 1
fi

REMOTE_HOST="$1"
REMOTE_PATH="$2"
SOURCE_DIR="$(dirname "$0")/.."

echo "=== Dentatrak Production Deployment ==="
echo "Target: ${REMOTE_HOST}:${REMOTE_PATH}"
echo ""

# ===========================================
# STEP 1: RUN E2E TESTS (MANDATORY)
# ===========================================
echo "=== Step 1: Running E2E Tests ==="
echo "Tests must pass before deployment can proceed."
echo ""

cd "${SOURCE_DIR}"

# Run Playwright tests - script exits immediately on failure due to set -e
npm run test:e2e

# If we reach here, tests passed
echo ""
echo "=== All tests passed. Proceeding with deployment. ==="
echo ""

# ===========================================
# STEP 2: DEPLOY TO PRODUCTION
# ===========================================
echo "=== Step 2: Deploying to Production ==="

# Rsync with explicit exclusions for safety
rsync -avz --delete \
    --exclude='tests/' \
    --exclude='test-results/' \
    --exclude='playwright-report/' \
    --exclude='playwright.config.ts' \
    --exclude='playwright.config.js' \
    --exclude='node_modules/' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='yarn.lock' \
    --exclude='pnpm-lock.yaml' \
    --exclude='.env' \
    --exclude='.env.*' \
    --exclude='.env_mode' \
    --exclude='.git/' \
    --exclude='.gitignore' \
    --exclude='.vscode/' \
    --exclude='.idea/' \
    --exclude='*.log' \
    --exclude='logs/' \
    --exclude='*.tmp' \
    --exclude='*.bak' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    --exclude='scripts/deploy.sh' \
    --exclude='deploy-exclude.txt' \
    --exclude='testResults/' \
    "${SOURCE_DIR}/" "${REMOTE_HOST}:${REMOTE_PATH}/"

echo ""
echo "=== Deployment Complete ==="
echo "Deployed to: ${REMOTE_HOST}:${REMOTE_PATH}"
echo "Tests passed and deployment succeeded."
