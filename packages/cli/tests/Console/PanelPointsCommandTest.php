<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Core\Tests\Registry\Fixtures\Panel\NoteSectionsV1;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Panel\NoteSubmitV2;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/*
 * cms:panel:points (PRD 13.2, 13.4): the panel's extension points as the registry holds them, by
 * name and version, as lines and as one JSON document; all of them, or those a page, a name or an
 * id selects. A selector that selects no point, or is no page's name or point's id, exits 64, and
 * a registry cache that cannot be read 78.
 */

/**
 * @return array{int, string}
 */
function panelPointsCli(?string $selector = null, bool $json = false): array
{
    $status = Artisan::call('cms:panel:points', [...($selector === null ? [] : ['selector' => $selector]), ...($json ? ['--json' => true] : [])]);

    return [$status, Artisan::output()];
}

/**
 * @return array<array-key, mixed>
 */
function panelPointsDocument(string $output): array
{
    $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : throw new RuntimeException('The output is not a JSON object.');
}

it('prints every panel point of the registry by name and version, two lines each', function (): void {
    PanelRegistry::bind(withFills: true);

    [$status, $output] = panelPointsCli();
    $package = RegistryFixtures::PACKAGE;
    $fixtures = 'Cbox\Cms\Core\Tests\Registry\Fixtures\Panel';

    expect($status)->toBe(0)
        ->and($output)->toBe(
            "5 panel points, by name and version\n"
            ."  notes.detail.sections@1  slot in sections, renders many  page notes.detail  experimental since 1.0  3 contributions\n"
            ."      props {$fixtures}\\NoteSectionsV1 ({$package}), label fixture.points.note_sections\n"
            ."  notes.form.field@1  replacement, renders one, own keys by field_type  page notes.form  experimental since 1.0  0 contributions\n"
            ."      props {$fixtures}\\NoteFieldInputV1 ({$package}), label fixture.points.note_field\n"
            ."  notes.form.submit@1  decorator, renders many, tightens disabled_reason and description  page notes.form  stable since 1.0  0 contributions\n"
            ."      props {$fixtures}\\NoteSubmitV1 ({$package}), label fixture.points.note_submit\n"
            ."  notes.form.submit@2  decorator, renders many, tightens tone_towards_danger  page notes.form  internal since 1.2  0 contributions\n"
            ."      props {$fixtures}\\NoteSubmitV2 ({$package}), label fixture.points.note_submit\n"
            ."  notes.list.toolbar@1  slot in toolbar, renders at most 3  page notes.list  experimental since 1.1  0 contributions\n"
            ."      props {$fixtures}\\NotesToolbarV1 ({$package}), label fixture.points.notes_toolbar\n",
        );
});

it('prints the panel points as one JSON document, every key present', function (): void {
    PanelRegistry::bind(withFills: true);

    [$status, $output] = panelPointsCli('notes.detail', json: true);

    expect($status)->toBe(0)
        ->and(panelPointsDocument($output))->toBe([
            'points' => [[
                'class' => NoteSectionsV1::class,
                'deprecated' => null,
                'fills' => 3,
                'id' => 'notes.detail.sections@1',
                'keyed_by' => null,
                'kind' => 'slot',
                'label' => 'fixture.points.note_sections',
                'max' => null,
                'multiplicity' => 'many',
                'ownership' => null,
                'package' => RegistryFixtures::PACKAGE,
                'page' => 'notes.detail',
                'region' => 'sections',
                'since' => '1.0',
                'stability' => 'experimental',
                'tightens' => [],
            ]],
            'version' => 1,
        ]);
});

