<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Panel\Actions\RecordCspReports;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The panel's report route (GUARDRAILS 5, 6): a browser posts what the panel's policy blocked,
 * without a CSRF token, and the panel counts it on cms.panel.csp_violations by directive and by
 * the addon whose files were blocked or loading, never keeping anything else of the report.
 */
final class CspReportTest extends TestCase
{
    private ?FakeTelemetry $telemetry = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->telemetry = new FakeTelemetry;
        app()->instance(Telemetry::class, $this->telemetry);
    }

    #[Test]
    public function it_counts_the_reports_of_the_reporting_api_by_directive_and_addon_without_a_csrf_token(): void
    {
        $reports = [
            ['type' => 'csp-violation', 'url' => 'http://localhost/cms', 'body' => ['effectiveDirective' => 'script-src-elem', 'blockedURL' => 'https://cdn.example.test/x.js', 'sourceFile' => 'http://localhost/cms/addons/tally/'.str_repeat('a', 64).'/assets/addon-1a2b3c.js']],
            ['type' => 'csp-violation', 'url' => 'http://localhost/cms', 'body' => ['effectiveDirective' => 'script-src-elem', 'blockedURL' => 'http://localhost/cms/addons/tally/'.str_repeat('a', 64).'/assets/other.js']],
            ['type' => 'csp-violation', 'url' => 'http://localhost/cms', 'body' => ['effectiveDirective' => 'connect-src', 'blockedURL' => 'https://api.example.test/']],
            ['type' => 'deprecation', 'url' => 'http://localhost/cms', 'body' => ['id' => 'x']],
        ];

        $this->call('POST', '/cms/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode($reports, JSON_THROW_ON_ERROR))
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');

        self::assertSame([
            ['connect-src', RecordCspReports::NO_ADDON, 1],
            ['script-src-elem', 'tally', 2],
        ], $this->counts());
    }

    #[Test]
    public function it_counts_a_report_uri_report_the_directive_without_its_sources_and_keeps_no_text_of_it(): void
    {
        $report = ['csp-report' => ['document-uri' => 'http://localhost/cms', 'violated-directive' => "style-src 'self' 'nonce-x'", 'effective-directive' => 'style-src-attr', 'blocked-uri' => 'inline', 'script-sample' => 'color: red']];

        $this->call('POST', '/cms/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode($report, JSON_THROW_ON_ERROR))
            ->assertNoContent();

        self::assertSame([['style-src-attr', RecordCspReports::NO_ADDON, 1]], $this->counts());

        foreach ($this->telemetry()->counters() as $counter) {
            foreach ($counter->attributes->attributes as $attribute) {
                self::assertStringNotContainsString('red', (string) $attribute->value);
                self::assertStringNotContainsString('nonce', (string) $attribute->value);
            }
        }
    }

    #[Test]
    #[DataProvider('bodiesThatAreNoReport')]
    public function it_answers_204_and_counts_nothing_for_a_body_that_is_no_report(string $body): void
    {
        $this->call('POST', '/cms/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertNoContent();

        self::assertSame([], $this->telemetry()->counters());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatAreNoReport(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'an object without a report' => ['{"a":1}'];
        yield 'a report without a directive' => ['[{"type":"csp-violation","body":{"blockedURL":"x"}}]'];
        yield 'a directive that is no directive' => ['[{"type":"csp-violation","body":{"effectiveDirective":"<script>"}}]'];
    }

    private function telemetry(): FakeTelemetry
    {
        return $this->telemetry ?? self::fail('The test has no telemetry.');
    }

    /**
     * The counters recorded, each as [directive, addon, count].
     *
     * @return list<array{string, string, int}>
     */
    private function counts(): array
    {
        return array_values(array_map(static fn (CounterRecord $counter): array => [
            (string) $counter->attributes->get(RecordCspReports::DIRECTIVE_ATTRIBUTE),
            (string) $counter->attributes->get(RecordCspReports::ADDON_ATTRIBUTE),
            $counter->increment,
        ], array_filter($this->telemetry()->counters(), static fn (CounterRecord $counter): bool => $counter->name->value === RecordCspReports::COUNTER)));
    }
}
