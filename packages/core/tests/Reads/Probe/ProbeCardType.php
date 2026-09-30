<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
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
 * The type of the probe's cards, test:card: a public label, an internal note, a confidential memo,
 * a sensitive diagnosis, and from the extender `probe` a public tag and a sensitive code. Each
 * field is open to agents as a blueprint without `agents` makes it (PRD 2.31): the public and
 * internal fields are, the others are not.
 */
final readonly class ProbeCardType
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000000d7';

    public const string EXTENDER = 'probe';

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:card'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            [new ExtensionVersion(new FieldNamespace(self::EXTENDER), 1)],
            [
                self::field(null, 'label', ClassificationAccess::Public),
                self::field(null, 'note', ClassificationAccess::Internal),
                self::field(null, 'memo', ClassificationAccess::Confidential),
                self::field(null, 'diagnosis', ClassificationAccess::Sensitive),
                self::field(self::EXTENDER, 'tag', ClassificationAccess::Public),
                self::field(self::EXTENDER, 'code', ClassificationAccess::Sensitive),
            ],
        );
    }

    private static function field(?string $namespace, string $handle, ClassificationAccess $classification): FieldDefinition
    {
        $column = $namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle;

        return new FieldDefinition(
            $namespace === null ? null : new FieldNamespace($namespace),
            new FieldHandle($handle),
            'text',
            $classification,
            $classification === ClassificationAccess::Public || $classification === ClassificationAccess::Internal,
            false,
            false,
            false,
            false,
            new ColumnDefinition($column, 'text', true, []),
        );
    }
}
