<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
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
 * entry's type does not declare, so an entry of a type the installation does not have keeps no
 * field: what the kernel cannot classify, it does not hand out. An extender whose fields are all
 * left out is left out too. A group is one top-level field with one classification, so it is kept
 * or left out whole by its classification.
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
        $extensions = [];

        foreach ($content->fields->extensions as $extension) {
            $fields = $this->allowed($type, $extension->namespace, $extension->fields, $access, $agent);

            if (! $fields->isEmpty()) {
                $extensions[] = new ExtensionFields($extension->namespace, $fields);
            }
        }

        return $content->withFields(new FieldValues($this->allowed($type, null, $content->fields->own, $access, $agent), ...$extensions));
    }

    /**
     * The fields of the entry that require the read audit, or null when none does.
     */
    public function audited(ReadContent $content): ?AuditedRead
    {
        $type = $this->types->find($content->type);
        $fields = [];
        $highest = null;

        foreach ($this->definitions($type, $content->fields) as $definition) {
            if (in_array($definition->classification, self::AUDITED, true)) {
                $fields[] = $definition->address();
                $highest = ! $highest instanceof ClassificationAccess || $highest->rank() < $definition->classification->rank() ? $definition->classification : $highest;
            }
        }

        return $highest instanceof ClassificationAccess ? new AuditedRead($content->entry, $fields, $highest) : null;
    }

    private function allowed(?TypeDefinition $type, ?FieldNamespace $namespace, FieldMap $fields, ClassificationAccess $access, bool $agent): FieldMap
    {
        $allowed = [];

        foreach ($fields->fields as $field) {
            $definition = $type?->field($namespace, $field->handle);

            if ($definition instanceof FieldDefinition && $access->allows($definition->classification) && (! $agent || $definition->agents)) {
                $allowed[] = $agent ? new NamedValue($field->handle, $this->forAgents($definition, $field->value)) : $field;
            }
        }

        return new FieldMap(...$allowed);
    }

    /**
     * The value of a field agents see, with the nested fields of a group that agents do not see
     * left out, in the group's value and in each item of a repeated group's.
     */
    private function forAgents(FieldDefinition $definition, FieldValue $value): FieldValue
    {
        if ($definition->fields === []) {
            return $value;
        }

        if ($value instanceof ListValue) {
            return new ListValue(...array_map(fn (FieldValue $item): FieldValue => $this->forAgents($definition, $item), $value->items));
        }

        if (! $value instanceof GroupValue) {
            return $value;
        }

        $nested = [];

        foreach ($value->fields->fields as $field) {
            $member = $definition->field($field->handle);

            if ($member instanceof FieldDefinition && $member->agents) {
                $nested[] = new NamedValue($field->handle, $this->forAgents($member, $field->value));
            }
        }

        return new GroupValue(new FieldMap(...$nested));
    }

    /**
     * The definitions of the fields present, the owner's and every extender's, that the type
     * declares.
     *
     * @return list<FieldDefinition>
     */
    private function definitions(?TypeDefinition $type, FieldValues $fields): array
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
