@echo off
REM Deploys the current working tree to the live/test system over SSH.
REM
REM There is no composer on the remote host, so production dependencies are
REM built locally (in a throwaway temp copy, so the developer's own vendor\
REM with dev dependencies like phpunit is left untouched) and shipped as
REM part of the upload. The actual upload uses PuTTY's plink.exe (not
REM ssh.exe) piped together with tar.exe - see the DEPLOY_SSH_PASSWORD block
REM below for why.
REM
REM The remote .env is never touched: it's excluded from the package, and
REM this script never deletes anything on the remote side, it only extracts
REM on top of what's already there. That also means files removed locally
REM are NOT removed from the remote - this is a simple "copy newer files
REM over", not a mirror.
REM
REM Only what the PHP app actually needs at runtime is uploaded. Everything
REM below is local dev/build-only tooling and is excluded (see the --exclude
REM list): android/ and ios/ (the native Capacitor projects), node_modules/
REM + package.json/package-lock.json/capacitor.config.json (root-level
REM npm/Capacitor tooling, unrelated to the PHP app's own composer
REM dependencies), assets/ (Capacitor app icon/splash source images),
REM tests/, docs/, storage/ (persistent uploads live only on the remote -
REM never shipped from/overwritten by a local deploy), .phpunit.cache/,
REM .playwright-mcp/ (Claude/Playwright MCP tool scratch output - gitignored
REM but not tar-ignored, so it would otherwise ship whatever happens to be
REM sitting in the working tree at deploy time), .chefkoch/ (local Chefkoch
REM import source data for bin/import-recipes.php), editor folders, all
REM Markdown docs (README/CLAUDE/todo/done, public/lib/README.md - the
REM latter would otherwise even be publicly reachable), LICENSE,
REM .env.example, .gitignore/.gitkeep, phpunit.xml, *.map source maps of
REM the vendored minified libs, and every bin/ script except migrate.php
REM (the only one run on the remote host). composer.json/composer.lock are
REM still packaged because the local `composer install --no-dev` step needs
REM them, but are deleted from the build dir right after that step.
REM
REM tar's --exclude patterns are unanchored, so "*.md" or ".gitkeep" match
REM at any depth, and "bin/deploy.sh" matches "./bin/deploy.sh".
REM
REM Usage: bin\deploy.bat
REM Override any of these by setting the env var before running, e.g.:
REM   set REMOTE_PHP=php8.1
REM   bin\deploy.bat

REM Delayed expansion (!var!) stays OFF on purpose: DEPLOY_SSH_PASSWORD is
REM used later as a plain %-expanded value, and if it ever contains a "!",
REM delayed expansion's own "!...!" scanning could mangle it on the command
REM line. Nothing in this script needs !var! syntax.
setlocal

if not defined DEPLOY_USER set "DEPLOY_USER=csteindorff"
if not defined DEPLOY_HOST set "DEPLOY_HOST=steindorff.de"
if not defined DEPLOY_PORT set "DEPLOY_PORT=22"

REM TODO: replace <DOC_ID> with the actual numeric document-root id for
REM kochbuch.steindorff.de from the hosting control panel (same pattern as
REM YTAN's /home/www/doc/9769/ytan.pesr.org/www - that id is per-domain and
REM unknown here).
if not defined DEPLOY_PATH set "DEPLOY_PATH=/home/www/doc/9769/kochen.steindorff.de/www"

REM Windows' native ssh.exe has no flag to supply a password non-interactively
REM (only key-based auth or an interactive prompt) - plink.exe (PuTTY) does,
REM via -pw, and is already installed on this machine. Falls back to reading
REM DEPLOY_SSH_PASSWORD from .env (never committed - see .gitignore) if it
REM isn't already set as an environment variable.
if not defined DEPLOY_SSH_CLIENT set "DEPLOY_SSH_CLIENT=plink"
if not defined DEPLOY_SSH_PASSWORD (
    for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%~dp0..\.env") do (
        if "%%A"=="DEPLOY_SSH_PASSWORD" set "DEPLOY_SSH_PASSWORD=%%B"
    )
)
if not defined DEPLOY_SSH_PASSWORD (
    echo ==^> DEPLOY_SSH_PASSWORD is not set and not found in .env - add a line
    echo      DEPLOY_SSH_PASSWORD=... to .env, or set the env var before running.
    goto :error
)

