<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Illuminate\Contracts\Container\Container;
use Override;

/**
 * The query actions of the compiled registry (PRD 13.2): the action cms:build registered for the
 * query's class, built by the container so it gets its read ports through its constructor, with
 * the name and version of the query from the registry.
 */
#[Internal]
final readonly class RegistryQueryActions implements QueryActions
{
    public function __construct(
        private CompiledRegistry $registry,
        private Container $container,
    ) {}

    #[Override]
    public function for(Query $query): QueryBinding
    {
        $entry = $this->registry->actionFor($query::class);

        if (! $entry instanceof ActionEntry || $entry->kind !== ActionKind::Query) {
            throw UnknownQuery::noAction($query::class);
        }

        $action = $this->container->make($entry->class);

        if (! $action instanceof QueryAction) {
            throw UnknownQuery::notAQueryAction($query::class, $entry->class);
        }

        return new QueryBinding($entry->command, $entry->commandVersion, $action);
    }
}
