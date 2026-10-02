<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Decides from a namespace which of the testkit's PHPStan rules apply (GUARDRAILS 2.2 and 2.5).
 *
 * A namespace is in a layer when one of its segments is the layer name. When several segments
 * match, the innermost one decides, so Cbox\Cms\Http\Boundary\RequestParser is Boundary and
 * Cbox\Cms\Core\Boundary\Domain is Domain. This is the same reading as the Arch suite, and a
 * test in the monorepo keeps the two lists of layer names equal.
 */
#[Internal]
final class LayerScope
{
    /**
     * The layer segments of GUARDRAILS 2.5, as the Arch suite names them.
     *
     * @var list<string>
     */
    public const array LAYERS = ['Domain', 'Actions', 'Boundary', 'Adapter', 'Infrastructure', 'Jobs', 'Http', 'Cli', 'Mcp', 'Panel'];

    /**
     * The only layers that may use mixed, untyped arrays and phpstan-ignore comments.
     *
     * @var list<string>
     */
    public const array LOOSE_LAYERS = ['Boundary', 'Adapter'];

    /** The namespace segment that marks test code, as in Cbox\Cms\Core\Tests. */
    public const string TESTS = 'Tests';

    /** The directory that marks a file in the global namespace as test code: Pest files live below tests/. */
    public const string TESTS_DIRECTORY = 'tests';

    public static function layerOf(string $namespace): ?string
    {
        foreach (array_reverse(explode('\\', $namespace)) as $segment) {
            if (in_array($segment, self::LAYERS, true)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * True in Boundary and Adapter, the only layers where mixed, untyped arrays and
     * phpstan-ignore comments are allowed.
     */
    public static function allowsLooseTypes(string $namespace): bool
    {
        return in_array(self::layerOf($namespace), self::LOOSE_LAYERS, true);
    }

    /**
     * True for test code: a namespace with a Tests segment, or the global namespace, where
     * Pest test files and migrations live. isTestFile() is the narrower reading. Code in packages/src and in workbench/app always has a namespace
     * without a Tests segment; the Arch suite checks that.
     */
    public static function isTestCode(string $namespace): bool
    {
        return $namespace === '' || in_array(self::TESTS, explode('\\', $namespace), true);
    }

    /**
     * True for test code, read from the namespace and the file: a namespace with a Tests segment,
     * or the global namespace in a file below a directory named tests, where Pest files live.
     * The global namespace elsewhere is production code: migrations, config files, route files
     * and scripts. A namespace without a Tests segment is never test code, wherever its file is,
     * as isTestCode() reads it. cboxCms.systemClock and cboxCms.uuid use this, because they
     * exempt only tests; the other rules still exempt the whole global namespace.
     */
    public static function isTestFile(string $namespace, string $file): bool
    {
        if ($namespace !== '') {
            return in_array(self::TESTS, explode('\\', $namespace), true);
        }

        $directories = array_slice(explode('/', str_replace('\\', '/', $file)), 0, -1);

        return in_array(self::TESTS_DIRECTORY, $directories, true);
    }
}
