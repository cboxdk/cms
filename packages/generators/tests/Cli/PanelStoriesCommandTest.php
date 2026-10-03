<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Cli\Tests\Console\WorkbenchRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\PanelStories\Domain\PanelStoriesModule;
use Cbox\Cms\Generators\Tests\Generation\Fakes\FakeGeneratedOutput;
use Cbox\Cms\Tests\Support\Phpstan;
use Illuminate\Contracts\Console\Kernel;

/*
 * cms:panel:stories in the testbench application (PRD 13.4): from the registry compiled from the
 * installation's scan roots and manifests as cms:build compiles it, it writes exactly the
 * committed modules of js/panel/stories/generated, so the panel's Storybook has a story for every
 * point of the installation; it refuses a registry it cannot read with the catalog's exit code.
 * The output is the fake one, so the test never touches the working tree.
 */

it('writes the committed stories of the installation s points', function (): void {
    WorkbenchRegistry::bind();
    $output = new FakeGeneratedOutput;
    app()->instance(GeneratedOutput::class, $output);
    $kernel = app(Kernel::class);

    $status = $kernel->call('cms:panel:stories');

    expect($status)->toBe(0)
        ->and($kernel->output())->toContain('Wrote the stories of the panel points.');

    foreach ([PanelStoriesModule::STORIES, PanelStoriesModule::DATA] as $file) {
        $path = PanelStoriesModule::DIRECTORY.'/'.$file;
        expect($output->contents(Phpstan::root().'/'.$path))->toBe(file_get_contents(Phpstan::root().'/'.$path), $path.' is not what cms:panel:stories writes: run cms:build and cms:panel:stories, and commit both modules.');
    }
});

it('refuses a registry it cannot read with exit 78', function (): void {
    app()->instance(RegistryCache::class, new FakeRegistryCache);
    app()->instance(GeneratedOutput::class, new FakeGeneratedOutput);
    $kernel = app(Kernel::class);

    expect($kernel->call('cms:panel:stories'))->toBe(78)
        ->and($kernel->output())->toContain('generate_registry_unreadable');
});
