<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Core\TypeTables\Domain\UnreadableTypeTable;
use stdClass;

/**
 * The record of an entry through its type's generated codec (PRD 8.9, GUARDRAILS 2.2), for the
 * RecordCodecs that cms:generate writes.
 *
 * write() builds the JSON document of the record from the entry's id and its field values, in the
 * form of the record contract: the id under `cms_id`, each of the owner's fields under its handle,
 * and when the type has extension fields, `ext` with an object per extender's namespace. A field
 * the content does not hold is left out, and the order of the keys is the codec's to fix. The codec
 * then reads the document, at the highest classification access, so every rule of every value is
 * checked, and writes the DTO it read as a caller with $access may see it: no field above the
 * access leaves (invariant 10).
 */
#[Experimental]
final readonly class RecordDocument
{
    /**
     * @template TDto of object
     *
     * @param  JsonCodec<TDto>  $codec  the codec of the record of the content's type
     *
     * @throws InvalidRecordDocument when the catalog has no such type, or its codec refuses the values
     */
    public static function write(TypeCatalog $catalog, JsonCodec $codec, ReadContent $content, ClassificationAccess $access): string
    {
        $type = $catalog->find($content->type) ?? throw InvalidRecordDocument::unknownType($content->type);

        try {
            return $codec->encode($codec->decode(JsonText::encode(self::document($type, $content)), ClassificationAccess::Sensitive), $access);
        } catch (DecodingFailed|EncodingFailed|UnreadableTypeTable $refused) {
            throw InvalidRecordDocument::refused($content->type, $refused->getMessage(), $refused);
        }
    }

    /**
     * @throws UnreadableTypeTable
     */
    private static function document(TypeDefinition $type, ReadContent $content): stdClass
    {
        $record = new stdClass;
        $record->cms_id = $content->entry->toString();
        $extensions = [];

        foreach ($type->fields as $field) {
            $namespace = $field->namespace;

            if ($namespace instanceof FieldNamespace) {
                $extensions[$namespace->value] ??= new stdClass;
            }

            $value = self::value($field, $content);

            if (! $value instanceof FieldValue) {
                continue;
            }

            $target = $namespace instanceof FieldNamespace ? $extensions[$namespace->value] : $record;
            $target->{$field->handle->value} = TypeTableColumns::documentValue($field, $field->address(), $value);
        }

        if ($extensions !== []) {
            $record->ext = (object) $extensions;
        }

        return $record;
    }

    private static function value(FieldDefinition $field, ReadContent $content): ?FieldValue
    {
        return $field->namespace instanceof FieldNamespace
            ? $content->fields->extension($field->namespace)?->get($field->handle)
            : $content->fields->own->get($field->handle);
    }
}
