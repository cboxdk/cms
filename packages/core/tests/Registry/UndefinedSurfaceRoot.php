<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

/**
 * A scan root whose action lists Surface::Graphql, a case Surface does not have. PHP evaluates the
 * attribute's arguments only when the scanner builds it, so the class loads and the build reports
 * the case. The file is written to a scratch directory at run time, because PHPStan would refuse it
 * in the repository, and an autoloader for NAMESPACE loads it from there.
 */
final class UndefinedSurfaceRoot
{
    public const string NAMESPACE = 'Cbox\\Cms\\Core\\Tests\\Registry\\UndefinedSurface';

    private static ?string $directory = null;

    /**
     * The directory of the scan root, created on the first call. RegistryFixtures::cleanUp()
     * removes it after the test.
     */
    public static function create(): string
    {
        $directory = RegistryFixtures::scratch();
        mkdir($directory);
        file_put_contents($directory.'/ShareByGraphql.php', self::source());

        if (self::$directory === null) {
            spl_autoload_register(static function (string $class): void {
                if ($class === self::NAMESPACE.'\\ShareByGraphql' && self::$directory !== null && is_file(self::$directory.'/ShareByGraphql.php')) {
                    require self::$directory.'/ShareByGraphql.php';
                }
            });
        }

        self::$directory = $directory;

        return $directory;
    }

    private static function source(): string
    {
        $namespace = self::NAMESPACE;
        $command = Fixtures\Valid\CreateNote::class;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Cbox\\Cms\\Contracts\\Attributes\\Action;
            use Cbox\\Cms\\Contracts\\Attributes\\Surface;
            use Cbox\\Cms\\Contracts\\Pipeline\\WriteAction;
            use Cbox\\Cms\\Core\\Tests\\Registry\\FixtureSupport\\PlansNothing;

            #[Action(handles: \\{$command}::class, surfaces: [Surface::Graphql])]
            final readonly class ShareByGraphql implements WriteAction
            {
                use PlansNothing;
            }

            PHP;
    }
}
