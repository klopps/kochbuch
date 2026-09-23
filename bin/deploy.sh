#!/usr/bin/env bash
#
# Deploys the current working tree to the live/test system over SSH.
#
# There is no `composer` on the remote host, so production dependencies are
# built locally (in a throwaway copy, so the developer's own vendor/ with
# dev dependencies like phpunit is left untouched) and shipped as part of
# the upload. Transfer uses `tar` piped through `ssh` - one connection, one
# archive.
#
# The remote .env is never touched: it's excluded from the package, and
# this script never deletes anything on the remote side, it only extracts
# on top of what's already there. Note that also means files removed
# locally are NOT removed from the remote - this is a simple "copy newer
# files over", not a mirror.
#
# Usage: bin/deploy.sh
# Override any of these via environment variables, e.g.:
#   REMOTE_PHP=php8.1 bin/deploy.sh
set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-csteindorff}"
DEPLOY_HOST="${DEPLOY_HOST:-steindorff.de}"
DEPLOY_PORT="${DEPLOY_PORT:-22}"
# TODO: replace <DOC_ID> with the actual numeric document-root id for
# kochbuch.steindorff.de from the hosting control panel.
DEPLOY_PATH="${DEPLOY_PATH:-home/www/doc/<DOC_ID>/kochbuch.steindorff.de/www}"

# PHP/Composer used LOCALLY to build the production vendor/ directory.
# Defaults target this dev machine's toolchain (see CLAUDE.md) - adjust if
# the plain `php`/`composer` on PATH already satisfy composer.json's
# ">=8.1" platform check.
LOCAL_PHP="${LOCAL_PHP:-/c/dev/php8/php.exe}"
LOCAL_COMPOSER_PHAR="${LOCAL_COMPOSER_PHAR:-/c/ProgramData/ComposerSetup/bin/composer.phar}"

# PHP used on the REMOTE host to run bin/migrate.php after upload. Must be
# 8.1+; adjust if the server's default `php` on PATH is older (shared
# hosts often expose specific versions as e.g. php8.1/php8.2).
REMOTE_PHP="${REMOTE_PHP:-php}"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

# Same exclude list as bin/deploy.bat - see the comment there for why each entry is excluded.
echo "==> Packaging working tree (excluding dev/build-only files)"
tar -C "$ROOT_DIR" \
    --exclude='.git' \
    --exclude='.env' \
    --exclude='.claude' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='android' \
    --exclude='ios' \
    --exclude='assets' \
    --exclude='docs' \
    --exclude='design' \
    --exclude='tests' \
    --exclude='capacitor.config.json' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='storage' \
    --exclude='.githooks' \
    --exclude='.phpunit.cache' \
    --exclude='.playwright-mcp' \
    --exclude='.chefkoch' \
    --exclude='.vscode' \
    --exclude='.idea' \
    --exclude='.env.example' \
    --exclude='.gitignore' \
    --exclude='.gitkeep' \
    --exclude='*.md' \
    --exclude='LICENSE' \
    --exclude='phpunit.xml' \
    --exclude='*.map' \
    --exclude='bin/deploy.bat' \
    --exclude='bin/deploy.sh' \
    --exclude='bin/import-recipes.php' \
    --exclude='bin/setup-test-db.php' \
    -cf - . | tar -C "$BUILD_DIR" -xf -

echo "==> Installing production dependencies (composer install --no-dev)"
"$LOCAL_PHP" "$LOCAL_COMPOSER_PHAR" install --no-dev --optimize-autoloader --working-dir="$BUILD_DIR"
rm -f "$BUILD_DIR/composer.json" "$BUILD_DIR/composer.lock"

echo "==> Uploading to $DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH and running migrations"
tar -C "$BUILD_DIR" -cf - . | ssh -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST" "
    set -e
    mkdir -p '$DEPLOY_PATH'
    tar -C '$DEPLOY_PATH' -xf -
    cd '$DEPLOY_PATH'
    $REMOTE_PHP bin/migrate.php
"

echo "==> Deploy finished."
