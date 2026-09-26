<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;
use Cbox\Cms\Generators\Tests\Schema\Fakes\FakeSchemaSource;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SchemaSourceBehaviour against the fake the generator's action tests use.
 */
final class FakeSchemaSourceBehaviourTest extends TestCase
{
    use SchemaSourceBehaviour;

    private ?FakeSchemaSource $source = null;

    #[Override]
    protected function schemaSource(): SchemaSource
    {
        return $this->source();
    }

    #[Override]
    protected function schemaFile(FixtureSchema $schema): string
    {
        $this->source()->put('/srv/app/schema/fixture.yaml', $schema);

        return '/srv/app/schema/fixture.yaml';
    }

    #[Override]
    protected function unparsableSchemaFile(): string
    {
        $path = '/srv/app/schema/broken.yaml';
        $this->source()->refuse($path, GenerationFailed::because(GenerateErrorCode::SchemaSyntax, sprintf('The schema file %s is not valid YAML: Malformed inline YAML string at line 2.', $path)));

        return $path;
    }

    #[Override]
    protected function schemaFileWithoutTypes(): string
    {
        $path = '/srv/app/schema/empty.yaml';
        $this->source()->refuse($path, GenerationFailed::with([new GenerationProblem(GenerateErrorCode::SchemaInvalid, $path.', types: must be a list with at least one item')]));

        return $path;
    }

    /**
     * The one fake of the case, which both the source and the files come from.
     */
    private function source(): FakeSchemaSource
    {
        return $this->source ??= new FakeSchemaSource;
    }
}
