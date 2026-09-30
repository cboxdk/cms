<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;

/**
 * The record codecs of an installation's types (PRD 8.9, GUARDRAILS 2.2): how an entry a read
 * returned leaves the server as the record DTO of its type. cms:generate writes the record DTO and
 * its JSON codec per type, and a class that implements this contract with all of them, which the
 * generated service provider binds. The kernel knows no type (GUARDRAILS 2.4), so a projection that
 * serves an entry, such as the delivery API, asks this contract for its JSON.
 *
 * encode() builds the record DTO of the entry's type, contract version VERSION, from the entry's
 * id and its field values, through the type's generated codec, and writes the codec's canonical
 * JSON as a caller with $access may see it: keys sorted, no whitespace, and no field classified
 * above the access (invariant 10). A field the content does not hold, such as one a read stripped,
 * is left out. It throws InvalidRecordDocument for a type the installation does not have, and for
 * field values the type's contract refuses, such as a value of the wrong kind.
 *
 * Like the TypeCatalog, it is fixed for the life of the process, reads nothing and holds one codec
 * for every type of the catalog and none for another.
 */
#[Experimental]
interface RecordCodecs
{
    /** The contract version of the records the codecs write. */
    public const int VERSION = 1;

    /**
     * The id of every type with a codec, sorted, each once.
     *
     * @return list<TypeId>
     */
    public function types(): array;

    /**
     * @throws InvalidRecordDocument
     */
    public function encode(ReadContent $content, ClassificationAccess $access): string;
}
