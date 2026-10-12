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
 * type. A test hands them to the FakeTypeEditors.
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
                self::field('label', null, null, EditorBase::Text, Presence::Required, ClassificationAccess::Public, true, '{"kind":"text","minLength":1,"maxLength":120}'),
                self::field('note', null, null, EditorBase::LongText, Presence::Optional, ClassificationAccess::Internal, false, '{"kind":"text","maxLength":2000}'),
                self::field('memo', null, null, EditorBase::Text, Presence::Optional, ClassificationAccess::Confidential, true, '{"kind":"text","maxLength":255}', encrypted: true),
                self::field('diagnosis', null, null, EditorBase::Text, Presence::Optional, ClassificationAccess::Personal, false, '{"kind":"text","maxLength":255}'),
                self::field('mood', null, null, EditorBase::Select, Presence::Optional, ClassificationAccess::Public, true, '{"kind":"choice","choices":["calm","busy"]}', choices: [
                    new EditorChoice(new FieldHandle('calm'), 'Calm'),
                    new EditorChoice(new FieldHandle('busy'), 'Busy'),
                ]),
                self::field('extras', null, null, EditorBase::Group, Presence::Optional, ClassificationAccess::Internal, true, null),
                self::field('extras.flag', null, 'extras', EditorBase::Boolean, Presence::Optional, ClassificationAccess::Internal, false, '{"kind":"boolean"}'),
                self::field('extras.count', null, 'extras', EditorBase::Integer, Presence::Required, ClassificationAccess::Internal, true, '{"kind":"integer","min":0}'),
                self::field('sizes', null, null, EditorBase::Group, Presence::Optional, ClassificationAccess::Public, true, null, repeat: new EditorRepeat(1, 5)),
                self::field('sizes.size', null, 'sizes', EditorBase::Select, Presence::Required, ClassificationAccess::Public, true, '{"kind":"choice","choices":["small","large"]}', choices: [
                    new EditorChoice(new FieldHandle('small'), 'Small'),
                    new EditorChoice(new FieldHandle('large'), 'Large'),
                ]),
                self::field('ext.probe.tag', $probe, null, EditorBase::Text, Presence::RequiredOnRelease, ClassificationAccess::Public, true, '{"kind":"text","maxLength":40}'),
            ],
        );
    }

    /**
     * @param  list<EditorChoice>  $choices
     */
    private static function field(string $path, ?FieldNamespace $namespace, ?string $parent, EditorBase $base, Presence $presence, ClassificationAccess $classification, bool $agents, ?string $rule, bool $encrypted = false, array $choices = [], ?EditorRepeat $repeat = null): EditorField
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
        );
    }
}
