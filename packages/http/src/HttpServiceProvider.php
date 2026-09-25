<?php

declare(strict_types=1);

namespace Cbox\Cms\Http;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the http package in a Laravel application. Loaded through package discovery.
 */
#[Internal]
final class HttpServiceProvider extends ServiceProvider {}
