<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Override;

/**
 * The PermissionCatalog in memory: the command names and the query names a test gives
 * (PermissionCatalogBehaviour holds it to RegistryPermissionCatalog). A command is a write, a query
 * is not.
 */
final readonly class FakePermissionCatalog implements PermissionCatalog
{
    /**
     * The kernel's commands, as the compiled registry has them.
     *
     * @var list<string>
     */
    public const array KERNEL_COMMANDS = [
        'actor.activate',
        'actor.deactivate',
        'actor.register',
        'entry.create',
        'entry.publish',
        'entry.revise',
        'entry.unpublish',
        'grant.assign',
        'grant.revoke',
        'placement.create',
        'placement.set_window',
        'role.create',
        'role.set_permissions',
        'site.register',
        'variant.release',
    ];

    /**
     * The kernel's queries, as the compiled registry has them.
     *
     * @var list<string>
     */
    public const array KERNEL_QUERIES = ['actor.list', 'grant.list', 'node.list', 'path.resolve', 'role.list'];

    /** @var array<string, true> */
    private array $names;

    /** @var array<string, true> */
    private array $writes;

    /**
     * @param  list<string>  $commands
     * @param  list<string>  $queries
     */
    public function __construct(array $commands, array $queries = [])
    {
        $this->writes = array_fill_keys($commands, true);
        $this->names = $this->writes + array_fill_keys($queries, true);
    }

    /**
     * A catalog of the kernel's commands and queries.
     */
    public static function kernel(): self
    {
        return new self(self::KERNEL_COMMANDS, self::KERNEL_QUERIES);
    }

    #[Override]
    public function has(CommandName $name): bool
    {
        return isset($this->names[$name->value]);
    }

    #[Override]
    public function writes(CommandName $name): bool
    {
        return isset($this->writes[$name->value]);
    }
}
