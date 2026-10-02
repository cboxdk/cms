<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every PermissionCatalog of the kernel does (PRD 5.10), held against the fake the action
 * tests use and RegistryPermissionCatalog: it knows the name of every command and of every query
 * an action handles, in any version, and no other name.
 */
trait PermissionCatalogBehaviour
{
    /**
     * A catalog of a registry with the commands, each in versions 1 and 2, and the queries, each
     * with a query action.
     *
     * @param  list<string>  $commands
     * @param  list<string>  $queries
     */
    abstract protected function catalogOf(array $commands, array $queries): PermissionCatalog;

    #[Test]
    public function it_knows_the_name_of_every_command_and_query_of_the_registry_and_no_other(): void
    {
        $catalog = $this->catalogOf(['probe.rename', 'entry.create'], ['probe.cards']);

        Assert::assertTrue($catalog->has(new CommandName('probe.rename')));
        Assert::assertTrue($catalog->has(new CommandName('entry.create')));
        Assert::assertTrue($catalog->has(new CommandName('probe.cards')));
        Assert::assertFalse($catalog->has(new CommandName('entry.creates')));
        Assert::assertFalse($catalog->has(new CommandName('probe.unknown')));
    }

    #[Test]
    public function it_knows_no_name_of_an_empty_registry(): void
    {
        Assert::assertFalse($this->catalogOf([], [])->has(new CommandName('entry.create')));
    }
}
