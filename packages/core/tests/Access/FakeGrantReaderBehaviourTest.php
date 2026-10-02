<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeGrantReader;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * FakeGrantReader against GrantReaderBehaviour, as ALICE of AccessWorld: her regions reach NEWS,
 * FOOTBALL and CULTURE, and not SPORT.
 */
final class FakeGrantReaderBehaviourTest extends TestCase
{
    use GrantReaderBehaviour;

    #[Override]
    protected function readAsAlice(array $roles, array $grants, Closure $read): void
    {
        $reader = new FakeGrantReader(array_map(NodeId::fromString(...), [AccessWorld::NEWS, AccessWorld::FOOTBALL, AccessWorld::CULTURE]));

        foreach ($roles as $role) {
            $reader->addRole($role);
        }

        foreach ($grants as $grant) {
            $reader->addGrant($grant);
        }

        $read($reader);
    }
}
