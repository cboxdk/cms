<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\CspViolation;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

/**
 * Reads the violations a browser reports to the panel's report route (GUARDRAILS 6): the
 * Reporting API's list of reports of type csp-violation, sent for report-to, or the one report
 * under `csp-report`, sent for report-uri. Of each report only two things are kept: the effective
 * directive, and the addon whose files the blocked resource or the loading script belongs to,
 * read from the address below the panel's addon asset route, or null. A report that is not of
 * either form, or has no directive, counts for nothing, and at most MAX_REPORTS of a request do.
 */
#[Internal]
final readonly class CspReportInput
{
    /** How many reports of one request are counted. */
    public const int MAX_REPORTS = 50;

    /** The media type of the Reporting API's reports and of a report-uri report. */
    public const array CONTENT_TYPES = ['application/reports+json', 'application/csp-report', 'application/json'];

    public function __construct(private UrlGenerator $urls) {}

    /**
     * @return list<CspViolation>
     */
    public function violations(Request $request): array
    {
        try {
            $body = json_decode((string) $request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $reports = [];

        if (is_array($body) && array_is_list($body)) {
            foreach ($body as $report) {
                if (is_array($report) && ($report['type'] ?? null) === 'csp-violation' && is_array($report['body'] ?? null)) {
                    $reports[] = $report['body'];
                }
            }
        } elseif (is_array($body) && is_array($body['csp-report'] ?? null)) {
            $reports[] = $body['csp-report'];
        }

        $violations = [];
        $addons = $this->addonsPrefix();

        foreach (array_slice($reports, 0, self::MAX_REPORTS) as $report) {
            $directive = $this->text($report, ['effectiveDirective', 'effective-directive', 'violatedDirective', 'violated-directive']);

            if ($directive === null) {
                continue;
            }

            try {
                $violations[] = new CspViolation(strtolower(strtok($directive, ' ') ?: $directive), $this->addonOf($addons, [
                    $this->text($report, ['blockedURL', 'blocked-uri']),
                    $this->text($report, ['sourceFile', 'source-file']),
                ]));
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $violations;
    }

    /**
     * The path every addon's files lie below, `<prefix>/addons/`.
     */
    private function addonsPrefix(): string
    {
        $url = $this->urls->route(PanelRoute::AddonAsset->value, ['addon' => 'a', 'hash' => str_repeat('0', 64), 'path' => 'f'], false);

        return dirname($url, 3).'/';
    }

    /**
     * @param  array<mixed>  $report
     * @param  list<string>  $keys
     */
    private function text(array $report, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $report[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The addon whose files one of the addresses lies below, or null.
     *
     * @param  list<string|null>  $urls
     */
    private function addonOf(string $prefix, array $urls): ?AddonNamespace
    {
        foreach ($urls as $url) {
            $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

            if (! is_string($path) || ! str_starts_with($path, $prefix)) {
                continue;
            }

            $namespace = strtok(substr($path, strlen($prefix)), '/');

            try {
                return $namespace === false ? null : new AddonNamespace($namespace);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }
}
