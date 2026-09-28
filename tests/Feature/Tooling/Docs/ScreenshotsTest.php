<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tooling\Docs\Boundary\DocsScreenshotsOptions;
use Cbox\Cms\Tooling\Docs\Boundary\ScreenshotCapture;
use Cbox\Cms\Tooling\Docs\Domain\Screenshot;
use Cbox\Cms\Tooling\Docs\Domain\Screenshots;
use Cbox\Cms\Tooling\Docs\Domain\TerminalCell;
use Cbox\Cms\Tooling\Docs\Domain\TerminalStyle;
use Cbox\Cms\Tooling\Docs\Domain\TerminalSvg;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * The screenshots of the documentation (`composer docs:screenshots`): the terminal that TerminalSvg
 * draws from a command's output, the capture that runs the command, and the options of the script.
 * The capture itself runs the commands of Screenshots only when a developer runs the script, since
 * it rewrites committed files; here it runs PHP one-liners instead.
 */

/**
 * The text of each line of the screen.
 *
 * @param  list<list<TerminalCell>>  $lines
 * @return list<string>
 */
function screenText(array $lines): array
{
    return array_map(static fn (array $cells): string => implode('', array_map(static fn (TerminalCell $cell): string => $cell->character, $cells)), $lines);
}

/**
 * Runs tools/bin/docs-screenshots.php of this checkout with the arguments.
 *
 * @return array{int, string, string}
 */
function runDocsScreenshots(string ...$arguments): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/docs-screenshots.php', ...array_values($arguments)], Phpstan::root(), null, null, 60);
    $process->run();

    return [$process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput()];
}

it('wraps a line at the width, returns to the first column on \r, moves to the next tab stop and drops trailing empty lines', function (): void {
    $lines = TerminalSvg::screen("abcdefghij\rXY\n\tz\n12345678901234\n\n\n", 10);

    expect(screenText($lines))->toBe(['XYcdefghij', '        z', '1234567890', '1234']);
});

it('reads SGR colours and attributes, and drops other escape sequences and control characters', function (): void {
    $lines = TerminalSvg::screen("\e[1;31mred\e[0m \e[38;5;208mo\e[38;2;1;2;3mr\e[39m\e]8;;https://example.com\e\\x\e]8;;\e\\\e[2K\x07!\e[7mi\e[27m", 80);
    $cells = $lines[0];

    expect(screenText($lines))->toBe(['red orx!i'])
        ->and($cells[0]->style->bold)->toBeTrue()
        ->and($cells[0]->style->textColour())->toBe(TerminalStyle::PALETTE[1])
        ->and($cells[3]->style->textColour())->toBe(TerminalStyle::FOREGROUND)
        ->and($cells[4]->style->textColour())->toBe('#ff8700')
        ->and($cells[5]->style->textColour())->toBe('#010203')
        ->and($cells[6]->style->textColour())->toBe(TerminalStyle::FOREGROUND)
        ->and($cells[8]->style->textColour())->toBe(TerminalStyle::BACKGROUND)
        ->and($cells[8]->style->cellColour())->toBe(TerminalStyle::FOREGROUND);
});

it('clears to the end of the line on ESC [ K', function (): void {
    expect(screenText(TerminalSvg::screen("progress 10%\r\e[Kdone", 80)))->toBe(['done']);
});

it('keeps multibyte characters in one cell and replaces invalid UTF-8', function (): void {
    expect(screenText(TerminalSvg::screen("✓ ok æ\xC3", 80)))->toBe(["✓ ok æ\u{FFFD}"]);
});

