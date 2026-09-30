<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * The type of the cards an agent reads, test:agent_card, with a field for each way the
 * classification and `agents` decide whether an agent sees it (PRD 2.31, 12.2):
 *
 * - label, public, and note, internal, which agents see because nothing closes them;
 * - aside, internal with `agents: false`;
 * - memo, confidential without `agents`, and brief, confidential with `agents: true`;
 * - contact, personal, and diagnosis, sensitive, which agents never see;
 * - sources, an internal repeated group agents see, whose title they see and whose url says
 *   `agents: false`;
 * - from the extender `probe`, tag, public, and code, internal with `agents: false`.
 *
 * VISIBLE is what an agent whose access is confidential sees of a card.
 */
final readonly class AgentCardType
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000000d8';

    public const string EXTENDER = 'probe';

    public const string NODE = '01936f5e-8a2b-7c3d-9e4f-0000000000a2';

    /** @var list<string> the fields of a card an agent with confidential access sees */
    public const array VISIBLE = ['brief', 'label', 'note', 'sources', 'ext.probe.tag'];

    /** @var list<string> the fields of a card an agent never sees, whatever its access */
    public const array HIDDEN = ['aside', 'contact', 'diagnosis', 'memo', 'ext.probe.code'];

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:agent_card'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            [new ExtensionVersion(new FieldNamespace(self::EXTENDER), 1)],
            [
                self::field(null, 'label', ClassificationAccess::Public, true),
                self::field(null, 'note', ClassificationAccess::Internal, true),
                self::field(null, 'aside', ClassificationAccess::Internal, false),
                self::field(null, 'memo', ClassificationAccess::Confidential, false),
                self::field(null, 'brief', ClassificationAccess::Confidential, true),
                self::field(null, 'contact', ClassificationAccess::Personal, false),
                self::field(null, 'diagnosis', ClassificationAccess::Sensitive, false),
                self::field(null, 'sources', ClassificationAccess::Internal, true, 'group', [
                    self::nested('title', ClassificationAccess::Internal, true),
                    self::nested('url', ClassificationAccess::Internal, false),
                ]),
                self::field(self::EXTENDER, 'tag', ClassificationAccess::Public, true),
                self::field(self::EXTENDER, 'code', ClassificationAccess::Internal, false),
            ],
        );
    }

    /**
     * A card of test:agent_card with a value for every field of the type, two sources, and the
     * undeclared field stray.
     */
    public static function card(string $entry): ReadContent
    {
        $own = array_map(
            static fn (string $handle): NamedValue => new NamedValue(new FieldHandle($handle), new TextValue($handle.' of '.$entry)),
            ['label', 'note', 'aside', 'memo', 'brief', 'contact', 'diagnosis', 'stray'],
        );
        $own[] = new NamedValue(new FieldHandle('sources'), new ListValue(self::source('first'), self::source('second')));

        return new ReadContent(
            EntryId::fromString($entry),
            NodeId::fromString(self::NODE),
            TypeId::fromString(self::ID),
            new FieldValues(
                new FieldMap(...$own),
                new ExtensionFields(new FieldNamespace(self::EXTENDER), new FieldMap(
                    new NamedValue(new FieldHandle('tag'), new TextValue('tag')),
                    new NamedValue(new FieldHandle('code'), new TextValue('code')),
                )),
            ),
        );
    }

    /**
     * One source of a card: its title and its url.
     */
    public static function source(string $name): GroupValue
    {
        return new GroupValue(new FieldMap(
            new NamedValue(new FieldHandle('title'), new TextValue($name.' title')),
            new NamedValue(new FieldHandle('url'), new TextValue('https://example.com/'.$name)),
        ));
    }

    /**
     * @param  list<FieldDefinition>  $fields
     */
    private static function field(?string $namespace, string $handle, ClassificationAccess $classification, bool $agents, string $type = 'text', array $fields = []): FieldDefinition
    {
        $column = $namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle;

        return new FieldDefinition(
            $namespace === null ? null : new FieldNamespace($namespace),
            new FieldHandle($handle),
            $type,
            $classification,
            $agents,
            false,
            false,
            false,
            false,
            new ColumnDefinition($column, $type === 'group' ? 'jsonb' : 'text', false, []),
            $fields,
        );
    }

    private static function nested(string $handle, ClassificationAccess $classification, bool $agents): FieldDefinition
    {
        return new FieldDefinition(null, new FieldHandle($handle), 'text', $classification, $agents, false, false, false, false, null);
    }
}
