<?php

declare(strict_types=1);

/*
 * PHPStan runs this file just after Larastan's bootstrap has booted Laravel; it releases the lock
 * that phpstan-boot-lock.php took. See LaravelBootLock.
 */

namespace Cbox\Cms\Testkit\Phpstan;

LaravelBootLock::release();
