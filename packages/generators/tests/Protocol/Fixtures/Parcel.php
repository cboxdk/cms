<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol\Fixtures;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Step;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The document of the probe schema of JsonSchemaContract, with a key of each presence: required,
 * required and nullable, and with a default, nullable or not. Its constructor refuses a label of
 * "refused", as a class of the contracts refuses what breaks its invariants.
 */
final readonly class Parcel
{
    /**
     * @param  list<ProjectionName>  $tags
     */
    public function __construct(
        public string $label,
        public Step $step,
        public ?ChangesetId $id,
        public ?DateTimeImmutable $sentAt,
        public ?Box $owner,
        public int $count = 3,
        public Tone $tone = Tone::Warm,
        public bool $fragile = false,
        public ?string $note = null,
        public array $tags = [],
        public Box $box = new Box,
    ) {
        if ($label === 'refused') {
            throw new InvalidArgumentException('A parcel is never labelled "refused".');
        }
    }
}
