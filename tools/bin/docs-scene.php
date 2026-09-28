<?php

declare(strict_types=1);

/*
 * Runs an artisan command of the workbench in a scene, for the screenshots of the documentation
 * (Cbox\Cms\Tooling\Docs\Domain\Screenshots):
 *
 *   php tools/bin/docs-scene.php <scene> <command> [<argument>...]
 *
 * It boots the workbench application as vendor/bin/testbench does, from testbench.yaml, binds the
 * scene's fixtures (Cbox\Cms\Tooling\Docs\Adapter\SceneFixtures), runs the command with colour on
 * and exits with the command's exit code. Exits 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Docs\Adapter\SceneFixtures;
use Cbox\Cms\Tooling\Docs\Domain\Scene;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$repository = (string) realpath(dirname(__DIR__, 2));

require $repository.'/vendor/autoload.php';

$arguments = CommandLine::arguments();
$scene = Scene::tryFrom($arguments[0] ?? '');

if (! $scene instanceof Scene || count($arguments) < 2) {
    $scenes = implode('|', array_map(static fn (Scene $case): string => $case->value, Scene::cases()));
    fwrite(STDERR, "Usage: php tools/bin/docs-scene.php <{$scenes}> <command> [<argument>...]\n");
    exit(2);
}

$app = Application::createFromConfig(Config::loadFromYaml($repository));
SceneFixtures::bind($app, $scene);

$kernel = $app->make(Kernel::class);
$status = $kernel->handle(new ArgvInput(['artisan', ...array_slice($arguments, 1)]), new ConsoleOutput(decorated: true));
$kernel->terminate(new ArgvInput(['artisan', ...array_slice($arguments, 1)]), $status);

exit($status);
