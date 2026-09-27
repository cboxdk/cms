<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\Boundary\InternalUseRecords;
use Cbox\Cms\Testkit\Phpstan\InternalUseSite;
use Cbox\Cms\Testkit\Phpstan\InternalUseWaiver;
use InvalidArgumentException;

/*
 * The collected data of rule 5, which PHPStan passes from its workers to the main process as
 * JSON and keeps in its result cache under the path of the analysed file.
 */

it('round-trips a site, taking the analysed file from the key', function (): void {
    $site = new InternalUseSite('/app/src/Bridge.php', '/app/src/Bridge.php', '/app/src/Bridge.php', 12, 'Call to internal method Foo::bar().');
    $record = InternalUseRecords::site($site);

    expect(InternalUseRecords::decode('/app/src/Bridge.php', $record))->toEqual($site)
        ->and($record)->not->toContain('/app/src/Bridge.php');
});

it('round-trips a site in a trait with its description and source', function (): void {
    $site = new InternalUseSite('/app/src/User.php', '/app/src/UsesEngine.php (in context of class Acme\User)', '/app/src/UsesEngine.php', 7, 'Access to constant LIMIT of internal class Foo.');

    expect(InternalUseRecords::decode('/app/src/User.php', InternalUseRecords::site($site)))->toEqual($site);
});

it('round-trips a waiver', function (): void {
    $waiver = new InternalUseWaiver('/app/src/Bridge.php', 30, 2);

    expect(InternalUseRecords::decode('/app/src/Bridge.php', InternalUseRecords::waiver($waiver)))->toEqual($waiver);
});

it('refuses a record that is neither a site nor a waiver', function (string $record): void {
    expect(static fn (): InternalUseSite|InternalUseWaiver => InternalUseRecords::decode('/app/src/Bridge.php', $record))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'not JSON' => ['{'],
    'a list' => ['[1]'],
    'another kind' => ['{"use":{"line":1,"message":"m"}}'],
    'a site without a message' => ['{"site":{"line":1}}'],
    'a site with a line as text' => ['{"site":{"line":"1","message":"m"}}'],
    'a site with a numeric source' => ['{"site":{"line":1,"message":"m","source":3}}'],
    'a waiver that waives nothing' => ['{"waiver":{"line":1,"count":0}}'],
    'a waiver without a count' => ['{"waiver":{"line":1}}'],
]);
