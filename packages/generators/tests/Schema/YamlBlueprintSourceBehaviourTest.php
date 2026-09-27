<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Schema\Boundary\BlueprintDocumentReader;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * BlueprintSourceBehaviour against YamlBlueprintSource on files in a scratch directory, validated
 * against the blueprint schema in the installed cboxdk/cms-contracts.
 */
final class YamlBlueprintSourceBehaviourTest extends TestCase
{
    use BlueprintSourceBehaviour;

    private ?string $base = null;

    #[Override]
    protected function tearDown(): void
    {
        SchemaFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function blueprintSource(): BlueprintSource
    {
        return new YamlBlueprintSource(new BlueprintSchemaFile, new BlueprintDocumentReader(new FieldTypeRegistry(new CoreFieldTypes)), new BlueprintRules);
    }

    #[Override]
    protected function schemaRoot(Owner $owner, string $directory): SchemaRoot
    {
        $root = new SchemaRoot($owner, $this->base(), $directory);
        mkdir($root->path(), 0o775, true);

        return $root;
    }

    #[Override]
    protected function missingSchemaRoot(Owner $owner): SchemaRoot
    {
        return new SchemaRoot($owner, $this->base(), 'missing/'.$owner->value.'/schema');
    }

    #[Override]
    protected function putBlueprint(SchemaRoot $root, string $path, TypeBlueprint|ExtensionBlueprint $blueprint): void
    {
        SchemaFixtures::write($root->path().'/'.$path, BlueprintFixtures::yaml($blueprint));
    }

    #[Override]
    protected function putInvalidHandle(SchemaRoot $root, string $path): void
    {
        $type = BlueprintFixtures::type($root, $path, '0192a3b4-c5d6-7e8f-9a0b-cccccccccccc', 'article');

        SchemaFixtures::write($root->path().'/'.$path, str_replace("handle: article\n", "handle: Article\n", BlueprintFixtures::yaml($type)));
    }

    #[Override]
    protected function putLaterVersion(SchemaRoot $root, string $path): void
    {
        SchemaFixtures::write($root->path().'/'.$path, "blueprint: 2\nkind: type\nsomething: new\n");
    }

    private function base(): string
    {
        return $this->base ??= SchemaFixtures::scratch();
    }
}
