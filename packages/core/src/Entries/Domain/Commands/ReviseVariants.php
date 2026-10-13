<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantRevision;
use InvalidArgumentException;
use Override;

/**
 * Writes the next revision of one or more variants of an entry and moves each variant's head to it
 * (PRD 5.4, 6.4, 10.3), version 2 of entry.revise: the entry and, per variant, the shared one or a
 * language's, the version of it the caller read and every field of the new revision. A save that
 * changes a language's text and a shared field is one changeset with a revision in each variant,
 * and the variants are released together or apart, each with its own release.
 *
 * Each version is the variant's, which every save and release raises. A variant at another
 * version, or one the entry does not have or the caller cannot reach, is version_conflict, so a
 * caller that worked from a stale copy never overwrites a newer change (invariant 11). A language's
 * variant is authorized in its locale and the shared variant in every locale (PRD 5.10, 10.3), so a
 * translator granted one language writes that language alone. The fields are validated against the
 * rules of the fields each variant holds (invariant 4), at the write stage.
 */
#[CommandName('entry.revise', version: 2)]
#[Experimental]
final readonly class ReviseVariants implements ExpectsVersions
{
    /** @var non-empty-list<VariantRevision> each variant once, in the order given */
    public array $variants;

    /**
     * @param  list<VariantRevision>  $variants
     *
     * @throws InvalidArgumentException for no variant or a variant given twice
     */
    public function __construct(
        public EntryId $entry,
        array $variants,
    ) {
        if ($variants === []) {
            throw new InvalidArgumentException('A revise writes at least one variant.');
        }

        $seen = [];

        foreach ($variants as $variant) {
            if (isset($seen[$variant->variant->value])) {
                throw new InvalidArgumentException(sprintf('The variant %s is given twice; a revision is one whole snapshot of its variant.', $variant->variant->value));
            }

            $seen[$variant->variant->value] = true;
        }

        $this->variants = $variants;
    }

    /**
     * The variants this command revises, in the order given.
     *
     * @return non-empty-list<VariantRef>
     */
    public function refs(): array
    {
        return array_map(fn (VariantRevision $variant): VariantRef => new VariantRef($this->entry, $variant->variant), $this->variants);
    }

    /**
     * Each variant at the version the caller saw.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(...array_map(
            fn (VariantRevision $variant): ReadVersion => ReadVersion::at(new VariantRef($this->entry, $variant->variant), $variant->version),
            $this->variants,
        ));
    }
}
