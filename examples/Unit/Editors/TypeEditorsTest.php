<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Testkit\Editors\FakeTypeEditors;
use Cbox\Cms\Testkit\Editors\SampleEditors;

// A form renders a type from its editor descriptor, never from a type named in the code. A test
// hands the fake descriptors to the code under test, and filters them as the queries type.list
// and type.describe do for a reader: with visibleTo().

/**
 * The paths of the inputs a form shows a reader, in form order: every field the reader may see,
 * and the fields below a group only while the group is seen.
 *
 * @return list<string>
 */
function formInputs(TypeEditors $editors, TypeName $type, ClassificationAccess $access, bool $agent): array
{
    $editor = $editors->named($type);

    if (! $editor instanceof TypeEditor) {
        return [];
    }

    return array_map(static fn (EditorField $field): string => $field->path, $editor->visibleTo($access, $agent)->fields);
}

it('shows a reader the inputs of the fields the reader may see', function (): void {
    $editors = new FakeTypeEditors(...SampleEditors::many(2));
    $card = new TypeName('sample:card_1');

    expect(formInputs($editors, $card, ClassificationAccess::Public, agent: false))
        ->toBe(['label', 'mood', 'sizes', 'sizes.size', 'ext.probe.tag'])
        ->and(formInputs($editors, $card, ClassificationAccess::Sensitive, agent: false))
        ->toBe(SampleEditors::PATHS)
        ->and(formInputs($editors, $card, ClassificationAccess::Sensitive, agent: true))
        ->toBe(['label', 'memo', 'mood', 'extras', 'extras.count', 'sizes', 'sizes.size', 'ext.probe.tag'])
        ->and(formInputs($editors, new TypeName('sample:card_9'), ClassificationAccess::Sensitive, agent: false))
        ->toBe([]);
});

it('carries per leaf field the value rule of the validation runtime, and per group how it repeats', function (): void {
    $editor = new FakeTypeEditors(SampleEditors::type(1))->all()[0];
    $sizes = $editor->field('sizes');

    expect($editor->titleField)->toBe('label')
        ->and($editor->field('label')?->rule?->value)->toBe('{"kind":"text","minLength":1,"maxLength":120}')
        ->and($sizes?->rule)->toBeNull()
        ->and([$sizes?->repeat?->minItems, $sizes?->repeat?->maxItems])->toBe([1, 5])
        ->and(array_map(static fn (EditorField $field): string => $field->path, $editor->below('sizes')))->toBe(['sizes.size']);
});
