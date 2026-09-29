<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Fakes;

use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Override;

/**
 * The query actions a test registers, by query class. QueryActionsBehaviour holds it to
 * RegistryQueryActions.
 */
final readonly class FakeQueryActions implements QueryActions
{
    /** @var array<string, QueryBinding> by lower-case query class */
    private array $bindings;

    /**
     * @param  array<class-string<Query>, QueryBinding>  $bindings
     */
    public function __construct(array $bindings)
    {
        $byClass = [];

        foreach ($bindings as $class => $binding) {
            $byClass[strtolower($class)] = $binding;
        }

        $this->bindings = $byClass;
    }

    #[Override]
    public function for(Query $query): QueryBinding
    {
        return $this->bindings[strtolower($query::class)] ?? throw UnknownQuery::noAction($query::class);
    }
}
