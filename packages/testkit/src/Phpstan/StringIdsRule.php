<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * PRD 5.3 and GUARDRAILS 2.2: ids are typed value objects, such as ChangesetId, not strings.
 * Outside Boundary and Adapter it reports a public method parameter named $id or ending in
 * Id, and a public method named id() or ending in Id, whose type is a string, nullable or not.
 * A value object takes its string as $value, and the Boundary parses the string into it.
 *
 * The one exemption is a signature the framework requires: a method that implements or
 * overrides a method of a framework interface or parent class (PHP's own, or one in the
 * namespaces of FRAMEWORK_NAMESPACES) whose declaration, read by reflection with its PHPDoc,
 * has a string at the same place: the return type, or the parameter at the same position.
 * A constructor is only required when the framework declares it abstract, as an interface
 * does. The name of a method never exempts it: a job's uniqueId() is reported, because
 * ShouldBeUnique declares no method and Laravel accepts any value it can concatenate, such
 * as a Stringable id. Nor does a declaration outside the framework, such as an addon's own
 * interface in an Adapter, or a framework declaration whose type is wider than string, exempt
 * a method.
 *
 * Test code is not checked. The errors are non-ignorable.
 *
 * @implements Rule<InClassMethodNode>
 */
#[Internal]
final readonly class StringIdsRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.stringId';

    /**
     * The namespaces of the framework besides PHP's own classes: Laravel, the Symfony
     * components it is built on, and the PSR interfaces it implements.
     *
     * @var list<string>
     */
    public const array FRAMEWORK_NAMESPACES = ['Illuminate\\', 'Symfony\\', 'Psr\\'];

    public function __construct(private ReflectionProvider $reflectionProvider) {}

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
        $prototypes = $this->frameworkPrototypes($node->getClassReflection(), $method->getName());
        $errors = [];

        foreach ($signature->getParameters() as $position => $parameter) {
            if (self::isIdName($parameter->getName()) && $this->isString($parameter->getType()) && ! $this->requiresStringParameter($prototypes, $position)) {
                $errors[] = $this->error(sprintf('Parameter $%s of %s', $parameter->getName(), $function), $parameter->getType(), $this->lineOf($node, $parameter->getName()));
            }
        }

        if (self::isIdName($method->getName()) && $this->isString($signature->getReturnType()) && ! $this->requiresStringReturn($prototypes)) {
            $errors[] = $this->error(sprintf('Return type of %s', $function), $signature->getReturnType(), $node->getOriginalNode()->getStartLine());
        }

        return $errors;
    }

    public static function isIdName(string $name): bool
    {
        return $name === 'id' || str_ends_with($name, 'Id');
    }

    /**
     * True for PHP's own classes and interfaces and those in FRAMEWORK_NAMESPACES.
     */
    private function isFramework(ClassReflection $class): bool
    {
        if ($class->isBuiltin()) {
            return true;
        }

        return array_any(self::FRAMEWORK_NAMESPACES, fn (string $namespace): bool => str_starts_with($class->getName(), $namespace));
    }

    /**
     * The declarations of the method in the framework interfaces and parent classes of the
     * class, as the framework declares them: read from the ancestor's own reflection, so a
     * template type is not resolved to what the class chose for it. A private method is not
     * inherited, and a constructor binds a subclass only when it is abstract.
     *
     * @return list<ExtendedMethodReflection>
     */
    private function frameworkPrototypes(ClassReflection $class, string $method): array
    {
        $prototypes = [];

        foreach ([...$class->getParents(), ...$class->getInterfaces()] as $ancestor) {
            if (! $this->isFramework($ancestor)) {
                continue;
            }

            $declaration = $this->reflectionProvider->getClass($ancestor->getName());

            if (! $declaration->hasNativeMethod($method)) {
                continue;
            }

            $prototype = $declaration->getNativeMethod($method);

            if ($prototype->isPrivate()) {
                continue;
            }

            if (strtolower($method) === '__construct' && ! $this->isAbstract($prototype)) {
                continue;
            }

            $prototypes[] = $prototype;
        }

        return $prototypes;
    }

    /**
     * @param  list<ExtendedMethodReflection>  $prototypes
     */
    private function requiresStringParameter(array $prototypes, int $position): bool
    {
        foreach ($this->variants($prototypes) as $variant) {
            $parameter = $variant->getParameters()[$position] ?? null;

            if ($parameter !== null && $this->isString($parameter->getType())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ExtendedMethodReflection>  $prototypes
     */
    private function requiresStringReturn(array $prototypes): bool
    {
        return array_any($this->variants($prototypes), fn (ExtendedParametersAcceptor $variant): bool => $this->isString($variant->getReturnType()));
    }

    /**
     * @param  list<ExtendedMethodReflection>  $prototypes
     * @return list<ExtendedParametersAcceptor>
     */
    private function variants(array $prototypes): array
    {
        $variants = [];

        foreach ($prototypes as $prototype) {
            foreach ($prototype->getVariants() as $variant) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    private function isAbstract(ExtendedMethodReflection $method): bool
    {
        $abstract = $method->isAbstract();

        return $abstract instanceof TrinaryLogic ? $abstract->yes() : $abstract;
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
