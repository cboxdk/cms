<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every BlueprintSource does, run against YamlBlueprintSource on scratch directories and
 * against FakeBlueprintSource, so the fake that the generator's action tests use cannot drift from
 * the reader (GUARDRAILS 9).
 */
trait BlueprintSourceBehaviour
{
    protected const string ARTICLE_ID = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

    protected const string PRODUCT_ID = '0192a3b4-c5d6-7e8f-9a0b-bbbbbbbbbbbb';

    abstract protected function blueprintSource(): BlueprintSource;

    /**
     * A new, empty schema root of the owner at the directory below the base.
     */
    abstract protected function schemaRoot(Owner $owner, string $directory): SchemaRoot;

    /**
     * A schema root whose directory does not exist.
     */
    abstract protected function missingSchemaRoot(Owner $owner): SchemaRoot;

    /**
     * A file at the path below the root that reads into the blueprint, which names that file.
     */
    abstract protected function putBlueprint(SchemaRoot $root, string $path, TypeBlueprint|ExtensionBlueprint $blueprint): void;

    /**
     * A file at the path below the root that is a type with the handle "Article", which breaks the
     * blueprint schema at /handle.
     */
    abstract protected function putInvalidHandle(SchemaRoot $root, string $path): void;

    /**
     * A file at the path below the root with `blueprint: 2`.
     */
    abstract protected function putLaterVersion(SchemaRoot $root, string $path): void;

    #[Test]
    public function it_reads_the_blueprints_of_every_root_with_the_root_as_owner(): void
    {
        $app = $this->schemaRoot(Owner::app(), 'schema');
        $acme = $this->schemaRoot(new Owner('acme'), 'vendor/acme/shop/schema');
        $article = BlueprintFixtures::type($app, 'blog/article.yaml', self::ARTICLE_ID, 'article');
        $taxCode = BlueprintFixtures::extension($app, 'shop/tax_code.yaml', self::PRODUCT_ID);
        $product = BlueprintFixtures::type($acme, 'product.yaml', self::PRODUCT_ID, 'product');
        $this->putBlueprint($app, 'blog/article.yaml', $article);
        $this->putBlueprint($app, 'shop/tax_code.yaml', $taxCode);
        $this->putBlueprint($acme, 'product.yaml', $product);
        $source = $this->blueprintSource();

        $blueprints = $source->read([$app, $acme]);

        Assert::assertEquals(new Blueprints([$article, $product], [$taxCode]), $blueprints);
        Assert::assertSame('app', $blueprints->extensions[0]->fields[0]->owner->value, 'An extension field belongs to the extender.');
        $credits = $blueprints->types[1]->fields[2]->options;
        Assert::assertInstanceOf(GroupOptions::class, $credits);
        Assert::assertSame('acme', $credits->fields[0]->owner->value, 'A field in a group belongs to the owner of its type.');
        Assert::assertEquals($blueprints, $source->read([$app, $acme]), 'Reading again gives the same blueprints.');
        Assert::assertEquals($blueprints, $source->read([$acme, $app]), 'The order of the roots does not matter.');
    }

    #[Test]
    public function it_reads_the_files_in_sorted_path_order_at_any_depth(): void
    {
        $app = $this->schemaRoot(Owner::app(), 'schema');
        $ids = ['b.yaml' => '0192a3b4-c5d6-7e8f-9a0b-000000000001', 'a/z.yaml' => '0192a3b4-c5d6-7e8f-9a0b-000000000002', 'a.yaml' => '0192a3b4-c5d6-7e8f-9a0b-000000000003', 'a/b/c.yaml' => '0192a3b4-c5d6-7e8f-9a0b-000000000004'];

        foreach ($ids as $path => $id) {
            $this->putBlueprint($app, $path, BlueprintFixtures::type($app, $path, $id, 'type_'.substr($id, -1)));
        }

        $files = array_map(static fn (TypeBlueprint $type): string => $type->location->file, $this->blueprintSource()->read([$app])->types);

        Assert::assertSame(['schema/a.yaml', 'schema/a/b/c.yaml', 'schema/a/z.yaml', 'schema/b.yaml'], $files);
    }

