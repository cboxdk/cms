<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Build\ScanRoot;

/**
 * The scan roots of one cms:build (PRD 13.2), in the order they were declared. The order does not
 * change the registry: the scanner visits the roots sorted.
 */
#[Experimental]
final readonly class ScanRoots
{
    /** @var list<ScanRoot> */
    public array $roots;

    public function __construct(ScanRoot ...$roots)
    {
        $this->roots = array_values($roots);
    }
}
