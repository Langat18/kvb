#!/bin/bash
set -e

echo "=== CPD System Deploy ==="

cd "$(dirname "$0")"

echo "Building image..."
docker compose -f docker-compose.prod.yml build app

echo "Starting container..."
docker compose -f docker-compose.prod.yml up -d

echo "Clearing sessions..."
docker exec cpd_app find /tmp -name "sess_*" -delete 2>/dev/null || true

echo "Verifying site..."
sleep 3
STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8081)
echo "HTTP status: $STATUS"

echo "=== Deploy complete ==="
echo "Note: this only builds/starts the app container. On the very first"
echo "deploy, load the schema + migrations into the database yourself —"
echo "see docs/DEPLOYMENT.md step 6. Re-running migrations on later deploys"
echo "will error since they are not idempotent/tracked automatically."
