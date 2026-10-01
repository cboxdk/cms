<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;

/**
 * The fields of a read as the principal may see them (PRD 6.2, 12.2), with each field's
 * classification from the TypeCatalog, and the fields among them that require the read audit
 * (PRD 12.12).
 *
 * strip() leaves out every field classified above the classification access, and every field the
 * entry's type does not declare, by the type's TypeDefinition::readable(), the one rule every
 * reader of fields applies, the TypeTableReader's included; an entry of a type the installation
 * does not have keeps no field: what the kernel cannot classify, it does not hand out.
 *
 * For an agent it also leaves out every field whose definition does not open it to agents
 * (FieldDefinition::$agents, PRD 2.31, 12.2): a public or internal field unless its blueprint says
 * `agents: false`, a confidential field only with `agents: true`, and never a personal or
 * sensitive field. Inside a group it opens to agents, the nested fields that its blueprint closes
 * to them are left out of every value of the group, a repeated group's items included, at every
 * depth.
 *
 * audited() names the fields of a stripped entry whose classification requires the read audit:
 * every sensitive field (PRD 12.12). The other rules of PRD 12.2, personal fields in list reads
 * over a threshold, exports and reads of owned types by others than the owner, come with the
 * subject register and ownership (B6).
 */
#[Internal]
final readonly class ReadableFields
{
    /** @var list<ClassificationAccess> the classifications whose fields are always read-audited (PRD 12.12) */
    public const array AUDITED = [ClassificationAccess::Sensitive];

    public function __construct(private TypeCatalog $types) {}

    /**
     * @param  bool  $agent  whether the reader's credential was issued for an agent
     */
    public function strip(ReadContent $content, ClassificationAccess $access, bool $agent = false): ReadContent
    {
        $type = $this->types->find($content->type);

        return $content->withFields($type instanceof TypeDefinition ? $type->readable($content->fields, $access, $agent) : new FieldValues(new FieldMap));
    }

    /**
     * The fields of the entry that require the read audit, or null when none does.
     */
    public function audited(ReadContent $content): ?AuditedRead
    {
        return self::auditedOf($this->types->find($content->type), $content->entry, $content->fields);
    }

    /**
     * The fields of an entry of the type that require the read audit, or null when none does,
     * for a reader that has the entry's fields without a ReadContent, such as the TypeTableReader.
     */
    public static function auditedOf(?TypeDefinition $type, EntryId $entry, FieldValues $fields): ?AuditedRead
    {
        $names = [];
        $highest = null;

        foreach (self::definitions($type, $fields) as $definition) {
            if (in_array($definition->classification, self::AUDITED, true)) {
                $names[] = $definition->address();
                $highest = ! $highest instanceof ClassificationAccess || $highest->rank() < $definition->classification->rank() ? $definition->classification : $highest;
            }
        }

        return $highest instanceof ClassificationAccess ? new AuditedRead($entry, $names, $highest) : null;
    }

    /**
     * The definitions of the fields present, the owner's and every extender's, that the type
     * declares.
     *
     * @return list<FieldDefinition>
     */
    private static function definitions(?TypeDefinition $type, FieldValues $fields): array
    {
        $definitions = [];
        $maps = [[null, $fields->own]];

        foreach ($fields->extensions as $extension) {
            $maps[] = [$extension->namespace, $extension->fields];
        }

        foreach ($maps as [$namespace, $map]) {
            foreach ($map->fields as $field) {
                $definition = $type?->field($namespace, $field->handle);

                if ($definition instanceof FieldDefinition) {
                    $definitions[] = $definition;
                }
            }
        }

        return $definitions;
    }
}
