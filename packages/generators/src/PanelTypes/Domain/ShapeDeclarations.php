<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\Declarations;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\JsonShape;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\ShapeDocument;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\TypeExpression;

/**
 * The TypeScript declarations of a contract's JSON document (PRD 13.4, GUARDRAILS 2.2): the
 * document's type, named `<Stem>V<version>` such as NoteCreateV1, and a type per definition of its
 * `$defs` it reaches and per object nested in a member, named after the stem, the definition or
 * the members that lead to it, and the version, such as NoteCreateFieldValueV1 and
 * NoteCreateAuthorV1. An object with members is an interface of readonly members, the members a
 * document may leave out optional; any other place is a type alias. The types follow the schema
 * one way, from the contract to TypeScript, and say nothing a codec does not check.
 */
#[Internal]
final class ShapeDeclarations
{
    /** @var array<string, list<string>> the declarations by name, in the order they were made */
    private array $declarations = [];

    /** @var array<string, true> the definitions declared, or being declared, by key */
    private array $definitions = [];

    /** @var array<string, true> the names this module imports from the SDK, such as JsonValue */
    private array $imports = [];

    private function __construct(
        private readonly ShapeDocument $document,
        private readonly string $stem,
        private readonly string $suffix,
    ) {}

    /**
     * The declarations of the document, the document's type first, and the SDK's names they use.
     *
     * @param  string  $stem  the name's stem, such as NoteCreate
     * @param  string  $suffix  the name's suffix, such as V1 or ResultV1
     * @param  string  $summary  what the document is, the document type's comment when the schema has no description
     */
    public static function of(ShapeDocument $document, string $stem, string $suffix, string $summary): Declarations
    {
        $declarations = new self($document, $stem, $suffix);
        $name = $stem.$suffix;
        $declarations->declare($name, $document->root, $document->root->description ?? $summary);
        $lines = [];

        foreach ($declarations->declarations as $declaration) {
            $lines = [...$lines, '', ...$declaration];
        }

        $imports = array_keys($declarations->imports);
        sort($imports, SORT_STRING);

        return new Declarations($name, $lines, $imports);
    }

