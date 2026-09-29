<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs;

use JsonSerializable;
use Override;

/**
 * A planted class that serialises itself, which HandWrittenCodecScan reports for a bound class.
 */
final readonly class SelfEncodingSlip implements JsonSerializable
{
    public function __construct(public string $label) {}

    /**
     * @return array{label: string}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['label' => $this->label];
    }
}
