<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the cli package in a Laravel application. Loaded through package discovery.
 */
#[Internal]
final class CliServiceProvider extends ServiceProvider {}
