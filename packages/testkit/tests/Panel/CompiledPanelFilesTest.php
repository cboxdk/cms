<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Panel;

use Cbox\Cms\Testkit\Panel\Boundary\CompiledPanelFiles;
use RuntimeException;

/*
 * CompiledPanelFiles reads what cms:build wrote: the addons' namespaces from addons.php and the
 * contributions of each point from panel.php, in the order they are listed; a missing file and a
 * file that is no registry file are refused with their path.
 */

function compiledPanelDirectory(string $addons, string $panel): string
{
    $directory = sys_get_temp_dir().'/cms-compiled-panel-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700);
    file_put_contents($directory.'/addons.php', $addons);
    file_put_contents($directory.'/panel.php', $panel);

    return $directory;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/cms-compiled-panel-*') ?: [] as $directory) {
        @unlink($directory.'/addons.php');
        @unlink($directory.'/panel.php');
        @rmdir($directory);
    }
});

it('reads the addons and the contributions of each point', function (): void {
    $directory = compiledPanelDirectory(
        "<?php return ['entries' => [['namespace' => 'tally', 'package' => 'acme/cms-tally'], ['namespace' => 'approvals']]];",
        "<?php return ['entries' => [['id' => 'desk.cards@1', 'fills' => [['contribution' => 'tally.count'], ['contribution' => 'approvals.badge']]], ['id' => 'desk.aside@1', 'fills' => []]]];",
    );

    $compiled = CompiledPanelFiles::read($directory);

    expect($compiled->addons)->toBe(['tally', 'approvals'])
        ->and($compiled->contributionsOf('desk.cards@1'))->toBe(['tally.count', 'approvals.badge'])
        ->and($compiled->contributionsOf('desk.aside@1'))->toBe([])
        ->and($compiled->contributionsOf('desk.missing@1'))->toBe([]);
});

it('refuses a missing file and a file that is no registry file', function (): void {
    $directory = compiledPanelDirectory("<?php return ['entries' => []];", '<?php return 42;');

    expect(fn (): CompiledPanelFiles => CompiledPanelFiles::read($directory))
        ->toThrow(RuntimeException::class, 'panel.php is not a registry file');

    unlink($directory.'/addons.php');

    expect(fn (): CompiledPanelFiles => CompiledPanelFiles::read($directory))
        ->toThrow(RuntimeException::class, 'cms:build wrote no '.$directory.'/addons.php');
});