    #[Test]
    public function it_reads_only_the_roots_it_is_given(): void
    {
        $app = $this->schemaRoot(Owner::app(), 'schema');
        $acme = $this->schemaRoot(new Owner('acme'), 'vendor/acme/shop/schema');
        $this->putBlueprint($app, 'article.yaml', BlueprintFixtures::type($app, 'article.yaml', self::ARTICLE_ID, 'article'));
        $product = BlueprintFixtures::type($acme, 'product.yaml', self::PRODUCT_ID, 'product');
        $this->putBlueprint($acme, 'product.yaml', $product);

        Assert::assertEquals(new Blueprints([$product], []), $this->blueprintSource()->read([$acme]));
    }

    #[Test]
    public function no_roots_and_empty_roots_have_no_blueprints(): void
    {
        $source = $this->blueprintSource();

        Assert::assertEquals(new Blueprints([], []), $source->read([]));
        Assert::assertEquals(new Blueprints([], []), $source->read([$this->schemaRoot(Owner::app(), 'schema')]));
    }

    #[Test]
    public function a_missing_root_is_generate_schema_missing_naming_the_directory_and_owner(): void
    {
        $root = $this->missingSchemaRoot(new Owner('acme'));

        $failed = $this->failure([$root]);

        Assert::assertSame([GenerateErrorCode::SchemaMissing], $failed->codes());
        Assert::assertSame(
            sprintf('[generate_schema_missing] The schema root %s of acme does not exist or cannot be read. Create the directory, or remove the root.', $root->path()),
            $failed->problems[0]->describe(),
        );
    }

    #[Test]
    public function an_invalid_file_is_generate_schema_invalid_at_its_file_and_json_pointer(): void
    {
        $app = $this->schemaRoot(Owner::app(), 'schema');
        $this->putBlueprint($app, 'article.yaml', BlueprintFixtures::type($app, 'article.yaml', self::ARTICLE_ID, 'article'));
        $this->putInvalidHandle($app, 'broken.yaml');

        $failed = $this->failure([$app]);

        Assert::assertSame([GenerateErrorCode::SchemaInvalid], $failed->codes());
        Assert::assertStringStartsWith('schema/broken.yaml, /handle: ', $failed->problems[0]->message);
    }

    #[Test]
    public function every_problem_in_every_root_is_reported_in_one_run(): void
    {
        $app = $this->schemaRoot(Owner::app(), 'schema');
        $acme = $this->schemaRoot(new Owner('acme'), 'vendor/acme/shop/schema');
        $missing = $this->missingSchemaRoot(new Owner('beta'));
        $this->putInvalidHandle($app, 'broken.yaml');
        $this->putInvalidHandle($acme, 'nested/broken.yaml');
        $this->putLaterVersion($acme, 'later.yaml');

        $failed = $this->failure([$app, $acme, $missing]);
        $messages = array_map(static fn (GenerationProblem $problem): string => $problem->message, $failed->problems);

        Assert::assertSame([GenerateErrorCode::SchemaInvalid, GenerateErrorCode::SchemaInvalid, GenerateErrorCode::SchemaMissing, GenerateErrorCode::SchemaUnsupportedVersion], $failed->codes());
        Assert::assertStringStartsWith('schema/broken.yaml, /handle: ', $messages[0]);
        Assert::assertStringStartsWith('vendor/acme/shop/schema/nested/broken.yaml, /handle: ', $messages[1]);
        Assert::assertStringContainsString($missing->path(), $messages[2]);
        Assert::assertStringStartsWith('vendor/acme/shop/schema/later.yaml, /blueprint: ', $messages[3]);
    }

    #[Test]
    public function a_later_blueprint_version_is_generate_schema_unsupported_version_asking_for_a_newer_generator(): void
    {
        $app = $this->schemaRoot(Owner::app(), 'schema');
        $this->putLaterVersion($app, 'future.yaml');

        $failed = $this->failure([$app]);

        Assert::assertSame([GenerateErrorCode::SchemaUnsupportedVersion], $failed->codes());
        Assert::assertSame(
            '[generate_schema_unsupported_version] schema/future.yaml, /blueprint: the file is blueprint version 2, and this cboxdk/cms-generators reads version 1. The file needs a newer cboxdk/cms-generators.',
            $failed->problems[0]->describe(),
        );
    }

    /**
     * @param  list<SchemaRoot>  $roots
     */
    private function failure(array $roots): GenerationFailed
    {
        try {
            $this->blueprintSource()->read($roots);
        } catch (GenerationFailed $failed) {
            return $failed;
        }

        Assert::fail('The source read the roots without a problem.');
    }
}
