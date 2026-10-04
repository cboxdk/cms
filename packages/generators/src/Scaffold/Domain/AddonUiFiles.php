<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\PanelTypes\Domain\ShapeDeclarations;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonPackage;

/**
 * The files of an addon's panel UI beside its code (PRD 13.4, sections 4.5 and 7 of the panel
 * extension architecture), as cms:make:addon-ui writes them into the addon's package, each only
 * where the addon has none: package.json with the SDK and its scripts, tsconfig.json on the shared
 * base, the Vite build with the SDK's plugin, Vitest, ESLint on the SDK's rules, Prettier on the
 * shared configuration, and the PHP test that runs the testkit's PanelContributionsContract.
 */
#[Internal]
final readonly class AddonUiFiles
{
    public const string PACKAGE = 'package.json';

    public const string TSCONFIG = 'tsconfig.json';

    public const string VITE = 'vite.config.ts';

    public const string VITEST = 'vitest.config.ts';

    public const string ESLINT = 'eslint.config.js';

    public const string PRETTIER = '.prettierrc';

    public const string PHP_TEST = 'tests/Panel/PanelContributionsTest.php';

    private function __construct() {}

    /**
     * Every file of the skeleton, relative to the package.
     *
     * @return list<GeneratedFile>
     */
    public static function files(AddonNamespace $namespace, AddonPackage $package): array
    {
        return [
            self::packageJson($namespace, $package),
            self::tsconfig(),
            self::vite($namespace),
            self::vitest(),
            self::eslint(),
            self::prettier(),
            self::phpTest($namespace, $package),
        ];
    }

    private static function packageJson(AddonNamespace $namespace, AddonPackage $package): GeneratedFile
    {
        $development = [];

        foreach (SdkVersions::DEVELOPMENT as $name => $version) {
            $development[] = sprintf('    "%s": "%s"', $name, $version);
        }

        $lines = [
            '{',
            sprintf('  "name": "%s",', $package->npmName()),
            '  "private": true,',
            sprintf('  "description": "The panel UI of the addon %s (%s): its contributions, built with @cboxdk/cms-panel/vite into dist/panel.",', $namespace->value, $package->name),
            '  "type": "module",',
            '  "engines": {',
            sprintf('    "node": "%s"', SdkVersions::NODE),
            '  },',
            '  "scripts": {',
            '    "build": "vite build",',
            '    "typecheck": "tsc --noEmit",',
            '    "lint": "eslint --max-warnings=0 .",',
            '    "format": "prettier --write .",',
            '    "format:check": "prettier --check .",',
            '    "test": "vitest run",',
            '    "verify": "cms-panel-addon verify"',
            '  },',
            '  "dependencies": {',
            sprintf('    "%s": "%s"', SdkVersions::SDK, SdkVersions::SDK_VERSION),
            '  },',
            '  "devDependencies": {',
            implode(",\n", $development),
            '  }',
            '}',
            '',
        ];

        return new GeneratedFile(self::PACKAGE, implode("\n", $lines));
    }

    private static function tsconfig(): GeneratedFile
    {
        $lines = [
            '{',
            '  "$schema": "https://json.schemastore.org/tsconfig",',
            '  "extends": "@cboxdk/cms-tooling/tsconfig.base.json",',
            '  "compilerOptions": {',
            '    "noEmit": true,',
            '    "allowJs": true,',
            '    "checkJs": true,',
            '    "types": ["node"]',
            '  },',
            '  "include": ["eslint.config.js", "vite.config.ts", "vitest.config.ts", "resources/panel/**/*"]',
            '}',
            '',
        ];

        return new GeneratedFile(self::TSCONFIG, implode("\n", $lines));
    }

    private static function vite(AddonNamespace $namespace): GeneratedFile
    {
        $lines = [
            sprintf('// The build of the panel UI of %s (section 4.5 of the panel extension architecture): one', $namespace->value),
            "// ES module entry on the panel's shared React and SDK, with the SDK's plugin, which refuses what an",
            '// addon may not import, scopes the stylesheets, keeps the size budget and writes',
            '// dist/panel/panel-manifest.json. `npm run build` writes it; `npm run verify` holds the committed',
            '// bundle to it.',
            '',
            "import cmsPanelAddon from '@cboxdk/cms-panel/vite';",
            "import { defineConfig } from 'vite';",
            '',
            "import { CONTRIBUTIONS } from './resources/panel/src/ids';",
            '',
            'export default defineConfig({',
            sprintf('  plugins: [cmsPanelAddon({ namespace: %s, contributions: [...CONTRIBUTIONS] })],', ScaffoldNames::quote($namespace->value)),
            '  build: {',
            "    outDir: 'dist/panel',",
            '    emptyOutDir: true,',
            "    lib: { entry: 'resources/panel/src/index.ts', formats: ['es'], fileName: 'addon' },",
            '  },',
            '});',
            '',
        ];

        return new GeneratedFile(self::VITE, implode("\n", $lines));
    }

    private static function vitest(): GeneratedFile
    {
        $lines = [
            '// The tests of the panel UI, with @cboxdk/cms-panel/testing: in Node, and a test file that renders',
            '// a contribution asks for jsdom with a `@vitest-environment jsdom` comment, as the stubs do.',
            '',
            "import { defineConfig } from 'vitest/config';",
            '',
            'export default defineConfig({',
            '  test: {',
            "    include: ['resources/panel/src/**/*.test.{ts,tsx}'],",
            "    environment: 'node',",
            '  },',
            '});',
            '',
        ];

        return new GeneratedFile(self::VITEST, implode("\n", $lines));
    }

    private static function eslint(): GeneratedFile
    {
        $lines = [
            "// The lint of the panel UI, on the SDK's rules: the panel's own, with the imports the panel keeps to",
            '// itself refused and no-deprecated on.',
            '',
            "import cmsPanelAddonEslint from '@cboxdk/cms-panel/eslint';",
            '',
            'export default cmsPanelAddonEslint({ tsconfigRootDir: import.meta.dirname });',
            '',
        ];

        return new GeneratedFile(self::ESLINT, implode("\n", $lines));
    }

    private static function prettier(): GeneratedFile
    {
        return new GeneratedFile(self::PRETTIER, "\"@cboxdk/cms-tooling/prettier\"\n");
    }

    private static function phpTest(AddonNamespace $namespace, AddonPackage $package): GeneratedFile
    {
        $provider = $package->provider ?? $package->namespace.'\\'.ShapeDeclarations::pascal($namespace->value).'ServiceProvider';
        $short = ScaffoldNames::short($provider);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s\\Panel;', $package->testNamespace),
            '',
            'use Cbox\\Cms\\Contracts\\Addons\\AddonManifest;',
            'use Cbox\\Cms\\Testkit\\Panel\\PanelContributionsContract;',
            'use Illuminate\\Filesystem\\Filesystem;',
            'use Orchestra\\Testbench\\TestCase;',
            'use Override;',
            sprintf('use %s;', $provider),
            '',
            '/**',
            sprintf(' * The panel contributions of %s run the shared suite of cboxdk/cms (PRD 13.4): cms:build', $namespace->value),
            " * compiles the installation with the addon's manifest and refuses nothing. The installed packages'",
            " * providers are discovered, the addon's among them, and the bootstrap directory is a temporary",
            " * directory of the test's own, which cms:build writes into.",
            ' */',
            'final class PanelContributionsTest extends TestCase',
            '{',
            '    use PanelContributionsContract;',
            '',
            "    /** Discover the service providers of the installed packages, the addon's among them. */",
            '    #[Override]',
            '    protected $enablesPackageDiscoveries = true;',
            '',
            "    private string $bootstrap = '';",
            '',
            '    #[Override]',
            '    protected function defineEnvironment($app): void',
            '    {',
            sprintf("        \$this->bootstrap = sys_get_temp_dir().'/%s-panel-'.bin2hex(random_bytes(8));", $namespace->value),
            '        mkdir($this->bootstrap, 0o700);',
            '',
            '        $app->useBootstrapPath($this->bootstrap);',
            '    }',
            '',
            '    #[Override]',
            '    protected function tearDown(): void',
            '    {',
            '        parent::tearDown();',
            '',
            '        new Filesystem()->deleteDirectory($this->bootstrap);',
            '    }',
            '',
            '    #[Override]',
            '    protected function addonManifest(): AddonManifest',
            '    {',
            sprintf('        return new %s(app())->addonManifest();', $short),
            '    }',
            '}',
            '',
        ];

        return new GeneratedFile(self::PHP_TEST, implode("\n", $lines));
    }
}
