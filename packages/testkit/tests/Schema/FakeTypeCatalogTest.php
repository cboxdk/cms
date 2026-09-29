<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Schema;

use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;

/*
 * The fake catalog holds each id and each name once, as the generated catalog does, and tells two
 * types with one handle apart by their owner.
 */

it('refuses two types with one name or one id', function (TypeDefinition $second, string $message): void {
    expect(fn (): FakeTypeCatalog => new FakeTypeCatalog(SampleTypes::note(), $second))->toThrow(InvalidTypeDefinition::class, $message);
})->with([
    'one name' => [fn (): TypeDefinition => new TypeDefinition(TypeId::fromString(SampleTypes::APP_NOTE_ID), new TypeName('acme:note'), 1, SampleTypes::appNote()->capabilities, [], SampleTypes::appNote()->fields), 'The type acme:note appears twice in one catalog.'],
    'one id' => [fn (): TypeDefinition => new TypeDefinition(TypeId::fromString(SampleTypes::NOTE_ID), new TypeName('app:note'), 1, SampleTypes::appNote()->capabilities, [], SampleTypes::appNote()->fields), 'The type '.SampleTypes::NOTE_ID.' appears twice in one catalog.'],
]);

it('tells two types with one handle apart by their owner', function (): void {
    $catalog = new FakeTypeCatalog(SampleTypes::note(), SampleTypes::appNote());

    expect($catalog->named(new TypeName('app:note'))?->id->toString())->toBe(SampleTypes::APP_NOTE_ID)
        ->and($catalog->named(new TypeName('acme:note'))?->id->toString())->toBe(SampleTypes::NOTE_ID)
        ->and(new FakeTypeCatalog()->all())->toBe([]);
});
