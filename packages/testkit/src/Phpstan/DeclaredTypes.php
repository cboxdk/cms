<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ClosureType;
use PHPStan\Type\VerbosityLevel;

/**
 * Rule 1 of GUARDRAILS 2.2: no mixed, no untyped arrays and no array shapes in declarations
 * outside the Boundary and Adapter layers. The Typed*Rule classes collect the declarations
 * of one kind of node each, and this class turns them into errors.
 *
 * Test code, meaning a namespace with a Tests segment or the global namespace of Pest files,
 * is not checked. The errors are non-ignorable: neither an ignore comment nor ignoreErrors
 * can hide them. A declaration that needs mixed or an untyped array belongs in Boundary or
 * Adapter.
 */
#[Internal]
final class DeclaredTypes
{
    /**
     * @param  list<Declaration>  $declarations
     * @return list<IdentifierRuleError>
     */
    public static function errors(Scope $scope, array $declarations): array
    {
        $namespace = $scope->getNamespace() ?? '';

        if (LayerScope::allowsLooseTypes($namespace) || LayerScope::isTestCode($namespace)) {
            return [];
        }

        $errors = [];

        foreach ($declarations as $declaration) {
            foreach ($declaration->looseTypes() as $looseType) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    '%s uses %s in its %s %s. %s; %s.',
                    $declaration->subject,
                    $looseType->singular(),
                    $declaration->isBound ? 'bound' : 'type',
                    $declaration->type->describe(VerbosityLevel::precise()),
                    $looseType->advice(),
                    $looseType->restriction(),
                ))
                    ->identifier($looseType->identifier())
                    ->nonIgnorable()
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * The parameters, the return and the template bounds of a function or a method.
     *
     * @return list<Declaration>
     */
    public static function signature(string $function, ExtendedParametersAcceptor $signature): array
    {
        $declarations = [];

        foreach ($signature->getParameters() as $parameter) {
            $declarations[] = new Declaration(sprintf('Parameter $%s of %s', $parameter->getName(), $function), $parameter->getType());
        }

        $declarations[] = new Declaration(sprintf('Return type of %s', $function), $signature->getReturnType());

        foreach (LooseTypeFinder::boundsOf($signature->getTemplateTypeMap()->getTypes()) as $name => $bound) {
            $declarations[] = new Declaration(sprintf('Template %s of %s', $name, $function), $bound, isBound: true);
        }

        return $declarations;
    }

    /**
     * A closure or an arrow function. PHPStan reads no PHPDoc on them and infers the type of
     * an undeclared parameter or return from the call site and the body, so only the native
     * declarations are checked.
     *
     * @return list<Declaration>
     */
    public static function closure(string $kind, Scope $scope, ClosureType $type, Closure|ArrowFunction $original): array
    {
        $declarations = [];

        foreach ($type->getParameters() as $index => $parameter) {
            $native = ($original->params[$index] ?? null)?->type;

            if ($native !== null) {
                $declarations[] = new Declaration(
                    sprintf('Parameter $%s of %s', $parameter->getName(), $kind),
                    $scope->getFunctionType($native, false, false),
                );
            }
        }

        if ($original->returnType instanceof Node) {
            $declarations[] = new Declaration(
                sprintf('Return type of %s', $kind),
                $scope->getFunctionType($original->returnType, false, false),
            );
        }

        return $declarations;
    }
}
