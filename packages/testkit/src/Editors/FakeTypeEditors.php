<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Editors\InvalidTypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Override;

/**
 * The testkit's TypeEditors: the descriptors a test gives it, in memory. A test of kernel code
 * that must work for any type builds the descriptors it needs with TypeEditor, instead of relying
 * on the application's generated class (GUARDRAILS 2.4). Like the generated class, it holds each
 * id and each name once, lists the descriptors sorted by name and never changes.
 */
#[Experimental]
final readonly class FakeTypeEditors implements TypeEditors
{
    /** @var list<TypeEditor> sorted by name */
    private array $editors;

    /**
     * @throws InvalidTypeEditor when two descriptors share an id or a name
     */
    public function __construct(TypeEditor ...$editors)
    {
        $byName = [];
        $ids = [];

        foreach ($editors as $editor) {
            if (isset($byName[$editor->name->value])) {
                throw InvalidTypeEditor::duplicateType($editor->name->value);
            }

            if (isset($ids[$editor->id->toString()])) {
                throw InvalidTypeEditor::duplicateType($editor->id->toString());
            }

            $byName[$editor->name->value] = $editor;
            $ids[$editor->id->toString()] = true;
        }

        ksort($byName, SORT_STRING);
        $this->editors = array_values($byName);
    }

    #[Override]
    public function all(): array
    {
        return $this->editors;
    }

    #[Override]
    public function find(TypeId $id): ?TypeEditor
    {
        return array_find($this->editors, static fn (TypeEditor $editor): bool => $editor->id->equals($id));
    }

    #[Override]
    public function named(TypeName $name): ?TypeEditor
    {
        return array_find($this->editors, static fn (TypeEditor $editor): bool => $editor->name->equals($name));
    }
}
