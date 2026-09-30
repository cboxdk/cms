<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * How one call of a read ended (GUARDRAILS 2.1, PRD 6.2): answered or rejected.
 *
 * - answered: the action's Result, with every field above the principal's classification access
 *   removed, and that classification access, which a surface encodes the result with, so a field
 *   above it never leaves the server even when the result reads no content; the content keys of the entries it read, `e-{entry}` and `n-{node}` (PRD 9.4), each
 *   once and sorted; and the read's position, the xmin of its snapshot (PRD 8.4, 8.12): it saw
 *   every changeset whose position is below it.
 * - rejected: at least one catalog error, and nothing else. Nothing was read; its classification
 *   access is public.
 *
 * Each surface translates it for its transport, as it does a WriteResult (GUARDRAILS 2.1).
 */
#[Experimental]
final readonly class QueryResult
{
    /** @var list<DependencyKey> each once, sorted */
    public array $contentKeys;

    /**
     * @param  list<DependencyKey>  $contentKeys
     * @param  list<CatalogError>  $errors
     */
    private function __construct(
        public ?Result $result,
        array $contentKeys,
        public ?CommitPosition $position,
        public array $errors,
        public ClassificationAccess $access,
    ) {
        $byKey = [];

        foreach ($contentKeys as $key) {
            $byKey[$key->toString()] = $key;
        }

        ksort($byKey, SORT_STRING);
        $this->contentKeys = array_values($byKey);
    }

    /**
     * @param  list<DependencyKey>  $contentKeys
     * @param  ClassificationAccess  $access  the classification access of the read's principal
     */
    public static function answered(Result $result, array $contentKeys, CommitPosition $position, ClassificationAccess $access = ClassificationAccess::Public): self
    {
        return new self($result, $contentKeys, $position, [], $access);
    }

    public static function rejected(CatalogError $error, CatalogError ...$more): self
    {
        return new self(null, [], null, [$error, ...array_values($more)], ClassificationAccess::Public);
    }

    public function isAnswered(): bool
    {
        return $this->result instanceof Result;
    }
}
