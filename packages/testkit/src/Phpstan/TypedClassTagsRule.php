<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\Type;

/**
 * Rule 1 of GUARDRAILS 2.2 for the types in the PHPDoc of a class: template bounds, the
 * generic arguments in the extends, implements and use tags, and the magic properties and
 * methods in the property and method tags, such as the generated properties of an Eloquent
 * model. See DeclaredTypes.
 *
 * @implements Rule<InClassNode>
 */
#[Internal]
final class TypedClassTagsRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $phpDoc = $class->getResolvedPhpDoc();

        if ($phpDoc === null) {
            return [];
        }

        $name = $class->getDisplayName();
        $declarations = [];

        foreach ($phpDoc->getTemplateTags() as $template => $tag) {
            $declarations[] = new Declaration(sprintf('Template %s of class %s', $template, $name), $tag->getBound(), isBound: true);
        }

        $supertypes = [
            'extends' => $phpDoc->getExtendsTags(),
            'implements' => $phpDoc->getImplementsTags(),
            'use' => $phpDoc->getUsesTags(),
        ];

        foreach ($supertypes as $kind => $tags) {
            foreach ($tags as $supertype => $tag) {
                $declarations[] = new Declaration(sprintf('The %s tag for %s of class %s', $kind, $supertype, $name), $tag->getType());
            }
        }

        foreach ($phpDoc->getPropertyTags() as $property => $tag) {
            $readable = $tag->getReadableType();
            $writable = $tag->getWritableType();
            $subject = sprintf('The property tag $%s of class %s', $property, $name);

            if ($readable instanceof Type) {
                $declarations[] = new Declaration($subject, $readable);
            }

            if ($writable instanceof Type && (! $readable instanceof Type || ! $readable->equals($writable))) {
                $declarations[] = new Declaration($subject, $writable);
            }
        }

        foreach ($phpDoc->getMethodTags() as $method => $tag) {
            foreach ($tag->getParameters() as $parameter => $parameterTag) {
                $declarations[] = new Declaration(sprintf('Parameter $%s of the method tag %s() of class %s', $parameter, $method, $name), $parameterTag->getType());
            }

            $declarations[] = new Declaration(sprintf('Return type of the method tag %s() of class %s', $method, $name), $tag->getReturnType());
        }

        return DeclaredTypes::errors($scope, $declarations);
    }
}
