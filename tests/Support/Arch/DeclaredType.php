<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * A class, interface, trait or enum declared in a source file.
 */
final readonly class DeclaredType
{
    public function __construct(
        public string $namespace,
        public string $name,
        public string $kind,
        public string $path,
        public int $line,
    ) {}

    /**
     * @return class-string
     */
    public function fqcn(): string
    {
        /** @var class-string */
        return ltrim($this->namespace.'\\'.$this->name, '\\');
    }

    public function layer(): ?Layer
    {
        return Layer::of($this->namespace);
    }

    public function category(): ?Category
    {
        return Category::of($this->namespace);
    }
}