REM PHP/Composer used LOCALLY to build the production vendor\ directory.
REM Plain `php`/`composer` on PATH may resolve to an older PHP build on this
REM machine (see CLAUDE.md), which fails composer.json's ">=8.1" platform
REM check, so point at an explicit 8.x install + composer.phar if needed.
if not defined LOCAL_PHP set "LOCAL_PHP=C:\dev\php8\php.exe"
if not defined LOCAL_COMPOSER_PHAR set "LOCAL_COMPOSER_PHAR=C:\ProgramData\ComposerSetup\bin\composer.phar"

REM PHP used on the REMOTE host to run bin/migrate.php after upload. Must be
REM 8.1+; adjust if the server's default `php` on PATH is older (shared
REM hosts often expose specific versions as e.g. php8.1/php8.2).
if not defined REMOTE_PHP set "REMOTE_PHP=php"

set "ROOT_DIR=%~dp0.."
set "BUILD_DIR=%TEMP%\kochbuch-deploy-%RANDOM%%RANDOM%"

echo ==^> Packaging working tree (excluding dev/build-only files - see comment above)
mkdir "%BUILD_DIR%" || goto :error
tar -C "%ROOT_DIR%" ^
    --exclude=".git" ^
    --exclude=".env" ^
    --exclude=".claude" ^
    --exclude=".githooks" ^
    --exclude=".phpunit.cache" ^
    --exclude=".playwright-mcp" ^
    --exclude="vendor" ^
    --exclude="node_modules" ^
    --exclude="android" ^
    --exclude="ios" ^
    --exclude="assets" ^
    --exclude="docs" ^
    --exclude="tests" ^
    --exclude="capacitor.config.json" ^
    --exclude="package.json" ^
    --exclude="package-lock.json" ^
    --exclude="storage" ^
    --exclude=".chefkoch" ^
    --exclude=".vscode" ^
    --exclude=".idea" ^
    --exclude=".env.example" ^
    --exclude=".gitignore" ^
    --exclude=".gitkeep" ^
    --exclude="*.md" ^
    --exclude="LICENSE" ^
    --exclude="phpunit.xml" ^
    --exclude="*.map" ^
    --exclude="bin/deploy.bat" ^
    --exclude="bin/deploy.sh" ^
    --exclude="bin/import-recipes.php" ^
    --exclude="bin/setup-test-db.php" ^
    -cf - . | tar -C "%BUILD_DIR%" -xf -
if errorlevel 1 goto :error

echo ==^> Installing production dependencies (composer install --no-dev)
"%LOCAL_PHP%" "%LOCAL_COMPOSER_PHAR%" install --no-dev --optimize-autoloader --working-dir="%BUILD_DIR%"
if errorlevel 1 goto :error
del /q "%BUILD_DIR%\composer.json" "%BUILD_DIR%\composer.lock"
if errorlevel 1 goto :error

echo ==^> Uploading to %DEPLOY_USER%@%DEPLOY_HOST%:%DEPLOY_PATH% and running migrations
REM No -batch here on purpose: plink keeps its own host-key cache, separate
REM from ssh.exe's known_hosts, so the very first run against a host may
REM still show a one-time "store this host key?" prompt even though the
REM password itself is no longer asked for - accept it once and it's cached
REM for every run after that.
tar -C "%BUILD_DIR%" -cf - . | "%DEPLOY_SSH_CLIENT%" -ssh -P %DEPLOY_PORT% -l %DEPLOY_USER% -pw %DEPLOY_SSH_PASSWORD% %DEPLOY_HOST% "mkdir -p '%DEPLOY_PATH%' && tar -C '%DEPLOY_PATH%' -xf - && cd '%DEPLOY_PATH%' && %REMOTE_PHP% bin/migrate.php"

if errorlevel 1 goto :error

echo ==^> Deploy finished.
date /t
time /t
rmdir /s /q "%BUILD_DIR%" 2>nul
endlocal
exit /b 0

:error
echo ==^> Deploy FAILED.
if exist "%BUILD_DIR%" rmdir /s /q "%BUILD_DIR%" 2>nul
endlocal
exit /b 1
