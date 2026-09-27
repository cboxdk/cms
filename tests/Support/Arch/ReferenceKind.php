<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * What a Reference names.
 */
enum ReferenceKind: string
{
    case Function = 'function';
    case Method = 'method';
    case ClassName = 'class';
    /** A string literal that reads as a function, method or class name, which PHP can call or resolve. */
    case StringLiteral = 'string';
}
