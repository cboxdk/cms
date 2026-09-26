<?php

declare(strict_types=1);

/*
 * PHPStan runs this file in its main process and in every parallel worker, just before Larastan's
 * bootstrap boots Laravel, and phpstan-boot-unlock.php just after. Together they let one process
 * at a time boot; see LaravelBootLock for why.
 */

namespace Cbox\Cms\Testkit\Phpstan;

use RuntimeException;

$workingDirectory = getcwd();

if ($workingDirectory === false) {
    throw new RuntimeException('PHPStan runs in a directory that PHP cannot read, so Larastan cannot boot Laravel there.');
}

LaravelBootLock::acquire(sys_get_temp_dir(), $workingDirectory);
