<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Mutation\Adapter\MutatedSourcePreload;
use Cbox\Cms\Tooling\Mutation\Adapter\PestMutationReport;
use Pest\Mutate\Plugins\Mutate;

/*
 * A process that tests one mutation loads the mutated source before any test runs, so a test that
 * starts a PHPStan analysis, which puts PHP's own file:// wrapper back, cannot make a class that is
 * loaded later come from the original source.
 */

afterEach(function (): void {
    putenv(Mutate::ENV_MUTATION_TESTING);
    ScratchDirectory::cleanUp();
});

it('loads the source of the mutation when the process tests one, before it hands the arguments on', function (): void {
    $class = 'MutatedSourceProbe'.bin2hex(random_bytes(4));
    $file = ScratchDirectory::write(ScratchDirectory::make().'/'.$class.'.php', "<?php\n\nfinal class {$class}\n{\n}\n");
    putenv(Mutate::ENV_MUTATION_TESTING.'='.$file);

    $arguments = new PestMutationReport()->handleArguments(['--filter=nothing']);

    expect(class_exists($class, false))->toBeTrue()
        ->and($arguments)->toBe(['--filter=nothing']);
});

it('loads nothing for a path that is no file, and in a process that tests no mutation', function (): void {
    MutatedSourcePreload::load('');
    MutatedSourcePreload::load('/nonexistent/cbox-cms-probe.php');

    expect(new PestMutationReport()->handleArguments(['--filter=nothing']))->toBe(['--filter=nothing']);
});
