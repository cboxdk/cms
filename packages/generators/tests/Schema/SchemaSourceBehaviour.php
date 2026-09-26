<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SchemaSource does, run against YamlSchemaSource on scratch files and against
 * FakeSchemaSource, so the fake the generator's action tests use cannot drift from the source
 * (GUARDRAILS 9).
 */
trait SchemaSourceBehaviour
{
    /** A schema file that does not exist. */
    protected const string MISSING_SCHEMA = '/nonexistent/cbox-cms/schema/fixture.yaml';

    abstract protected function schemaSource(): SchemaSource;

    /**
     * The absolute path of a schema file that holds the schema.
     */
    abstract protected function schemaFile(FixtureSchema $schema): string;

    /**
     * The absolute path of a file that is not valid YAML.
     */
    abstract protected function unparsableSchemaFile(): string;

    /**
     * The absolute path of a YAML file in the schema's format with an empty list of types.
     */
    abstract protected function schemaFileWithoutTypes(): string;

    #[Test]
    public function it_loads_the_schema_in_a_file(): void
    {
        $schema = SchemaFixtures::schema(['page' => ['title' => 'text', 'body' => 'rich_text'], 'author' => ['name' => 'text']]);
        $path = $this->schemaFile($schema);
        $source = $this->schemaSource();

        Assert::assertEquals($schema, $source->load($path));
        Assert::assertEquals($schema, $source->load($path), 'Loading again gives the same schema.');
    }

    #[Test]
    public function a_missing_file_is_generate_schema_missing_naming_the_path(): void
    {
        $failed = $this->failure(self::MISSING_SCHEMA);

        Assert::assertSame([GenerateErrorCode::SchemaMissing], $failed->codes());
        Assert::assertSame(
            '[generate_schema_missing] The schema file '.self::MISSING_SCHEMA.' does not exist or cannot be read. Create it, or point cms.generators.schema at it.',
            $failed->problems[0]->describe(),
        );
    }

    #[Test]
    public function a_file_that_is_not_yaml_is_generate_schema_syntax(): void
    {
        $path = $this->unparsableSchemaFile();
        $failed = $this->failure($path);

        Assert::assertSame([GenerateErrorCode::SchemaSyntax], $failed->codes());
        Assert::assertStringContainsString($path.' is not valid YAML', $failed->getMessage());
    }

    #[Test]
    public function a_schema_without_types_is_generate_schema_invalid(): void
    {
        $path = $this->schemaFileWithoutTypes();
        $failed = $this->failure($path);

        Assert::assertSame([GenerateErrorCode::SchemaInvalid], $failed->codes());
        Assert::assertStringContainsString($path, $failed->getMessage());
    }

    private function failure(string $path): GenerationFailed
    {
        try {
            $this->schemaSource()->load($path);
        } catch (GenerationFailed $failed) {
            return $failed;
        }

        Assert::fail(sprintf('The source loaded %s.', $path));
    }
}
