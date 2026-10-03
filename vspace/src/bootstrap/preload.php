<?php

declare(strict_types=1);

/**
 * OPcache preload (FPM master). Требует validate_timestamps=0 и перезапуск php-fpm после deploy.
 * @see docker/php/docker-entrypoint.sh (PHP_OPCACHE_PRELOAD)
 */

require_once dirname(__DIR__).'/vendor/autoload.php';

$app = require_once __DIR__.'/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
