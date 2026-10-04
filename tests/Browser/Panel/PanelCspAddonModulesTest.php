<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use ArrayObject;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\PagePolicy;
use Cbox\Cms\Tests\Support\Browser\PanelModules;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Closure;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;

/*
 * Addon modules under the panel's Content-Security-Policy (PRD 13.4, GUARDRAILS 6, D2, D6): a panel
 * page of the real build loads a test addon's module lazily with import(), through the page's one
 * import map with the addon's scope and the integrity of every module, renders React Aria
 * Components with the panel's React, and the browser reports no violation of the policy, which has
 * no 'unsafe-' source. A module whose bytes are not those the map names does not run.
 *
 * The last test is the evidence for D6: what the policy lets trusted code's import() of a module
 * from another origin do. Under a nonce with 'strict-dynamic', the panel's policy before this
 * decision, a nonced script's import() reaches any origin, because the browser hands the
 * importing script's nonce on to what it imports; Trusted Types do not cover import(), and
 * dropping 'strict-dynamic' for 'self' does not help while the nonce stays. Only a script-src
 * without a nonce, 'self' with the hashes of the page's inline scripts, the import map among them,
 * keeps import() on the panel's origin, and the panel page and the addon still run under it: that
 * is the panel's policy now (ContentSecurityPolicy).
 *
 * The tests are in the group browser-matrix, which gate 8 also runs in Firefox and WebKit.
 */

beforeEach(function (): void {
    PanelModules::serve();
});

/**
 * Records the policy the test application sends for each path.
 *
 * @return ArrayObject<string, string>
 */
function addonPagePolicies(): ArrayObject
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
 * The panel's policy for the page with its script-src replaced by the given sources.
 */
function withScriptSources(string $html, string $nonce, string $sources): string
{
    return (string) preg_replace('/script-src [^;]+/', "script-src {$sources}", PanelProbe::policyOf($html, $nonce, ''));
}

it('loads an addon\'s module lazily through the import map under the panel\'s policy, with no violation and no unsafe source', function (): void {
    app()->instance(ImportMap::class, PanelModules::importMap());
    $policies = addonPagePolicies();
    PanelProbe::onPage('/cms/no/such/page');

    $page = visit('/cms/no/such/page');
    $result = PanelProbe::result($page);
    $policy = (string) ($policies['/cms/no/such/page'] ?? '');

    $importMap = $page->script('document.querySelector(\'script[type="importmap"]\').textContent');

    expect($policy)->not->toContain("'unsafe-")
        ->and($policy)->not->toContain('strict-dynamic')
        ->and($policy)->not->toContain("script-src 'nonce-")
        ->and($policy)->toMatch("~^default-src 'self'; script-src 'self'(?: 'sha256-[A-Za-z0-9+/]{43}=')+; style-src 'self' 'nonce-[A-Za-z0-9+/]{22}=='; ~")
        ->and($policy)->toContain("'sha256-".PagePolicy::hashOf(is_string($importMap) ? $importMap : '')."'")
        ->and($policy)->toContain('report-to '.ContentSecurityPolicy::REPORT_GROUP)
        ->and($result['probe']['addon'])->toBe('rendered');

    // The map the page carries is the one the test composed, with the addon's scope and integrity.
    $map = $page->script('JSON.parse(document.querySelector(\'script[type="importmap"]\').textContent)');

    $scopes = is_array($map) && is_array($map['scopes'] ?? null) ? $map['scopes'] : [];
    $integrity = is_array($map) && is_array($map['integrity'] ?? null) ? $map['integrity'] : [];

    expect($map)->toBeArray()
        ->and(array_keys($scopes))->toBe([PanelModules::PREFIX])
        ->and($integrity[PanelModules::PREFIX.'counter.js'] ?? null)->toBe(PanelModules::integrity('addons/acme/counter.js'))
        ->and($page->script('document.querySelectorAll(\'script[type="importmap"]\').length'))->toBe(1);

    $page->assertSee(PanelPage::text('panel.not_found.title'))
        ->assertSeeIn('#probe-counter', 'count 0')
        ->assertNoJavaScriptErrors();
    PanelProbe::assertQuiet($result['log']);
})->group('browser-matrix');

it('does not run an addon\'s module whose bytes are not those the import map\'s integrity names', function (): void {
    app()->instance(ImportMap::class, PanelModules::importMap(['counter.js' => 'sha384-'.base64_encode(hash('sha384', 'other bytes', true))]));
    PanelProbe::onPage('/cms/no/such/page');

    $page = visit('/cms/no/such/page');
    $result = PanelProbe::result($page);

    expect($result['probe']['addon'])->toStartWith('failed TypeError')
        ->and($page->script('document.getElementById("probe-counter")'))->toBeNull();

    // The panel's own modules, each with its integrity in the same map, ran.
    $page->assertSee(PanelPage::text('panel.not_found.title'));
    expect($result['log']['violations'])->toBe([]);
})->group('browser-matrix');

