<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/*
 * cms:panel:fills (PRD 13.2, 13.4): the contributions to a panel point in the order the host
 * renders them, priority with the lowest first, then the addon's namespace, then the
 * contribution's id, as lines and as one JSON document. An argument that is not the id of a
 * registered point exits 64, and a registry cache that cannot be read 78.
 */

/**
 * @return array{int, string}
 */
function panelFillsCli(string $point, bool $json = false): array
{
    $status = Artisan::call('cms:panel:fills', ['point' => $point, ...($json ? ['--json' => true] : [])]);

    return [$status, Artisan::output()];
}

/**
 * @return array<array-key, mixed>
 */
function panelFillsDocument(string $output): array
{
    $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : throw new RuntimeException('The output is not a JSON object.');
}

it('prints the contributions to a point in render order, each with its scope', function (): void {
    PanelRegistry::bind(withFills: true);

    [$status, $output] = panelFillsCli('notes.detail.sections@1');

    expect($status)->toBe(0)
        ->and($output)->toBe(
            "notes.detail.sections@1: 3 contributions, in the order the host renders them\n"
            ."  1. cms.summary  priority 100  cboxdk/cms, addon cms\n"
            ."  2. approvals.badge  priority 500  acme/cms-approvals, addon approvals\n"
            ."  3. reviews.stars  priority 500  acme/cms-reviews, addon reviews, scope pages notes.detail; commands note.create@1; types app:note; field types reviews:stars; requires note.find\n",
        );
});

it('prints the contributions as one JSON document in render order', function (): void {
    PanelRegistry::bind(withFills: true);

    [$status, $output] = panelFillsCli('notes.detail.sections@1', json: true);
    $everywhere = ['commands' => [], 'field_types' => [], 'pages' => [], 'requires' => null, 'types' => []];

    expect($status)->toBe(0)
        ->and(panelFillsDocument($output))->toBe([
            'fills' => [
                ['addon' => 'cms', 'contribution' => 'cms.summary', 'package' => 'cboxdk/cms', 'priority' => 100, 'scope' => $everywhere],
                ['addon' => 'approvals', 'contribution' => 'approvals.badge', 'package' => 'acme/cms-approvals', 'priority' => 500, 'scope' => $everywhere],
                ['addon' => 'reviews', 'contribution' => 'reviews.stars', 'package' => 'acme/cms-reviews', 'priority' => 500, 'scope' => [
                    'commands' => ['note.create@1'],
                    'field_types' => ['reviews:stars'],
                    'pages' => ['notes.detail'],
                    'requires' => 'note.find',
                    'types' => ['app:note'],
                ]],
            ],
            'point' => 'notes.detail.sections@1',
            'version' => 1,
        ]);
});

it('says so for a point without contributions, as text and as JSON', function (): void {
    PanelRegistry::bind();

    [$status, $output] = panelFillsCli('notes.form.submit@1');
    [$jsonStatus, $json] = panelFillsCli('notes.form.submit@1', json: true);

    expect([$status, $jsonStatus])->toBe([0, 0])
        ->and($output)->toBe("notes.form.submit@1: no contributions.\n")
        ->and(panelFillsDocument($json))->toBe(['fills' => [], 'point' => 'notes.form.submit@1', 'version' => 1]);
});

it('exits 64 for a point the registry does not hold, and for an argument that is not a point\'s id', function (string $point, string $message): void {
    PanelRegistry::bind();

    [$status, $output] = panelFillsCli($point);
    [$jsonStatus, $json] = panelFillsCli($point, json: true);

    expect($status)->toBe(64)
        ->and($output)->toContain($message)
        ->and($jsonStatus)->toBe(64)
        ->and($json)->toBe($output);
})->with([
    'an unknown point' => ['shell.banner@1', 'No panel point is registered as shell.banner@1. The registered panel points are notes.detail.sections@1,'],
    'a name without a version' => ['notes.form.submit', '"notes.form.submit" is not a panel point id'],
]);

it('exits 78 with the code of a registry cache that cannot be read', function (bool $damaged, string $code): void {
    WorkbenchRegistry::unreadable($damaged);

    [$status, $output] = panelFillsCli('notes.detail.sections@1');

    expect($status)->toBe(78)
        ->and($output)->toStartWith($code.': ');
})->with([
    'missing' => [false, 'registry_cache_missing'],
    'damaged' => [true, 'registry_cache_malformed'],
]);
