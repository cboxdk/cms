<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\TextHash;
use Cbox\Cms\Contracts\Ids\Identifier;
use DateTimeImmutable;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

/**
 * PRD 6.5 invariant 10 and 7.2: an event carries ids, versions, values that are not text and
 * hashes of text, never content. It reports a property of a class that implements
 * Cbox\Cms\Contracts\Events\EventPayload whose type may hold a string, as plain string, a string
 * in a union, a list of strings or mixed, unless it is an id value object (a class that implements
 * Ids\Identifier) or a hash (TextHash). A string that holds an id or a hash is reported too: it is
 * given its value object, so no property of a payload can hold text.
 *
 * It also reports a property of any other type an event cannot carry, such as a float, an array
 * that is no list, whose keys can be text, or an object that is no id, hash, DateTimeImmutable or
 * backed enum, because such an object can hold a string. A list is judged by its values.
 *
 * Every payload is checked, test code included, and the errors are non-ignorable.
 *
 * @implements Rule<ClassPropertyNode>
 */
#[Internal]
final readonly class EventPayloadTextRule implements Rule
{
    public const string IDENTIFIER = 'cboxCms.eventPayloadText';

    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! $class->implementsInterface(EventPayload::class) || ! $class->hasNativeProperty($node->getName())) {
            return [];
        }

        $type = $class->getNativeProperty($node->getName())->getReadableType();
        $verdict = $this->verdict($type);

        if ($verdict === null) {
            return [];
        }

        $subject = sprintf(
            'Property %s::$%s of an event payload is of type %s',
            $class->getDisplayName(),
            $node->getName(),
            $type->describe(VerbosityLevel::precise()),
        );

        $message = $verdict === 'text'
            ? $subject.', which can hold text. Events carry ids, versions, values that are not text and hashes of text, never text (PRD 6.5 invariant 10, 7.2): type it as an id value object that implements Cbox\Cms\Contracts\Ids\Identifier, or as a Cbox\Cms\Contracts\Events\TextHash.'
            : $subject.', which an event cannot carry. Use int, bool, null, DateTimeImmutable, a backed enum, an id value object that implements Cbox\Cms\Contracts\Ids\Identifier, a Cbox\Cms\Contracts\Events\TextHash, or a list of these (PRD 7.2).';

        return [
            RuleErrorBuilder::message($message)
                ->identifier(self::IDENTIFIER)
                ->line($node->getStartLine())
                ->nonIgnorable()
                ->build(),
        ];
    }

    /**
     * Null when an event can carry the type, 'text' when it can hold a string, 'other' otherwise.
     */
    private function verdict(Type $type): ?string
    {
        if ($type instanceof MixedType || ! $type->isString()->no()) {
            return 'text';
        }

        if ($type instanceof UnionType) {
            $verdicts = array_map($this->verdict(...), $type->getTypes());

            return in_array('text', $verdicts, true) ? 'text' : (in_array('other', $verdicts, true) ? 'other' : null);
        }

        if ($type->isNull()->yes() || $type->isInteger()->yes() || $type->isBoolean()->yes()) {
            return null;
        }

        if ($type->isArray()->yes()) {
            return $type->isList()->yes() ? $this->verdict($type->getIterableValueType()) : 'other';
        }

        $classes = $type->getObjectClassReflections();

        if ($classes !== [] && array_all($classes, $this->carries(...))) {
            return null;
        }

        return 'other';
    }

    private function carries(ClassReflection $class): bool
    {
        return $class->implementsInterface(Identifier::class)
            || $class->getName() === TextHash::class
            || $class->is(DateTimeImmutable::class)
            || $class->isBackedEnum();
    }
}