it('renders React Aria Components with the panel\'s React under its policy, in Danish, with the keyboard', function (): void {
    app()->instance(ImportMap::class, PanelModules::importMap());
    PanelProbe::onPage('/cms/no/such/page', ['kit' => true]);

    $page = visit('/cms/no/such/page');
    $result = PanelProbe::result($page);

    expect($result['probe']['kit'])->toBe('mounted');

    // A dialog in a modal: it opens, takes the focus, closes with Escape and gives the focus back.
    $page->click('#kit-open')
        ->assertVisible('[role="dialog"]');
    expect(PanelProbe::eventually($page, 'document.getElementById("kit-dialog")?.contains(document.activeElement)'))->toBeTrue();
    $page->keys('[role="dialog"]', 'Escape')
        ->assertMissing('[role="dialog"]');
    expect(PanelProbe::eventually($page, 'document.activeElement.id === "kit-open" && document.activeElement.id'))->toBe('kit-open');

    // A combo box filters its options in a popover that React Aria positions through the CSSOM.
    $page->click('#kit-combo-input')
        ->typeSlowly('#kit-combo-input', 'Ti')
        ->assertVisible('#kit-combo-list');
    expect(PanelProbe::eventually($page, '[...document.querySelectorAll("#kit-combo-list [role=option]")].map((option) => option.textContent).join() === "Tirsdag" && ["Tirsdag"]'))->toBe(['Tirsdag'])
        ->and($page->script('document.querySelector("[data-trigger=ComboBox]").style.position'))->toBe('absolute');

    // A date field in Danish order: day, month, year.
    expect($page->script('[...document.querySelectorAll("#kit-date-group [data-type]")].map((segment) => segment.dataset.type).filter((type) => type !== "literal")'))
        ->toBe(['day', 'month', 'year']);

    $page->assertNoJavaScriptErrors();
    PanelProbe::assertQuiet(PanelProbe::result($page)['log']);
})->group('browser-matrix');

it('shows which policy keeps a trusted module\'s import() on the panel\'s origin (D6)', function (?Closure $policy, bool $blocked, bool $trustedTypes): void {
    /** @var (Closure(string, string): string)|null $policy */
    app()->instance(ImportMap::class, PanelModules::importMap());
    PanelProbe::onPage('/cms/no/such/page', ['crossOrigin' => 'cross-origin.js'], $policy);

    $page = visit('/cms/no/such/page');
    $result = PanelProbe::result($page);
    $probe = $result['probe'];
    $log = $result['log'];
    $crossOrigin = $page->script('window.cmsCrossOriginRan === true');

    // Under every policy the panel page and the addon run.
    $page->assertSee(PanelPage::text('panel.not_found.title'));
    expect($probe['addon'])->toBe('rendered');

    if ($trustedTypes) {
        // Inertia's progress bar writes its template with innerHTML when the panel starts, which
        // Trusted Types refuse: the policy breaks the panel's own script, and it does not stop
        // the import() from another origin either.
        expect($log['errors'])->not->toBe([])
            ->and(array_filter($log['violations'], static fn (string $violation): bool => ! str_starts_with($violation, 'require-trusted-types-for')))->toBe([]);
    } else {
        expect($log['errors'])->toBe([]);
    }

    if ($blocked) {
        expect($probe['crossOrigin'])->toStartWith('blocked TypeError')
            ->and($crossOrigin)->toBeFalse()
            ->and($log['violations'])->toHaveCount(1)
            ->and($log['violations'][0] ?? '')->toStartWith('script-src')
            ->and($log['violations'][0] ?? '')->toContain('/_probe/panel-modules/cross-origin.js');
    } else {
        expect($probe['crossOrigin'])->toBe('ran')
            ->and($crossOrigin)->toBeTrue();
    }

    if (! $blocked && ! $trustedTypes) {
        expect($log['violations'])->toBe([]);
    }
})->with([
    'the panel\'s policy: \'self\' and the hashes of the inline scripts, without a nonce' => [null, true, false],
    'the nonce and \'strict-dynamic\', the policy before D6' => [fn (string $html, string $nonce): string => withScriptSources($html, $nonce, "'nonce-{$nonce}' 'strict-dynamic'"), false, false],
    'with Trusted Types required for scripts' => [fn (string $html, string $nonce): string => withScriptSources($html, $nonce, "'nonce-{$nonce}' 'strict-dynamic'")."; require-trusted-types-for 'script'; trusted-types cms", false, true],
    'the nonce and \'self\', without \'strict-dynamic\'' => [fn (string $html, string $nonce): string => withScriptSources($html, $nonce, "'nonce-{$nonce}' 'self'"), false, false],
])->group('browser-matrix');
