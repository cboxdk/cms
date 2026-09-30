<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Contract;

use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Testkit\Codecs\FakeRecordCodecs;
use Cbox\Cms\Testkit\Codecs\RecordCodecsContract;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Tests\Schema\SampleTypes;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared RecordCodecs contract suite against the in-memory fake, over the two SampleTypes: a
 * note with a public title, a confidential group and a field the app adds, and the app's note.
 */
final class FakeRecordCodecsContractTest extends TestCase
{
    use RecordCodecsContract;

    private const string NODE = '0198d2a4-5c3e-7a41-9b2f-000000000101';

    #[Override]
    protected function codecs(): RecordCodecs
    {
        return new FakeRecordCodecs($this->catalog());
    }

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new FakeTypeCatalog(SampleTypes::note(), SampleTypes::appNote());
    }

    #[Override]
    protected function contents(): array
    {
        $title = static fn (string $text): NamedValue => new NamedValue(new FieldHandle('title'), new TextValue($text));

        return [
            new ReadContent(
                EntryId::fromString('0198d2a4-5c3e-7a41-9b2f-000000000201'),
                NodeId::fromString(self::NODE),
                TypeId::fromString(SampleTypes::NOTE_ID),
                new FieldValues(
                    new FieldMap(
                        $title('A note'),
                        new NamedValue(new FieldHandle('author'), new GroupValue(new FieldMap(new NamedValue(new FieldHandle('name'), new TextValue('Ann'))))),
                    ),
                    new ExtensionFields(new FieldNamespace('app'), new FieldMap($title('The app\'s title'))),
                ),
            ),
            new ReadContent(
                EntryId::fromString('0198d2a4-5c3e-7a41-9b2f-000000000202'),
                NodeId::fromString(self::NODE),
                TypeId::fromString(SampleTypes::APP_NOTE_ID),
                new FieldValues(new FieldMap(new NamedValue(new FieldHandle('body'), new TextValue('A body')))),
            ),
        ];
    }
}
