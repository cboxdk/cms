<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Domain;

use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use InvalidArgumentException;

/**
 * Which test databases of removed checkouts `composer test-db:prune` drops.
 *
 * It looks at the configured database and at every database named `<configured>_<12 hex digits>`
 * (TestDatabaseName), and drops exactly those whose testkit comment (TestDatabaseComment) names
 * this host and a checkout that is no longer a directory, or that no longer derives that name.
 * It keeps the configured database, the database of the checkout that runs it, the databases of
 * other hosts, whose paths mean nothing here, and every database without a valid testkit comment,
 * which the testkit did not provision or whose checkout it cannot tell. Other databases are not
 * listed. The decisions are in the order of the listing.
 */
final readonly class PrunePlan
{
    /**
     * @param  list<PruneDecision>  $decisions
     */
    private function __construct(
        public array $decisions,
    ) {}

    /**
     * @param  list<ListedDatabase>  $databases
     */
    public static function for(array $databases, PruneContext $context, Checkouts $checkouts): self
    {
        $decisions = [];

        foreach ($databases as $database) {
            if ($database->name === $context->base) {
                $decisions[] = self::keep($database, 'the configured database, which the owner role connects to.');
            } elseif (self::isTestDatabaseName($context->base, $database->name)) {
                $decisions[] = self::decide($database, $context, $checkouts);
            }
        }

        return new self($decisions);
    }

    /**
     * Whether $name has the form of a checkout's test database derived from $base.
     */
    public static function isTestDatabaseName(string $base, string $name): bool
    {
        return preg_match('/\A'.preg_quote($base, '/').'_[0-9a-f]{'.TestDatabaseName::HASH_DIGITS.'}\z/', $name) === 1;
    }

    /**
     * The names of the databases to drop.
     *
     * @return list<string>
     */
    public function drops(): array
    {
        $names = [];

        foreach ($this->decisions as $decision) {
            if ($decision->verdict === PruneVerdict::Drop) {
                $names[] = $decision->name;
            }
        }

        return $names;
    }

    private static function decide(ListedDatabase $database, PruneContext $context, Checkouts $checkouts): PruneDecision
    {
        if ($database->name === $context->current) {
            return self::keep($database, 'the test database of this checkout.');
        }

        if ($database->comment === null || $database->comment === '') {
            return self::keep($database, 'it has no comment, so the testkit did not provision it or cannot tell its checkout.');
        }

        try {
            $comment = TestDatabaseComment::decode($database->comment);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return self::keep($database, 'its comment is not the testkit\'s ('.rtrim($invalidArgumentException->getMessage(), '.').').');
        }

        if ($comment->host !== $context->host) {
            return self::keep($database, "its checkout {$comment->checkout} is on the host {$comment->host}, not on this host {$context->host}.");
        }

        $derived = $checkouts->derivedName($context->base, $comment->checkout);

        if ($derived === null) {
            return new PruneDecision($database->name, PruneVerdict::Drop, "its checkout {$comment->checkout} on this host no longer exists.");
        }

        if ($derived !== $database->name) {
            return new PruneDecision($database->name, PruneVerdict::Drop, "its checkout {$comment->checkout} on this host now has the test database {$derived}.");
        }

        return self::keep($database, "its checkout {$comment->checkout} exists on this host.");
    }

    private static function keep(ListedDatabase $database, string $reason): PruneDecision
    {
        return new PruneDecision($database->name, PruneVerdict::Keep, $reason);
    }
}
