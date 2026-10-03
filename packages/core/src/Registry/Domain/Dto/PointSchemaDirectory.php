<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A directory of panel point props schemas, `<name>.v<version>.json` each, as a module that
 * declares panel points registers it under TAG (PRD 13.4). cms:build reads every one to check
 * the prefill pointers and data queries of the contributions to the points.
 */
#[Experimental]
final readonly class PointSchemaDirectory
{
    public const string TAG = 'cbox-cms.panel-point-schemas';

    public function __construct(public string $path) {}
}
