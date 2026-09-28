<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\IdGenerator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * GUARDRAILS 2.3 and PRD 5.3: the id generator is a contract, so ids are deterministic in tests.
 * Only a class that implements Cbox\Cms\Contracts\IdGenerator makes a new UUID; every other
 * class asks the IdGenerator it is given and wraps the Uuid7 in a typed id. UuidCreations lists
 * what counts as making one, from Str::uuid() to ramsey/uuid and symfony/uid.
 *
 * Test code is not checked: a namespace with a Tests segment, or a file in the global namespace
 * below a tests directory (LayerScope::isTestFile()). Migrations, config files and route files
 * in the global namespace are checked. The errors are non-ignorable, in every layer.
 *
 * @implements Rule<Node>
 */
#[Internal]
final readonly class UuidCreationRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.uuid';

    private UuidCreations $creations;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->creations = new UuidCreations($reflectionProvider);
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
        $creation = $this->creations->in($node, $scope);

        if ($creation === null || LayerScope::isTestFile($scope->getNamespace() ?? '', $scope->getFile()) || $scope->getClassReflection()?->implementsInterface(IdGenerator::class) === true) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Makes a UUID through %s. Ask the IdGenerator contract for a new id: only an IdGenerator implementation makes ids, so tests get the same ids on every run (GUARDRAILS 2.3, PRD 5.3).',
                $creation,
            ))
                ->identifier(self::IDENTIFIER)
                ->nonIgnorable()
                ->build(),
        ];
    }
}
