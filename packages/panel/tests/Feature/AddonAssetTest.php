<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\AddonAssetResponse;
use Cbox\Cms\Panel\Boundary\PanelAssetResponse;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Tests\Addons\AddonBundleWorld;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * The files of an addon's panel bundle over HTTP (PRD 13.4), in the workbench, which mounts the
 * panel at /cms: a file the compiled registry lists is served below /cms/addons/<ns>/<hash>/,
 * immutable and nosniff, after its bytes are checked against the SHA-384 cms:build compiled; a
 * file whose bytes changed since is refused with panel_asset_hash_mismatch; every other address
 * is 404. A page behind the login writes the bundle into its import map, with the addon's entry,
 * scope and integrity, and links its stylesheets; a credential page and the page for an address
 * the panel does not have write none of it.
 */
final class AddonAssetTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'mette.holm@example.com';

    private ?AddonBundleWorld $bundle = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPanelLogins()->person(self::EMAIL);
        $this->bundle = AddonBundleWorld::write();
        $this->bundle->bind($this->app ?? app());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->bundle?->remove();
        $this->bundle = null;
        $this->tearDownPanelLogins();

        parent::tearDown();
    }

    #[Test]
    public function it_serves_a_file_the_registry_lists_by_the_bundles_hash_immutable_and_nosniff(): void
    {
        $this->get($this->bundle()->url(AddonBundleWorld::ENTRY))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeaderMissing(ContentSecurityPolicy::HEADER)
            ->assertContent(AddonBundleWorld::ENTRY_SOURCE);

        $this->get($this->bundle()->url(AddonBundleWorld::STYLE))
            ->assertOk()
            ->assertHeader('Content-Type', PanelAssetResponse::CONTENT_TYPES['css'])
            ->assertContent(AddonBundleWorld::STYLE_SOURCE);
    }

    #[Test]
    public function it_answers_404_for_every_address_that_is_not_a_listed_file_of_the_bundle_at_its_hash(): void
    {
        $hash = $this->bundle()->hash();

        foreach ([
            '/cms/addons/other/'.$hash.'/'.AddonBundleWorld::ENTRY,
            '/cms/addons/tally/'.strrev($hash).'/'.AddonBundleWorld::ENTRY,
            '/cms/addons/tally/'.$hash.'/assets/other.js',
            '/cms/addons/tally/'.$hash.'/panel-manifest.json',
        ] as $url) {
            $this->get($url)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing(ContentSecurityPolicy::HEADER);
        }

        // An address that is not of the route's form reaches the panel's page for it instead.
        foreach (['/cms/addons/tally/'.$hash.'/../'.AddonBundleWorld::ENTRY, '/cms/addons/Tally/'.$hash.'/'.AddonBundleWorld::ENTRY] as $url) {
            $this->get($url)->assertNotFound()->assertHeader(ContentSecurityPolicy::HEADER);
        }
    }

    #[Test]
    public function it_refuses_a_file_whose_bytes_changed_after_cms_build_with_panel_asset_hash_mismatch(): void
    {
        $this->get($this->bundle()->url(AddonBundleWorld::ENTRY))->assertOk();
        $this->bundle()->tamper(AddonBundleWorld::ENTRY);

        $response = $this->get($this->bundle()->url(AddonBundleWorld::ENTRY));

        $response->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', AddonAssetResponse::CODE)
            ->assertJsonPath('status', 500);

        $detail = $response->json('detail');
        self::assertIsString($detail);
        self::assertStringContainsString('its bytes changed', $detail);
        self::assertStringNotContainsString('changed after cms:build */', (string) $response->getContent());

        unlink($this->bundle()->directory.'/'.AddonBundleWorld::ENTRY);

        $this->get($this->bundle()->url(AddonBundleWorld::ENTRY))->assertStatus(500)->assertJsonPath('code', AddonAssetResponse::CODE);
    }

    #[Test]
    public function it_writes_the_bundle_into_the_import_map_of_a_page_behind_the_login_and_links_its_stylesheet(): void
    {
        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD)) ?? self::fail('The login set no session cookie.');
        $this->withUnencryptedCookie($this->cookieName(), (string) $cookie->getValue());

        $html = (string) $this->get('/cms')->assertOk()->getContent();
        $map = $this->importMapOf($html);
        $entry = $this->bundle()->url(AddonBundleWorld::ENTRY);

        self::assertSame($entry, $map['imports'][ImportMap::ADDON_SPECIFIER.'tally'] ?? null);
        self::assertSame(['@cboxdk/cms-ui-kit', '@inertiajs/core', '@inertiajs/react', 'react-aria-components'], array_keys($map['scopes'][$this->bundle()->prefix()] ?? []));
        self::assertSame(AddonBundleWorld::integrity(AddonBundleWorld::ENTRY_SOURCE), $map['integrity'][$entry] ?? null);
        self::assertArrayNotHasKey($this->bundle()->url(AddonBundleWorld::STYLE), $map['integrity']);
        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="'.preg_quote($this->bundle()->url(AddonBundleWorld::STYLE), '~').'" nonce="[^"]+">~', $html);
    }

    #[Test]
    public function it_writes_no_addon_into_a_credential_page_or_the_page_for_an_address_the_panel_does_not_have(): void
    {
        foreach (['/cms/login', '/cms/forgot-password', '/cms/no/such/page'] as $path) {
            $html = (string) $this->get($path)->getContent();
            $map = $this->importMapOf($html);

            self::assertArrayNotHasKey(ImportMap::ADDON_SPECIFIER.'tally', $map['imports'], $path);
            self::assertSame([], $map['scopes'], $path);
            self::assertStringNotContainsString('/cms/addons/', $html, $path);
        }
    }

    private function bundle(): AddonBundleWorld
    {
        return $this->bundle ?? self::fail('The test has no bundle.');
    }

    /**
     * @return array{imports: array<string, string>, scopes: array<string, array<string, string>>, integrity: array<string, string>}
     */
    private function importMapOf(string $html): array
    {
        self::assertSame(1, preg_match('~<script type="importmap" nonce="[^"]+">(.*?)</script>~s', $html, $match));
        $map = json_decode($match[1] ?? '', true, 8, JSON_THROW_ON_ERROR);

        self::assertIsArray($map);

        /** @var array{imports: array<string, string>, scopes: array<string, array<string, string>>, integrity: array<string, string>} $map */
        return $map;
    }
}
