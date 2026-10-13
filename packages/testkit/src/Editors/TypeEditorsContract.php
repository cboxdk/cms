<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Editors\EditorBase;
use Cbox\Cms\Contracts\Editors\EditorChoice;
use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\EditorRepeat;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Testkit\Editors\Boundary\RuleShape;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for TypeEditors (GUARDRAILS 2.3 and 9). The FakeTypeEditors and the
 * class cms:generate writes run the same cases, so a test that uses the fake sees what the panel's
 * forms see at run time.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return the
 * descriptors from editors(), with at least one type:
 *
 *     final class GeneratedTypeEditorsContractTest extends TestCase
 *     {
 *         use TypeEditorsContract;
 *
 *         protected function editors(): TypeEditors
 *         {
 *             return new GeneratedTypeEditors;
 *         }
 *     }
 *
 * The cases hold the descriptors to their lookups and to the rules a form relies on: the fields
 * are a flat tree in form order where every group comes before the fields below it, a leaf field
 * carries a value rule of the validation runtime and a group none, a select has its choices, the
 * title field is a top-level text field of the owner, an extension field belongs to a declared
 * extender and only it is required on release, and visibleTo() keeps for a reader exactly the
 * fields the reader may see (PRD 2.31, 12.2), the fields below a group only with the group.
 */
#[Experimental]
trait TypeEditorsContract
{
    /**
     * The kinds of a value rule of the validation runtime a leaf field may carry.
     *
     * @var list<string>
     */
    private const array RULE_KINDS = ['boolean', 'choice', 'date', 'datetime', 'decimal', 'integer', 'list', 'portable_text', 'string', 'text'];

    abstract protected function editors(): TypeEditors;

    #[Test]
    public function all_gives_at_least_one_descriptor_sorted_by_name_with_each_id_and_each_name_once(): void
    {
        $editors = $this->editors()->all();
        $names = array_map(static fn (TypeEditor $editor): string => $editor->name->value, $editors);
        $ids = array_map(static fn (TypeEditor $editor): string => $editor->id->toString(), $editors);
        $sorted = $names;
        sort($sorted, SORT_STRING);

        Assert::assertNotSame([], $editors);
        Assert::assertSame($sorted, $names);
        Assert::assertSame(array_values(array_unique($names)), $names);
        Assert::assertSame(array_values(array_unique($ids)), $ids);
    }

    #[Test]
    public function find_and_named_give_each_descriptor_by_its_id_and_its_name(): void
    {
        $editors = $this->editors();

        foreach ($editors->all() as $editor) {
            Assert::assertEquals($editor, $editors->find(TypeId::fromString($editor->id->toString())), $editor->name->value);
            Assert::assertEquals($editor, $editors->named(new TypeName($editor->name->value)), $editor->name->value);
        }
    }

    #[Test]
    public function find_and_named_give_null_for_a_type_the_installation_does_not_have(): void
    {
        $editors = $this->editors();
        $ids = array_map(static fn (TypeEditor $editor): string => $editor->id->toString(), $editors->all());
        $names = array_map(static fn (TypeEditor $editor): string => $editor->name->value, $editors->all());
        $milliseconds = 981_173_106_000;

        do {
            $id = new TypeId(Uuid7::lowestAt($milliseconds));
            $milliseconds += 86_400_000;
        } while (in_array($id->toString(), $ids, true));

        $number = 0;

        do {
            $name = new TypeName('absent:type_'.$number++);
        } while (in_array($name->value, $names, true));

        Assert::assertNull($editors->find($id));
        Assert::assertNull($editors->named($name));
    }

    #[Test]
    public function every_group_comes_before_the_fields_below_it_and_only_a_group_has_fields_below_it(): void
    {
        foreach ($this->editors()->all() as $editor) {
            $seen = [];

            foreach ($editor->fields as $field) {
                if ($field->parent !== null) {
                    $parent = $seen[$field->parent] ?? null;

                    Assert::assertInstanceOf(EditorField::class, $parent, $editor->name->value.' '.$field->path);
                    Assert::assertTrue($parent->isGroup(), $editor->name->value.' '.$field->path);
                    Assert::assertSame($parent->namespace?->value, $field->namespace?->value, $editor->name->value.' '.$field->path);
                    Assert::assertSame($parent->classification, $field->classification, $editor->name->value.' '.$field->path);
                }

                $seen[$field->path] = $field;
            }

            foreach ($editor->fields as $field) {
                Assert::assertTrue($field->isGroup() || $editor->below($field->path) === [], $editor->name->value.' '.$field->path);
            }
        }
    }

