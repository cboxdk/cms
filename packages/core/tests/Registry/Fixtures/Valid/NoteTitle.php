<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

/**
 * A class without registry attributes, which the scan loads and leaves out.
 */
final readonly class NoteTitle
{
    public function __construct(
        public string $value,
    ) {}
}
