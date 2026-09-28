<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Domain;

use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use InvalidArgumentException;

/**
 * Which test databases of removed checkouts `composer test-db:prune` drops.
 *
 * It looks at the configured database, at every database named `<configured>_<12 hex digits>`
 * (TestDatabaseName), a checkout's, and at every one named `<configured>_<12 hex digits>_w<n>`,
 * a parallel worker's of that checkout, and drops exactly those whose testkit comment
 * (TestDatabaseComment) names this host and a checkout that is no longer a directory, or that no
 * longer derives the checkout's name. It keeps the configured database, the database of the
 * checkout that runs it and those of its workers, the databases of other hosts, whose paths mean
 * nothing here, and every database without a valid testkit comment, which the testkit did not
 * provision or whose checkout it cannot tell. Other databases are not listed. The decisions are
 * in the order of the listing.
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
            } else {
                $checkout = self::checkoutDatabase($context->base, $database->name);

                if ($checkout !== null) {
                    $decisions[] = self::decide($database, $checkout, $context, $checkouts);
                }
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
     * The checkout's database that $name belongs to: $name itself when it has the form of a
     * checkout's test database derived from $base, the checkout's name without `_w<n>` when it has
     * the form of a parallel worker's, and null otherwise.
     */
    public static function checkoutDatabase(string $base, string $name): ?string
    {
        $pattern = '/\A('.preg_quote($base, '/').'_[0-9a-f]{'.TestDatabaseName::HASH_DIGITS.'})(?:'.TestDatabaseName::WORKER_SEPARATOR.TestDatabaseName::WORKER_PATTERN.')?\z/';

        return preg_match($pattern, $name, $matches) === 1 ? $matches[1] : null;
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

    private static function decide(ListedDatabase $database, string $checkout, PruneContext $context, Checkouts $checkouts): PruneDecision
    {
        if ($database->name === $context->current) {
            return self::keep($database, 'the test database of this checkout.');
        }

        if ($checkout === $context->current) {
            return self::keep($database, 'the test database of a parallel worker of this checkout.');
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

        if ($derived !== $checkout) {
            return new PruneDecision($database->name, PruneVerdict::Drop, "its checkout {$comment->checkout} on this host now has the test database {$derived}.");
        }

        return self::keep($database, "its checkout {$comment->checkout} exists on this host.");
    }

    private static function keep(ListedDatabase $database, string $reason): PruneDecision
    {
        return new PruneDecision($database->name, PruneVerdict::Keep, $reason);
    }
}
