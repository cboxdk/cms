<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe;

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;

/**
 * The secret part of the probe.
 */
final readonly class ProbeSecret
{
    /**
     * @param  string  $code  The code.
     * @param  Tone|Omitted|null  $tone  The tone.
     */
    public function __construct(
        public string $code,
        public Tone|Omitted|null $tone,
    ) {}

    /**
     * This object as a caller with $access may see it: every property classified above the
     * access is Omitted (PRD 12.2).
     */
    public function visibleTo(ClassificationAccess $access): self
    {
        return new self(
            code: $this->code,
            tone: $access->allows(ClassificationAccess::Confidential) ? $this->tone : Omitted::Field,
        );
    }
}
