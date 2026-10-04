<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldResult;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldOutput;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every ScaffoldOutput does (PRD 13.4), run against FilesystemScaffoldOutput in scratch
 * directories and against FakeScaffoldOutput, so the fake the scaffold actions' tests use cannot
 * drift from the filesystem (GUARDRAILS 9): the package from composer.json, files read where they
 * are, a file written only where none exists, an update written whatever exists, and a path that
 * cannot be written refused.
 */
trait ScaffoldOutputBehaviour
{
    abstract protected function scaffoldOutput(): ScaffoldOutput;

    /**
     * A new, empty absolute directory of an addon's package.
     */
    abstract protected function scaffoldRoot(): string;

    /**
     * Places a file below the root, as the addon's author or an earlier run would have.
     */
    abstract protected function putFile(ScaffoldOutput $output, string $root, string $path, string $contents): void;

    /**
     * Makes the path below the root impossible to write.
     */
    abstract protected function blockPath(ScaffoldOutput $output, string $root, string $path): void;

    /**
     * The contents of a file below the root, or null when there is none.
     */
    abstract protected function contentsAt(ScaffoldOutput $output, string $root, string $path): ?string;

    #[Test]
    public function it_reads_the_package_from_composer_json(): void
    {
        $output = $this->scaffoldOutput();
        $root = $this->scaffoldRoot();
        $this->putFile($output, $root, 'composer.json', ScaffoldWorld::composerJson());

        $package = $output->package($root);

        Assert::assertSame('acme/cms-tally', $package->name);
        Assert::assertSame('Acme\Tally', $package->namespace);
        Assert::assertSame('Acme\Tally\Tests', $package->testNamespace);
        Assert::assertSame('Acme\Tally\TallyServiceProvider', $package->provider);
        Assert::assertSame('@acme/cms-tally', $package->npmName());
    }

    #[Test]
    public function it_refuses_a_root_without_composer_json(): void
    {
        $output = $this->scaffoldOutput();
        $root = $this->scaffoldRoot();

        try {
            $output->package($root);
        } catch (GenerationFailed $refused) {
            Assert::assertSame(GenerateErrorCode::InvalidConfig, $refused->problems[0]->code);
            Assert::assertStringContainsString($root.' has no readable composer.json', $refused->problems[0]->describe());

            return;
        }

        Assert::fail('A root without composer.json was read as a package.');
    }

    #[Test]
    public function it_reads_a_file_below_the_root_and_null_for_none(): void
    {
        $output = $this->scaffoldOutput();
        $root = $this->scaffoldRoot();
        $this->putFile($output, $root, 'resources/panel/src/index.ts', "export {};\n");

        Assert::assertSame("export {};\n", $output->read($root, 'resources/panel/src/index.ts'));
        Assert::assertNull($output->read($root, 'resources/panel/src/ids.ts'));
    }

    #[Test]
    public function it_writes_a_file_only_where_none_exists_and_an_update_whatever_exists(): void
    {
        $output = $this->scaffoldOutput();
        $root = $this->scaffoldRoot();
        $this->putFile($output, $root, 'package.json', "{\n  \"name\": \"the author's\"\n}\n");
        $this->putFile($output, $root, 'resources/panel/src/ids.ts', "export const CONTRIBUTIONS: readonly string[] = [];\n");

        $report = $output->write($root, new ScaffoldResult(
            files: [
                new GeneratedFile('tsconfig.json', "{}\n"),
                new GeneratedFile('package.json', "{\n  \"name\": \"the scaffold's\"\n}\n"),
            ],
            updates: [
                new GeneratedFile('resources/panel/src/ids.ts', "export const CONTRIBUTIONS: readonly string[] = ['tally.badge'];\n"),
                new GeneratedFile('resources/panel/src/index.ts', "export {};\n"),
            ],
            notes: ['Run npm install.'],
        ));

        Assert::assertSame(['resources/panel/src/ids.ts', 'resources/panel/src/index.ts', 'tsconfig.json'], $report->written);
        Assert::assertSame(['package.json'], $report->kept);
        Assert::assertSame(['Run npm install.'], $report->notes);
        Assert::assertSame("{\n  \"name\": \"the author's\"\n}\n", $this->contentsAt($output, $root, 'package.json'));
        Assert::assertSame("{}\n", $this->contentsAt($output, $root, 'tsconfig.json'));
        Assert::assertSame("export const CONTRIBUTIONS: readonly string[] = ['tally.badge'];\n", $this->contentsAt($output, $root, 'resources/panel/src/ids.ts'));
        Assert::assertSame("export {};\n", $this->contentsAt($output, $root, 'resources/panel/src/index.ts'));
    }

    #[Test]
    public function a_second_write_of_the_same_result_keeps_everything(): void
    {
        $output = $this->scaffoldOutput();
        $root = $this->scaffoldRoot();
        $result = new ScaffoldResult(
            files: [new GeneratedFile('tsconfig.json', "{}\n")],
            updates: [new GeneratedFile('resources/panel/src/ids.ts', "export const CONTRIBUTIONS: readonly string[] = [];\n")],
        );
        $output->write($root, $result);

        $report = $output->write($root, $result);

        Assert::assertSame([], $report->written);
        Assert::assertSame(['resources/panel/src/ids.ts', 'tsconfig.json'], $report->kept);
    }

    #[Test]
    public function it_refuses_a_path_that_cannot_be_written(): void
    {
        $output = $this->scaffoldOutput();
        $root = $this->scaffoldRoot();
        $this->blockPath($output, $root, 'vite.config.ts');

        try {
            $output->write($root, new ScaffoldResult(files: [new GeneratedFile('vite.config.ts', "export default {};\n")]));
        } catch (GenerationFailed $refused) {
            Assert::assertSame(GenerateErrorCode::OutputUnwritable, $refused->problems[0]->code);
            Assert::assertStringContainsString('vite.config.ts could not be written', $refused->problems[0]->describe());

            return;
        }

        Assert::fail('A blocked path was written.');
    }
}
