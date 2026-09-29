<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
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
 * or left out whole.
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

    public function strip(ReadContent $content, ClassificationAccess $access): ReadContent
    {
        $type = $this->types->find($content->type);
        $extensions = [];

        foreach ($content->fields->extensions as $extension) {
            $fields = $this->allowed($type, $extension->namespace, $extension->fields, $access);

            if (! $fields->isEmpty()) {
                $extensions[] = new ExtensionFields($extension->namespace, $fields);
            }
        }

        return $content->withFields(new FieldValues($this->allowed($type, null, $content->fields->own, $access), ...$extensions));
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

    private function allowed(?TypeDefinition $type, ?FieldNamespace $namespace, FieldMap $fields, ClassificationAccess $access): FieldMap
    {
        return new FieldMap(...array_filter(
            $fields->fields,
            static function (NamedValue $field) use ($type, $namespace, $access): bool {
                $definition = $type?->field($namespace, $field->handle);

                return $definition instanceof FieldDefinition && $access->allows($definition->classification);
            },
        ));
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
