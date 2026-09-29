<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs;

/**
 * A planted class with a toArray(), which HandWrittenCodecScan reports for a bound class.
 */
final readonly class ArraySlip
{
    public function __construct(public string $label) {}

    /**
     * @return array{label: string}
     */
    public function toArray(): array
    {
        return ['label' => $this->label];
    }
}