it('selects the points a page renders, the versions of a point by its name, and one point by its id', function (string $selector, array $ids): void {
    PanelRegistry::bind();

    [$status, $output] = panelPointsCli($selector, json: true);
    $points = panelPointsDocument($output)['points'] ?? null;

    expect($status)->toBe(0)
        ->and(is_array($points) ? array_column($points, 'id') : null)->toBe($ids);
})->with([
    'a page' => ['notes.form', ['notes.form.field@1', 'notes.form.submit@1', 'notes.form.submit@2']],
    'a point\'s name' => ['notes.form.submit', ['notes.form.submit@1', 'notes.form.submit@2']],
    'an id' => ['notes.form.submit@2', ['notes.form.submit@2']],
]);

it('prints the one point of an id with its props class and its stability', function (): void {
    PanelRegistry::bind();

    [$status, $output] = panelPointsCli('notes.form.submit@2');

    expect($status)->toBe(0)
        ->and($output)->toContain('1 panel point, by name and version')
        ->toContain('notes.form.submit@2  decorator, renders many, tightens tone_towards_danger  page notes.form  internal since 1.2')
        ->toContain(NoteSubmitV2::class);
});

it('lists the panel\'s own points, the shell\'s three and the sections of the who-am-I, roles and grants pages and the command form\'s aside, as text and as JSON', function (): void {
    WorkbenchRegistry::bind();

    [$status, $output] = panelPointsCli();
    [$jsonStatus, $json] = panelPointsCli(json: true);

    expect([$status, $jsonStatus])->toBe([0, 0])
        ->and($output)->toContain('7 panel points, by name and version', 'access.grants.sections@1  slot in sections, renders many  page access.grants  experimental since 1.0  0 contributions', 'access.roles.sections@1  slot in sections, renders many  page access.roles  experimental since 1.0  0 contributions', 'account.me.sections@1  slot in sections, renders many  page account.me  experimental since 1.0  0 contributions', 'command.form.aside@1  slot in aside, renders many  page command.form  experimental since 1.0  0 contributions', 'shell.nav@1  nav, renders many  page shell  experimental since 1.0  3 contributions', 'shell.page@1  page, renders many  page shell  experimental since 1.0  0 contributions', 'shell.user-menu@1  action, renders many  page shell  experimental since 1.0  0 contributions')
        ->and(is_array($points = panelPointsDocument($json)['points'] ?? null) ? array_column($points, 'id') : null)->toBe(['access.grants.sections@1', 'access.roles.sections@1', 'account.me.sections@1', 'command.form.aside@1', 'shell.nav@1', 'shell.page@1', 'shell.user-menu@1']);
});

it('exits 64 for a selector that selects no point or is no page\'s name or point\'s id', function (string $selector, string $message): void {
    PanelRegistry::bind();

    [$status, $output] = panelPointsCli($selector);
    [$jsonStatus, $json] = panelPointsCli($selector, json: true);

    expect($status)->toBe(64)
        ->and($output)->toContain($message)
        ->and($jsonStatus)->toBe(64)
        ->and($json)->toBe($output);
})->with([
    'an unknown page' => ['shell', 'No registered panel point is named shell or rendered by a page of that name. The registered panel points are notes.detail.sections@1, notes.form.field@1, notes.form.submit@1, notes.form.submit@2, notes.list.toolbar@1.'],
    'an unknown version' => ['notes.form.submit@3', 'No panel point is registered as notes.form.submit@3.'],
    'not a name' => ['Notes Form', 'The panel page name "Notes Form" is not'],
    'not an id' => ['notes.form.submit@v1', '"notes.form.submit@v1" is not a panel point id'],
]);

it('exits 78 with the code of a registry cache that cannot be read', function (bool $damaged, string $code): void {
    WorkbenchRegistry::unreadable($damaged);

    [$status, $output] = panelPointsCli();
    [$jsonStatus, $json] = panelPointsCli(json: true);

    expect($status)->toBe(78)
        ->and($output)->toStartWith($code.': ')
        ->and($jsonStatus)->toBe(78)
        ->and(panelPointsDocument($json)['code'] ?? null)->toBe($code);
})->with([
    'missing' => [false, 'registry_cache_missing'],
    'damaged' => [true, 'registry_cache_malformed'],
]);
