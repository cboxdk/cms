<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Editor;

use Cbox\Cms\Generators\Editor\Domain\Dto\SchemaFile;
use Cbox\Cms\Generators\Editor\Domain\SchemaFiles;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SchemaFiles does, run against FilesystemSchemaFiles in scratch directories and
 * against FakeSchemaFiles, so the fake that the editor's action tests use cannot drift from the
 * filesystem (GUARDRAILS 9).
 */
trait SchemaFilesBehaviour
{
    abstract protected function schemaFiles(): SchemaFiles;

    /**
     * A new, empty, absolute and canonical directory to put roots below.
     */
    abstract protected function base(): string;

    abstract protected function putFile(SchemaFiles $files, string $path, string $contents): void;

    abstract protected function makeDirectory(SchemaFiles $files, string $path): void;

    /**
     * Makes the file impossible to write.
     */
    abstract protected function blockFile(SchemaFiles $files, string $path): void;

    /**
     * Makes the file impossible to read.
     */
    abstract protected function hideFile(SchemaFiles $files, string $path): void;

    abstract protected function contentsAt(SchemaFiles $files, string $path): ?string;

    #[Test]
    public function it_finds_every_yaml_file_below_the_roots_sorted_by_name(): void
    {
        $files = $this->schemaFiles();
        $base = $this->base();
        $app = new SchemaRoot(Owner::app(), $base, 'schema');
        $acme = new SchemaRoot(new Owner('acme'), $base, 'vendor/acme/shop/schema');
        $this->putFile($files, $base.'/schema/page.yaml', "blueprint: 1\n");
        $this->putFile($files, $base.'/schema/blog/article.yaml', "blueprint: 1\n");
        $this->putFile($files, $base.'/schema/README.md', "Not a blueprint.\n");
        $this->putFile($files, $base.'/schema/page.yml', "Not a blueprint file either.\n");
        $this->putFile($files, $base.'/vendor/acme/shop/schema/product.yaml', "blueprint: 1\n");

        Assert::assertEquals([
            new SchemaFile($base.'/schema/blog/article.yaml', 'schema/blog/article.yaml', $base.'/schema/blog'),
            new SchemaFile($base.'/schema/page.yaml', 'schema/page.yaml', $base.'/schema'),
            new SchemaFile($base.'/vendor/acme/shop/schema/product.yaml', 'vendor/acme/shop/schema/product.yaml', $base.'/vendor/acme/shop/schema'),
        ], $files->find([$acme, $app]));
    }

    #[Test]
    public function it_finds_nothing_in_a_root_without_yaml_files(): void
    {
        $files = $this->schemaFiles();
        $base = $this->base();
        $this->makeDirectory($files, $base.'/schema');

        Assert::assertSame([], $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]));
    }

    #[Test]
    public function it_refuses_a_missing_root_with_generate_schema_missing(): void
    {
        $files = $this->schemaFiles();
        $base = $this->base();
        $this->makeDirectory($files, $base.'/schema');

        $failed = $this->failure(static fn () => $files->find([new SchemaRoot(Owner::app(), $base, 'schema'), new SchemaRoot(new Owner('acme'), $base, 'missing')]));

        Assert::assertSame([GenerateErrorCode::SchemaMissing], $failed->codes());
        Assert::assertSame(sprintf('The schema root %s/missing of acme does not exist or cannot be read. Create the directory, or remove the root.', $base), $failed->problems[0]->message);
    }

    #[Test]
    public function it_refuses_a_root_that_lies_in_another_with_generate_invalid_config(): void
    {
        $files = $this->schemaFiles();
        $base = $this->base();
        $this->putFile($files, $base.'/schema/acme/product.yaml', "blueprint: 1\n");

        $failed = $this->failure(static fn () => $files->find([new SchemaRoot(Owner::app(), $base, 'schema'), new SchemaRoot(new Owner('acme'), $base, 'schema/acme')]));

        Assert::assertSame([GenerateErrorCode::InvalidConfig], $failed->codes());
        Assert::assertSame(sprintf('The schema root %1$s/schema/acme of acme lies in the schema root %1$s/schema of app, so its files would be read twice. Give each directory once.', $base), $failed->problems[0]->message);
    }

    #[Test]
    public function it_reads_and_writes_the_bytes_of_a_file(): void
    {
        $files = $this->schemaFiles();
        $base = $this->base();
        $this->putFile($files, $base.'/schema/page.yaml', "blueprint: 1\r\n# kept\n");
        [$file] = $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]);

        Assert::assertSame("blueprint: 1\r\n# kept\n", $files->read($file));

        $files->write($file, "# line\nblueprint: 1\r\n# kept\n");

        Assert::assertSame("# line\nblueprint: 1\r\n# kept\n", $files->read($file));
        Assert::assertSame("# line\nblueprint: 1\r\n# kept\n", $this->contentsAt($files, $base.'/schema/page.yaml'));
    }

    #[Test]
    public function it_refuses_to_write_an_unwritable_file_and_keeps_its_bytes(): void
    {
        $this->skipAsRoot();
        $files = $this->schemaFiles();
        $base = $this->base();
        $this->putFile($files, $base.'/schema/page.yaml', "blueprint: 1\n");
        [$file] = $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]);
        $this->blockFile($files, $base.'/schema/page.yaml');

        $failed = $this->failure(static fn () => $files->write($file, "# line\nblueprint: 1\n"));

        Assert::assertSame([GenerateErrorCode::SchemaUnwritable], $failed->codes());
        Assert::assertSame('schema/page.yaml cannot be written: the file is read-only. Its editor line was not changed; make it writable and run cms:schema:editor again.', $failed->problems[0]->message);
        Assert::assertSame("blueprint: 1\n", $this->contentsAt($files, $base.'/schema/page.yaml'));
    }

    #[Test]
    public function it_refuses_to_read_an_unreadable_file(): void
    {
        $this->skipAsRoot();
        $files = $this->schemaFiles();
        $base = $this->base();
        $this->putFile($files, $base.'/schema/page.yaml', "blueprint: 1\n");
        [$file] = $files->find([new SchemaRoot(Owner::app(), $base, 'schema')]);
        $this->hideFile($files, $base.'/schema/page.yaml');

        $failed = $this->failure(static fn () => $files->read($file));

        Assert::assertSame([GenerateErrorCode::SchemaMissing], $failed->codes());
        Assert::assertSame('schema/page.yaml cannot be read. Check its permissions.', $failed->problems[0]->message);
    }

    private function skipAsRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
    }

    /**
     * @param  callable(): mixed  $call
     */
    private function failure(callable $call): GenerationFailed
    {
        try {
            $call();
        } catch (GenerationFailed $failed) {
            return $failed;
        }

        Assert::fail('Expected GenerationFailed.');
    }
}
