<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Domain\Dto;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * What fixtureaddon.slug.set reads of an entry: its type and home node, the version of its shared
 * variant, the revision its head points at and the variant's highest revision number, which the
 * next revision follows, and the fields of the head's revision, which the new revision keeps but
 * for the addon's slug.
 */
final readonly class ArticleHead
{
    public function __construct(
        public TypeId $type,
        public NodeId $home,
        public AggregateVersion $version,
        public RevisionNumber $revision,
        public RevisionNumber $latest,
        public FieldValues $fields,
    ) {}
}
