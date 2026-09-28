<?php

declare(strict_types=1);

/*
 * `composer docs:screenshots`: captures the screenshots of the documentation, the terminal output
 * of each command in Cbox\Cms\Tooling\Docs\Domain\Screenshots, into docs/screenshots/<key>.svg.
 *
 *   php tools/bin/docs-screenshots.php [--only=<key>]...
 *
 * Run it in the php container of `composer services:up` after `composer dev:prepare`, so the output
 * is that of the development environment the pages describe. It runs each command from the root
 * of this repository, fails when a command ends with another exit code than its entry expects and
 * then writes nothing for that shot, and writes only a file whose bytes differ.
 *
 * Exits 0 when every shot was captured, 1 when one failed, and 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Docs\Boundary\DocsScreenshotsOptions;
use Cbox\Cms\Tooling\Docs\Boundary\ScreenshotCapture;

$repository = (string) realpath(dirname(__DIR__, 2));

require $repository.'/vendor/autoload.php';

try {
    $options = DocsScreenshotsOptions::forRepository(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".DocsScreenshotsOptions::USAGE."\n");
    exit(2);
}

$failed = 0;

foreach ($options->shots as $shot) {
    fwrite(STDOUT, "docs:screenshots: {$shot->key}: {$shot->commandLine()}\n");

    try {
        $svg = ScreenshotCapture::capture($repository, $shot);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        $failed++;

        continue;
    }

    $path = $repository.'/'.$shot->path();

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o755, true);
    }

    if (is_file($path) && file_get_contents($path) === $svg) {
        fwrite(STDOUT, "docs:screenshots: {$shot->path()} is unchanged.\n");

        continue;
    }

    if (file_put_contents($path, $svg) === false) {
        fwrite(STDERR, "docs:screenshots: cannot write {$shot->path()}.\n");
        $failed++;

        continue;
    }

    fwrite(STDOUT, "docs:screenshots: wrote {$shot->path()}.\n");
}

if ($failed > 0) {
    fwrite(STDERR, sprintf("docs:screenshots: %d of %d %s failed.\n", $failed, count($options->shots), count($options->shots) === 1 ? 'shot' : 'shots'));
    exit(1);
}

exit(0);
