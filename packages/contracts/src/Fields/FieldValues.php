<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The fields of one revision of an entry of any type (PRD 5.4, 11.12): the owner's fields by
 * handle, and the fields of each extender under its namespace. A namespace appears at most once,
 * and the extensions are sorted by namespace. The owner's fields and an extender's field may have
 * the same handle; they never collide, because the extender's lives under its namespace.
 *
 * The kernel reads and writes fields only in this form. The records generated from a blueprint
 * (M1 point 2) convert to and from it, and the codecs give it its JSON form.
 */
#[Experimental]
final readonly class FieldValues
{
    /** @var list<ExtensionFields> */
    public array $extensions;

    public function __construct(
        public FieldMap $own = new FieldMap,
        ExtensionFields ...$extensions,
    ) {
        $byNamespace = [];

        foreach ($extensions as $extension) {
            if (isset($byNamespace[$extension->namespace->value])) {
                throw InvalidFieldValue::duplicateNamespace($extension->namespace);
            }

            $byNamespace[$extension->namespace->value] = $extension;
        }

        ksort($byNamespace, SORT_STRING);

        $this->extensions = array_values($byNamespace);
    }

    /**
     * The fields the extender with the namespace adds, or null when it adds none.
     */
    public function extension(FieldNamespace $namespace): ?FieldMap
    {
        foreach ($this->extensions as $extension) {
            if ($extension->namespace->equals($namespace)) {
                return $extension->fields;
            }
        }

        return null;
    }

    public function equals(self $other): bool
    {
        if (! $other->own->equals($this->own) || count($other->extensions) !== count($this->extensions)) {
            return false;
        }

        foreach ($this->extensions as $index => $extension) {
            $theirs = $other->extensions[$index];

            if (! $theirs->namespace->equals($extension->namespace) || ! $theirs->fields->equals($extension->fields)) {
                return false;
            }
        }

        return true;
    }
}
