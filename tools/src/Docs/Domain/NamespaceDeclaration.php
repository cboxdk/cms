<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * A namespace statement of a PHP file and its line.
 */
final readonly class NamespaceDeclaration
{
    public function __construct(
        public string $name,
        public int $line,
    ) {}
}
