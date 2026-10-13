<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;

/**
 * The layout of a type's form (PRD 14.1, gap G2): its tabs, each tab handle once, and the sections
 * of its sidebar, each section handle once there. Every top-level field of the type is placed in
 * exactly one section, which TypeEditor holds the layout to; the fields below a group are never
 * placed, because the group's input holds them.
 *
 * The layout is presentation only: it reaches no record codec, no validator and no table. A type
 * whose blueprint declares none gets one tab of one section with its fields in blueprint order
 * (EditorLayout::ofPaths()), and the fields an extender places with no fragment land in a tab
 * named `extensions` with a section per namespace.
 */
#[Experimental]
final readonly class EditorLayout
{
    /** The handle and label of the tab a type without a layout gets. */
    public const string DEFAULT_TAB = 'content';

    public const string DEFAULT_TAB_LABEL = 'Content';

    /** The handle of the section a type without a layout gets. */
    public const string DEFAULT_SECTION = 'fields';

    /** The handle and label of the tab the unplaced fields of extenders land in. */
    public const string EXTENSIONS_TAB = 'extensions';

    public const string EXTENSIONS_TAB_LABEL = 'Extensions';

    /**
     * @param  list<EditorTab>  $tabs  in form order, each handle once
     * @param  list<EditorSection>  $sidebar  in form order, each handle once
     *
     * @throws InvalidTypeEditor when a tab or a sidebar section handle appears twice
     */
    public function __construct(
        public array $tabs,
        public array $sidebar,
    ) {
        $handles = [];

        foreach ($tabs as $tab) {
            if (isset($handles[$tab->handle->value])) {
                throw InvalidTypeEditor::duplicateTab($tab->handle->value);
            }

            $handles[$tab->handle->value] = true;
        }

        self::assertSections($sidebar, 'the sidebar');
    }

    /**
     * The layout of a type without one: one tab of one section with the paths in the order given,
     * each at full width, and no sidebar.
     *
     * @param  list<string>  $paths
     */
    public static function ofPaths(array $paths): self
    {
        $placements = array_map(static fn (string $path): EditorPlacement => new EditorPlacement($path, EditorWidth::Full), $paths);

        return new self(
            [new EditorTab(new FieldHandle(self::DEFAULT_TAB), self::DEFAULT_TAB_LABEL, [], [new EditorSection(new FieldHandle(self::DEFAULT_SECTION), null, [], null, [], false, $placements)])],
            [],
        );
    }

    /**
     * Every path the layout places, tabs first and then the sidebar, in form order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->tabs as $tab) {
            array_push($paths, ...$tab->paths());
        }

        foreach ($this->sidebar as $section) {
            array_push($paths, ...$section->paths());
        }

        return $paths;
    }

    /**
     * The layout with only the placements of the paths given, without the sections and tabs that
     * are left empty.
     *
     * @param  array<string, true>  $paths
     */
    public function keeping(array $paths): self
    {
        $tabs = [];

        foreach ($this->tabs as $tab) {
            $kept = $tab->keeping($paths);

            if ($kept instanceof EditorTab) {
                $tabs[] = $kept;
            }
        }

        return new self($tabs, self::keepSections($this->sidebar, $paths));
    }

    /**
     * @param  list<EditorSection>  $sections
     *
     * @throws InvalidTypeEditor when a section handle appears twice
     */
    public static function assertSections(array $sections, string $where): void
    {
        $handles = [];

        foreach ($sections as $section) {
            if (isset($handles[$section->handle->value])) {
                throw InvalidTypeEditor::duplicateSection($where, $section->handle->value);
            }

            $handles[$section->handle->value] = true;
        }
    }

    /**
     * @param  list<EditorSection>  $sections
     * @param  array<string, true>  $paths
     * @return list<EditorSection>
     */
    public static function keepSections(array $sections, array $paths): array
    {
        $kept = [];

        foreach ($sections as $section) {
            $section = $section->keeping($paths);

            if ($section instanceof EditorSection) {
                $kept[] = $section;
            }
        }

        return $kept;
    }
}
