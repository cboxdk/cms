<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\DevImage;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\CiFiles;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Affected\Boundary\PhpunitConfiguration;
use Cbox\Cms\Tooling\Affected\Domain\TestFileKind;
use Cbox\Cms\Tooling\DevImage\Boundary\ComposePsJson;
use Cbox\Cms\Tooling\DevImage\Domain\CheckoutVolume;
use Cbox\Cms\Tooling\DevImage\Domain\DevImage;
use Cbox\Cms\Tooling\DevImage\Domain\DevImageRun;
use Cbox\Cms\Tooling\DevImage\Domain\NodeModulesStamp;
use Cbox\Cms\Tooling\DevImage\Domain\ServiceState;
use Cbox\Cms\Tooling\DevImage\Domain\SharedServices;
use Cbox\Cms\Tooling\DevImage\Domain\VolumeKind;
use DOMDocument;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use UnexpectedValueException;

/*
 * The parts of the dev image runs (tools/src/DevImage) and of composer test:affected
 * (tools/src/Affected) that decide without Docker: which image, when a process is in it, the
 * node_modules volume and its stamp, the state of the shared services, and the two PHPUnit
 * configurations of composer test:affected.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

it('names the image that compose.yaml\'s php service, the CI Dockerfile and ci.yml run', function (): void {
    $dockerfile = (string) file_get_contents(Phpstan::root().'/docker/ci.Dockerfile');
    $compose = (string) file_get_contents(Phpstan::root().'/compose.yaml');
    $workflow = (string) file_get_contents(Phpstan::root().'/.github/workflows/ci.yml');

    expect($dockerfile)->toContain("\nFROM ".DevImage::IMAGE."\n")
        ->and($compose)->toContain('image: '.DevImage::IMAGE."\n")
        ->and($workflow)->toContain('image: '.DevImage::IMAGE."\n");
});

it('is in the dev image only where the image\'s tier variable says dev', function (): void {
    expect(DevImage::runsIn('dev'))->toBeTrue()
        ->and(DevImage::runsIn(false))->toBeFalse()
        ->and(DevImage::runsIn(null))->toBeFalse()
        ->and(DevImage::runsIn(''))->toBeFalse()
        ->and(DevImage::runsIn('prod'))->toBeFalse();
});

it('names one volume of each kind per checkout path, mounted over its directory, and knows its own names', function (): void {
    $hash = substr(hash('sha256', '/work/cms'), 0, 12);
    $all = CheckoutVolume::all('/work/cms');

    expect(array_map(static fn (CheckoutVolume $volume): string => $volume->name, $all))->toBe([
        'laravel-cms-node-modules-'.$hash,
        'laravel-cms-cache-'.$hash,
        'laravel-cms-bootstrap-cache-'.$hash,
    ])
        ->and(array_map(static fn (CheckoutVolume $volume): string => $volume->mountPoint(), $all))->toBe([
            '/work/cms/node_modules',
            '/work/cms/.cache',
            '/work/cms/vendor/orchestra/testbench-core/laravel/bootstrap/cache',
        ])
        ->and(CheckoutVolume::for(VolumeKind::Cache, '/work/cms-worktrees/M1-T1')->name)->not->toBe($all[1]->name)
        ->and(CheckoutVolume::kindOf($all[2]->name))->toBe(VolumeKind::BootstrapCache)
        ->and(CheckoutVolume::kindOf('laravel-cms_postgres-data'))->toBeNull()
        ->and(CheckoutVolume::kindOf('laravel-cms-cache-xyz'))->toBeNull()
        ->and(static fn (): CheckoutVolume => CheckoutVolume::for(VolumeKind::Cache, 'work/cms'))->toThrow(InvalidArgumentException::class);
});

it('mounts the Testbench bootstrap cache that is in vendor', function (): void {
    expect(is_dir(Phpstan::root().'/'.VolumeKind::BootstrapCache->directory()))->toBeTrue();
});

it('prunes a volume whose checkout is gone or derives another name, and keeps the others', function (VolumeKind $kind): void {
    $checkout = (string) realpath(ScratchDirectory::make('cbox-cms-volume-checkout-'));
    $volume = CheckoutVolume::for($kind, $checkout)->name;

    expect(CheckoutVolume::staleReason($volume, $checkout, $checkout))->toBeNull()
        ->and(CheckoutVolume::staleReason($volume, $checkout, false))->toBe("its checkout {$checkout} is gone")
        ->and(CheckoutVolume::staleReason($volume, $checkout, '/elsewhere'))->toContain('resolves to /elsewhere')
        ->and(CheckoutVolume::staleReason('someone-elses-volume', '/gone', false))->toBeNull();
})->with(VolumeKind::cases());

it('takes the node_modules volume as current only when its stamp is the lock file\'s hash', function (): void {
    $lock = '{"lockfileVersion":3}';

    expect(NodeModulesStamp::current($lock, NodeModulesStamp::of($lock)))->toBeTrue()
        ->and(NodeModulesStamp::current($lock, null))->toBeFalse()
        ->and(NodeModulesStamp::current($lock.' ', NodeModulesStamp::of($lock)))->toBeFalse()
        ->and(NodeModulesStamp::FILE)->toStartWith('node_modules/');
});

it('reads docker compose ps as one JSON object per line and as one JSON array', function (): void {
    $line = '{"Service":"postgres","State":"running","Health":"healthy","Networks":"laravel-cms_default"}';
    $expected = [new ServiceState('postgres', 'running', 'healthy', ['laravel-cms_default'])];

    expect(ComposePsJson::decode($line."\n"))->toEqual($expected)
        ->and(ComposePsJson::decode('['.$line.']'))->toEqual($expected)
        ->and(ComposePsJson::decode(''))->toBe([])
        ->and(ComposePsJson::decode('{"Service":"valkey","State":"exited"}'))->toEqual([new ServiceState('valkey', 'exited', '', [])])
        ->and(static fn (): array => ComposePsJson::decode('not json'))->toThrow(UnexpectedValueException::class)
        ->and(static fn (): array => ComposePsJson::decode('{"State":"running"}'))->toThrow(UnexpectedValueException::class, 'without Service and State');
});

it('is ready when Postgres and Valkey run healthy, and takes their network', function (): void {
    $ready = SharedServices::of([
        new ServiceState('postgres', 'running', 'healthy', ['laravel-cms_default']),
        new ServiceState('valkey', 'running', '', ['laravel-cms_default']),
        new ServiceState('php', 'exited', '', []),
    ], '/work/cms');
    $offNetwork = SharedServices::of([
        new ServiceState('postgres', 'running', 'healthy', []),
        new ServiceState('valkey', 'running', 'healthy', ['laravel-cms_default']),
    ], '/work/cms');

    expect($ready->ready())->toBeTrue()
        ->and($ready->network)->toBe('laravel-cms_default')
        ->and($offNetwork->ready())->toBeFalse()
        ->and($offNetwork->problem)->toContain('postgres (on no network)', 'cd /work/cms && composer services:up');
});

it('reaches the services by the names and container ports compose.yaml\'s php service uses', function (): void {
    expect(DevImageRun::SERVICE_ENVIRONMENT)->toBe(['DB_HOST' => 'postgres', 'DB_PORT' => '5432', 'REDIS_HOST' => 'valkey', 'REDIS_PORT' => '6379'])
        ->and(CiFiles::at(ciComposeService('php'), 'environment', 'DB_HOST'))->toBe('postgres')
        ->and(CiFiles::at(ciComposeService('php'), 'environment', 'DB_PORT'))->toBe('5432')
        ->and(CiFiles::at(ciComposeService('php'), 'environment', 'REDIS_HOST'))->toBe('valkey')
        ->and(CiFiles::at(ciComposeService('php'), 'environment', 'REDIS_PORT'))->toBe('6379');
});

it('tells a PHPUnit test class from a Pest file by a class named as the file', function (): void {
    expect(TestFileKind::of('/x/FakeClockContractTest.php', "<?php\nfinal class FakeClockContractTest extends TestCase\n{\n}\n"))->toBe(TestFileKind::PhpunitClass)
        ->and(TestFileKind::of('/x/ClockTest.php', "<?php\nit('ticks', fn () => expect(true)->toBeTrue());\nfinal class Helper {}\n\$x = Foo::class;\n"))->toBe(TestFileKind::Pest)
        ->and(TestFileKind::of('/x/ClockTest.php', "<?php\n\$c = new class extends Foo {};\n"))->toBe(TestFileKind::Pest);
});

it('derives a PHPUnit configuration with absolute paths that keeps the given suites and leaves the given files out of each', function (): void {
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="vendor/autoload.php" cacheDirectory=".phpunit.cache" failOnRisky="true">
            <testsuites>
                <testsuite name="Unit">
                    <directory suffix="Test.php">tests/Feature</directory>
                    <exclude>packages/*/tests/Postgres</exclude>
                </testsuite>
                <testsuite name="Postgres">
                    <directory suffix="Test.php">/abs/tests/Postgres</directory>
                </testsuite>
                <testsuite name="Browser">
                    <directory suffix="Test.php">tests/Browser</directory>
                </testsuite>
            </testsuites>
            <source><include><directory>packages/*/src</directory></include></source>
            <php><env name="DB_HOST" value="127.0.0.1"/></php>
        </phpunit>
        XML;

    $derived = new DOMDocument;
    $derived->loadXML(PhpunitConfiguration::without($xml, '/work/cms', ['Unit', 'Postgres'], ['/work/cms/tests/Feature/OneTest.php']));
    $xpath = new DOMXPath($derived);
    $texts = static function (string $query) use ($xpath): array {
        $found = [];

        foreach ($xpath->query($query) ?: [] as $node) {
            $found[] = $node instanceof DOMNode ? $node->textContent : $node->nodeValue;
        }

        return $found;
    };

    expect($derived->documentElement?->getAttribute('bootstrap'))->toBe('/work/cms/vendor/autoload.php')
        ->and($derived->documentElement?->getAttribute('cacheDirectory'))->toBe('/work/cms/.phpunit.cache')
        ->and($derived->documentElement?->getAttribute('failOnRisky'))->toBe('true')
        ->and($texts('//testsuite[@name="Unit"]/directory'))->toBe(['/work/cms/tests/Feature'])
        ->and($texts('//testsuite[@name="Unit"]/exclude'))->toBe(['/work/cms/packages/*/tests/Postgres', '/work/cms/tests/Feature/OneTest.php'])
        ->and($texts('//testsuite[@name="Postgres"]/directory'))->toBe(['/abs/tests/Postgres'])
        ->and($texts('//testsuite[@name="Postgres"]/exclude'))->toBe(['/work/cms/tests/Feature/OneTest.php'])
        ->and($texts('//testsuite/@name'))->toBe(['Unit', 'Postgres'])
        ->and($texts('//source//directory'))->toBe(['/work/cms/packages/*/src'])
        ->and($texts('//php/env/@value'))->toBe(['127.0.0.1'])
        ->and(static fn (): string => PhpunitConfiguration::without('<nope/>', '/w', [], []))->toThrow(UnexpectedValueException::class);
});

it('defines composer test:affected and image:run through the dev image scripts', function (): void {
    $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

    expect($scripts['test:affected'] ?? null)->toBe(['Composer\Config::disableProcessTimeout', '@php tools/bin/test-affected.php'])
        ->and($scripts['image:run'] ?? null)->toBe(['Composer\Config::disableProcessTimeout', '@php tools/bin/dev-image.php --'])
        ->and($scripts['image:prune'] ?? null)->toBe('@php tools/bin/prune-image-volumes.php');
});

/**
 * A service of compose.yaml.
 *
 * @return array<mixed>
 */
function ciComposeService(string $service): array
{
    $found = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE), 'services', $service);

    return is_array($found) ? $found : [];
}
