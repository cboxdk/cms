<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * The editor descriptor of a type (PRD 11.12, 14.1): what a form renders the type from, compiled
 * by cms:generate from the blueprints. Its JSON form is type-editor.v1.json.
 *
 * - `id` and `name` are the type's, as the TypeCatalog has them, with the owner's `label` and
 *   `description`, the owner's `version` and an ExtensionVersion per extender, sorted by namespace.
 * - `capabilities` are the type's, so a form knows whether the type has stages to release.
 * - `titleField` is the path of the field a form shows an entry by: the first required text
 *   field of the owner in blueprint order, else the first text field, or null when the owner has
 *   no text field.
 * - `fields` is the flat field tree in form order: the owner's top-level fields in blueprint order,
 *   each group followed by its nested fields, then each extender's fields the same way, by
 *   namespace. A nested field names its group's path as `parent`, and every group comes before
 *   the fields below it.
 * - `layout` is the form's tabs and sidebar (gap G2): every top-level field is placed in exactly
 *   one section, and nothing else is placed. `labels` and `descriptions` are the type's label and
 *   description per locale the blueprint names, beside the plain ones.
 *
 * visibleTo() is what the queries type.list and type.describe answer a reader with: the fields
 * the reader may see, and the fields below a group only while the group is seen.
 */
#[Experimental]
final readonly class TypeEditor
{
    /** @var list<ExtensionVersion> sorted by namespace */
    public array $extensions;

    /** @var list<EditorField> in form order, each path once */
    public array $fields;

    /** @var list<EditorText> sorted by locale */
    public array $labels;

    /** @var list<EditorText> sorted by locale */
    public array $descriptions;

    /**
     * @param  list<ExtensionVersion>  $extensions
     * @param  list<EditorField>  $fields
     * @param  EditorLayout  $layout  places every top-level field once
     * @param  list<EditorText>  $labels  the label per locale
     * @param  list<EditorText>  $descriptions  the description per locale
     *
     * @throws InvalidTypeEditor when the descriptor breaks its rules
     */
    public function __construct(
        public TypeId $id,
        public TypeName $name,
        public string $label,
        public ?string $description,
        public int $version,
        public TypeCapabilities $capabilities,
        public ?string $titleField,
        array $extensions,
        array $fields,
        public EditorLayout $layout,
        array $labels = [],
        array $descriptions = [],
    ) {
        if ($version < 1) {
            throw InvalidTypeEditor::version($name->value, $version);
        }

        if ($label === '' || mb_strlen($label) > EditorField::MAX_LABEL) {
            throw InvalidTypeEditor::label('the type '.$name->value);
        }

        if ($description === '' || mb_strlen($description ?? '') > EditorField::MAX_DESCRIPTION) {
            throw InvalidTypeEditor::description('the type '.$name->value);
        }

        $byNamespace = [];

        foreach ($extensions as $extension) {
            if (isset($byNamespace[$extension->namespace->value])) {
                throw InvalidTypeEditor::duplicateExtension($name->value, $extension->namespace->value);
            }

            $byNamespace[$extension->namespace->value] = $extension;
        }

        ksort($byNamespace, SORT_STRING);
        $this->extensions = array_values($byNamespace);
        $this->fields = $this->checked($fields, $byNamespace);

        if ($capabilities->localization === Localization::None) {
            foreach ($this->fields as $field) {
                if ($field->translatable) {
                    throw InvalidTypeEditor::translatable($name->value, $field->path);
                }
            }
        }

        if ($titleField !== null) {
            $title = $this->field($titleField);

            if (! $title instanceof EditorField || $title->parent !== null || $title->namespace instanceof FieldNamespace || $title->base !== EditorBase::Text) {
                throw InvalidTypeEditor::titleField($name->value, $titleField);
            }
        }

        $this->assertLayout($layout);
        $this->labels = EditorText::checked($labels, 'the label of the type '.$name->value);
        $this->descriptions = EditorText::checked($descriptions, 'the description of the type '.$name->value);
    }

    /**
     * The field at the path, or null.
     */
    public function field(string $path): ?EditorField
    {
        return array_find($this->fields, static fn (EditorField $field): bool => $field->path === $path);
    }

    /**
     * The fields directly below a group, or the top-level fields for null, in form order.
     *
     * @return list<EditorField>
     */
    public function below(?string $parent): array
    {
        return array_values(array_filter($this->fields, static fn (EditorField $field): bool => $field->parent === $parent));
    }

    /**
     * The descriptor as a reader may see it (PRD 6.2, 12.2): with the fields the reader's
     * classification access allows and, for an agent, that the blueprint opens to agents, and
     * without the fields below a group the reader does not see. The title field stays only while
     * its field does.
     *
     * @param  bool  $agent  whether the reader's credential was issued for an agent
     */
    public function visibleTo(ClassificationAccess $access, bool $agent): self
    {
        $kept = [];
        $paths = [];

        foreach ($this->fields as $field) {
            if (! $field->readableBy($access, $agent) || ($field->parent !== null && ! isset($paths[$field->parent]))) {
                continue;
            }

            $kept[] = $field;
            $paths[$field->path] = true;
        }

        return new self(
            $this->id,
            $this->name,
            $this->label,
            $this->description,
            $this->version,
            $this->capabilities,
            $this->titleField !== null && isset($paths[$this->titleField]) ? $this->titleField : null,
            $this->extensions,
            $kept,
            $this->layout->keeping($paths),
            $this->labels,
            $this->descriptions,
        );
    }

    /**
     * The layout places every top-level field exactly once, and nothing else.
     *
     * @throws InvalidTypeEditor
     */
    private function assertLayout(EditorLayout $layout): void
    {
        $placed = [];

        foreach ($layout->paths() as $path) {
            if (isset($placed[$path])) {
                throw InvalidTypeEditor::layoutPlacesTwice($this->name->value, $path);
            }

            $field = $this->field($path);

            if (! $field instanceof EditorField || $field->parent !== null) {
                throw InvalidTypeEditor::layoutPlacesUnknown($this->name->value, $path);
            }

            $placed[$path] = true;
        }

        foreach ($this->below(null) as $field) {
            if (! isset($placed[$field->path])) {
                throw InvalidTypeEditor::layoutLeavesOut($this->name->value, $field->path);
            }
        }
    }

    /**
     * @param  list<EditorField>  $fields
     * @param  array<string, ExtensionVersion>  $extensions
     * @return list<EditorField>
     *
     * @throws InvalidTypeEditor
     */
    private function checked(array $fields, array $extensions): array
    {
        /** @var array<string, EditorField> $seen */
        $seen = [];

        foreach ($fields as $field) {
            if (isset($seen[$field->path])) {
                throw InvalidTypeEditor::duplicateField($this->name->value, $field->path);
            }

            if ($field->parent !== null) {
                $parent = $seen[$field->parent] ?? null;

                if (! $parent instanceof EditorField || ! $parent->isGroup()) {
                    throw InvalidTypeEditor::parent($this->name->value, $field->path, $field->parent);
                }

                if ($parent->namespace?->value !== $field->namespace?->value || $parent->classification !== $field->classification || $parent->encrypted !== $field->encrypted
                    || $parent->translatable !== $field->translatable || $parent->fallback !== $field->fallback) {
                    throw InvalidTypeEditor::nested($this->name->value, $field->path);
                }
            } elseif ($field->namespace instanceof FieldNamespace && ! isset($extensions[$field->namespace->value])) {
                throw InvalidTypeEditor::extension($this->name->value, $field->path);
            }

            $seen[$field->path] = $field;
        }

        return $fields;
    }
}
