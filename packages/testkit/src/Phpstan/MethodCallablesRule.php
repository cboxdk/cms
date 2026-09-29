<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * SystemClockRule, UuidCreationRule and HookIoRule for a first-class callable such as $carbon->isPast(...) (GUARDRAILS
 * 2.3). PHPStan gives such a callable to rules as its own node, not as the call it wraps, so
 * this rule hands the wrapped call to all three. The callable reads the clock or makes an id when it
 * is called, and it can be called without the arguments that would keep it from doing so.
 *
 * @implements Rule<MethodCallableNode>
 */
#[Internal]
final readonly class MethodCallablesRule implements Rule
{
    private SystemClockRule $clock;

    private UuidCreationRule $uuids;

    private HookIoRule $hookIo;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->clock = new SystemClockRule($reflectionProvider);
        $this->uuids = new UuidCreationRule($reflectionProvider);
        $this->hookIo = new HookIoRule($reflectionProvider);
    }

    public function getNodeType(): string
    {
        return MethodCallableNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return [...$this->clock->processNode($node->getOriginalNode(), $scope), ...$this->uuids->processNode($node->getOriginalNode(), $scope), ...$this->hookIo->processNode($node->getOriginalNode(), $scope)];
    }
}
