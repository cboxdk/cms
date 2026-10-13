<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Schema\Localization;

/**
 * The runtime validator's rules for a type (PRD 11.8, 11.12): the rules of the owner's fields,
 * which the input holds by handle, and those of each extender's fields, which it holds under
 * `ext.<namespace>`. cms:generate writes them from the same schema as the type table, the records
 * and the TypeScript, so input from outside the repository, which no compiler checks, meets the
 * same rules. The owner's fields have different handles, and each namespace is given once.
 *
 * In a type with localization variants, a revision holds the fields of one variant (PRD 10.1):
 * forVariant() gives the rules of the fields that variant holds, so a revision of the shared variant
 * is not asked for a translatable field and one of a language's variant not for a shared one.
 */
#[Experimental]
final readonly class TypeRules
{
    /** The key of the input that holds the extension fields, by namespace (PRD 11.12 point 2). */
    public const string EXTENSIONS_KEY = 'ext';

    /**
     * @param  list<FieldRules>  $fields  the owner's fields
     * @param  list<ExtensionRules>  $extensions  the extenders' fields, by namespace
     *
     * @throws InvalidRules
     */
    public function __construct(
        public array $fields,
        public array $extensions = [],
    ) {
        FieldRules::assertDistinct($fields);
        $namespaces = [];

        foreach ($extensions as $extension) {
            if (isset($namespaces[$extension->namespace->value])) {
                throw InvalidRules::repeatedNamespace($extension->namespace);
            }

            $namespaces[$extension->namespace->value] = $extension->namespace;
        }
    }

    /**
     * The rules of the fields the variant holds (FieldRules::$translatable): every field for the
     * shared variant of a type without localization, the translatable fields for a language's
     * variant of a type with localization variants and the others for its shared variant. Each
     * extension stays, with the fields of the variant, so the input may name its namespace.
     */
    public function forVariant(VariantKey $variant, Localization $localization): self
    {
        if ($localization === Localization::None) {
            return $this;
        }

        $held = static fn (FieldRules $field): bool => $field->translatable !== $variant->isShared();

        return new self(
            array_values(array_filter($this->fields, $held)),
            array_map(
                static fn (ExtensionRules $extension): ExtensionRules => new ExtensionRules($extension->namespace, array_values(array_filter($extension->fields, $held))),
                $this->extensions,
            ),
        );
    }
}
