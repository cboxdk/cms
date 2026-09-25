<?php

declare(strict_types=1);

namespace Cbox\Cms\Core;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the core package in a Laravel application. Loaded through package discovery.
 */
#[Internal]
final class CoreServiceProvider extends ServiceProvider {}
