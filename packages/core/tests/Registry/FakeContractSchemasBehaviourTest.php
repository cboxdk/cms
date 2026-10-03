<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Boundary\JsonSchemaNodes;
use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeContractSchemas;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ContractSchemasBehaviour against the fake the build tests use, given the same schemas.
 */
final class FakeContractSchemasBehaviourTest extends TestCase
{
    use ContractSchemasBehaviour;

    #[Override]
    protected function contractSchemas(): ContractSchemas
    {
        [$command, $point] = self::fixtureSchemas();

        return new FakeContractSchemas(new ContractShapes(
            ['fixture.note.create@1' => JsonSchemaNodes::read($command)],
            [],
            ['notes.detail.sections@1' => JsonSchemaNodes::read($point)],
        ));
    }
}
