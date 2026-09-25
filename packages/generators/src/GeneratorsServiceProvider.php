<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the generators package in a Laravel application. Loaded through package discovery.
 */
#[Internal]
final class GeneratorsServiceProvider extends ServiceProvider {}
