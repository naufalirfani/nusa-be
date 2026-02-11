#!/bin/sh
set -e

# docker-deploy.sh
# Usage: ./docker-deploy.sh
# Requirements: git, docker, docker compose

REQUIRED_BRANCH="master"
COMPOSE_FILE="docker-compose.prod.yml"

# Ensure script executed from repo root
if [ ! -f composer.json ]; then
  echo "Error: please run this script from repository root (composer.json not found)"
  exit 1
fi

# Check git branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$CURRENT_BRANCH" != "$REQUIRED_BRANCH" ]; then
  echo "Error: current branch is '$CURRENT_BRANCH' (must be '$REQUIRED_BRANCH'). Aborting."
  exit 1
fi

# Pull latest
echo "👉 Pulling latest changes for $REQUIRED_BRANCH"
git fetch origin $REQUIRED_BRANCH
git pull origin $REQUIRED_BRANCH

# Build and deploy with Docker Compose (production)
echo "👉 Deploying with Docker Compose (production) using $COMPOSE_FILE"
docker compose -f "$COMPOSE_FILE" up -d --build --remove-orphans

echo "✅ Deployment complete. Containers status:"
docker compose -f "$COMPOSE_FILE" ps
