<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * What docs/requirements.md states, as the files of the repository declare it: the `require` and
 * `suggest` of cboxdk/cms's composer.json, the Node version of package.json's `engines`, and the
 * image of each service in compose.yaml.
 */
final readonly class Requirements
{
    /**
     * @param  array<string, string>  $require  package or platform name to constraint, as composer.json lists them
     * @param  array<string, string>  $suggest  package name to the reason composer.json gives
     * @param  ?string  $node  the constraint of package.json's engines.node, or null when it has none
     * @param  array<string, string>  $services  compose.yaml service name to its image
     */
    public function __construct(
        public array $require,
        public array $suggest,
        public ?string $node,
        public array $services,
    ) {}
}
