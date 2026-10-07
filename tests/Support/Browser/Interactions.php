<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Browser;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PHPUnit\Framework\Assert;

/**
 * The Interaction to Next Paint of a panel page in a browser test (section 7 of the panel
 * extension architecture): read with the Event Timing API. A test calls observe() on a document
 * before it interacts, which installs a PerformanceObserver that records every interaction of 16
 * ms or more, the least the API reports, from the input to the next paint, with the keyboard and
 * the mouse alike; of() then reads them, and assertUnder() fails on one at or over the budget,
 * naming each interaction's event, target and duration. An interaction the API did not report
 * took less than 16 ms. INP is the worst interaction of a page visit, below the 98th percentile for
 * a visit with many; a test's visit has few, so its INP is the longest of them. A full page load,
 * such as the login, starts a new document, so a test observes again after it.
 */
final readonly class Interactions
{
    /** The budget of GUARDRAILS 10 and the panel extension architecture: under 200 ms. */
    public const int BUDGET_MILLISECONDS = 200;

    /** Installs the observer on the document, once. */
    private const string OBSERVE = <<<'JS'
        (() => {
            if (window.cmsInteractions !== undefined) {
                return 'observing';
            }
            window.cmsInteractions = [];
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (entry.interactionId > 0) {
                        window.cmsInteractions.push({
                            event: entry.name,
                            target: entry.target ? `${entry.target.tagName.toLowerCase()}${entry.target.id ? '#' + entry.target.id : ''}` : '',
                            duration: entry.duration,
                            interaction: entry.interactionId,
                        });
                    }
                }
            }).observe({ type: 'event', durationThreshold: 16 });
            return 'observing';
        })()
        JS;

    /** The interactions recorded so far, after the pending ones were delivered, the longest first. */
    private const string INTERACTIONS = <<<'JS'
        () => new Promise((resolve) => {
            setTimeout(() => {
                resolve([...(window.cmsInteractions ?? [])].sort((a, b) => b.duration - a.duration));
            }, 250);
        })
        JS;

    /**
     * Starts observing the document's interactions; call it before the interactions to measure.
     */
    public static function observe(PendingAwaitablePage|AwaitableWebpage|Webpage $page): void
    {
        Assert::assertSame('observing', $page->script(self::OBSERVE), 'The Event Timing observer could not be installed.');
    }

    /**
     * The interactions of the document since observe(), the longest first.
     *
     * @return list<array{event: string, target: string, duration: float, interaction: int}>
     */
    public static function of(PendingAwaitablePage|AwaitableWebpage|Webpage $page): array
    {
        $entries = $page->script(self::INTERACTIONS);
        $interactions = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $interactions[] = [
                'event' => is_string($entry['event'] ?? null) ? $entry['event'] : '',
                'target' => is_string($entry['target'] ?? null) ? $entry['target'] : '',
                'duration' => is_numeric($entry['duration'] ?? null) ? (float) $entry['duration'] : 0.0,
                'interaction' => is_int($entry['interaction'] ?? null) ? $entry['interaction'] : 0,
            ];
        }

        return $interactions;
    }

    /**
     * Asserts that the longest interaction of the document since observe() took less than the
     * budget; a document whose interactions the API did not report had none of 16 ms or more.
     */
    public static function assertUnder(PendingAwaitablePage|AwaitableWebpage|Webpage $page, int $budget = self::BUDGET_MILLISECONDS): void
    {
        $interactions = self::of($page);
        $lines = array_map(static fn (array $interaction): string => sprintf('%s on %s: %.1f ms (interaction %d)', $interaction['event'], $interaction['target'], $interaction['duration'], $interaction['interaction']), $interactions);
        $longest = $interactions[0]['duration'] ?? 0.0;

        Assert::assertLessThan($budget, $longest, sprintf("The Interaction to Next Paint is %.1f ms, not under %d ms:\n%s", $longest, $budget, implode("\n", $lines)));
    }
}
