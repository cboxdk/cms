<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Browser;

use Cbox\Cms\Tests\Support\Arch\Codebase;
use JsonException;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\On;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * The assertions every browser test of a panel page makes (GUARDRAILS 8 and 9):
 *
 * - the page shows each text it is asked for, read from the panel's English catalogue by its
 *   translation key (or the kit's, kitText()), so a test names what the page says the way the page
 *   does, and a text that is not in the catalogue fails the test instead of passing on a
 *   hard-coded string;
 * - nothing was written to the console and no script threw;
 * - axe finds nothing of any impact, with its default rules and, run again with only them, with
 *   every rule of WCAG 2.0, 2.1 and 2.2 at levels A and AA, some of which are off by default;
 * - the browser reported no violation of the page's Content-Security-Policy (GUARDRAILS 6), such
 *   as a script or style the policy blocked, which the console assertions do not see;
 * - the page does not scroll sideways at the width it is shown at: the document is no wider than
 *   the viewport, so nothing on it is cut off at the right edge of a phone (WCAG 2.2 AA, 1.4.10
 *   Reflow), which axe does not measure.
 */
final class PanelPage
{
    /** The panel's English catalogue, which the workbench's locale picks. */
    public const string CATALOGUE = 'js/panel/src/i18n/catalogues/en.json';

    /** The component kit's English catalogue, the kit's own few texts, such as a picker's buttons. */
    public const string KIT_CATALOGUE = 'js/ui-kit/src/i18n/catalogues/en.json';

    /** The English catalogue of the workbench's fixture addon, which its contributions read. */
    public const string ADDON_CATALOGUE = 'workbench/addons/fixtureaddon/resources/panel/lang/en.json';

    /** The axe tags of WCAG 2.2 at levels A and AA, with the criteria of 2.0 and 2.1 it keeps. */
    public const array WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

    /**
     * Every violation of the WCAG 2.2 AA rules on the page, one line each: the rule, its help and
     * the elements.
     */
    private const string WCAG_RUN = <<<'JS'
        async (tags) => (await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }))
            .violations
            .map((violation) => `${violation.id} (${violation.impact}): ${violation.help}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`)
        JS;

    /**
     * Every Content-Security-Policy violation the browser reported for the page so far, one line
     * each. Chromium keeps the reports of a page and hands them to a buffered ReportingObserver.
     */
    private const string CSP_REPORTS = <<<'JS'
        () => new Promise((resolve) => {
            const lines = [];
            const take = (reports) => reports.forEach((report) => lines.push(`${report.body.effectiveDirective} blocked ${report.body.blockedURL || 'an inline resource'} (${report.body.sourceFile || report.url}:${report.body.lineNumber || 0})`));
            const observer = new ReportingObserver((reports) => take(reports), { types: ['csp-violation'], buffered: true });
            observer.observe();
            setTimeout(() => { take(observer.takeRecords()); observer.disconnect(); resolve(lines); }, 100);
        })
        JS;

    /**
     * Whether the page scrolls sideways, and what sticks out past the viewport's right edge: the
     * document's width against the viewport's, then the first few elements whose right edge is
     * past it and that no scrolling ancestor clips, each as its tag, its classes and that edge, so
     * a failure names what makes the page wider than the screen. An element of a region that
     * scrolls on its own, such as a wide table in its scroller, is left out, unless it is
     * positioned against a containing block outside that region and so escapes its clip.
     */
    private const string SIDEWAYS_SCROLL = <<<'JS'
        () => {
            const root = document.documentElement;
            if (root.scrollWidth <= root.clientWidth) {
                return [];
            }
            const clipped = (element) => {
                const position = getComputedStyle(element).position;
                if (position === 'fixed') {
                    return false;
                }
                let seeking = position === 'absolute';
                for (let node = element.parentElement; node !== null && node !== document.documentElement; node = node.parentElement) {
                    const style = getComputedStyle(node);
                    if (seeking) {
                        if (style.position === 'static' && style.transform === 'none' && style.filter === 'none') {
                            continue;
                        }
                        seeking = false;
                    }
                    if (style.overflowX !== 'visible') {
                        return true;
                    }
                }
                return false;
            };
            const edge = (element) => Math.round(element.getBoundingClientRect().right);
            const wide = [...document.body.querySelectorAll('*')]
                .filter((element) => edge(element) > root.clientWidth && !clipped(element))
                .slice(0, 8)
                .map((element) => `${element.tagName}${(element.getAttribute('class') || '').split(/\s+/).filter((name) => name !== '').map((name) => '.' + name).join('')} right=${edge(element)}`);
            return [`the document is ${root.scrollWidth} wide in a viewport of ${root.clientWidth}`, ...wide];
        }
        JS;

    /**
     * Asserts that the page shows each text and makes every assertion above.
     *
     * @param  array<string, array<string, string|int>>|list<string>  $texts  translation keys, or keys with the parameters of their text
     */
    public static function assertPage(On|PendingAwaitablePage|AwaitableWebpage|Webpage $page, array $texts): void
    {
        foreach ($texts as $key => $parameters) {
            $page->assertSee(is_string($parameters) ? self::text($parameters) : self::text((string) $key, $parameters));
        }

        $page->assertNoConsoleLogs()
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues(3);

        self::assertWcag22AA($page);
        self::assertNoPolicyViolations($page);
        self::assertNoSidewaysScroll($page);
    }

    /**
     * Asserts that the page does not scroll sideways at the width it is shown at: the document is
     * no wider than the viewport, so nothing on the page is cut off at the right edge.
     */
    public static function assertNoSidewaysScroll(On|PendingAwaitablePage|AwaitableWebpage|Webpage $page): void
    {
        $overflow = $page->script(self::SIDEWAYS_SCROLL);

        Assert::assertSame([], $overflow, "The page scrolls sideways:\n".(is_array($overflow) ? implode("\n", array_map(strval(...), array_filter($overflow, is_string(...)))) : ''));
    }

    /**
     * Asserts that axe finds no violation of the WCAG 2.2 AA rules, of any impact.
     */
    public static function assertWcag22AA(On|PendingAwaitablePage|AwaitableWebpage|Webpage $page): void
    {
        $violations = $page->script('('.self::WCAG_RUN.')('.json_encode(self::WCAG_22_AA, JSON_THROW_ON_ERROR).')');

        Assert::assertSame([], $violations, "The page breaks WCAG 2.2 AA:\n".(is_array($violations) ? implode("\n", array_map(strval(...), array_filter($violations, is_string(...)))) : ''));
    }

    /**
     * Asserts that the browser reported no Content-Security-Policy violation for the page.
     */
    public static function assertNoPolicyViolations(On|PendingAwaitablePage|AwaitableWebpage|Webpage $page): void
    {
        $reports = $page->script(self::CSP_REPORTS);

        Assert::assertSame([], $reports, "The page broke its Content-Security-Policy:\n".(is_array($reports) ? implode("\n", array_map(strval(...), array_filter($reports, is_string(...)))) : ''));
    }

    /**
     * A text of the panel's English catalogue, with its parameters filled in as the panel's t()
     * fills them.
     *
     * @param  array<string, string|int>  $parameters
     *
     * @throws JsonException
     */
    public static function text(string $key, array $parameters = []): string
    {
        return self::textOf(self::CATALOGUE, $key, $parameters);
    }

    /**
     * A text of the component kit's English catalogue, with its parameters filled in.
     *
     * @param  array<string, string|int>  $parameters
     *
     * @throws JsonException
     */
    public static function kitText(string $key, array $parameters = []): string
    {
        return self::textOf(self::KIT_CATALOGUE, $key, $parameters);
    }

    /**
     * A text of the workbench fixture addon's English catalogue, with its parameters filled in as
     * the host's t() fills them for a contribution (section 2.6 of the panel extension
     * architecture), so an assertion reads what the panel shows and never a translation key.
     *
     * @param  array<string, string|int>  $parameters
     *
     * @throws JsonException
     */
    public static function addonText(string $key, array $parameters = []): string
    {
        return self::textOf(self::ADDON_CATALOGUE, $key, $parameters);
    }

    /**
     * @param  array<string, string|int>  $parameters
     *
     * @throws JsonException
     */
    private static function textOf(string $catalogue, string $key, array $parameters): string
    {
        $decoded = json_decode((string) file_get_contents(Codebase::root().'/'.$catalogue), true, 512, JSON_THROW_ON_ERROR);
        $text = is_array($decoded) ? ($decoded[$key] ?? null) : null;

        if (! is_string($text)) {
            throw new RuntimeException("The catalogue {$catalogue} has no text {$key}.");
        }

        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $match): string => array_key_exists($match[1], $parameters) ? (string) $parameters[$match[1]] : $match[0],
            $text,
        );
    }
}
