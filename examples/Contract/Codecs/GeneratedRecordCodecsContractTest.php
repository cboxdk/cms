<?php

declare(strict_types=1);

namespace Examples\Contract\Codecs;

use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Codecs\RecordCodecsContract;
use DateTimeImmutable;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use Workbench\App\Cms\Generated\GeneratedRecordCodecs;
use Workbench\App\Cms\Generated\GeneratedTypeCatalog;

/**
 * The shared RecordCodecs suite against the record codecs cms:generate writes, next to the catalog
 * of the same schema and a fixture measurement with its internal station. In an application the
 * classes are in App\Cms\Generated; here they are the workbench's. Both have a constructor without
 * arguments, so the suite needs no application.
 */
final class GeneratedRecordCodecsContractTest extends TestCase
{
    use RecordCodecsContract;

    #[Override]
    protected function codecs(): RecordCodecs
    {
        return new GeneratedRecordCodecs;
    }

    #[Override]
    protected function catalog(): TypeCatalog
    {
        return new GeneratedTypeCatalog;
    }

    #[Override]
    protected function contents(): array
    {
        $type = $this->catalog()->named(new TypeName('app:fixture_measurement')) ?? throw new LogicException('The workbench has no fixture measurement.');

        return [new ReadContent(
            EntryId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'),
            NodeId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'),
            $type->id,
            new FieldValues(new FieldMap(
                new NamedValue(new FieldHandle('fixture_measured_at'), new DateTimeValue(new DateTimeImmutable('2026-03-10T12:00:00Z'))),
                new NamedValue(new FieldHandle('fixture_reading'), new DecimalValue('21.500')),
                new NamedValue(new FieldHandle('fixture_scale'), new TextValue('fixture_celsius')),
                new NamedValue(new FieldHandle('fixture_station'), new TextValue('DK-042')),
            )),
        )];
    }
}
