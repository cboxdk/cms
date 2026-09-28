<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * The files of this repository that are checks in the sense of GUARDRAILS 7.3: the tests and their
 * support and fixtures (every path below a `tests` directory), the running examples in `examples`,
 * which run in the gate-5 suites, the testkit with its contract suites, fakes and PHPStan rules,
 * the gate runner in `tools`, the shared JS tooling, the configuration of PHPUnit, PHPStan, Rector
 * and Pint, the root files that define or configure the gates (the Composer and npm scripts the
 * gates run, the root ESLint, TypeScript and Prettier files), the CI files, and the environment the
 * gates run in: the services in `compose.yaml` and the images, init scripts and settings in `docker`.
 */
final readonly class CheckPaths
{
    /** @var list<string> */
    private const array PREFIXES = ['packages/testkit/', 'tools/', 'js/tooling/', '.github/', 'examples/', 'docker/'];

    /** @var list<string> */
    private const array FILES = [
        'phpunit.xml',
        'phpunit.xml.dist',
        'phpstan.neon',
        'phpstan.neon.dist',
        'rector.php',
        'pint.json',
        'composer.json',
        'package.json',
        'eslint.config.js',
        'tsconfig.json',
        '.prettierrc',
        '.prettierignore',
        'bin/ci',
        'compose.ci.yaml',
        'compose.yaml',
    ];

    public static function isCheck(string $path): bool
    {
        return in_array($path, self::FILES, true)
            || array_any(self::PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix))
            || in_array('tests', array_slice(explode('/', $path), 0, -1), true);
    }
}
