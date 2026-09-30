<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Contract;

use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
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
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Testkit\Codecs\RecordCodecsContract;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use LogicException;
use Override;

/**
 * The shared RecordCodecs contract suite against the record codecs cms:generate wrote from the
 * workbench's schema, as a projection gets them: from the container, where the generated service
 * provider bound them next to the catalog. The entries are a fixture article with its confidential
 * embargo, its internal sources and the fixture addon's slug, and a fixture measurement.
 */
final class WorkbenchRecordCodecsContractTest extends TestCase
{
    use RecordCodecsContract;

    private const string NODE = '0192a0c0-0000-7000-8000-0000000036a1';

    #[Override]
    protected function codecs(): RecordCodecs
    {
        return $this->app?->make(RecordCodecs::class) ?? throw new LogicException('The application is not booted.');
    }

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return $this->app?->make(TypeCatalog::class) ?? throw new LogicException('The application is not booted.');
    }

    #[Override]
    protected function contents(): array
    {
        $embargo = new GroupValue(new FieldMap(
            new NamedValue(new FieldHandle('fixture_embargo_reason'), new TextValue('A court order')),
            new NamedValue(new FieldHandle('fixture_embargo_until'), new DateTimeValue(new DateTimeImmutable('2026-04-01T08:00:00+00:00'))),
        ));
        $article = EntryFields::article('The harbour opens', more: ['fixture_embargo' => $embargo]);

        return [
            new ReadContent(
                EntryId::fromString('0192a0c0-0000-7000-8000-0000000036e1'),
                NodeId::fromString(self::NODE),
                $this->type('app:fixture_article')->id,
                new FieldValues($article->own, new ExtensionFields(new FieldNamespace('fixtureaddon'), new FieldMap(new NamedValue(new FieldHandle('fixture_slug'), new TextValue('the-harbour'))))),
            ),
            new ReadContent(
                EntryId::fromString('0192a0c0-0000-7000-8000-0000000036e2'),
                NodeId::fromString(self::NODE),
                $this->type('app:fixture_measurement')->id,
                EntryFields::measurement(),
            ),
        ];
    }

    private function type(string $name): TypeDefinition
    {
        return $this->catalog()->named(new TypeName($name)) ?? throw new LogicException(sprintf('The workbench has no type %s.', $name));
    }
}
