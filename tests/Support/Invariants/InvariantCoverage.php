<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Invariants;

/**
 * One invariant of PRD 6.5, by its number, with the tests that try to break it.
 */
final readonly class InvariantCoverage
{
    /**
     * @param  list<TestReference>  $tests
     */
    public function __construct(
        public int $invariant,
        public string $rule,
        public array $tests,
    ) {}

    /**
     * The issuers of an envelope no covering test goes through, in the order of IssuingSurface.
     *
     * @return list<Issuer>
     */
    public function envelopeIssuersMissing(): array
    {
        $covered = [];

        foreach ($this->tests as $test) {
            foreach ($test->issuers as $issuer) {
                $covered[$issuer->value] = true;
            }
        }

        return array_values(array_filter(Issuer::envelopeIssuers(), static fn (Issuer $issuer): bool => ! isset($covered[$issuer->value])));
    }
}
