<?php

declare(strict_types=1);

/**
 * Activates .githooks/pre-commit (see CLAUDE.md) by pointing git at it - run
 * automatically via composer's post-install-cmd/post-update-cmd. A plain
 * `git config ...` composer script would instead fail the whole `composer
 * install` when run outside a real git checkout - which bin/deploy.bat's
 * throwaway build copy always is, since it deliberately excludes .git from
 * the packaged tree - so this checks first and silently no-ops there.
 */

$rootDir = dirname(__DIR__);

if (!is_dir($rootDir . '/.git') && !is_file($rootDir . '/.git')) {
    exit(0);
}

exec('git config core.hooksPath .githooks', $output, $exitCode);
exit($exitCode);
