<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\PanelStories;

use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\StoryPoint;
use Cbox\Cms\Generators\PanelStories\Domain\PanelStoriesModule;

/*
 * The stories of the panel's points (section 2.7 of the panel extension architecture), as
 * cms:panel:stories writes them from panel.php: an overview and one story per point, every point
 * with its facts, its schema and its enabled contributions in render order, each handed the
 * point's sample props. The world's modules are the golden files in
 * js/panel/tests/host/stories-golden/generated, which tsc and Prettier check and a test of the JS
 * suite renders; they change only when the output is meant to.
 */

/**
 * @return array<string, string> each file's contents by its name
 */
function storyFiles(): array
{
    $files = [];

    foreach (PanelStoriesModule::result(PanelStoriesWorld::source(PanelStoriesWorld::registry())->points())->files as $file) {
        $files[basename($file->path)] = $file->contents;
    }

    return $files;
}

it('writes the golden modules of the world', function (): void {
    $files = storyFiles();

    expect(array_keys($files))->toBe([PanelStoriesModule::STORIES, PanelStoriesModule::DATA]);

    foreach ($files as $name => $contents) {
        expect($contents)->toBe(file_get_contents(PanelStoriesWorld::GOLDEN.'/'.$name), $name);
    }
});

it('gives every point a story, the enabled contributions in render order with the sample props, and the schema', function (): void {
    $files = storyFiles();

    expect($files[PanelStoriesModule::STORIES])->toContain(
        "export const NotesDetailCardV1: Story = pointStory(PANEL_POINTS, 'notes.detail.card@1');",
        "export const NotesFormSubmitV1: Story = pointStory(PANEL_POINTS, 'notes.form.submit@1');",
        "export const NotesListToolbarV1: Story = pointStory(PANEL_POINTS, 'notes.list.toolbar@1');",
        "export const NotesWiringV1: Story = pointStory(PANEL_POINTS, 'notes.wiring@1');",
        'export const Overview: Story = overviewStory(PANEL_POINTS);',
    )
        ->and($files[PanelStoriesModule::DATA])->toContain("id: 'cms.summary'", "id: 'approvals.badge'", "owner: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'", "stability: 'internal'", 'schema: null')
        ->and($files[PanelStoriesModule::DATA])->not->toContain('approvals.hidden')
        ->and($files[PanelStoriesModule::DATA])->toMatch('/cms\.summary.*approvals\.badge/s');
});

it('writes an overview alone for an installation without points', function (): void {
    $result = PanelStoriesModule::result([]);
    $stories = array_first(array_filter($result->files, static fn (GeneratedFile $file): bool => str_ends_with($file->path, PanelStoriesModule::STORIES))) ?? null;

    expect($stories?->contents)->toContain('export const Overview: Story = overviewStory(PANEL_POINTS);')
        ->and($stories?->contents)->not->toContain('pointStory')
        ->and($result->directories)->toBe([PanelStoriesModule::DIRECTORY]);
});

it('refuses two points that give one story', function (): void {
    $point = static fn (string $name): StoryPoint => new StoryPoint(new PanelPoint($name, 1, PointKind::Slot, 'notes', '1.0', 'fixture.points.x', Region::Aside), 'Acme\\X', 'experimental', null, null, []);

    $refused = null;

    try {
        PanelStoriesModule::result([$point('notes.ab.c'), $point('notes.ab-c')]);
    } catch (GenerationFailed $failed) {
        $refused = $failed;
    }

    expect($refused?->problems[0]->code)->toBe(GenerateErrorCode::NameCollision)
        ->and($refused?->problems[0]->message)->toContain('notes.ab-c@1', 'notes.ab.c@1', 'NotesAbCV1');
});
