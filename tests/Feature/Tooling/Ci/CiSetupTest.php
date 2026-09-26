<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
 * docker/ci-setup.sh gives the CI image Chromium for the Playwright that package-lock.json pins
 * (M0-T46): a build the image has in PLAYWRIGHT_BROWSERS_PATH is used, a missing one is installed
 * with the pinned Playwright, and a build still missing afterwards fails the setup. It runs here
 * in a scratch checkout with fake apt-get, git, getent, useradd, psql, php, npm and Playwright on
 * the PATH, and the real node.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A scratch checkout with a lock file, fake tools and an empty browsers directory.
 *
 * @return array{string, string} the checkout and the browsers directory
 */
function setupScratch(string $lock = '{"packages": {"": {}, "node_modules/playwright": {"version": "1.63.0"}}}'): array
{
    $scratch = ScratchDirectory::make();
    $bin = $scratch.'/bin';
    $node = new ExecutableFinder()->find('node') ?? throw new RuntimeException('node is not on the PATH.');
    $record = "#!/usr/bin/env bash\nprintf '%s %s\\n' \"\$(basename \"\$0\")\" \"\$*\" >> \"\$FAKE_CALLS\"\n";

    foreach (['apt-get', 'git', 'useradd', 'psql', 'php'] as $tool) {
        ScratchDirectory::write("{$bin}/{$tool}", $record."echo {$tool} 1.0\n");
    }

    ScratchDirectory::write("{$bin}/getent", $record."exit 2\n");
    ScratchDirectory::write("{$bin}/node", "#!/usr/bin/env bash\nexec ".escapeshellarg($node)." \"\$@\"\n");
    ScratchDirectory::write("{$bin}/rm", $record.<<<'SH'
        [[ "$*" == *"/var/lib/apt/lists/"* ]] && exit 0
        exec /bin/rm "$@"
        SH);
    // npm install --prefix <dir> ... playwright@<version> puts a fake Playwright in <dir>. Its dry
    // run names the builds as Playwright does, and its install creates them when
    // FAKE_INSTALL_WORKS is 1.
    ScratchDirectory::write("{$bin}/npm", $record.<<<'SH'
        prefix=''
        while [[ $# -gt 0 ]]; do
            [[ "$1" == --prefix ]] && prefix="$2"
            shift
        done
        mkdir -p "$prefix/node_modules/.bin"
        cat > "$prefix/node_modules/.bin/playwright" <<'PLAYWRIGHT'
        #!/usr/bin/env bash
        printf 'playwright %s\n' "$*" >> "$FAKE_CALLS"
        if [[ "$*" == 'install --dry-run chromium' ]]; then
            for build in chromium-1243 ffmpeg-1011 chromium_headless_shell-1243; do
                printf 'Browser %s\n  Install location:    %s\n  Download url:        https://example.test/%s.zip\n\n' "$build" "$PLAYWRIGHT_BROWSERS_PATH/$build" "$build"
            done
        elif [[ "$*" == 'install --with-deps chromium' && "${FAKE_INSTALL_WORKS:-0}" == 1 ]]; then
            for build in chromium-1243 chromium_headless_shell-1243 ffmpeg-1011; do
                mkdir -p "$PLAYWRIGHT_BROWSERS_PATH/$build" && touch "$PLAYWRIGHT_BROWSERS_PATH/$build/INSTALLATION_COMPLETE"
            done
        fi
        PLAYWRIGHT
        chmod +x "$prefix/node_modules/.bin/playwright"
        SH);

    foreach (glob($bin.'/*') ?: [] as $file) {
        chmod($file, 0o755);
    }

    ScratchDirectory::write($scratch.'/checkout/package-lock.json', $lock);
    mkdir($scratch.'/browsers');

    return [$scratch, $scratch.'/browsers'];
}

/**
 * @param  array<string, string|false>  $env
 * @return array{Process, list<string>}
 */
function runSetup(string $scratch, array $env = []): array
{
    $process = new Process([Phpstan::root().'/docker/ci-setup.sh'], $scratch.'/checkout', [
        'PATH' => $scratch.'/bin:/usr/bin:/bin',
        'FAKE_CALLS' => $scratch.'/calls.log',
        'PLAYWRIGHT_BROWSERS_PATH' => $scratch.'/browsers',
        ...$env,
    ], null, 60);
    $process->run();

    $calls = is_file($scratch.'/calls.log') ? file($scratch.'/calls.log', FILE_IGNORE_NEW_LINES) : [];

    return [$process, $calls ?: []];
}

function completeBuild(string $browsers, string $build): void
{
    ScratchDirectory::write("{$browsers}/{$build}/INSTALLATION_COMPLETE");
}

it('uses the Chromium builds the image has for the pinned Playwright, and installs nothing', function (): void {
    [$scratch, $browsers] = setupScratch();
    completeBuild($browsers, 'chromium-1243');
    completeBuild($browsers, 'chromium_headless_shell-1243');

    [$process, $calls] = runSetup($scratch);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_values(array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'npm '))))->toHaveCount(1)
        ->and(implode("\n", $calls))->toContain('playwright@1.63.0', 'apt-get install --quiet --yes --no-install-recommends postgresql-client')
        ->and(array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'playwright install --with-deps')))->toBe([])
        ->and($process->getOutput())->toContain("Playwright 1.63.0 with Chromium in {$browsers}/chromium-1243 {$browsers}/chromium_headless_shell-1243");
});

it('installs the builds the image lacks with the pinned Playwright', function (): void {
    [$scratch, $browsers] = setupScratch();
    completeBuild($browsers, 'chromium-1234');

    [$process, $calls] = runSetup($scratch, ['FAKE_INSTALL_WORKS' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toContain('playwright install --with-deps chromium')
        ->and($process->getOutput())->toContain('the image lacks the Chromium builds of Playwright 1.63.0')
        ->and("{$browsers}/chromium-1243/INSTALLATION_COMPLETE")->toBeFile()
        ->and("{$browsers}/chromium_headless_shell-1243/INSTALLATION_COMPLETE")->toBeFile();
});

it('fails when a build of the pinned Playwright is still missing after the install', function (): void {
    [$scratch, $browsers] = setupScratch();
    completeBuild($browsers, 'chromium-1243');

    [$process, $calls] = runSetup($scratch);

    expect($process->getExitCode())->toBe(1)
        ->and($calls)->toContain('playwright install --with-deps chromium')
        ->and($process->getErrorOutput())->toContain(
            'Playwright 1.63.0 needs Chromium builds that are still missing after the install:',
            "{$browsers}/chromium_headless_shell-1243",
        );
});

it('fails before any change without PLAYWRIGHT_BROWSERS_PATH, or without Playwright in the lock file', function (string|false $browsersPath, string $lock, string $message): void {
    [$scratch] = setupScratch($lock);

    [$process, $calls] = runSetup($scratch, $browsersPath === false ? ['PLAYWRIGHT_BROWSERS_PATH' => false] : []);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain($message)
        ->and($calls)->toBe([]);
})->with([
    'no browsers path' => [false, '{"packages": {"node_modules/playwright": {"version": "1.63.0"}}}', 'PLAYWRIGHT_BROWSERS_PATH is not set'],
    'no Playwright locked' => ['set', '{"packages": {"": {}}}', 'package-lock.json locks no version of playwright'],
]);
