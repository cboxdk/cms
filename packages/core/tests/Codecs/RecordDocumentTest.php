<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\RecordDocument;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\TypeTables\Domain\UnreadableTypeTable;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use LogicException;
use Workbench\App\Cms\Generated\Boundary\AppFixtureArticleCodecV1;

/*
 * The record of an entry through its type's generated codec (PRD 8.9, GUARDRAILS 2.2), with the
 * workbench's fixture article: the document is read by the codec at the highest access and written
 * at the caller's, so a field above the access never leaves, the extenders' namespaces are always
 * there, and a value the type or its contract refuses is InvalidRecordDocument.
 */

const DOCUMENTED_ENTRY = '0192a0c0-0000-7000-8000-0000000036e5';

function documentedArticle(FieldValues $fields): ReadContent
{
    $type = app(TypeCatalog::class)->named(new TypeName('app:fixture_article')) ?? throw new LogicException('The workbench has no fixture article.');

    return new ReadContent(EntryId::fromString(DOCUMENTED_ENTRY), NodeId::fromString('0192a0c0-0000-7000-8000-0000000036a1'), $type->id, $fields);
}

it('writes the record through the codec, as the caller\'s access sees it, with every extender\'s namespace', function (): void {
    $catalog = app(TypeCatalog::class);
    $content = documentedArticle(EntryFields::article('The harbour opens'));

    $public = RecordDocument::write($catalog, new AppFixtureArticleCodecV1, $content, ClassificationAccess::Public);
    $internal = RecordDocument::write($catalog, new AppFixtureArticleCodecV1, $content, ClassificationAccess::Internal);

    expect($public)->toStartWith('{"cms_id":"'.DOCUMENTED_ENTRY.'","ext":{"fixtureaddon":{}},"fixture_featured":true,')
        ->and($public)->not->toContain('fixture_sources')
        ->and($internal)->toContain('"fixture_sources":[{"fixture_source_title":"A \"quoted\" source","fixture_source_url":"https://example.org/source"}]')
        ->and($public)->not->toContain('fixture_body');
});

it('refuses an entry of a type the catalog does not have', function (): void {
    $content = new ReadContent(EntryId::fromString(DOCUMENTED_ENTRY), NodeId::fromString('0192a0c0-0000-7000-8000-0000000036a1'), new TypeId(new FakeIdGenerator()->next()), new FieldValues);

    expect(static fn (): string => RecordDocument::write(app(TypeCatalog::class), new AppFixtureArticleCodecV1, $content, ClassificationAccess::Public))
        ->toThrow(InvalidRecordDocument::class, 'has no record codec for the type');
});

it('refuses a value of the wrong kind for its field, and a value the codec\'s rules refuse, with the cause', function (): void {
    $catalog = app(TypeCatalog::class);
    $wrong = documentedArticle(new FieldValues(new FieldMap(new NamedValue(new FieldHandle('fixture_title'), new IntegerValue(1)))));
    $long = documentedArticle(EntryFields::article(str_repeat('x', 256)));

    $refusal = static function (ReadContent $content) use ($catalog): InvalidRecordDocument {
        try {
            RecordDocument::write($catalog, new AppFixtureArticleCodecV1, $content, ClassificationAccess::Public);
        } catch (InvalidRecordDocument $refused) {
            return $refused;
        }

        throw new LogicException('The record was written.');
    };

    expect($refusal($wrong)->getPrevious())->toBeInstanceOf(UnreadableTypeTable::class)
        ->and($refusal($long)->getPrevious())->toBeInstanceOf(DecodingFailed::class)
        ->and($refusal($long)->getMessage())->toContain('fixture_title');

    expect(static fn (): string => RecordDocument::write($catalog, new AppFixtureArticleCodecV1, documentedArticle(new FieldValues(new FieldMap(new NamedValue(new FieldHandle('fixture_title'), new TextValue('Only a title'))))), ClassificationAccess::Public))
        ->toThrow(InvalidRecordDocument::class, 'fixture_featured');
});
