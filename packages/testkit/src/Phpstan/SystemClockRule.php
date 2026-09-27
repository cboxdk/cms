<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * GUARDRAILS 2.3: the clock is a contract, so time is deterministic in tests. Only a class that
 * implements Cbox\Cms\Contracts\Clock reads the system's wall clock; every other class asks the
 * Clock it is given. ClockReads lists what counts as reading it, from time() and
 * new DateTimeImmutable() to Carbon::now(). hrtime() is not reported: it measures a duration.
 *
 * Test code is not checked. The errors are non-ignorable, in every layer.
 *
 * @implements Rule<Node>
 */
#[Internal]
final readonly class SystemClockRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.systemClock';

    private ClockReads $reads;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reads = new ClockReads($reflectionProvider);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $read = $this->reads->in($node, $scope);

        if ($read === null || LayerScope::isTestCode($scope->getNamespace() ?? '') || $scope->getClassReflection()?->implementsInterface(Clock::class) === true) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Reads the system clock through %s. Ask the Clock contract for the time: only a Clock implementation reads the system clock, so tests control time (GUARDRAILS 2.3). Measure a duration with hrtime(true).',
                $read,
            ))
                ->identifier(self::IDENTIFIER)
                ->nonIgnorable()
                ->build(),
        ];
    }
}
