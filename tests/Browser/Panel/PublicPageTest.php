<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use ArrayObject;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Api\PendingAwaitablePage;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;

/*
 * The panel's public page, the one it shows for an address it does not have, in Chromium against
 * the build `composer panel:build` writes (PRD 13.4, GUARDRAILS 6, 8 and 9). The page is rendered
 * by React from the build's script, so its translated text proves the script ran under the page's
 * Content-Security-Policy; the policy's nonce is the one on that script, the console stays empty,
 * no script throws, axe finds no WCAG 2.2 AA issue, the browser reports no policy violation, and
 * the page works with the keyboard alone, in the light and the dark theme and on a phone.
 *
 * The browser plugin serves the workbench application in this process, so the test hears the
 * kernel answer each request and reads the policy the browser got.
 */

/**
 * The Content-Security-Policy of every panel page the kernel answers during the test, by path.
 *
 * @return ArrayObject<string, string>
 */
function recordPanelPolicies(): ArrayObject
{
    /** @var ArrayObject<string, string> $policies */
    $policies = new ArrayObject;

    Event::listen(RequestHandled::class, static function (RequestHandled $handled) use ($policies): void {
        $policy = $handled->response->headers->get(ContentSecurityPolicy::HEADER);

        if (is_string($policy)) {
            $policies['/'.ltrim($handled->request->path(), '/')] = $policy;
        }
    });

    return $policies;
}

/**
 * The page for an address the panel does not have, with its translated texts.
 *
 * @return list<string>
 */
function notFoundTexts(): array
{
    return ['panel.not_found.title', 'panel.not_found.body', 'panel.not_found.home'];
}

it('shows the translated page, with a policy whose nonce is the script\'s, no console output, no errors and no accessibility issues', function (): void {
    $policies = recordPanelPolicies();

    $page = visit('/cms/no/such/page');

    PanelPage::assertPage($page, notFoundTexts());
    $page->assertTitle(PanelPage::text('panel.title', ['page' => PanelPage::text('panel.not_found.title'), 'name' => PanelPage::text('panel.name')]))
        ->assertSee('404');

    $policy = (string) ($policies['/cms/no/such/page'] ?? '');

    expect($policy)->not->toContain('unsafe-inline');
    expect($policy)->not->toContain('unsafe-eval');
    expect(preg_match("/script-src 'nonce-([A-Za-z0-9+\\/]{22}==)' 'strict-dynamic'/", $policy, $match))->toBe(1);

    $nonce = $match[1] ?? '';
    $styles = $page->script("[...document.querySelectorAll('link[rel=\"stylesheet\"]')].map((link) => link.nonce)");

    expect($page->script("document.querySelector('script[type=\"module\"]').nonce"))->toBe($nonce);
    expect($page->script("document.querySelector('meta[property=\"csp-nonce\"]').nonce"))->toBe($nonce);
    expect($styles)->toBe([$nonce]);
    expect($policy)->toBe(ContentSecurityPolicy::header(new CspNonce($nonce)));
});

it('shows the page in the dark theme and on a phone with the same assertions', function (callable $visit): void {
    $page = $visit();

    expect($page)->toBeInstanceOf(PendingAwaitablePage::class);
    assert($page instanceof PendingAwaitablePage);

    PanelPage::assertPage($page, notFoundTexts());
})->with([
    'the dark theme' => [fn (): PendingAwaitablePage => visit('/cms/no/such/page')->inDarkMode()],
    'a phone' => [fn (): PendingAwaitablePage => visit('/cms/no/such/page')->on()->mobile()->inLightMode()],
]);

it('reaches the link back to the panel\'s start with the keyboard and shows where the focus is', function (): void {
    $page = visit('/cms/no/such/page');

    PanelPage::assertPage($page, notFoundTexts());

    // The page's root element, which takes no focus, so the Tab starts from the document.
    $page->keys('app', 'Tab');

    expect($page->script('document.activeElement.textContent'))->toBe(PanelPage::text('panel.not_found.home'))
        ->and($page->script('document.activeElement.getAttribute("href")'))->toBe('/cms');

    expect($page->script('getComputedStyle(document.activeElement).boxShadow'))->not->toBe('none');
});

it('fails the shared assertions on a panel page whose script the policy blocks', function (): void {
    Route::middleware(SendContentSecurityPolicy::class)->get('/_probe/panel-policy', static fn (): string => '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        .'<title>Probe</title><link rel="icon" href="data:,"></head><body><main><h1>Probe</h1>'
        .'<script>document.body.dataset.ran = "yes"</script></main></body></html>');

    $page = visit('/_probe/panel-policy')->assertSee('Probe');

    expect($page->script('document.body.dataset.ran ?? "blocked"'))->toBe('blocked')
        ->and(fn () => PanelPage::assertNoPolicyViolations($page))->toThrow(ExpectationFailedException::class, 'script-src');
});

it('fails the shared assertions on a page with a WCAG 2.2 AA issue', function (): void {
    Route::get('/_probe/inaccessible', static fn (): string => '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        .'<title>Probe</title><link rel="icon" href="data:,"></head><body><main><h1>Probe</h1>'
        .'<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="40" height="40"></main></body></html>');

    $page = visit('/_probe/inaccessible')->assertSee('Probe');

    expect(fn () => PanelPage::assertWcag22AA($page))->toThrow(ExpectationFailedException::class, 'image-alt');
});

it('fails the shared assertions on a text that is not in the panel\'s catalogue', function (): void {
    expect(fn (): string => PanelPage::text('panel.no_such_text'))->toThrow(RuntimeException::class, 'has no text panel.no_such_text');
});
