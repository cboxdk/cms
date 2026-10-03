<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Why something is in the inventory of extension points.
 */
enum ExtensionPointKind: string
{
    case Interface = 'interface';
    case Attribute = 'attribute class';
    case Trait = 'trait';
    case Command = '#[Command] class';
    case Hook = '#[Hook] class';
    case PanelPoint = '#[PanelPoint] class';
    case Schema = 'JSON schema';
}
