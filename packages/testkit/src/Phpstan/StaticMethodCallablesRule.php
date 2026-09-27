<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * SystemClockRule and UuidCreationRule for a first-class callable such as Str::uuid(...) (GUARDRAILS
 * 2.3). PHPStan gives such a callable to rules as its own node, not as the call it wraps, so
 * this rule hands the wrapped call to both. The callable reads the clock or makes an id when it
 * is called, and it can be called without the arguments that would keep it from doing so.
 *
 * @implements Rule<StaticMethodCallableNode>
 */
#[Internal]
final readonly class StaticMethodCallablesRule implements Rule
{
    private SystemClockRule $clock;

    private UuidCreationRule $uuids;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->clock = new SystemClockRule($reflectionProvider);
        $this->uuids = new UuidCreationRule($reflectionProvider);
    }

    public function getNodeType(): string
    {
        return StaticMethodCallableNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return [...$this->clock->processNode($node->getOriginalNode(), $scope), ...$this->uuids->processNode($node->getOriginalNode(), $scope)];
    }
}
