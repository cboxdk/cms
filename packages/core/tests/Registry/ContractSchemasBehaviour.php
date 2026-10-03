<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\JsonKind;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\CreateNoteCodec;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every implementation of ContractSchemas does, run against CodecContractSchemas and against
 * the fake the build tests use: given the codec of fixture.note.create and the props schema of
 * notes.detail.sections@1 (Fixtures/PointSchemas), it gives their shapes by `<name>@<version>` and
 * by point id, and none for a contract it has no schema of.
 */
trait ContractSchemasBehaviour
{
    /**
     * The schemas of CreateNoteCodec::SCHEMA and Fixtures/PointSchemas.
     */
    abstract protected function contractSchemas(): ContractSchemas;

    #[Test]
    public function it_gives_the_shape_of_a_command_by_its_name_and_version(): void
    {
        $shapes = $this->contractSchemas()->shapes();
        $command = $shapes->command(new CommandName('fixture.note.create'), 1);

        Assert::assertNotNull($command);
        Assert::assertTrue($command->closed);
        Assert::assertSame(['title'], $command->required);
        Assert::assertSame([JsonKind::String], $command->member('title')?->kinds);
        Assert::assertNull($shapes->command(new CommandName('fixture.note.create'), 2));
        Assert::assertNull($shapes->query(new CommandName('fixture.note.create'), 1));
    }

    #[Test]
    public function it_gives_the_shape_of_a_point_by_its_id_from_its_props_schema(): void
    {
        $shapes = $this->contractSchemas()->shapes();
        $point = $shapes->point(PointId::fromString('notes.detail.sections@1'));

        Assert::assertNotNull($point);
        Assert::assertSame(['count', 'note'], $point->required);
        Assert::assertSame([JsonKind::Integer], $point->member('count')?->kinds);
        Assert::assertNull($shapes->point(PointId::fromString('notes.detail.sections@2')));
    }

    /**
     * The JSON of the fixtures, for an implementation that is given its shapes.
     *
     * @return array{string, string}
     */
    protected static function fixtureSchemas(): array
    {
        $point = file_get_contents(__DIR__.'/Fixtures/PointSchemas/notes.detail.sections.v1.json');
        Assert::assertIsString($point);

        return [CreateNoteCodec::SCHEMA, $point];
    }
}
