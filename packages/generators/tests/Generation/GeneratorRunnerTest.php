<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use PHPUnit\Framework\Assert;

/*
 * The runner collects the generators' files into one sorted result and checks them before
 * anything is written.
 */

/**
 * @param  list<Generator>  $generators
 */
function runGenerators(array $generators): GenerationFailed
{
    try {
        new GeneratorRunner($generators)->run(SchemaFixtures::schema(['page' => ['title' => 'text']]), SchemaFixtures::target());
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The runner accepted invalid output.');
}

it('sorts the files by path and the directories, whatever order the generators run in', function (): void {
    $result = new GeneratorRunner([
        new FixedGenerator('b/Generated', ['b/Generated/Z.php', 'b/Generated/A.php']),
        new FixedGenerator('a/generated', ['a/generated/sub/x.ts', 'a/generated/index.ts']),
    ])->run(SchemaFixtures::schema(['page' => ['title' => 'text']]), SchemaFixtures::target());

    expect($result->paths())->toBe(['a/generated/index.ts', 'a/generated/sub/x.ts', 'b/Generated/A.php', 'b/Generated/Z.php'])
        ->and($result->directories)->toBe(['a/generated', 'b/Generated']);
});

it('refuses a directory that is not named Generated or generated, because stale files in it are removed', function (): void {
    $failed = runGenerators([new FixedGenerator('app/Models', ['app/Models/Page.php'])]);

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
        ->and($failed->getMessage())->toContain('owns "app/Models", but an owned directory must be named "Generated" or "generated"');
});

it('refuses a file outside the generator\'s directory', function (): void {
    $failed = runGenerators([new FixedGenerator('app/Generated', ['app/Other.php', 'app/GeneratedX/A.php'])]);

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput, GenerateErrorCode::InvalidOutput])
        ->and($failed->getMessage())->toContain('produced "app/Other.php", which is outside its directory "app/Generated"')
        ->and($failed->getMessage())->toContain('produced "app/GeneratedX/A.php", which is outside its directory "app/Generated"');
});

it('refuses two files with the same path', function (): void {
    $failed = runGenerators([
        new FixedGenerator('app/Generated', ['app/Generated/A.php']),
        new FixedGenerator('app/Generated', ['app/Generated/A.php']),
    ]);

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
        ->and($failed->getMessage())->toContain('More than one generator produced "app/Generated/A.php".');
});

it('refuses file paths that leave the root or are not relative', function (string $path): void {
    expect(static fn (): GeneratedFile => new GeneratedFile($path, "x\n"))
        ->toThrow(GenerationFailed::class, 'is not a relative path');
})->with(['', '/etc/passwd', 'app/../../x.php', 'app/./x.php', 'app//x.php', 'app\\x.php']);

it('refuses contents without exactly one final newline or with carriage returns', function (string $contents): void {
    expect(static fn (): GeneratedFile => new GeneratedFile('app/Generated/A.php', $contents))
        ->toThrow(GenerationFailed::class, 'must end with exactly one newline');
})->with(['no newline' => 'x', 'two newlines' => "x\n\n", 'crlf' => "x\r\n"]);
