<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\ExpectationFailedException;

/*
 * Gate 8 of GUARDRAILS 10 and the browser row of GUARDRAILS 9: the Pest browser plugin drives
 * Chromium through Playwright against the workbench application that Testbench boots. The plugin
 * serves that application in-process on a free port, so a test visits a path, not a URL.
 *
 * The last three tests are negative controls: they serve a page that breaks one assertion and
 * check that the assertion fails, so a green run of the first test means something.
 */

/**
 * Registers a page on the application the plugin serves, for one test.
 */
function servePage(string $path, string $body): void
{
    Route::get($path, static fn (): string => '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        .'<title>Probe</title><link rel="icon" href="data:,"></head><body>'.$body.'</body></html>');
}

it('renders the workbench page in Chromium without console output or JavaScript errors', function (): void {
    visit('/')
        ->assertTitle('Cbox CMS workbench')
        ->assertSee('Cbox CMS workbench')
        ->assertNoConsoleLogs()
        ->assertNoJavaScriptErrors();
});

it('fails assertSee when the text is not on the page', function (): void {
    servePage('/_probe/text', '<p>Something else</p>');

    expect(fn (): mixed => visit('/_probe/text')->assertSee('Cbox CMS workbench'))
        ->toThrow(ExpectationFailedException::class, 'Expected to see text [Cbox CMS workbench]');
});

it('fails assertNoConsoleLogs when the page writes to the console', function (): void {
    servePage('/_probe/console', '<p>Probe</p><script>console.log("probe output")</script>');

    expect(fn (): mixed => visit('/_probe/console')->assertSee('Probe')->assertNoConsoleLogs())
        ->toThrow(ExpectationFailedException::class, 'probe output');
});

it('fails assertNoJavaScriptErrors when a script throws', function (): void {
    servePage('/_probe/error', '<p>Probe</p><script>throw new Error("probe failure")</script>');

    expect(fn (): mixed => visit('/_probe/error')->assertSee('Probe')->assertNoJavaScriptErrors())
        ->toThrow(ExpectationFailedException::class, 'probe failure');
});
