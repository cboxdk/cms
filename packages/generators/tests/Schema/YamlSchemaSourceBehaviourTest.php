<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Schema\Boundary\FixtureSchemaParser;
use Cbox\Cms\Generators\Schema\Boundary\YamlSchemaSource;
use Cbox\Cms\Generators\Schema\Domain\FieldDefinition;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SchemaSourceBehaviour against YamlSchemaSource on files in a scratch directory.
 */
final class YamlSchemaSourceBehaviourTest extends TestCase
{
    use SchemaSourceBehaviour;

    #[Override]
    protected function tearDown(): void
    {
        SchemaFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function schemaSource(): SchemaSource
    {
        return new YamlSchemaSource(new FixtureSchemaParser);
    }

    #[Override]
    protected function schemaFile(FixtureSchema $schema): string
    {
        $yaml = 'format: '.FixtureSchema::FORMAT."\ntypes:\n";

        foreach ($schema->types as $type) {
            $yaml .= sprintf("  - handle: %s\n    label: %s\n    fields:\n", $type->handle->value, $type->label);
            $yaml .= implode('', array_map(
                static fn (FieldDefinition $field): string => sprintf("      - handle: %s\n        type: %s\n", $field->handle->value, $field->type->value),
                $type->fields,
            ));
        }

        return $this->file('fixture.yaml', $yaml);
    }

    #[Override]
    protected function unparsableSchemaFile(): string
    {
        return $this->file('broken.yaml', 'format: '.FixtureSchema::FORMAT."\n  types: [\n");
    }

    #[Override]
    protected function schemaFileWithoutTypes(): string
    {
        return $this->file('empty.yaml', 'format: '.FixtureSchema::FORMAT."\ntypes: []\n");
    }

    private function file(string $name, string $contents): string
    {
        $path = SchemaFixtures::scratch().'/'.$name;
        SchemaFixtures::write($path, $contents);

        return $path;
    }
}
