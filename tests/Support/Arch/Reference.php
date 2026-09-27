<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * A function called, a method called or a class named in a source file, read from its tokens.
 *
 * A function is lowercase, as PHP resolves it; an unqualified call in a namespace counts as the
 * global function, which PHP falls back to. A method is lowercase too. A class is fully
 * qualified, as written or resolved through the file's imports, without the leading backslash.
 * A string literal is its value as written, without the leading backslash: 'file_get_contents'
 * given to array_map(), 'GuzzleHttp\Client' given to app() or 'Foo::bar' given to
 * call_user_func().
 */
final readonly class Reference
{
    public function __construct(
        public ReferenceKind $kind,
        public string $name,
        public string $namespace,
        public string $path,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->path.':'.$this->line;
    }
}
