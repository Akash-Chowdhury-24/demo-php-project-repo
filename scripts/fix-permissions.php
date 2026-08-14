<?php

declare(strict_types=1);

/**
 * Ensure storage/bootstrap/database are writable by both the deploy user (SSH)
 * and the PHP-FPM user (apache on Amazon Linux, www-data on Ubuntu).
 *
 * DeployHub runs `composer install --no-dev` on SSH deploys, which triggers
 * this via composer post-install-cmd — fixing the classic
 * tempnam(): file created in the system's temporary directory 500.
 */

$root = dirname(__DIR__);

$dirs = [
    $root.'/storage/app/public',
    $root.'/storage/app/private',
    $root.'/storage/framework/cache/data',
    $root.'/storage/framework/sessions',
    $root.'/storage/framework/testing',
    $root.'/storage/framework/views',
    $root.'/storage/logs',
    $root.'/bootstrap/cache',
    $root.'/database',
];

foreach ($dirs as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

$sqlite = $root.'/database/database.sqlite';
if (! file_exists($sqlite)) {
    @touch($sqlite);
}

if (PHP_OS_FAMILY !== 'Linux') {
    exit(0);
}

$deployUser = trim((string) shell_exec('whoami 2>/dev/null'));
if ($deployUser === '') {
    $deployUser = get_current_user() ?: 'root';
}

$webUser = 'apache';
if (trim((string) shell_exec('id -u www-data 2>/dev/null')) !== '') {
    $webUser = 'www-data';
}

$storage = $root.'/storage';
$bootstrapCache = $root.'/bootstrap/cache';
$database = $root.'/database';

$targets = [$storage, $bootstrapCache, $database, $sqlite];

$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
$canSudo = ! $isRoot
    && trim((string) shell_exec('sudo -n true >/dev/null 2>&1 && echo yes || true')) === 'yes';

$run = static function (string $command) use ($isRoot, $canSudo): void {
    if ($isRoot) {
        shell_exec($command.' 2>/dev/null');

        return;
    }

    if ($canSudo) {
        shell_exec('sudo -n '.$command.' 2>/dev/null');
    }
};

if ($isRoot || $canSudo) {
    $owner = escapeshellarg($deployUser.':'.$webUser);
    foreach ($targets as $target) {
        $run('chown -R '.$owner.' '.escapeshellarg($target));
    }

    $run('chmod -R ug+rwX '.escapeshellarg($storage).' '.escapeshellarg($bootstrapCache).' '.escapeshellarg($database));
    $run('find '.escapeshellarg($storage).' '.escapeshellarg($bootstrapCache).' '.escapeshellarg($database)." -type d -exec chmod g+s {} +");
    $run('chmod 664 '.escapeshellarg($sqlite));
} else {
    // Last resort when we cannot change ownership (still better than a 500).
    shell_exec('chmod -R a+rwX '.escapeshellarg($storage).' '.escapeshellarg($bootstrapCache).' 2>/dev/null');
    @chmod($sqlite, 0666);
    @chmod($database, 0777);
}

exit(0);
