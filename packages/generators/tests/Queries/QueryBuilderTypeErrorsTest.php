<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Queries;

use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Phpstan;

/*
 * A wrong field in a query is a type error, not a query that scans the table (PRD 8.8, 11.12):
 * PHPStan level 10, with the repository's configuration, reports a filter on a field that is not
 * filterable and an order by a field that is not sortable in code that uses the workbench's
 * generated query builder, and accepts the same code with the fields its blueprint declares.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * Analyses a class that builds a query of app:fixture_measurement with the statements in its body.
 *
 * @return list<string> the identifiers of the errors PHPStan reports
 */
function analyseQuery(string $statements): array
{
    $file = SchemaFixtures::scratch().'/MeasurementListing.php';
    SchemaFixtures::write($file, <<<PHP
        <?php

        declare(strict_types=1);

        namespace Acme\\Listing;

        use Cbox\\Cms\\Contracts\\Fields\\DecimalValue;
        use Cbox\\Cms\\Contracts\\Fields\\TextValue;
        use Cbox\\Cms\\Contracts\\Identity\\AccessContext;
        use Cbox\\Cms\\Contracts\\TypeTables\\FilterOperator;
        use Cbox\\Cms\\Contracts\\TypeTables\\RecordPage;
        use Cbox\\Cms\\Contracts\\TypeTables\\SortDirection;
        use Workbench\\App\\Cms\\Generated\\QueryBuilders\\AppFixtureMeasurement\\AppFixtureMeasurementFilterField;
        use Workbench\\App\\Cms\\Generated\\QueryBuilders\\AppFixtureMeasurement\\AppFixtureMeasurementQuery;
        use Workbench\\App\\Cms\\Generated\\QueryBuilders\\AppFixtureMeasurement\\AppFixtureMeasurementSortField;
        use Workbench\\App\\Cms\\Generated\\Records\\AppFixtureMeasurement\\AppFixtureMeasurementRecord;
        use Workbench\\App\\Cms\\Generated\\Records\\AppFixtureMeasurement\\FixtureScaleChoice;

        final readonly class MeasurementListing
        {
            public function __construct(private AppFixtureMeasurementQuery \$query) {}

            /**
             * @return RecordPage<AppFixtureMeasurementRecord>
             */
            public function page(AccessContext \$access): RecordPage
            {
                {$statements}
            }
        }

        PHP);

    return Phpstan::analyse($file)->identifiers;
}

it('accepts filters on filterable fields and an order by sortable fields', function (): void {
    expect(analyseQuery(<<<'PHP'
        return $this->query
                    ->whereFixtureScale(FilterOperator::Eq, FixtureScaleChoice::FixtureCelsius)
                    ->where(AppFixtureMeasurementFilterField::FixtureStation, FilterOperator::In, new TextValue('north'))
                    ->orderBy(AppFixtureMeasurementSortField::FixtureReading, SortDirection::Descending)
                    ->page($access);
        PHP))->toBe([]);
});

it('reports a filter on a field that is not filterable as a type error', function (string $statements, string $identifier): void {
    expect(analyseQuery($statements))->toContain($identifier);
})->with([
    'the sortable field fixture_reading in where()' => ["return \$this->query->where(AppFixtureMeasurementSortField::FixtureReading, FilterOperator::Eq, new DecimalValue('1'))->page(\$access);", 'argument.type'],
    'a case the filter enum does not have' => ["return \$this->query->where(AppFixtureMeasurementFilterField::FixtureReading, FilterOperator::Eq, new DecimalValue('1'))->page(\$access);", 'classConstant.notFound'],
    'a typed filter method the builder does not have' => ["return \$this->query->whereFixtureReading(FilterOperator::Eq, '1')->page(\$access);", 'method.notFound'],
    'a value of the wrong type' => ["return \$this->query->whereFixtureScale(FilterOperator::Eq, 'fixture_celsius')->page(\$access);", 'argument.type'],
]);

it('reports an order by a field that is not sortable as a type error', function (): void {
    expect(analyseQuery('return $this->query->orderBy(AppFixtureMeasurementFilterField::FixtureStation)->page($access);'))->toContain('argument.type');
});
