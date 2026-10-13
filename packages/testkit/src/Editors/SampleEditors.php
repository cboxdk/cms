<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
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
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\Presence;

/**
 * Editor descriptors for tests of code that must work for any type (GUARDRAILS 2.4): sample types
 * of the owner `sample`, `sample:card_<n>`, each with fields of every classification and both
 * agents flags, a select, a group once and repeated, and an extension field of the extender
 * `probe` required on release, so a test of the filtering a reader gets has every case in one
 * type; a layout of two tabs and a sidebar, a label per locale, the hints of a select and of a
 * repeated group, and a form-only default, so a test of a form has every presentation key too.
 * A test hands them to the FakeTypeEditors.
 */
#[Experimental]
final readonly class SampleEditors
{
    /** The owner of the sample types. */
    public const string OWNER = 'sample';

    /** The extender of every sample type. */
    public const string EXTENDER = 'probe';

    /**
     * The paths of the fields of a sample type, in form order.
     *
     * @var list<string>
     */
    public const array PATHS = ['label', 'note', 'memo', 'diagnosis', 'mood', 'extras', 'extras.flag', 'extras.count', 'sizes', 'sizes.size', 'ext.probe.tag'];

    /**
     * The paths a reader at public access, not an agent, sees.
     *
     * @var list<string>
     */
    public const array PUBLIC_PATHS = ['label', 'mood', 'sizes', 'sizes.size', 'ext.probe.tag'];

    /**
     * The paths an agent at its highest access, confidential, sees.
     *
     * @var list<string>
     */
    public const array AGENT_PATHS = ['label', 'memo', 'mood', 'extras', 'extras.count', 'sizes', 'sizes.size', 'ext.probe.tag'];

    /**
     * The paths of a sample type as its layout places them: the tab `main`, the tab `more` and
     * then the sidebar.
     *
     * @var list<string>
     */
    public const array PLACED_PATHS = ['label', 'note', 'memo', 'diagnosis', 'mood', 'extras', 'sizes', 'ext.probe.tag'];

    private const int FIRST_MILLISECOND = 1_700_000_000_000;

    /**
     * The sample types numbered 1 to $count.
     *
     * @return list<TypeEditor>
     */
    public static function many(int $count): array
    {
        return array_map(self::type(...), range(1, $count));
    }

    /**
     * The sample type numbered $number: `sample:card_<number>`, with an id of its own.
     */
    public static function type(int $number): TypeEditor
    {
        $probe = new FieldNamespace(self::EXTENDER);

        $da = new Locale('da');

        return new TypeEditor(
            new TypeId(Uuid7::lowestAt(self::FIRST_MILLISECOND + $number)),
            new TypeName(self::OWNER.':card_'.$number),
            'Card '.$number,
            'A sample card for tests.',
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, true),
            'label',
            [new ExtensionVersion($probe, 1)],
            [
                self::field('label', null, null, EditorBase::Text, Presence::Required, ClassificationAccess::Public, true, '{"kind":"text","minLength":1,"maxLength":120}', labels: [new EditorText($da, 'Etiket')], ui: new EditorUi(inputHint: 'A short name', inputHintByLocale: [new EditorText($da, 'Et kort navn')]), defaultValue: new JsonDocument('{"kind":"constant","value":"Untitled"}')),
                self::field('note', null, null, EditorBase::LongText, Presence::Optional, ClassificationAccess::Internal, false, '{"kind":"text","maxLength":2000}'),
                self::field('memo', null, null, EditorBase::Text, Presence::Optional, ClassificationAccess::Confidential, true, '{"kind":"text","maxLength":255}', encrypted: true),
                self::field('diagnosis', null, null, EditorBase::Text, Presence::Optional, ClassificationAccess::Personal, false, '{"kind":"text","maxLength":255}', ui: new EditorUi(readOnly: true)),
                self::field('mood', null, null, EditorBase::Select, Presence::Optional, ClassificationAccess::Public, true, '{"kind":"choice","choices":["calm","busy"]}', choices: [
                    new EditorChoice(new FieldHandle('calm'), 'Calm', [new EditorText($da, 'Rolig')]),
                    new EditorChoice(new FieldHandle('busy'), 'Busy'),
                ], ui: new EditorUi(instructions: 'Pick the mood of the card.', instructionsByLocale: [new EditorText($da, 'Vælg kortets stemning.')], display: SelectDisplay::Radio), defaultValue: new JsonDocument('{"kind":"constant","value":"calm"}')),
                self::field('extras', null, null, EditorBase::Group, Presence::Optional, ClassificationAccess::Internal, true, null, ui: new EditorUi(hidden: true)),
                self::field('extras.flag', null, 'extras', EditorBase::Boolean, Presence::Optional, ClassificationAccess::Internal, false, '{"kind":"boolean"}', defaultValue: new JsonDocument('{"kind":"constant","value":false}')),
                self::field('extras.count', null, 'extras', EditorBase::Integer, Presence::Required, ClassificationAccess::Internal, true, '{"kind":"integer","min":0}'),
                self::field('sizes', null, null, EditorBase::Group, Presence::Optional, ClassificationAccess::Public, true, null, repeat: new EditorRepeat(1, 5), ui: new EditorUi(summary: '{size}')),
                self::field('sizes.size', null, 'sizes', EditorBase::Select, Presence::Required, ClassificationAccess::Public, true, '{"kind":"choice","choices":["small","large"]}', choices: [
                    new EditorChoice(new FieldHandle('small'), 'Small'),
                    new EditorChoice(new FieldHandle('large'), 'Large'),
                ]),
                self::field('ext.probe.tag', $probe, null, EditorBase::Text, Presence::RequiredOnRelease, ClassificationAccess::Public, true, '{"kind":"text","maxLength":40}'),
            ],
            new EditorLayout(
                [
                    new EditorTab(new FieldHandle('main'), 'Main', [new EditorText($da, 'Hoved')], [
                        new EditorSection(new FieldHandle('names'), null, [], null, [], false, [
                            new EditorPlacement('label', EditorWidth::Half),
                            new EditorPlacement('note', EditorWidth::Half),
                            new EditorPlacement('memo', EditorWidth::Full),
                        ]),
                        new EditorSection(new FieldHandle('health'), 'Health', [new EditorText($da, 'Helbred')], 'Only staff see these.', [new EditorText($da, 'Kun ansatte ser disse.')], true, [
                            new EditorPlacement('diagnosis', EditorWidth::Full),
                            new EditorPlacement('mood', EditorWidth::Third),
                        ]),
                    ]),
                    new EditorTab(new FieldHandle('more'), 'More', [], [
                        new EditorSection(new FieldHandle('groups'), null, [], null, [], false, [
                            new EditorPlacement('extras', EditorWidth::Full),
                            new EditorPlacement('sizes', EditorWidth::Full),
                        ]),
                    ]),
                ],
                [
                    new EditorSection(new FieldHandle('probe'), 'Probe', [], null, [], false, [new EditorPlacement('ext.probe.tag', EditorWidth::Full)]),
                ],
            ),
            [new EditorText($da, 'Kort '.$number)],
            [new EditorText($da, 'Et eksempelkort til tests.')],
        );
    }

    /**
     * @param  list<EditorChoice>  $choices
     * @param  list<EditorText>  $labels
     */
    private static function field(string $path, ?FieldNamespace $namespace, ?string $parent, EditorBase $base, Presence $presence, ClassificationAccess $classification, bool $agents, ?string $rule, bool $encrypted = false, array $choices = [], ?EditorRepeat $repeat = null, array $labels = [], ?EditorUi $ui = null, ?JsonDocument $defaultValue = null): EditorField
    {
        $segments = explode('.', $path);
        $handle = $segments[count($segments) - 1];

        return new EditorField(
            path: $path,
            handle: new FieldHandle($handle),
            namespace: $namespace,
            parent: $parent,
            fieldType: $base->value,
            base: $base,
            label: ucfirst(str_replace('_', ' ', $handle)),
            description: 'The '.$handle.' of the card.',
            presence: $presence,
            classification: $classification,
            agents: $agents,
            encrypted: $encrypted,
            choices: $choices,
            repeat: $repeat,
            rule: $rule === null ? null : new JsonDocument($rule),
            labels: $labels,
            ui: $ui ?? new EditorUi,
            defaultValue: $defaultValue,
        );
    }
}
