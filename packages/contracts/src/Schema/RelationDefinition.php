<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;

/**
 * What a relation field holds beside its targets (PRD 5.11), as cms:generate compiles it from the
 * blueprint:
 *
 * - `meta` says whether each target carries attributes, such as a role on a byline. The value then
 *   takes the form of a group, once or for a multiple relation repeated, whose nested fields are
 *   the target's entry under ENTRY and the attributes, so the kernel validates and stores a target
 *   with its attributes as it does a group's item; the relations table keeps each edge's
 *   attributes in relations.meta. An attribute declared filterable has a partial index there.
 * - `sortBy` is the owner's sortable top-level field a filterable relation's released edges are
 *   sorted by: the kernel writes its value onto each released edge as its sort key, in the
 *   transaction of the release, so the newest sources of one target are an index scan.
 */
#[Experimental]
final readonly class RelationDefinition
{
    /** The handle of the nested field that holds a target's entry beside its attributes. */
    public const string ENTRY = 'entry';

    public function __construct(
        public bool $meta = false,
        public ?FieldHandle $sortBy = null,
    ) {}
}
