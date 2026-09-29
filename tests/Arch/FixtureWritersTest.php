<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Phpstan\KernelTableWriteRule;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\FixtureWriters;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Cbox\Cms\Tests\Support\Arch\SourceFile;
use ReflectionClass;

/*
 * PRD 6.5 invariants 1 and 13: outside the kernel, only the testkit's fixture writers write the
 * kernel's tables, and the testkit's KernelTableWriteRule allows them because tests alone run
 * them. These tests hold that no production module, packages/*\/src outside the testkit and
 * workbench/app, uses them.
 */

arch('fixture writers: no production module or the workbench uses the testkit\'s fixture writers', function (): void {
    Rules::none(FixtureWriters::violations(Codebase::code()), 'Production code may not load the testkit\'s fixture writers, the one allowed writer of the kernel\'s tables outside the kernel (PRD 6.5 invariants 1 and 13):');
});

arch('fixture writers: the namespace the rule allows is the directory in the testkit', function (): void {
    expect(is_dir(Codebase::root().'/'.FixtureWriters::DIRECTORY))->toBeTrue()
        ->and(PostgresIdentitySeeder::class)->toStartWith(KernelTableWriteRule::FIXTURE_WRITERS.'\\')
        ->and((string) new ReflectionClass(PostgresIdentitySeeder::class)->getFileName())->toStartWith(Codebase::root().'/'.FixtureWriters::DIRECTORY.'/');
});

arch('fixture writers: a production module that imports or names a fixture writer is reported', function (): void {
    $core = SourceFile::parse(Codebase::root().'/packages/core/src/Selftest/Adapter/Seeds.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Selftest\Adapter;

        use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;

        final readonly class Seeds
        {
            public const string WRITER = 'Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder';

            public function __construct(public PostgresIdentitySeeder $seeder) {}
        }
        PHP);
    $testkit = SourceFile::parse(Codebase::root().'/packages/testkit/src/Identity/Uses.php', <<<'PHP'
        <?php

        namespace Cbox\Cms\Testkit\Identity;

        use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
        PHP);

    expect(FixtureWriters::violations([$core, $testkit]))->toBe([
        'packages/core/src/Selftest/Adapter/Seeds.php:7: class Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder',
        'packages/core/src/Selftest/Adapter/Seeds.php:11: string Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder',
    ]);
});
