<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Schema;

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
 * Types built by hand for the tests of the fake catalog: a note of the module acme with a field of
 * its own, a confidential group and a field the app adds, and a note of the app with the same
 * handle, so two types share a handle and differ by owner (PRD 11.12).
 */
final class SampleTypes
{
    public const string NOTE_ID = '0198d2a4-5c3e-7a41-9b2f-000000000001';

    public const string APP_NOTE_ID = '0198d2a4-5c3e-7a41-9b2f-000000000002';

    public static function note(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::NOTE_ID),
            new TypeName('acme:note'),
            2,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, true),
            [new ExtensionVersion(new FieldNamespace('app'), 1)],
            [
                self::text(null, 'title', required: true),
                new FieldDefinition(
                    namespace: null,
                    handle: new FieldHandle('author'),
                    fieldType: 'group',
                    classification: ClassificationAccess::Confidential,
                    agents: false,
                    encrypted: true,
                    required: false,
                    filterable: false,
                    sortable: false,
                    column: new ColumnDefinition('author', 'bytea', false, []),
                    fields: [
                        new FieldDefinition(null, new FieldHandle('name'), 'text', ClassificationAccess::Confidential, false, true, true, false, false, null),
                    ],
                ),
                self::text(new FieldNamespace('app'), 'title'),
            ],
        );
    }

    public static function appNote(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::APP_NOTE_ID),
            new TypeName('app:note'),
            1,
            new TypeCapabilities(History::None, Stages::None, Localization::None, false),
            [],
            [self::text(null, 'body')],
        );
    }

    public static function text(?FieldNamespace $namespace, string $handle, bool $required = false): FieldDefinition
    {
        $column = $namespace instanceof FieldNamespace ? 'ext__'.$namespace->value.'__'.$handle : $handle;

        return new FieldDefinition(
            namespace: $namespace,
            handle: new FieldHandle($handle),
            fieldType: 'text',
            classification: ClassificationAccess::Public,
            agents: true,
            encrypted: false,
            required: $required,
            filterable: false,
            sortable: false,
            column: new ColumnDefinition($column, 'text', $required && ! $namespace instanceof FieldNamespace, ['char_length("'.$column.'") <= 255']),
        );
    }
}
