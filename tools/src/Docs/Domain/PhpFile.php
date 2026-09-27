<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * What the check needs to know of a PHP file, read from its tokens without loading it: its
 * namespace statements, the types it declares, the traits its classes use and the names it calls
 * as functions or methods. Names are fully qualified, without a leading backslash.
 */
final readonly class PhpFile
{
    /**
     * @param  string  $path  repo-relative
     * @param  list<NamespaceDeclaration>  $namespaces
     * @param  list<DeclaredType>  $types
     * @param  list<string>  $traitUses  the traits used in class, trait and enum bodies, anonymous classes included
     * @param  list<string>  $calls  the unqualified names of the functions and methods it calls, such as expect or assertSame
     */
    public function __construct(
        public string $path,
        public array $namespaces,
        public array $types,
        public array $traitUses,
        public array $calls,
    ) {}
}
