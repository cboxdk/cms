<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Install\Domain;

/**
 * One side of a Composer installation: either what composer.lock and composer.json declare, or
 * what `composer install` and `composer dump-autoload` last wrote to vendor/composer.
 */
final readonly class ComposerState
{
    /**
     * @param  array<string, string>  $packages  package name => version and reference, such as
     *                                           `0.1.x-dev@95606ba2bc82899f89eee721aedf354245ddba1b`
     * @param  array<string, list<string>>  $rootAutoload  the root package's autoload rules, keyed
     *                                                     by kind and prefix, such as `psr-4 Examples\`,
     *                                                     or `files`, each with its paths relative to
     *                                                     the root, without a trailing slash, sorted
     */
    public function __construct(
        public array $packages,
        public array $rootAutoload,
    ) {}
}
