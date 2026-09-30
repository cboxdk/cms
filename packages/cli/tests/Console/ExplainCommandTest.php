<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/*
 * cms:explain with fakes (GUARDRAILS 9): path.resolve's action on the ResolveWorld's structure,
 * run by a query pipeline of the QueryWorld's fakes as the anonymous principal. It covers what
 * stops the command before or in the read: a URL or a locale path.resolve does not take (exit 64),
 * a read the pipeline rejects (the catalog's exit code, the problem details with --json) and a
 * registry cache that cannot be read (78). The explanations themselves are covered against the
 * workbench on Postgres, in packages/cli/tests/Postgres/ExplainCommandTest.php.
 */

/**
 * Binds a query pipeline that resolves on the ResolveWorld, with a placement "harbour" below its
 * section, and an authorizer that refuses when a reason is given.
 */
function explainFakes(?string $refusal = null, int $budget = ResolvePathAction::COST): void
{
    $world = new QueryWorld;
    $resolve = new ResolveWorld()->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    app()->instance(QueryPipeline::class, new QueryPipeline(
        new FakeQueryActions([ResolvePath::class => ProbeQueryBinding::of($resolve->action(), 'path.resolve', 1)]),
        $world->identity,
        $world->access,
        new FakeQueryAuthorizer($refusal),
        new QuerySettings(new QueryCost($budget), new QueryCost($budget)),
        new ReadableFields(new FakeTypeCatalog),
        $world->audit,
        $world->transaction,
        new PipelineTelemetry($world->telemetry, $world->clock, new FakeStopwatch),
    ));
}

/**
 * @param  array<string, bool|string>  $options
 * @return array{int, string}
 */
function explainFaked(string $url, array $options = ['--locale' => 'da']): array
{
    $status = Artisan::call('cms:explain', ['url' => $url, ...$options]);

    return [$status, Artisan::output()];
}

it('explains a URL through the query pipeline and exits 0', function (): void {
    explainFakes();

    [$status, $output] = explainFaked('https://south.example/national/harbour');
    [$jsonStatus, $json] = explainFaked('HTTPS://South.Example/national/harbour', ['--locale' => 'da', '--json' => true]);
    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($output)->toStartWith("south.example/national/harbour in da: resolved\n")
        ->and($output)->toContain('  read at      '.QueryWorld::POSITION.', the read saw every changeset below it')
        ->and($jsonStatus)->toBe(0)
        ->and(is_array($document) ? $document['read_position'] ?? null : null)->toBe(QueryWorld::POSITION);
});

it('decodes the path and leaves out the query and the fragment', function (): void {
    explainFakes();

    [$status, $output] = explainFaked('https://north.example/nyheder/%68arbour?page=2#comments');

    expect($status)->toBe(0)
        ->and($output)->toStartWith("north.example/nyheder/harbour in da: resolved\n");
});

it('exits 64 for a URL or a locale path.resolve does not take', function (string $url, array $options, string $message): void {
    /** @var array<string, string> $options */
    explainFakes();

    [$status, $output] = explainFaked($url, $options);
    [$jsonStatus, $json] = explainFaked($url, [...$options, '--json' => true]);

    expect($status)->toBe(64)
        ->and($output)->toContain($message)
        ->and($jsonStatus)->toBe(64)
        ->and($json)->toBe($output);
})->with([
    'a relative URL' => ['/nyheder/harbour', ['--locale' => 'da'], 'is not an absolute http or https URL'],
    'another scheme' => ['ftp://north.example/nyheder/harbour', ['--locale' => 'da'], 'is not an absolute http or https URL'],
    'credentials' => ['https://editor:secret@north.example/nyheder/harbour', ['--locale' => 'da'], 'without credentials'],
    'a trailing slash' => ['https://north.example/nyheder/harbour/', ['--locale' => 'da'], '/nyheder/harbour/'],
    'an invalid host' => ['https://north_example/nyheder', ['--locale' => 'da'], 'north_example'],
    'no locale' => ['https://north.example/nyheder/harbour', [], 'Give the locale to resolve the URL in with --locale'],
    'an invalid locale' => ['https://north.example/nyheder/harbour', ['--locale' => 'Danish!'], 'Danish!'],
]);

it('exits with the catalog\'s exit code of a read the pipeline rejects, and prints the problem details with --json', function (): void {
    explainFakes(refusal: 'Only editors explain pages.');

    [$status, $output] = explainFaked('https://north.example/nyheder/harbour');
    [$jsonStatus, $json] = explainFaked('https://north.example/nyheder/harbour', ['--locale' => 'da', '--json' => true]);
    $problem = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(77)
        ->and($output)->toStartWith('unauthorized: ')
        ->and($output)->toContain('Only editors explain pages.')
        ->and($output)->toContain('See docs/reference/errors.md#unauthorized.')
        ->and($jsonStatus)->toBe(77)
        ->and(is_array($problem) ? $problem['code'] ?? null : throw new RuntimeException('The problem is not an object.'))->toBe('unauthorized');
});

it('exits with query_over_budget\'s exit code when the read costs more than the budget', function (): void {
    explainFakes(budget: ResolvePathAction::COST - 1);

    [$status, $output] = explainFaked('https://north.example/nyheder/harbour');

    expect($status)->toBe(ErrorCode::QueryOverBudget->entry()->exit->value)
        ->and($output)->toStartWith('query_over_budget: ');
});

it('exits 78 with the code of a registry cache that cannot be read', function (bool $damaged, string $code): void {
    WorkbenchRegistry::unreadable($damaged);
    app()->forgetInstance(QueryPipeline::class);

    [$status, $output] = explainFaked('https://north.example/nyheder/harbour');

    expect($status)->toBe(78)
        ->and($output)->toStartWith($code.': ');
})->with([
    'missing' => [false, 'registry_cache_missing'],
    'damaged' => [true, 'registry_cache_malformed'],
]);
