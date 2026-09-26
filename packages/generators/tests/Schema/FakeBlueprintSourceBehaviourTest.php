<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Schema\Fakes\FakeBlueprintSource;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * BlueprintSourceBehaviour against the fake that the generator's action tests use.
 */
final class FakeBlueprintSourceBehaviourTest extends TestCase
{
    use BlueprintSourceBehaviour;

    private const string BASE = '/srv/app';

    private ?FakeBlueprintSource $source = null;

    #[Override]
    protected function blueprintSource(): BlueprintSource
    {
        return $this->source();
    }

    #[Override]
    protected function schemaRoot(Owner $owner, string $directory): SchemaRoot
    {
        return $this->source()->root(new SchemaRoot($owner, self::BASE, $directory));
    }

    #[Override]
    protected function missingSchemaRoot(Owner $owner): SchemaRoot
    {
        return new SchemaRoot($owner, self::BASE, 'missing/'.$owner->value.'/schema');
    }

    #[Override]
    protected function putBlueprint(SchemaRoot $root, string $path, TypeBlueprint|ExtensionBlueprint $blueprint): void
    {
        $this->source()->put($root, $path, $blueprint);
    }

    #[Override]
    protected function putInvalidHandle(SchemaRoot $root, string $path): void
    {
        $this->source()->refuse($root, $path, [new GenerationProblem(
            GenerateErrorCode::SchemaInvalid,
            $root->file($path).', /handle: The string should match pattern: ^[a-z][a-z0-9]*(_[a-z0-9]+)*$',
        )]);
    }

    #[Override]
    protected function putLaterVersion(SchemaRoot $root, string $path): void
    {
        $this->source()->refuse($root, $path, [new GenerationProblem(
            GenerateErrorCode::SchemaUnsupportedVersion,
            $root->file($path).', /blueprint: the file is blueprint version 2, and this cboxdk/cms-generators reads version 1. The file needs a newer cboxdk/cms-generators.',
        )]);
    }

    /**
     * The one fake of the case, which both the source and the files come from.
     */
    private function source(): FakeBlueprintSource
    {
        return $this->source ??= new FakeBlueprintSource;
    }
}
