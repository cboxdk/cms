<?php

declare(strict_types=1);

namespace Fixture\Counters\Domain;

use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\TextHash;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Testkit\Tests\Phpstan\EventPayloads\CounterFlag;
use Cbox\Cms\Testkit\Tests\Phpstan\EventPayloads\CounterState;
use Cbox\Cms\Testkit\Tests\Phpstan\EventPayloads\Title;
use DateTimeImmutable;

final readonly class CountedV1 implements EventPayload
{
    /**
     * @param  list<ChangesetId>  $changesets
     * @param  list<string>  $tags
     * @param  array<string, int>  $counts
     * @param  list<TextHash|null>  $hashes
     */
    public function __construct(
        public ChangesetId $changeset,
        public TextHash $titleHash,
        public int $count,
        public ?int $previous,
        public bool $manual,
        public DateTimeImmutable $at,
        public CounterState $state,
        public array $changesets,
        public string $title,
        public ?string $note,
        public int|string $key,
        public array $tags,
        public mixed $anything,
        public float $ratio,
        public Title $label,
        public CounterFlag $flag,
        public array $counts,
        public array $hashes,
        public \DateTimeInterface $when,
    ) {}

    public function data(): EventData
    {
        return EventData::empty();
    }
}

final readonly class DeclaredV1 implements EventPayload
{
    /** @var non-empty-string */
    public string $slug;

    public int $count;

    public function __construct()
    {
        $this->slug = 'a-slug';
        $this->count = 1;
    }

    public function data(): EventData
    {
        return EventData::empty();
    }
}

final readonly class NotAPayload
{
    public function __construct(public string $title) {}
}
