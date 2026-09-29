<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe;

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;
use DateTimeImmutable;

/**
 * The probe.
 */
final readonly class ProbeRecord
{
    /**
     * @param  ChangesetId  $changeset  The changeset.
     * @param  ProbeStep  $firstStep  The first step.
     * @param  list<list<int>>|Omitted|null  $grid  Rows of numbers.
     * @param  ChangesetId|Omitted|null  $parent  The changeset before, if any.
     * @param  ProbeSecret|Omitted|null  $secret  The secret part.
     * @param  list<ProbeStep>  $steps  Every step.
     * @param  list<Tone>  $tones  The tones.
     * @param  DateTimeImmutable|Omitted|null  $when  When it happened.
     */
    public function __construct(
        public ChangesetId $changeset,
        public ProbeStep $firstStep,
        public array|Omitted|null $grid,
        public ChangesetId|Omitted|null $parent,
        public ProbeSecret|Omitted|null $secret,
        public array $steps,
        public array $tones,
        public DateTimeImmutable|Omitted|null $when,
    ) {}

    /**
     * This object as a caller with $access may see it: every property classified above the
     * access is Omitted (PRD 12.2).
     */
    public function visibleTo(ClassificationAccess $access): self
    {
        return new self(
            changeset: $this->changeset,
            firstStep: $this->firstStep,
            grid: $this->grid,
            parent: $this->parent,
            secret: $access->allows(ClassificationAccess::Internal) ? ($this->secret instanceof ProbeSecret ? $this->secret->visibleTo($access) : $this->secret) : Omitted::Field,
            steps: $this->steps,
            tones: $this->tones,
            when: $this->when,
        );
    }
}
