<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a place in a contract's JSON Schema holds, as TypeScript types it (PRD 13.4): a JSON kind,
 * a literal value of `const` or `enum`, a reference to a definition, a union of the branches of
 * `anyOf`, `oneOf` or a list of types, an intersection of `allOf`, or any JSON value.
 */
#[Internal]
enum ShapeKind: string
{
    case Any = 'any';
    case Null = 'null';
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Array = 'array';
    case Object = 'object';
    case Literal = 'literal';
    case Reference = 'reference';
    case Union = 'union';
    case Intersection = 'intersection';
}
