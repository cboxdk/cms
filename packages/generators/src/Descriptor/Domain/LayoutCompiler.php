<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Editors\EditorLayout;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldPlacement;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\LayoutDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\SectionDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TabDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\Layout;
use Cbox\Cms\Generators\Schema\Domain\Dto\LayoutSection;
use Cbox\Cms\Generators\Schema\Domain\Dto\LayoutTab;
use Cbox\Cms\Generators\Schema\Domain\Dto\LocalizedText;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\LayoutWidth;

/**
 * Composes the layout of a type's form (gap G2) from the type's `layout` and the layout fragments
 * of the extension files that add to it, once BlueprintRules has held each to its file's fields:
 *
 * - A type without a `layout` gets the tab `content` with one section, `fields`, holding its
 *   top-level fields in file order at full width.
 * - A fragment's tab whose handle the layout has joins that tab, and in it a section whose handle
 *   the tab has gets the fragment's placements appended; every other tab or section is appended.
 *   The sidebar's sections merge the same way. A fragment places its own file's fields, as paths
 *   `ext.<namespace>.<handle>`.
 * - The fields of an extension file without a fragment land in the tab `extensions`, in a section
 *   named after the namespace, in file order; the tab is appended once, after the others, unless
 *   the layout has one.
 *
 * So every top-level field of the type is placed exactly once, which the editor descriptor holds
 * the layout to (TypeEditor).
 */
#[Internal]
final readonly class LayoutCompiler
{
    /**
     * @param  list<ExtensionBlueprint>  $extensions  the extension files that add to the type, sorted by namespace and then by file
     */
    public static function compile(TypeBlueprint $type, array $extensions): LayoutDescriptor
    {
        $layout = $type->layout instanceof Layout
            ? self::descriptor($type->layout, static fn (string $handle): string => $handle)
            : new LayoutDescriptor([self::defaultTab(array_map(static fn (FieldBlueprint $field): string => $field->handle->value, $type->fields))], []);

        $unplaced = [];

        foreach ($extensions as $extension) {
            $namespace = $extension->owner->value;
            $path = static fn (string $handle): string => 'ext.'.$namespace.'.'.$handle;

            if ($extension->layout instanceof Layout) {
                $fragment = self::descriptor($extension->layout, $path);
                $layout = $layout->merged($fragment->tabs, $fragment->sidebar);

                continue;
            }

            foreach ($extension->fields as $field) {
                $unplaced[$namespace][] = new FieldPlacement($path($field->handle->value), LayoutWidth::DEFAULT);
            }
        }

        if ($unplaced === []) {
            return $layout;
        }

        $sections = [];

        foreach ($unplaced as $namespace => $placements) {
            $sections[] = new SectionDescriptor(new Handle($namespace), LocalizedText::plain($namespace), null, false, $placements);
        }

        return $layout->merged([new TabDescriptor(new Handle(EditorLayout::EXTENSIONS_TAB), LocalizedText::plain(EditorLayout::EXTENSIONS_TAB_LABEL), $sections)], []);
    }

    /**
     * The layout of a descriptor compiled without one: the default tab with the owner's fields
     * in file order, and the tab `extensions` with each extender's fields.
     */
    public static function ofFields(TypeDescriptor $type): LayoutDescriptor
    {
        $layout = new LayoutDescriptor([self::defaultTab(array_map(static fn (FieldDescriptor $field): string => $field->handle->value, self::inFileOrder($type->ownFields())))], []);
        $sections = [];

        foreach ($type->extensionFields() as $namespace => $fields) {
            $sections[] = new SectionDescriptor(new Handle($namespace), LocalizedText::plain($namespace), null, false, array_map(
                static fn (FieldDescriptor $field): FieldPlacement => new FieldPlacement('ext.'.$namespace.'.'.$field->handle->value, LayoutWidth::DEFAULT),
                self::inFileOrder($fields),
            ));
        }

        return $sections === [] ? $layout : $layout->merged([new TabDescriptor(new Handle(EditorLayout::EXTENSIONS_TAB), LocalizedText::plain(EditorLayout::EXTENSIONS_TAB_LABEL), $sections)], []);
    }

    /**
     * @param  list<string>  $handles  in form order
     */
    private static function defaultTab(array $handles): TabDescriptor
    {
        return new TabDescriptor(new Handle(EditorLayout::DEFAULT_TAB), LocalizedText::plain(EditorLayout::DEFAULT_TAB_LABEL), [
            new SectionDescriptor(new Handle(EditorLayout::DEFAULT_SECTION), null, null, false, array_map(
                static fn (string $handle): FieldPlacement => new FieldPlacement($handle, LayoutWidth::DEFAULT),
                $handles,
            )),
        ]);
    }

    /**
     * @param  callable(string): string  $path  the path of a placed handle
     */
    private static function descriptor(Layout $layout, callable $path): LayoutDescriptor
    {
        return new LayoutDescriptor(
            array_map(static fn (LayoutTab $tab): TabDescriptor => new TabDescriptor($tab->handle, $tab->label, self::sections($tab->sections, $path)), $layout->tabs),
            self::sections($layout->sidebar, $path),
        );
    }

    /**
     * @param  list<LayoutSection>  $sections
     * @param  callable(string): string  $path
     * @return list<SectionDescriptor>
     */
    private static function sections(array $sections, callable $path): array
    {
        $descriptors = [];

        foreach ($sections as $section) {
            $placements = [];

            foreach ($section->fields as $field) {
                $placements[] = new FieldPlacement($path($field->field), $field->width() ?? LayoutWidth::DEFAULT);
            }

            $descriptors[] = new SectionDescriptor($section->handle, $section->label, $section->instructions, $section->collapsible, $placements);
        }

        return $descriptors;
    }

    /**
     * The fields in the order of their blueprint file: by the index of each field's place among
     * its siblings, the last `/fields/<n>` of its source location, then by handle.
     *
     * @param  list<FieldDescriptor>  $fields
     * @return list<FieldDescriptor>
     */
    public static function inFileOrder(array $fields): array
    {
        usort($fields, static function (FieldDescriptor $a, FieldDescriptor $b): int {
            $byIndex = self::index($a) <=> self::index($b);

            return $byIndex !== 0 ? $byIndex : strcmp($a->handle->value, $b->handle->value);
        });

        return $fields;
    }

    private static function index(FieldDescriptor $field): int
    {
        return preg_match('/\/fields\/([0-9]+)\z/', $field->location->pointer, $match) === 1 ? (int) $match[1] : PHP_INT_MAX;
    }
}
