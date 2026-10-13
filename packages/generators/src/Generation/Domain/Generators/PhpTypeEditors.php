<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Editors\EditorBase;
use Cbox\Cms\Contracts\Editors\EditorChoice;
use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\EditorLayout;
use Cbox\Cms\Contracts\Editors\EditorPlacement;
use Cbox\Cms\Contracts\Editors\EditorRepeat;
use Cbox\Cms\Contracts\Editors\EditorSection;
use Cbox\Cms\Contracts\Editors\EditorTab;
use Cbox\Cms\Contracts\Editors\EditorText;
use Cbox\Cms\Contracts\Editors\EditorUi;
use Cbox\Cms\Contracts\Editors\EditorWidth;
use Cbox\Cms\Contracts\Editors\SelectDisplay;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldFallback;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\TypeEditors\Domain\TypeEditorCompiler;
use Override;

/**
 * The type editor descriptors of the type chain (PRD 11.12, 14.1, GUARDRAILS 2.4), in the PHP
 * directory: `GeneratedTypeEditors`, the implementation of Cbox\Cms\Contracts\Editors\TypeEditors
 * with a TypeEditor per type, sorted by name, which the generated service provider binds
 * (PhpTypeCatalog). The panel's forms render a type from it, through the queries type.list and
 * type.describe, and a form validates with the rule each leaf field carries, the ValueRule of the
 * record's TypeScript module, as one JSON document (TypeEditorCompiler, RuleJson). Each type's
 * layout, the labels per locale, the hints of each field and its form-only default come with it
 * (gap G2); an argument that has its default is left out, so a field without hints stays short.
 *
 * The output is formatted the way Pint, Rector and PHPStan level 10 accept it unchanged, and it
 * uses only the public API of cboxdk/cms.
 */