    /**
     * The PascalCase of a key or a name, such as FieldValue for field_value and NoteCreate for
     * note.create.
     */
    public static function pascal(string $key): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(ucfirst(...), $words));
    }

    private function declare(string $name, JsonShape $shape, ?string $description): void
    {
        if (isset($this->declarations[$name])) {
            throw GenerationFailed::because(GenerateErrorCode::NameCollision, sprintf(
                'Two places of the schema of %s get the TypeScript name %s. Rename a definition or a member so their names differ in more than punctuation.',
                $this->stem.$this->suffix,
                $name,
            ));
        }

        // Reserved first, so a declaration it reaches cannot take its name.
        $this->declarations[$name] = [];
        $comment = $description === null ? [] : TypeScriptLayout::comment($description, 0);

        if ($shape->kind === ShapeKind::Object && ($shape->properties !== [] || $shape->closed)) {
            $this->declarations[$name] = [...$comment, 'export interface '.$name.' {', ...$this->members($name, $shape), '}'];

            return;
        }

        $this->declarations[$name] = [...$comment, ...TypeScriptLayout::statement('export type '.$name.' =', $this->expression($shape, $name), 0)];
    }

    /**
     * @return list<string>
     */
    private function members(string $owner, JsonShape $shape): array
    {
        $lines = [];
        $types = [];
        $optional = false;

        foreach ($shape->properties as $property) {
            $type = $this->expression($property->shape, $this->nested($owner, $property->name));
            $types[] = $type;
            $optional = $optional || ! $property->required;
            $comment = $property->shape->description === null ? [] : TypeScriptLayout::comment($property->shape->description, 2);
            $head = '  readonly '.TypeScriptLayout::key($property->name).($property->required ? ':' : '?:');
            $lines = [...$lines, ...$comment, ...TypeScriptLayout::statement(ltrim($head), $type, 2)];
        }

        if ($shape->additional instanceof JsonShape) {
            $members = [$this->expression($shape->additional, $this->nested($owner, 'value')), ...$types];

            if ($optional) {
                $members[] = TypeExpression::atom('undefined');
            }

            $lines = [...$lines, ...TypeScriptLayout::statement('readonly [key: string]:', TypeExpression::union($members), 2)];
        }

        return $lines;
    }

    /**
     * The name of an object nested below the owner's member: the owner's name without its suffix,
     * the member in PascalCase, and the suffix.
     */
    private function nested(string $owner, string $member): string
    {
        return substr($owner, 0, -strlen($this->suffix)).self::pascal($member).$this->suffix;
    }

    private function expression(JsonShape $shape, string $name): TypeExpression
    {
        return match ($shape->kind) {
            ShapeKind::Any => $this->import('JsonValue'),
            ShapeKind::Null => TypeExpression::atom('null'),
            ShapeKind::String => TypeExpression::atom('string'),
            ShapeKind::Number => TypeExpression::atom('number'),
            ShapeKind::Boolean => TypeExpression::atom('boolean'),
            ShapeKind::Literal => TypeExpression::atom($shape->literal ?? 'null'),
            ShapeKind::Reference => TypeExpression::atom($this->definition($shape->reference ?? '')),
            ShapeKind::Array => TypeExpression::array($shape->items instanceof JsonShape ? $this->expression($shape->items, $this->nested($name, 'item')) : $this->import('JsonValue')),
            ShapeKind::Union => $this->union($shape, $name),
            ShapeKind::Intersection => TypeExpression::atom(implode(' & ', array_map(fn (JsonShape $member): string => $this->parenthesised($this->expression($member, $name)), $shape->members))),
            ShapeKind::Object => $this->object($shape, $name),
        };
    }

    /**
     * The union of the branches; when more than one branch is an object of its own, each is named
     * after its place among the branches, such as NoteCreateOption2V1.
     */
    private function union(JsonShape $shape, string $name): TypeExpression
    {
        $objects = array_filter($shape->members, static fn (JsonShape $member): bool => $member->kind === ShapeKind::Object && $member->properties !== []);
        $members = [];

        foreach ($shape->members as $index => $member) {
            $members[] = $this->expression($member, count($objects) > 1 ? $this->nested($name, 'option '.($index + 1)) : $name);
        }

        return TypeExpression::union($members);
    }

    private function object(JsonShape $shape, string $name): TypeExpression
    {
        if ($shape->properties !== [] || $shape->closed && ! $shape->additional instanceof JsonShape) {
            if ($shape->properties === []) {
                return TypeExpression::atom('{ readonly [key: string]: never }');
            }

            $this->declare($name, $shape, null);

            return TypeExpression::atom($name);
        }

        if ($shape->additional instanceof JsonShape) {
            // An index signature, not Readonly<Record<...>>, so a definition may refer to itself.
            return TypeExpression::indexed($this->expression($shape->additional, $this->nested($name, 'value')));
        }

        return $this->import('JsonObject');
    }

    private function definition(string $key): string
    {
        $shape = $this->document->definitions[$key] ?? null;

        if (! $shape instanceof JsonShape) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                'The schema of %s refers to the definition "%s", which its $defs do not have.',
                $this->stem.$this->suffix,
                $key,
            ));
        }

        $name = $this->stem.self::pascal($key).$this->suffix;

        if (! isset($this->definitions[$key])) {
            $this->definitions[$key] = true;
            $this->declare($name, $shape, $shape->description);
        }

        return $name;
    }

    private function import(string $name): TypeExpression
    {
        $this->imports[$name] = true;

        return TypeExpression::atom($name);
    }

    private function parenthesised(TypeExpression $type): string
    {
        return $type->union ? '('.$type->flat().')' : $type->flat();
    }
}
