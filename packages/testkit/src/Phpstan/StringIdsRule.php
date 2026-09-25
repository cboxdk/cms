<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * PRD 5.3 and GUARDRAILS 2.2: ids are typed value objects, such as ChangesetId, not strings.
 * Outside Boundary and Adapter it reports a public method parameter named $id or ending in
 * Id, and a public method named id() or ending in Id, whose type is a string, nullable or not.
 * A value object takes its string as $value, and the Boundary parses the string into it.
 *
 * Test code is not checked. The errors are non-ignorable.
 *
 * @implements Rule<InClassMethodNode>
 */
#[Internal]
final class StringIdsRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.stringId';

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace() ?? '';
        $method = $node->getMethodReflection();

        if (! $method->isPublic() || LayerScope::allowsLooseTypes($namespace) || LayerScope::isTestCode($namespace)) {
            return [];
        }

        $function = sprintf('method %s::%s()', $node->getClassReflection()->getDisplayName(), $method->getName());
        $signature = $method->getOnlyVariant();
        $errors = [];

        foreach ($signature->getParameters() as $parameter) {
            if (self::isIdName($parameter->getName()) && $this->isString($parameter->getType())) {
                $errors[] = $this->error(sprintf('Parameter $%s of %s', $parameter->getName(), $function), $parameter->getType(), $this->lineOf($node, $parameter->getName()));
            }
        }

        if (self::isIdName($method->getName()) && $this->isString($signature->getReturnType())) {
            $errors[] = $this->error(sprintf('Return type of %s', $function), $signature->getReturnType(), $node->getOriginalNode()->getStartLine());
        }

        return $errors;
    }

    public static function isIdName(string $name): bool
    {
        return $name === 'id' || str_ends_with($name, 'Id');
    }

    private function isString(Type $type): bool
    {
        return TypeCombinator::removeNull($type)->isString()->yes();
    }

    private function lineOf(InClassMethodNode $node, string $parameter): int
    {
        foreach ($node->getOriginalNode()->params as $param) {
            if ($param->var instanceof Variable && $param->var->name === $parameter) {
                return $param->getStartLine();
            }
        }

        return $node->getOriginalNode()->getStartLine();
    }

    private function error(string $subject, Type $type, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            '%s is a string id of type %s. Type ids as value objects, such as ChangesetId (PRD 5.3); string ids are only allowed in Boundary and Adapter namespaces.',
            $subject,
            $type->describe(VerbosityLevel::precise()),
        ))
            ->identifier(self::IDENTIFIER)
            ->line($line)
            ->nonIgnorable()
            ->build();
    }
}
