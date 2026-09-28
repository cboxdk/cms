<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use InvalidArgumentException;

/*
 * The name of each checkout's own Postgres test database: the configured database, `_`, and the
 * first 12 hex digits of the SHA-256 of the checkout root's real path. It needs no service.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

it('gives the same path the same name', function (): void {
    $root = ScratchDirectory::make('cbox-cms-name-test-');

    expect(TestDatabaseName::for('cms_test', $root))->toBe(TestDatabaseName::for('cms_test', $root))
        ->and(TestDatabaseName::for('cms_test', $root.'/'))->toBe(TestDatabaseName::for('cms_test', $root))
        ->and(TestDatabaseName::for('cms_test', $root))->toBe('cms_test_'.substr(hash('sha256', (string) realpath($root)), 0, 12));
});

it('gives two paths two names', function (): void {
    $first = ScratchDirectory::make('cbox-cms-name-test-');
    $second = ScratchDirectory::make('cbox-cms-name-test-');

    expect(TestDatabaseName::for('cms_test', $first))->not->toBe(TestDatabaseName::for('cms_test', $second))
        ->and(TestDatabaseName::for('cms_test', CheckoutRoot::current()))->not->toBe(TestDatabaseName::for('cms_test', $first));
});

it('gives a symlink to the checkout the name of its real path', function (): void {
    $scratch = ScratchDirectory::make('cbox-cms-name-test-');
    mkdir($scratch.'/checkout');
    symlink($scratch.'/checkout', $scratch.'/link');

    expect(is_link($scratch.'/link'))->toBeTrue()
        ->and(TestDatabaseName::for('cms_test', $scratch.'/link'))->toBe(TestDatabaseName::for('cms_test', $scratch.'/checkout'))
        ->and(TestDatabaseName::for('cms_test', $scratch.'/link/../checkout'))->toBe(TestDatabaseName::for('cms_test', $scratch.'/checkout'));
});

it('matches cms_test_ and 12 hex digits and keeps within the 63 bytes of a Postgres name', function (): void {
    $name = TestDatabaseName::for('cms_test', CheckoutRoot::current());

    expect($name)->toMatch('/\Acms_test_[0-9a-f]{12}\z/')
        ->and(strlen($name))->toBeLessThanOrEqual(63)
        ->and(TestDatabaseName::for(str_repeat('b', 50), CheckoutRoot::current()))->toHaveLength(63);
});

it('refuses a configured database that would make the name longer than 63 bytes', function (): void {
    expect(static fn (): string => TestDatabaseName::for(str_repeat('b', 51), CheckoutRoot::current()))
        ->toThrow(InvalidArgumentException::class, 'has 64 bytes; Postgres keeps at most 63. Shorten the configured database '.str_repeat('b', 51).' to at most 50 bytes.');
});

it('refuses an empty configured database and a checkout root that is not a directory', function (): void {
    $file = ScratchDirectory::write(ScratchDirectory::make('cbox-cms-name-test-').'/file');

    expect(static fn (): string => TestDatabaseName::for('', CheckoutRoot::current()))
        ->toThrow(InvalidArgumentException::class, 'The configured test database has no name.')
        ->and(static fn (): string => TestDatabaseName::for('cms_test', '/no/such/checkout'))
        ->toThrow(InvalidArgumentException::class, 'The checkout root /no/such/checkout is not a directory.')
        ->and(static fn (): string => TestDatabaseName::for('cms_test', $file))
        ->toThrow(InvalidArgumentException::class, "The checkout root {$file} is not a directory.");
});

it('finds the configured database a name was derived from', function (): void {
    $root = ScratchDirectory::make('cbox-cms-name-test-');
    $name = TestDatabaseName::for('cms_test', $root);
    $suffix = TestDatabaseName::suffix($root);

    expect(TestDatabaseName::base($name, $root))->toBe('cms_test')
        ->and(TestDatabaseName::base('cms_test', $root))->toBe('cms_test')
        ->and(TestDatabaseName::base($name, CheckoutRoot::current()))->toBe($name)
        ->and(TestDatabaseName::base($suffix, $root))->toBe($suffix)
        ->and($suffix)->toMatch('/\A_[0-9a-f]{12}\z/');
});

it('gives each parallel worker of a checkout a name of its own, the same for the same path and worker', function (): void {
    $root = ScratchDirectory::make('cbox-cms-name-test-');
    $other = ScratchDirectory::make('cbox-cms-name-test-');
    $checkout = TestDatabaseName::for('cms_test', $root);

    expect(TestDatabaseName::for('cms_test', $root, 1))->toBe($checkout.'_w1')
        ->and(TestDatabaseName::for('cms_test', $root, 12))->toBe($checkout.'_w12')
        ->and(TestDatabaseName::for('cms_test', $root, 1))->toBe(TestDatabaseName::for('cms_test', $root.'/', 1))
        ->and(TestDatabaseName::for('cms_test', $root, 1))->not->toBe(TestDatabaseName::for('cms_test', $root, 2))
        ->and(TestDatabaseName::for('cms_test', $root, 1))->not->toBe($checkout)
        ->and(TestDatabaseName::for('cms_test', $root, 1))->not->toBe(TestDatabaseName::for('cms_test', $other, 1))
        ->and(TestDatabaseName::for('cms_test', $root, 3))->toMatch('/\Acms_test_[0-9a-f]{12}_w3\z/');
});

it('keeps a worker\'s name within the 63 bytes of a Postgres name, and refuses a worker below 1', function (): void {
    $root = CheckoutRoot::current();

    expect(strlen(TestDatabaseName::for('cms_test', $root, 999_999_999)))->toBeLessThanOrEqual(63)
        ->and(TestDatabaseName::for(str_repeat('b', 47), $root, 9))->toHaveLength(63)
        ->and(static fn (): string => TestDatabaseName::for(str_repeat('b', 47), $root, 10))
        ->toThrow(InvalidArgumentException::class, 'has 64 bytes; Postgres keeps at most 63. Shorten the configured database '.str_repeat('b', 47).' to at most 46 bytes.')
        ->and(static fn (): string => TestDatabaseName::for('cms_test', $root, 0))
        ->toThrow(InvalidArgumentException::class, 'A parallel worker has a number of 1 or more, not 0.')
        ->and(static fn (): string => TestDatabaseName::for('cms_test', $root, -1))
        ->toThrow(InvalidArgumentException::class, 'not -1.');
});

it('finds the configured database a worker\'s name was derived from', function (): void {
    $root = ScratchDirectory::make('cbox-cms-name-test-');
    $checkout = TestDatabaseName::for('cms_test', $root);

    expect(TestDatabaseName::base(TestDatabaseName::for('cms_test', $root, 4), $root))->toBe('cms_test')
        ->and(TestDatabaseName::base(TestDatabaseName::for('cms_test', $root, 4), CheckoutRoot::current()))->toBe($checkout.'_w4')
        ->and(TestDatabaseName::base($checkout.'_w0', $root))->toBe($checkout.'_w0')
        ->and(TestDatabaseName::base($checkout.'_w01', $root))->toBe($checkout.'_w01')
        ->and(TestDatabaseName::base($checkout.'_wx', $root))->toBe($checkout.'_wx');
});

it('takes the checkout root from the Composer root package of the autoloader that loads the testkit', function (): void {
    expect(CheckoutRoot::current())->toBe(realpath(dirname(__DIR__, 4)))
        ->and(CheckoutRoot::vendorDirectory())->toBe(realpath(dirname(__DIR__, 4)).'/vendor');
});
