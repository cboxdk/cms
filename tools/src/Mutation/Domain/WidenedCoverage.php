<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * The line coverage and the test ids after TestFilterWidth::widen(), and the files it widened.
 */
final readonly class WidenedCoverage
{
    /**
     * @param  array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>>  $lineCoverage  file, line, test index and hits
     * @param  array<int<0, max>, non-empty-string>  $testIds  test index and test id
     * @param  list<non-empty-string>  $widenedFiles  the files whose lines now name test classes
     */
    public function __construct(
        public array $lineCoverage,
        public array $testIds,
        public array $widenedFiles,
    ) {}
}
