<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every GeneratedOutput does (PRD 11.12), run against FilesystemGeneratedOutput in scratch
 * directories and against FakeGeneratedOutput, so the fake the generator's action tests use
 * cannot drift from the filesystem (GUARDRAILS 9).
 */
trait GeneratedOutputBehaviour
{
    abstract protected function generatedOutput(): GeneratedOutput;

    /**
     * A new, empty absolute directory to write below.
     */
    abstract protected function outputRoot(): string;

    /**
     * Places a file below the root, as an earlier run or a person would have.
     */
    abstract protected function putFile(GeneratedOutput $output, string $root, string $path, string $contents): void;

    /**
     * Makes the path below the root impossible to write.
     */
    abstract protected function blockPath(GeneratedOutput $output, string $root, string $path): void;

    /**
     * The contents of a file below the root, or null when there is none.
     */
    abstract protected function contentsAt(GeneratedOutput $output, string $root, string $path): ?string;

    /**
     * Every file below the root, relative to it and sorted.
     *
     * @return list<string>
     */
    abstract protected function filesBelow(GeneratedOutput $output, string $root): array;

    #[Test]
    public function it_writes_every_file_of_a_new_result(): void
    {
        $output = $this->generatedOutput();
        $root = $this->outputRoot();

        $report = $output->write($root, $this->generationResult());

        Assert::assertSame(['app/Generated/TypeHandle.php', 'js/generated/index.ts'], $report->written);
        Assert::assertSame([], $report->unchanged);
        Assert::assertSame([], $report->removed);
        Assert::assertTrue($report->changed());
        Assert::assertSame("<?php\n", $this->contentsAt($output, $root, 'app/Generated/TypeHandle.php'));
        Assert::assertSame(['app/Generated/TypeHandle.php', 'js/generated/index.ts'], $this->filesBelow($output, $root));
    }

    #[Test]
    public function a_second_write_of_the_same_result_changes_nothing(): void
    {
        $output = $this->generatedOutput();
        $root = $this->outputRoot();
        $output->write($root, $this->generationResult());

        $report = $output->write($root, $this->generationResult());

        Assert::assertSame([], $report->written);
        Assert::assertSame(['app/Generated/TypeHandle.php', 'js/generated/index.ts'], $report->unchanged);
        Assert::assertFalse($report->changed());
    }

    #[Test]
    public function only_a_file_with_other_contents_is_written_again(): void
    {
        $output = $this->generatedOutput();
        $root = $this->outputRoot();
        $this->putFile($output, $root, 'app/Generated/TypeHandle.php', "<?php\n");
        $this->putFile($output, $root, 'js/generated/index.ts', "export const edited = true;\n");

        $report = $output->write($root, $this->generationResult());

        Assert::assertSame(['js/generated/index.ts'], $report->written);
        Assert::assertSame(['app/Generated/TypeHandle.php'], $report->unchanged);
        Assert::assertSame("export {};\n", $this->contentsAt($output, $root, 'js/generated/index.ts'));
    }

    #[Test]
    public function it_removes_the_stale_files_in_the_owned_directories_and_nothing_outside_them(): void
    {
        $output = $this->generatedOutput();
        $root = $this->outputRoot();
        $this->putFile($output, $root, 'app/Generated/OldHandle.php', "<?php\n");
        $this->putFile($output, $root, 'app/Generated/Nested/Deep.php', "<?php\n");
        $this->putFile($output, $root, 'app/Models/Page.php', "<?php\n");
        $this->putFile($output, $root, 'js/generatedExtra/keep.ts', "export {};\n");

        $report = $output->write($root, $this->generationResult());

        Assert::assertSame(['app/Generated/Nested/Deep.php', 'app/Generated/OldHandle.php'], $report->removed);
        Assert::assertSame(['app/Generated/TypeHandle.php', 'app/Models/Page.php', 'js/generated/index.ts', 'js/generatedExtra/keep.ts'], $this->filesBelow($output, $root));
    }

    #[Test]
    public function a_file_that_cannot_be_written_stops_the_write_with_generate_output_unwritable(): void
    {
        $output = $this->generatedOutput();
        $root = $this->outputRoot();
        $this->blockPath($output, $root, 'js/generated/index.ts');

        try {
            $output->write($root, $this->generationResult());
            Assert::fail('A blocked path was written.');
        } catch (GenerationFailed $failed) {
            Assert::assertSame([GenerateErrorCode::OutputUnwritable], $failed->codes());
            Assert::assertStringContainsString('The generated file '.$root.'/js/generated/index.ts could not be written: ', $failed->getMessage());
        }

        Assert::assertSame("<?php\n", $this->contentsAt($output, $root, 'app/Generated/TypeHandle.php'), 'The files before it are written.');
    }

    private function generationResult(): GenerationResult
    {
        return new GenerationResult(
            [
                new GeneratedFile('app/Generated/TypeHandle.php', "<?php\n"),
                new GeneratedFile('js/generated/index.ts', "export {};\n"),
            ],
            ['app/Generated', 'js/generated'],
        );
    }
}
