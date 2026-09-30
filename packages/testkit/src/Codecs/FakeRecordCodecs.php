<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Testkit\Codecs\Boundary\RecordJson;
use Override;
use stdClass;

/**
 * The testkit's RecordCodecs: a record codec for every type of the catalog a test gives it, next to
 * a FakeTypeCatalog (GUARDRAILS 2.4), so code that serves entries runs without generated code.
 *
 * It writes the record in the form of the generated codecs: the entry's id under `cms_id`, each of
 * the owner's fields the content holds under its handle, and when the type has extension fields,
 * `ext` with an object per extender's namespace, with the top-level keys sorted; a field classified
 * above the caller's access is left out, with its group when it is one. A value that does not fit
 * its field, and a type the catalog does not have, throw InvalidRecordDocument, as the generated
 * class does. It does not check the blueprint's rules, such as a text's length, which the generated
 * codec does.
 */
#[Experimental]
final readonly class FakeRecordCodecs implements RecordCodecs
{
    public function __construct(private TypeCatalog $catalog) {}

    #[Override]
    public function types(): array
    {
        $ids = array_map(static fn (TypeDefinition $type): string => $type->id->toString(), $this->catalog->all());
        sort($ids, SORT_STRING);

        return array_map(TypeId::fromString(...), $ids);
    }

    #[Override]
    public function encode(ReadContent $content, ClassificationAccess $access): string
    {
        $type = $this->catalog->find($content->type) ?? throw InvalidRecordDocument::unknownType($content->type);
        $record = new stdClass;
        $record->cms_id = $content->entry->toString();
        $extensions = [];

        foreach ($type->fields as $field) {
            $namespace = $field->namespace;

            if ($namespace instanceof FieldNamespace) {
                $extensions[$namespace->value] ??= new stdClass;
            }

            $value = $namespace instanceof FieldNamespace
                ? $content->fields->extension($namespace)?->get($field->handle)
                : $content->fields->own->get($field->handle);

            if (! $value instanceof FieldValue || ! $access->allows($field->classification)) {
                continue;
            }

            $target = $namespace instanceof FieldNamespace ? $extensions[$namespace->value] : $record;
            $target->{$field->handle->value} = RecordJson::value($content->type, $field, $value);
        }

        if ($extensions !== []) {
            ksort($extensions, SORT_STRING);
            $record->ext = (object) array_map(RecordJson::sorted(...), $extensions);
        }

        return RecordJson::encode($content->type, RecordJson::sorted($record));
    }
}