#[Internal]
final readonly class PhpTypeEditors implements Generator
{
    public const string EDITORS = 'GeneratedTypeEditors';

    /**
     * What the descriptor holds for each core field type of the blueprint schema v1: its base, by
     * which a form picks its input, and its rule. A field type without a mapping here is refused as
     * invalid output. The generator-coverage test holds the keys to the field types of the
     * installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = [
        'boolean' => 'the base boolean and a boolean rule',
        'date' => 'the base date and a date rule with its bounds',
        'datetime' => 'the base datetime and a datetime rule with its bounds',
        'decimal' => 'the base decimal and a decimal rule with its precision, scale and bounds',
        'group' => 'the base group, its repeat and no rule; the fields below it follow it',
        'integer' => 'the base integer and an integer rule with its bounds',
        'long_text' => 'the base long_text and a text rule with its lengths',
        'rich_text' => 'the base rich_text and a portable_text rule with its styles, marks, lists and links',
        'select' => 'the base select, its choices with their labels, and a choice rule, or a list of one with multiple',
        'text' => 'the base text and a text rule with its lengths and format',
    ];

    /**
     * What the descriptor holds for a blueprint file of each kind. The generator-coverage test
     * holds the keys to the kinds of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'extension' => 'its version among the extensions of the type it extends, its fields after the owner\'s under ext.<namespace>, with required_on_release presence where the blueprint requires them, and its layout fragment composed into the type\'s layout, or its fields in the tab extensions',
        'type' => 'a TypeEditor with the type\'s id, name, label, description, version, capabilities, title field, fields in form order, layout and labels per locale',
    ];

    public function __construct(private TypeEditorCompiler $compiler) {}

    #[Override]
    public function directory(GenerationTarget $target): string
    {
        return $target->phpDirectory;
    }

    /**
     * @throws GenerationFailed
     */
    #[Override]
    public function generate(CompiledSchema $schema, GenerationTarget $target): array
    {
        PhpTypeHandleEnum::assertCaseNames($schema);

        $types = $schema->types;
        usort($types, static fn (TypeDescriptor $one, TypeDescriptor $other): int => strcmp($one->name(), $other->name()));
        $editors = [];

        foreach ($types as $type) {
            $editors = [...$editors, ...$this->editor($this->compiler->compile($type))];
        }

        // PhpSource::file() keeps the imports the file names.
        return [
            PhpSource::file($target->phpDirectory.'/'.self::EDITORS.'.php', $target->phpNamespace, [
                ClassificationAccess::class,
                EditorBase::class,
                EditorChoice::class,
                EditorField::class,
                EditorLayout::class,
                EditorPlacement::class,
                EditorRepeat::class,
                EditorSection::class,
                EditorTab::class,
                EditorText::class,
                EditorUi::class,
                EditorWidth::class,
                ExtensionVersion::class,
                FieldFallback::class,
                FieldHandle::class,
                FieldNamespace::class,
                History::class,
                JsonDocument::class,
                Locale::class,
                Localization::class,
                Override::class,
                Presence::class,
                SelectDisplay::class,
                Stages::class,
                TypeCapabilities::class,
                TypeEditor::class,
                TypeEditors::class,
                TypeId::class,
                TypeName::class,
            ], [
                '/**',
                ' * The editor descriptors of the schema roots\' types, as the panel\'s forms render them at run time',
                ' * through the TypeEditors contract (PRD 11.12, 14.1): each type\'s name, labels, capabilities, title',
                ' * field, layout and fields in form order, each field with its rule in the form of the validation',
                ' * runtime, the hints its form takes and its form-only default.',
                ' *',
                ...array_map(static fn (string $line): string => ' * '.$line, PhpSource::NOTICE),
                ' *',
                ' * Schema roots, by owner:',
                ...GeneratedLines::schemaRoots($target, ' *   '),
                ' */',
                'final readonly class '.self::EDITORS.' implements TypeEditors',
                '{',
                '    /** @var list<TypeEditor> */',
                '    private array $editors;',
                '',
                '    public function __construct()',
                '    {',
                ...($editors === [] ? ['        $this->editors = [];'] : ['        $this->editors = [', ...$editors, '        ];']),
                '    }',
                '',
                '    #[Override]',
                '    public function all(): array',
                '    {',
                '        return $this->editors;',
                '    }',
                '',
                '    #[Override]',
                '    public function find(TypeId $id): ?TypeEditor',
                '    {',
                '        return array_find($this->editors, static fn (TypeEditor $editor): bool => $editor->id->equals($id));',
                '    }',
                '',
                '    #[Override]',
                '    public function named(TypeName $name): ?TypeEditor',
                '    {',
                '        return array_find($this->editors, static fn (TypeEditor $editor): bool => $editor->name->equals($name));',
                '    }',
                '}',
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    private function editor(TypeEditor $editor): array
    {
        $extensions = [];

        foreach ($editor->extensions as $extension) {
            $extensions[] = sprintf('                    new ExtensionVersion(new FieldNamespace(%s), %d),', PhpSource::literal($extension->namespace->value), $extension->version);
        }

        $fields = [];

        foreach ($editor->fields as $field) {
            $fields = [...$fields, ...$this->field($field)];
        }

        return [
            '            new TypeEditor(',
            sprintf('                TypeId::fromString(%s),', PhpSource::literal($editor->id->toString())),
            sprintf('                new TypeName(%s),', PhpSource::literal($editor->name->value)),
            sprintf('                %s,', PhpSource::literal($editor->label)),
            sprintf('                %s,', $editor->description === null ? 'null' : PhpSource::literal($editor->description)),
            sprintf('                %d,', $editor->version),
            sprintf(
                '                new TypeCapabilities(History::%s, Stages::%s, Localization::%s, %s),',
                $editor->capabilities->history->name,
                $editor->capabilities->stages->name,
                $editor->capabilities->localization->name,
                $editor->capabilities->routable ? 'true' : 'false',
            ),
            sprintf('                %s,', $editor->titleField === null ? 'null' : PhpSource::literal($editor->titleField)),
            ...($extensions === [] ? ['                [],'] : ['                [', ...$extensions, '                ],']),
            ...($fields === [] ? ['                [],'] : ['                [', ...$fields, '                ],']),
            ...$this->layout($editor->layout, '                '),
            ...($editor->labels === [] && $editor->descriptions === [] ? [] : $this->texts($editor->labels, '                ')),
            ...($editor->descriptions === [] ? [] : $this->texts($editor->descriptions, '                ')),
            '            ),',
        ];
    }

    /**
     * The layout as a `new EditorLayout(...)` argument at the indent, ending with a comma.
     *
     * @return list<string>
     */
    private function layout(EditorLayout $layout, string $indent): array
    {
        $tabs = [];

        foreach ($layout->tabs as $tab) {
            $tabs = [
                ...$tabs,
                sprintf('%s        new EditorTab(', $indent),
                sprintf('%s            new FieldHandle(%s),', $indent, PhpSource::literal($tab->handle->value)),
                sprintf('%s            %s,', $indent, PhpSource::literal($tab->label)),
                ...$this->texts($tab->labels, $indent.'            '),
                ...$this->sections($tab->sections, $indent.'            '),
                sprintf('%s        ),', $indent),
            ];
        }

        return [
            $indent.'new EditorLayout(',
            ...($tabs === [] ? [$indent.'    [],'] : [$indent.'    [', ...$tabs, $indent.'    ],']),
            ...$this->sections($layout->sidebar, $indent.'    '),
            $indent.'),',
        ];
    }

    /**
     * A list of sections as an argument at the indent, ending with a comma.
     *
     * @param  list<EditorSection>  $sections
     * @return list<string>
     */
    private function sections(array $sections, string $indent): array
    {
        if ($sections === []) {
            return [$indent.'[],'];
        }

        $lines = [$indent.'['];

        foreach ($sections as $section) {
            $placements = [];

            foreach ($section->fields as $placement) {
                $placements[] = sprintf('%s            new EditorPlacement(%s, EditorWidth::%s),', $indent, PhpSource::literal($placement->path), $placement->width->name);
            }

            $lines = [
                ...$lines,
                sprintf('%s    new EditorSection(', $indent),
                sprintf('%s        new FieldHandle(%s),', $indent, PhpSource::literal($section->handle->value)),
                sprintf('%s        %s,', $indent, $section->label === null ? 'null' : PhpSource::literal($section->label)),
                ...$this->texts($section->labels, $indent.'        '),
                sprintf('%s        %s,', $indent, $section->instructions === null ? 'null' : PhpSource::literal($section->instructions)),
                ...$this->texts($section->instructionsByLocale, $indent.'        '),
                sprintf('%s        %s,', $indent, $section->collapsible ? 'true' : 'false'),
                ...($placements === [] ? [$indent.'        [],'] : [$indent.'        [', ...$placements, $indent.'        ],']),
                sprintf('%s    ),', $indent),
            ];
        }

        return [...$lines, $indent.'],'];
    }

    /**
     * A list of texts per locale as an argument at the indent, ending with a comma; `$name: ` is
     * written before it for a named argument.
     *
     * @param  list<EditorText>  $texts
     * @return list<string>
     */
    private function texts(array $texts, string $indent, string $name = ''): array
    {
        $prefix = $name === '' ? '' : $name.': ';

        if ($texts === []) {
            return [$indent.$prefix.'[],'];
        }

        return [
            $indent.$prefix.'[',
            ...array_map(static fn (EditorText $text): string => sprintf('%s    new EditorText(new Locale(%s), %s),', $indent, PhpSource::literal($text->locale->value), PhpSource::literal($text->text)), $texts),
            $indent.'],',
        ];
    }

    /**
     * The `ui:` argument of a field with hints, at the indent of the field's arguments.
     *
     * @return list<string>
     */
    private function ui(EditorUi $ui, string $indent): array
    {
        $lines = [];

        if ($ui->instructions !== null) {
            $lines[] = $indent.'    instructions: '.PhpSource::literal($ui->instructions).',';
        }

        if ($ui->instructionsByLocale !== []) {
            $lines = [...$lines, ...$this->texts($ui->instructionsByLocale, $indent.'    ', 'instructionsByLocale')];
        }

        if ($ui->inputHint !== null) {
            $lines[] = $indent.'    inputHint: '.PhpSource::literal($ui->inputHint).',';
        }

        if ($ui->inputHintByLocale !== []) {
            $lines = [...$lines, ...$this->texts($ui->inputHintByLocale, $indent.'    ', 'inputHintByLocale')];
        }

        if ($ui->readOnly) {
            $lines[] = $indent.'    readOnly: true,';
        }

        if ($ui->hidden) {
            $lines[] = $indent.'    hidden: true,';
        }

        if ($ui->display instanceof SelectDisplay) {
            $lines[] = $indent.'    display: SelectDisplay::'.$ui->display->name.',';
        }

        if ($ui->summary !== null) {
            $lines[] = $indent.'    summary: '.PhpSource::literal($ui->summary).',';
        }

        return [$indent.'ui: new EditorUi(', ...$lines, $indent.'),'];
    }

    /**
     * @return list<string>
     */
    private function field(EditorField $field): array
    {
        $indent = '                    ';
        $flag = static fn (bool $value): string => $value ? 'true' : 'false';
        $choices = [];

        foreach ($field->choices as $choice) {
            $choices = [
                ...$choices,
                ...($choice->labels === []
                    ? [sprintf('%s        new EditorChoice(new FieldHandle(%s), %s),', $indent, PhpSource::literal($choice->value->value), PhpSource::literal($choice->label))]
                    : [
                        sprintf('%s        new EditorChoice(new FieldHandle(%s), %s, [', $indent, PhpSource::literal($choice->value->value), PhpSource::literal($choice->label)),
                        ...array_map(static fn (EditorText $text): string => sprintf('%s            new EditorText(new Locale(%s), %s),', $indent, PhpSource::literal($text->locale->value), PhpSource::literal($text->text)), $choice->labels),
                        sprintf('%s        ]),', $indent),
                    ]),
            ];
        }

        return [
            $indent.'new EditorField(',
            $indent.'    path: '.PhpSource::literal($field->path).',',
            $indent.'    handle: new FieldHandle('.PhpSource::literal($field->handle->value).'),',
            $indent.'    namespace: '.($field->namespace instanceof FieldNamespace ? 'new FieldNamespace('.PhpSource::literal($field->namespace->value).')' : 'null').',',
            $indent.'    parent: '.($field->parent === null ? 'null' : PhpSource::literal($field->parent)).',',
            $indent.'    fieldType: '.PhpSource::literal($field->fieldType).',',
            $indent.'    base: EditorBase::'.$field->base->name.',',
            $indent.'    label: '.PhpSource::literal($field->label).',',
            $indent.'    description: '.($field->description === null ? 'null' : PhpSource::literal($field->description)).',',
            $indent.'    presence: Presence::'.$field->presence->name.',',
            $indent.'    classification: ClassificationAccess::'.$field->classification->name.',',
            $indent.'    agents: '.$flag($field->agents).',',
            $indent.'    encrypted: '.$flag($field->encrypted).',',
            ...($choices === [] ? [$indent.'    choices: [],'] : [$indent.'    choices: [', ...$choices, $indent.'    ],']),
            $indent.'    repeat: '.($field->repeat instanceof EditorRepeat ? sprintf('new EditorRepeat(%s, %s)', $field->repeat->minItems ?? 'null', $field->repeat->maxItems ?? 'null') : 'null').',',
            $indent.'    rule: '.($field->rule instanceof JsonDocument ? 'new JsonDocument('.PhpSource::literal($field->rule->value).')' : 'null').',',
            ...($field->translatable ? [$indent.'    translatable: true,'] : []),
            ...($field->fallback->value === FieldFallback::Variant->value ? [] : [$indent.'    fallback: FieldFallback::'.FieldFallback::from($field->fallback->value)->name.',']),
            ...($field->labels === [] ? [] : $this->texts($field->labels, $indent.'    ', 'labels')),
            ...($field->descriptions === [] ? [] : $this->texts($field->descriptions, $indent.'    ', 'descriptions')),
            ...($field->ui->isEmpty() ? [] : $this->ui($field->ui, $indent.'    ')),
            ...($field->defaultValue instanceof JsonDocument ? [$indent.'    defaultValue: new JsonDocument('.PhpSource::literal($field->defaultValue->value).'),'] : []),
            $indent.'),',
        ];
    }
}
