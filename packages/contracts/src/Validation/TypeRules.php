<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The runtime validator's rules for a type (PRD 11.8, 11.12): the rules of the owner's fields,
 * which the input holds by handle, and those of each extender's fields, which it holds under
 * `ext.<namespace>`. cms:generate writes them from the same schema as the type table, the records
 * and the TypeScript, so input from outside the repository, which no compiler checks, meets the
 * same rules. The owner's fields have different handles, and each namespace is given once.
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
}
