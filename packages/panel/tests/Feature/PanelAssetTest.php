<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Panel\Boundary\PanelAssetResponse;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The files of the panel's build below /cms/build (PRD 13.4: the panel module serves them and
 * needs no copy in the application's public directory). Only the files the manifest names are
 * served, with their content type, cached for a year and never sniffed; any other path is 404.
 */
final class PanelAssetTest extends TestCase
{
    private ?FixtureBuild $fixture = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = FixtureBuild::write();
        $this->fixture->bind($this->app ?? app());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->fixture?->remove();
        $this->fixture = null;

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function files(): array
    {
        return [
            'the entry script' => ['assets/app-1a2b3c.js', 'text/javascript; charset=utf-8', FixtureBuild::SCRIPT],
            'a lazily loaded chunk' => ['assets/Lazy-9f8e7d.js', 'text/javascript; charset=utf-8', FixtureBuild::SCRIPT],
            'a stylesheet' => ['assets/app-7a8b9c.css', 'text/css; charset=utf-8', FixtureBuild::STYLE],
            'a font' => ['assets/font-0a1b2c.woff2', 'font/woff2', 'bytes'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strangers(): array
    {
        return [
            'a file the manifest does not name' => ['/cms/build/assets/other.js'],
            'the manifest itself' => ['/cms/build/.vite/manifest.json'],
            'a parent segment' => ['/cms/build/assets/../../../composer.json'],
            'an encoded parent segment' => ['/cms/build/assets/%2e%2e/%2e%2e/composer.json'],
        ];
    }

    #[Test]
    #[DataProvider('files')]
    public function it_serves_a_file_of_the_build_with_its_content_type_cached_for_good_and_not_sniffed(string $file, string $type, string $body): void
    {
        $response = $this->get('/cms/build/'.$file);

        $response->assertOk()
            ->assertHeader('Content-Type', $type)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing(ContentSecurityPolicy::HEADER);

        self::assertSame($body, $response->getContent());
        self::assertSame('31536000', $response->headers->getCacheControlDirective('max-age'));
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    #[Test]
    #[DataProvider('strangers')]
    public function it_answers_404_for_any_path_that_is_not_a_file_of_the_build(string $path): void
    {
        $response = $this->get($path);

        $response->assertNotFound();
        self::assertStringNotContainsString(FixtureBuild::SCRIPT, (string) $response->getContent());
    }

    #[Test]
    public function it_knows_the_content_type_of_every_kind_of_file_vite_writes_and_sends_anything_else_as_bytes(): void
    {
        foreach (['js', 'css', 'svg', 'png', 'woff2', 'woff', 'map'] as $extension) {
            self::assertArrayHasKey($extension, PanelAssetResponse::CONTENT_TYPES);
        }

        self::assertSame('application/octet-stream', PanelAssetResponse::BYTES);
    }
}
