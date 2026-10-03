<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Actions;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Actions\WritePanelStories;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\PanelStoriesRequest;
use Cbox\Cms\Generators\PanelStories\Domain\PanelStoriesModule;
use Cbox\Cms\Generators\Tests\Generation\Fakes\FakeGeneratedOutput;
use Cbox\Cms\Generators\Tests\PanelStories\PanelStoriesWorld;

/*
 * cms:panel:stories' action called directly with its PanelStoriesRequest (GUARDRAILS 9): the
 * registry source over a fake registry cache with the world's registry, and the fake output. It
 * writes the section's two modules below the root and removes what else is in their directory, a
 * second run changes nothing, and a registry it cannot read is refused with nothing written.
 */

it('writes the stories of the points below the root and removes a story no point has', function (): void {
    $output = new FakeGeneratedOutput;
    $output->put('/srv/cms/'.PanelStoriesModule::DIRECTORY.'/Gone.stories.tsx', "export {};\n");

    $report = new WritePanelStories(PanelStoriesWorld::source(PanelStoriesWorld::registry()), $output)->write(new PanelStoriesRequest('/srv/cms'));

    expect($report->written)->toBe([PanelStoriesModule::DIRECTORY.'/'.PanelStoriesModule::STORIES, PanelStoriesModule::DIRECTORY.'/'.PanelStoriesModule::DATA])
        ->and($report->removed)->toBe([PanelStoriesModule::DIRECTORY.'/Gone.stories.tsx'])
        ->and($output->contents('/srv/cms/'.PanelStoriesModule::DIRECTORY.'/'.PanelStoriesModule::DATA))->toBe(file_get_contents(PanelStoriesWorld::GOLDEN.'/'.PanelStoriesModule::DATA));
});

it('changes nothing on a second run', function (): void {
    $output = new FakeGeneratedOutput;
    $action = new WritePanelStories(PanelStoriesWorld::source(PanelStoriesWorld::registry()), $output);
    $action->write(new PanelStoriesRequest('/srv/cms'));

    $again = $action->write(new PanelStoriesRequest('/srv/cms'));

    expect($again->written)->toBe([])
        ->and($again->removed)->toBe([])
        ->and($again->unchanged)->toHaveCount(2);
});

it('refuses a registry it cannot read, and writes nothing', function (): void {
    $output = new FakeGeneratedOutput;
    $refused = null;

    try {
        new WritePanelStories(PanelStoriesWorld::source(null), $output)->write(new PanelStoriesRequest('/srv/cms'));
    } catch (GenerationFailed $failed) {
        $refused = $failed;
    }

    expect($refused?->problems[0]->code)->toBe(GenerateErrorCode::RegistryUnreadable)
        ->and($refused?->problems[0]->message)->toContain('Run cms:build, then cms:panel:stories again.')
        ->and($output->contents('/srv/cms/'.PanelStoriesModule::DIRECTORY.'/'.PanelStoriesModule::DATA))->toBeNull();
});
