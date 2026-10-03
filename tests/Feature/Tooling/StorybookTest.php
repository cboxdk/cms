<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;

/*
 * The configuration gate 7 runs the component kit's Storybook with (GUARDRAILS 8, 10; the panel
 * extension architecture of 2 October 2026, section 2.7, decisions D10 and D11): axe fails a
 * story's test, a skipped story fails the run, each story is compared with a baseline committed
 * in js/ui-kit/visual-baselines, the comparison runs only in the dev image the baselines are
 * rendered in, and neither Storybook nor its tests reach the network. Each of these is a check of
 * its own (GUARDRAILS 7.3), so a change that weakens one fails here.
 */

function storybookFile(string $path): string
{
    return (string) file_get_contents(Phpstan::root().'/'.$path);
}

it('fails a story\'s test on an axe violation, and runs every story and MDX page of js/ui-kit/stories and the stories of the panel\'s points', function (): void {
    expect(storybookFile('js/ui-kit/.storybook/preview.tsx'))->toContain("a11y: { test: 'error' }")
        ->and(storybookFile('js/ui-kit/.storybook/main.ts'))->toContain("    '../stories/**/*.mdx',\n    '../stories/**/*.stories.tsx',\n    '../../panel/stories/**/*.stories.tsx',\n  ],", "'@storybook/addon-a11y'", "'@storybook/addon-docs'", "'@storybook/addon-vitest'");
});

it('turns off Storybook\'s telemetry and notifications in the configuration, the build and the story tests', function (): void {
    $scripts = Node::jsonFile('package.json')['scripts'] ?? [];

    expect(storybookFile('js/ui-kit/.storybook/main.ts'))->toContain('disableTelemetry: true', 'disableWhatsNewNotifications: true')
        ->and(is_array($scripts) ? ($scripts['storybook:build'] ?? '') : '')->toContain('--disable-telemetry')
        ->and(is_array($scripts) ? ($scripts['storybook'] ?? '') : '')->toContain('--disable-telemetry')
        ->and(storybookFile('js/ui-kit/scripts/story-tests.js'))->toContain("STORYBOOK_DISABLE_TELEMETRY: '1'");
});

it('compares every story with a baseline in js/ui-kit/visual-baselines, named by its story id, and fails a skipped story', function (): void {
    $config = storybookFile('vitest.config.ts');
    $baselines = glob(Phpstan::root().'/js/ui-kit/visual-baselines/*.png') ?: [];

    expect($config)->toContain("export const VISUAL_BASELINES = 'js/ui-kit/visual-baselines';", 'resolveScreenshotPath: ({ arg, ext }) => join(ROOT, VISUAL_BASELINES, arg + ext)', 'allowedMismatchedPixelRatio: 0')
        ->and(storybookFile('js/ui-kit/.storybook/vitest.setup.ts'))->toContain('toMatchScreenshot(storyId)')
        ->and(storybookFile('js/ui-kit/scripts/story-tests.js'))->toContain("'--reporter=./js/tooling/vitest-no-skipped.js'")
        ->and(count($baselines))->toBeGreaterThan(0);

    foreach ($baselines as $baseline) {
        expect(basename($baseline))->toMatch('/^[a-z0-9]+(-[a-z0-9]+)+\.png$/');
    }
});

it('refuses to run the story tests outside the dev image the baselines are rendered in, and says how to run them', function (string $script, string $advice): void {
    $process = Node::run(['npm', 'run', '--silent', $script], ['CBOX_IMAGE_TIER' => 'prod']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('rendered in the php-baseimages dev image', $advice);
})->with([
    'the comparison' => ['storybook:stories', 'composer image:run -- npm run storybook:test'],
    'writing the baselines' => ['storybook:baselines', 'composer image:run -- npm run storybook:baselines'],
]);
