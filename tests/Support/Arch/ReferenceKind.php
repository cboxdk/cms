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
}
