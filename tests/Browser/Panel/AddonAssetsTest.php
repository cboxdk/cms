<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Boundary\AddonAssetResponse;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Tests\Addons\AddonBundleWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * An addon's panel bundle in the browser (PRD 13.4): a page behind the login writes the bundle
 * into its import map, so the host's import of cms-addons/<namespace> loads the addon's entry
 * through the panel's addon asset route and the panel's shared SDK, under the page's
 * Content-Security-Policy with no violation; a file whose bytes changed after cms:build is
 * refused by the server with panel_asset_hash_mismatch, and the browser, whose import map names
 * the compiled integrity, does not run it either.
 */

const ADDON_EMAIL = 'mette.holm@example.com';

const ADDON_PASSWORD = 'correct horse battery staple';

beforeEach(function (): void {
    app(Repository::class)->set('session.driver', 'redis');
    app(Repository::class)->set('session.connection', 'default');

    $clock = new FakeClock;
    $actor = new PostgresIdentitySeeder(app(DatabaseManager::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff, ActorState::Active);
    app(LocalCredentialStore::class)->bind($actor->id, new LoginIdentifier(ADDON_EMAIL), app(PasswordHasher::class)->hash(new Password(ADDON_PASSWORD)));
});

/**
 * Writes the bundle, binds it and runs the test with it, then removes it.
 *
 * @param  callable(AddonBundleWorld): void  $test
 */
function withAddonBundle(callable $test): void
{
    $bundle = AddonBundleWorld::write();
    $bundle->bind(app());

    try {
        $test($bundle);
    } finally {
        $bundle->remove();
    }
}

/**
 * Signs in and lands on the start page.
 */
function signedInHome(): PendingAwaitablePage
{
    $page = visit('/cms/login');

    $page->type('email', ADDON_EMAIL)
        ->type('password', ADDON_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/cms');

    return $page;
}

it('loads the addon\'s entry through the import map on a page behind the login, and refuses a file whose bytes changed after cms:build', function (): void {
    withAddonBundle(static function (AddonBundleWorld $bundle): void {
        $entry = $bundle->url(AddonBundleWorld::ENTRY);
        $page = signedInHome();

        PanelPage::assertPage($page, ['panel.home.body', 'panel.home.sign_out']);

        $map = $page->script('JSON.parse(document.querySelector(\'script[type="importmap"]\').textContent)');
        $imports = is_array($map) && is_array($map['imports'] ?? null) ? $map['imports'] : [];
        $scopes = is_array($map) && is_array($map['scopes'] ?? null) ? $map['scopes'] : [];
        $integrity = is_array($map) && is_array($map['integrity'] ?? null) ? $map['integrity'] : [];

        expect($imports[ImportMap::ADDON_SPECIFIER.AddonBundleWorld::NAMESPACE] ?? null)->toBe($entry)
            ->and(array_keys($scopes))->toBe([$bundle->prefix()])
            ->and($integrity[$entry] ?? null)->toBe(AddonBundleWorld::integrity(AddonBundleWorld::ENTRY_SOURCE))
            ->and($page->script('[...document.querySelectorAll(\'link[rel="stylesheet"]\')].map((link) => link.getAttribute("href"))'))->toContain($bundle->url(AddonBundleWorld::STYLE));

        // The host's import of the addon, through the map: the entry runs on the panel's shared SDK.
        $loaded = $page->script('() => import("'.ImportMap::ADDON_SPECIFIER.AddonBundleWorld::NAMESPACE.'").then((module) => ({ loaded: module.loaded, ids: module.default.ids, sdk: module.default.sdk }), (error) => ({ failed: String(error) }))');
        $loaded = is_array($loaded) ? $loaded : [];

        expect($loaded['loaded'] ?? null)->toBe('tally')
            ->and($loaded['ids'] ?? null)->toBe([])
            ->and($loaded['sdk'] ?? null)->toBeArray();

        $served = $page->script('() => fetch("'.$entry.'", { cache: "no-store" }).then((response) => ({ status: response.status, type: response.headers.get("content-type") }))');

        expect($served)->toBe(['status' => 200, 'type' => 'text/javascript; charset=utf-8']);
        PanelPage::assertNoPolicyViolations($page);

        // The file changes after cms:build: the server refuses it with the catalog's problem details.
        $bundle->tamper(AddonBundleWorld::ENTRY);

        $refused = $page->script('() => fetch("'.$entry.'", { cache: "no-store" }).then(async (response) => ({ status: response.status, type: response.headers.get("content-type"), body: await response.json() }))');
        $refused = is_array($refused) ? $refused : [];
        $body = is_array($refused['body'] ?? null) ? $refused['body'] : [];

        expect($refused['status'] ?? null)->toBe(500)
            ->and($refused['type'] ?? null)->toBe('application/problem+json')
            ->and($body['code'] ?? null)->toBe(AddonAssetResponse::CODE);

        // A page cannot run the changed file: the server does not send it, past the browser's cache.
        $failed = $page->script('() => import("'.$entry.'?after=change").then(() => "ran", (error) => "failed " + error.name)');

        expect($failed)->toStartWith('failed');
    });
});

it('writes no addon into the login page', function (): void {
    withAddonBundle(static function (): void {
        $page = visit('/cms/login');
        $map = $page->script('JSON.parse(document.querySelector(\'script[type="importmap"]\').textContent)');
        $imports = is_array($map) && is_array($map['imports'] ?? null) ? $map['imports'] : [];

        expect(array_keys($imports))->not->toContain(ImportMap::ADDON_SPECIFIER.AddonBundleWorld::NAMESPACE)
            ->and(is_array($map) ? $map['scopes'] ?? null : null)->toBe([])
            ->and($page->script('document.documentElement.outerHTML.includes("/cms/addons/")'))->toBeFalse();
        PanelPage::assertNoPolicyViolations($page);
    });
});
