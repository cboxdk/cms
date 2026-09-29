<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Where generated PHP classes go: a directory relative to the generation root, and the namespace
 * that PSR-4 maps it to.
 */
#[Internal]
final readonly class PhpLocation
{
    public function __construct(
        public string $directory,
        public string $namespace,
    ) {}

    /**
     * The location of a subdirectory, whose namespace adds the same segments.
     */
    public function below(string ...$segments): self
    {
        return new self($this->directory.'/'.implode('/', $segments), $this->namespace.'\\'.implode('\\', $segments));
    }

    /**
     * The fully qualified name of a class in this location.
     */
    public function className(string $shortName): string
    {
        return $this->namespace.'\\'.$shortName;
    }
}