it('draws the prompt with the command, a text element per run and a rectangle behind a background, escaped, and gives the same bytes for the same output', function (): void {
    $svg = TerminalSvg::render('vendor/bin/testbench cms:doctor', "\e[37;41mFAIL\e[39;49m <a & b>\n", 40);

    expect($svg)->toStartWith('<svg xmlns="http://www.w3.org/2000/svg" width="344" height="106" viewBox="0 0 344 106" role="img" aria-label="vendor/bin/testbench cms:doctor">')
        ->toContain('<title>vendor/bin/testbench cms:doctor</title>')
        ->toContain('<text x="16" y="64" fill="'.TerminalStyle::PALETTE[2].'" class="b" textLength="7.8">$</text>')
        ->toContain('<rect x="16" y="70" width="31.2" height="20" fill="'.TerminalStyle::PALETTE[1].'"/>')
        ->toContain('<text x="16" y="84" fill="'.TerminalStyle::PALETTE[7].'" textLength="31.2">FAIL</text>')
        ->toContain('&lt;a &amp; b&gt;</text>')
        ->toEndWith("</svg>\n")
        ->and(TerminalSvg::render('vendor/bin/testbench cms:doctor', "\e[37;41mFAIL\e[39;49m <a & b>\n", 40))->toBe($svg);
});

it('captures a command\'s output with colour forced on and the width in COLUMNS, and refuses an unexpected exit code', function (): void {
    $script = 'echo getenv("FORCE_COLOR"), getenv("NO_COLOR") === false ? "" : "no-color", " ", getenv("COLUMNS"), "\n"; fwrite(STDERR, "\e[31mfailed\e[0m\n"); exit(3);';
    $shot = new Screenshot('probe', [PHP_BINARY, '-r', $script], 'A probe.', exitCode: 3, columns: 60);

    $svg = ScreenshotCapture::capture(Phpstan::root(), $shot);

    expect($svg)->toContain('>1 60</text>')
        ->toContain('fill="'.TerminalStyle::PALETTE[1].'" textLength="46.8">failed</text>')
        ->and(fn (): string => ScreenshotCapture::capture(Phpstan::root(), new Screenshot('probe', [PHP_BINARY, '-r', $script], 'A probe.')))
        ->toThrow(RuntimeException::class, 'and the screenshot probe expects 0');
});

it('captures every shot by default, the named ones with --only, and refuses an unknown key or argument', function (): void {
    $manifest = [new Screenshot('one', ['php'], 'One.'), new Screenshot('two', ['php'], 'Two.'), new Screenshot('three', ['php'], 'Three.')];
    $keys = static fn (DocsScreenshotsOptions $options): array => array_map(static fn (Screenshot $shot): string => $shot->key, $options->shots);

    expect($keys(DocsScreenshotsOptions::parse([], $manifest)))->toBe(['one', 'two', 'three'])
        ->and($keys(DocsScreenshotsOptions::parse(['--only=three', '--only=one', '--only=one'], $manifest)))->toBe(['one', 'three'])
        ->and($keys(DocsScreenshotsOptions::forRepository([])))->toBe(array_map(static fn (Screenshot $shot): string => $shot->key, Screenshots::all()))
        ->and(fn (): DocsScreenshotsOptions => DocsScreenshotsOptions::parse(['--only=four'], $manifest))->toThrow(InvalidArgumentException::class, 'the keys are one, two, three')
        ->and(fn (): DocsScreenshotsOptions => DocsScreenshotsOptions::parse(['--all'], $manifest))->toThrow(InvalidArgumentException::class, 'Unknown argument [--all].');
});

it('exits 2 on a usage error, before it runs any command', function (): void {
    [$exitCode, $output, $errors] = runDocsScreenshots('--only=nothing');

    expect($exitCode)->toBe(2)
        ->and($output)->toBe('')
        ->and($errors)->toContain('No screenshot has the key [nothing]', DocsScreenshotsOptions::USAGE);
});

it('exposes the capture as composer docs:screenshots, with no time limit', function (): void {
    expect(ComposerScripts::steps('docs:screenshots'))->toBe(['Composer\Config::disableProcessTimeout', '@php tools/bin/docs-screenshots.php'])
        ->and(ComposerScripts::description('docs:screenshots'))->toContain('docs/screenshots/<key>.svg', '--only=<key>', 'php container');
});
