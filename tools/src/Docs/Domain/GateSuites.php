<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The Pest suites of gate 5 (GUARDRAILS 10) as phpunit.xml defines them. An example on a page runs
 * as a test only when one of them includes it.
 */
final readonly class GateSuites
{
    /**
     * @param  list<SuiteSelection>  $suites
     */
    public function __construct(public array $suites) {}

    public function includes(string $path): bool
    {
        return array_any($this->suites, static fn (SuiteSelection $suite): bool => $suite->includes($path));
    }

    public function names(): string
    {
        return implode(', ', array_map(static fn (SuiteSelection $suite): string => $suite->name, $this->suites));
    }
}
