<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

/**
 * A scan root whose subscriber names Lane::Urgent, a case Lane does not have. PHP evaluates the
 * attribute's arguments only when the scanner builds it, so the class loads and the build reports
 * the case. The file is written to a scratch directory at run time, because PHPStan would refuse it
 * in the repository, and an autoloader for NAMESPACE loads it from there.
 */
final class UndefinedLaneRoot
{
    public const string NAMESPACE = 'Cbox\\Cms\\Core\\Tests\\Registry\\UndefinedLane';

    private static ?string $directory = null;

    /**
     * The directory of the scan root, created on the first call. RegistryFixtures::cleanUp()
     * removes it after the test.
     */
    public static function create(): string
    {
        $directory = RegistryFixtures::scratch();
        mkdir($directory);
        file_put_contents($directory.'/UrgentSubscriber.php', self::source());

        if (self::$directory === null) {
            spl_autoload_register(static function (string $class): void {
                if ($class === self::NAMESPACE.'\\UrgentSubscriber' && self::$directory !== null && is_file(self::$directory.'/UrgentSubscriber.php')) {
                    require self::$directory.'/UrgentSubscriber.php';
                }
            });
        }

        self::$directory = $directory;

        return $directory;
    }

    private static function source(): string
    {
        $namespace = self::NAMESPACE;
        $event = Fixtures\Valid\NoteCreated::class;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Cbox\\Cms\\Contracts\\Attributes\\Subscription;
            use Cbox\\Cms\\Contracts\\Subscribers\\Lane;
            use Cbox\\Cms\\Contracts\\Subscribers\\Subscriber;
            use Cbox\\Cms\\Core\\Tests\\Registry\\FixtureSupport\\IgnoresEvents;

            #[Subscription('fixture.urgent', events: [\\{$event}::class], lane: Lane::Urgent)]
            final readonly class UrgentSubscriber implements Subscriber
            {
                use IgnoresEvents;
            }

            PHP;
    }
}
