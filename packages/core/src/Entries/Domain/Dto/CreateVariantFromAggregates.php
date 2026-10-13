<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RevisionContent;
use Override;

/**
 * What variant.create_from read (PRD 6.2 phase 1): the entry with the heads of the new variant and
 * of the source, or null when the entry does not exist or the actor's regions do not reach it, its
 * type, and the content of the source revision, or null when the source has no revision with the
 * number. The entry is read at its version and only locked for share at commit; the new variant,
 * read as absent, is the aggregate the command creates, so two creates of it commit once, and the
 * source is read at its version, so a release or a save of it meanwhile is version_conflict.
 */
#[Internal]
final readonly class CreateVariantFromAggregates implements Aggregates
{
    public function __construct(
        public EntryId $entry,
        public VariantRef $target,
        public VariantRef $source,
        public ?StoredVariants $stored,
        public ?TypeDefinition $type = null,
        public ?RevisionContent $content = null,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $source = new ReadVersion($this->source, $this->stored?->head($this->source->variant)?->version);

        if ($this->target->variant->equals($this->source->variant)) {
            return new ReadVersions(new ReadVersion($this->entry, $this->stored?->version), $source);
        }

        return new ReadVersions(
            new ReadVersion($this->entry, $this->stored?->version),
            new ReadVersion($this->target, $this->stored?->head($this->target->variant)?->version),
            $source,
        );
    }

    /**
     * The entry's home in the locale of the new variant (PRD 5.10, 10.3), so a grant limited to
     * one language creates that language alone; anywhere when the entry read as absent.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->stored instanceof StoredVariants
            ? AuthorizationScope::on(new AuthorizationTarget($this->stored->home, $this->target->variant->locale))
            : AuthorizationScope::anywhere();
    }
}
