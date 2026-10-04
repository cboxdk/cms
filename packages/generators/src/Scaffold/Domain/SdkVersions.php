<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The npm packages and versions the package.json of an addon's panel UI names: the SDK and what it
 * needs beside it, at the versions this release of cboxdk/cms is built and tested with. A test
 * holds them to the repository's own package manifests, so they never drift from the SDK.
 */
#[Internal]
final readonly class SdkVersions
{
    /** The SDK, a dependency. */
    public const string SDK = '@cboxdk/cms-panel';

    public const string SDK_VERSION = '0.1.0';

    /**
     * The development dependencies, by package, sorted.
     *
     * @var array<string, string>
     */
    public const array DEVELOPMENT = [
        '@types/node' => '22.20.4',
        '@types/react' => '19.3.0',
        '@types/react-dom' => '19.3.0',
        'eslint' => '10.11.0',
        'jsdom' => '29.1.1',
        'prettier' => '3.9.9',
        'react' => '19.3.0',
        'react-dom' => '19.3.0',
        'typescript' => '6.0.3',
        'vite' => '8.3.2',
        'vitest' => '4.1.11',
    ];

    /** The Node versions the SDK runs on. */
    public const string NODE = '^22.13.0 || >=24';

    private function __construct() {}
}
