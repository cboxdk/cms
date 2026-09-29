<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Ids\Identifier;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The id of a test-only aggregate, a tally: a running total that the test-only command tally.add
 * raises. It is no content type (GUARDRAILS 2.4); its rows live in the scratch table TallyTable.
 */
final readonly class TallyId implements AggregateRef, Identifier
{
    public function __construct(public Uuid7 $value) {}

    public static function fromString(string $value): self
    {
        return new self(new Uuid7($value));
    }

    #[Override]
    public function toString(): string
    {
        return $this->value->value;
    }

    /**
     * "tally:" and the UUID.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return TallyTable::KIND.':'.$this->value->value;
    }
}
