<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

/**
 * The roles one test made, which afterEach drops.
 */
final class TablePrivilegesScratchRoles
{
    /** @var list<string> */
    public static array $roles = [];
}
