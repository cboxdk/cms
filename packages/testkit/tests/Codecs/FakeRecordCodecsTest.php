<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Codecs;

use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Testkit\Codecs\FakeRecordCodecs;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Tests\Schema\SampleTypes;

/*
 * The fake's record of an entry that holds only some of its type's fields: what it holds, in the
 * owner's place or under its extender's namespace, and nothing for the fields it does not hold; the
 * object of each extender is always there, as the generated codecs write it.
 * RecordCodecsContract holds the fake to the generated codecs.
 */

function noteRecord(FieldValues $fields): string
{
    return new FakeRecordCodecs(new FakeTypeCatalog(SampleTypes::note(), SampleTypes::appNote()))->encode(
        new ReadContent(EntryId::fromString('0198d2a4-5c3e-7a41-9b2f-000000000201'), NodeId::fromString('0198d2a4-5c3e-7a41-9b2f-000000000101'), TypeId::fromString(SampleTypes::NOTE_ID), $fields),
        ClassificationAccess::Sensitive,
    );
}

it('writes only the fields an entry holds, and an empty object for an extender it holds nothing of, as the generated codecs do', function (): void {
    $title = static fn (string $text): NamedValue => new NamedValue(new FieldHandle('title'), new TextValue($text));

    expect(noteRecord(new FieldValues(new FieldMap($title('A note')))))->toBe('{"cms_id":"0198d2a4-5c3e-7a41-9b2f-000000000201","ext":{"app":{}},"title":"A note"}')
        ->and(noteRecord(new FieldValues(new FieldMap, new ExtensionFields(new FieldNamespace('app'), new FieldMap($title('The app\'s title'))))))
        ->toBe('{"cms_id":"0198d2a4-5c3e-7a41-9b2f-000000000201","ext":{"app":{"title":"The app\'s title"}}}');
});