    #[Test]
    public function a_leaf_field_carries_a_value_rule_of_the_validation_runtime_and_a_group_none(): void
    {
        foreach ($this->editors()->all() as $editor) {
            foreach ($editor->fields as $field) {
                $where = $editor->name->value.' '.$field->path;

                if ($field->isGroup()) {
                    Assert::assertNull($field->rule, $where);

                    continue;
                }

                Assert::assertInstanceOf(JsonDocument::class, $field->rule, $where);
                Assert::assertNull($field->repeat, $where);
                $rule = RuleShape::of($field->rule);
                Assert::assertContains($rule->kind, self::RULE_KINDS, $where);

                if ($field->base === EditorBase::Relation) {
                    Assert::assertContains($rule->kind === 'list' ? $rule->itemKind : $rule->kind, ['string'], $where);
                } elseif ($rule->kind === 'list') {
                    Assert::assertSame(EditorBase::Select, $field->base, $where);
                    Assert::assertSame('choice', $rule->itemKind, $where);
                } else {
                    Assert::assertNotSame('string', $rule->kind, $where);
                }
            }
        }
    }

    #[Test]
    public function a_select_has_its_choices_each_once_and_no_other_field_has_any(): void
    {
        foreach ($this->editors()->all() as $editor) {
            foreach ($editor->fields as $field) {
                $values = array_map(static fn (EditorChoice $choice): string => $choice->value->value, $field->choices);

                Assert::assertSame($field->base === EditorBase::Select, $values !== [], $editor->name->value.' '.$field->path);
                Assert::assertSame(array_values(array_unique($values)), $values, $editor->name->value.' '.$field->path);
            }
        }
    }

    #[Test]
    public function a_relation_has_its_target_types_each_once_and_no_other_field_has_any(): void
    {
        foreach ($this->editors()->all() as $editor) {
            foreach ($editor->fields as $field) {
                $targets = array_map(static fn (TypeName $target): string => $target->value, $field->targets);

                Assert::assertSame($field->base === EditorBase::Relation, $targets !== [], $editor->name->value.' '.$field->path);
                Assert::assertSame(array_values(array_unique($targets)), $targets, $editor->name->value.' '.$field->path);
            }
        }
    }

    #[Test]
    public function a_field_repeats_only_as_a_group(): void
    {
        foreach ($this->editors()->all() as $editor) {
            foreach ($editor->fields as $field) {
                Assert::assertTrue(! $field->repeat instanceof EditorRepeat || $field->isGroup(), $editor->name->value.' '.$field->path);
            }
        }
    }

    #[Test]
    public function the_title_field_is_null_or_a_top_level_text_field_of_the_owner(): void
    {
        foreach ($this->editors()->all() as $editor) {
            if ($editor->titleField === null) {
                continue;
            }

            $title = $editor->field($editor->titleField);

            Assert::assertInstanceOf(EditorField::class, $title, $editor->name->value);
            Assert::assertNull($title->parent, $editor->name->value);
            Assert::assertNull($title->namespace, $editor->name->value);
            Assert::assertSame(EditorBase::Text, $title->base, $editor->name->value);
        }
    }

    #[Test]
    public function an_extension_field_belongs_to_a_declared_extender_and_only_an_extension_field_is_required_on_release(): void
    {
        foreach ($this->editors()->all() as $editor) {
            $namespaces = array_map(static fn (ExtensionVersion $extension): string => $extension->namespace->value, $editor->extensions);

            foreach ($editor->fields as $field) {
                $where = $editor->name->value.' '.$field->path;

                if ($field->namespace instanceof FieldNamespace) {
                    Assert::assertContains($field->namespace->value, $namespaces, $where);
                } else {
                    Assert::assertNotSame(Presence::RequiredOnRelease, $field->presence, $where);
                }

                Assert::assertTrue(str_contains($field->fieldType, ':') || $field->fieldType === $field->base->value, $where);
            }
        }
    }

    #[Test]
    public function visible_to_keeps_exactly_the_fields_the_reader_may_see_and_the_fields_below_a_group_only_with_it(): void
    {
        foreach ($this->editors()->all() as $editor) {
            Assert::assertEquals($editor, $editor->visibleTo(ClassificationAccess::Sensitive, false), $editor->name->value);

            foreach (ClassificationAccess::cases() as $access) {
                foreach ([false, true] as $agent) {
                    $visible = $editor->visibleTo($access, $agent);
                    $paths = array_map(static fn (EditorField $field): string => $field->path, $visible->fields);
                    $where = sprintf('%s at %s%s', $editor->name->value, $access->value, $agent ? ' for an agent' : '');

                    foreach ($editor->fields as $field) {
                        $expected = $field->readableBy($access, $agent) && ($field->parent === null || in_array($field->parent, $paths, true));

                        Assert::assertSame($expected, in_array($field->path, $paths, true), $where.' '.$field->path);
                    }

                    Assert::assertSame($editor->titleField !== null && in_array($editor->titleField, $paths, true) ? $editor->titleField : null, $visible->titleField, $where);
                    Assert::assertEquals($visible, $visible->visibleTo($access, $agent), $where);
                    Assert::assertSame([$editor->id, $editor->name, $editor->extensions], [$visible->id, $visible->name, $visible->extensions], $where);
                }
            }
        }
    }
}
