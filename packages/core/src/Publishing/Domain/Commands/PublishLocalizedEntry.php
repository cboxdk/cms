<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Publishing\Domain\Dto\VariantRelease;
use InvalidArgumentException;
use Override;

/**
 * Publishes an entry in one locale (PRD 6.4, 10.3), version 2 of entry.publish, the one action
 * behind the panel's primary button for an entry of any type: in one changeset it releases what
 * the locale needs of the entry's variants, the shared one and, for a type with localization
 * variants, the variant of the locale, and puts the home placement live in the locale, now or in a
 * window. It takes the entry, each variant with the version of it the caller saw and the revision
 * to release, the home placement with the version of it the caller saw, the locale and the window:
 * null puts the placement live from now, and a window schedules it.
 *
 * A variant whose released revision is the one named is left as it is, so a language goes live
 * beside a shared variant released before. A type with stages none has no revision to release:
 * each revision is null, and the command only puts the placement live. The single commands
 * variant.release and placement.set_window stay for the advanced cases, and version 1 stays for a
 * type with localization none. A dry run shows every placement that becomes visible. An agent may
 * not publish (invariant 18).
 */
#[CommandName('entry.publish', version: 2)]
#[Experimental]
final readonly class PublishLocalizedEntry implements ExpectsVersions
{
    /** @var non-empty-list<VariantRelease> each variant once, in the order given */
    public array $variants;

    /**
     * @param  list<VariantRelease>  $variants
     *
     * @throws InvalidArgumentException for no variant or a variant given twice
     */
    public function __construct(
        public EntryId $entry,
        array $variants,
        public PlacementId $placement,
        public AggregateVersion $placementVersion,
        public Locale $locale,
        public ?TimeWindow $window = null,
    ) {
        if ($variants === []) {
            throw new InvalidArgumentException('A publish names at least the shared variant.');
        }

        $seen = [];

        foreach ($variants as $variant) {
            if (isset($seen[$variant->variant->value])) {
                throw new InvalidArgumentException(sprintf('The variant %s is given twice; a publish releases one revision of each variant.', $variant->variant->value));
            }

            $seen[$variant->variant->value] = true;
        }

        $this->variants = $variants;
    }

    /**
     * The variant of the command's locale: the variant a type with localization variants serves
     * the locale from.
     */
    public function localeVariant(): VariantKey
    {
        return VariantKey::of($this->locale);
    }

    /**
     * The variants this command publishes, in the order given.
     *
     * @return non-empty-list<VariantKey>
     */
    public function keys(): array
    {
        return array_map(static fn (VariantRelease $variant): VariantKey => $variant->variant, $this->variants);
    }

    /**
     * Each variant and the home placement, at the versions the caller saw.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(
            ...array_map(fn (VariantRelease $variant): ReadVersion => ReadVersion::at(new VariantRef($this->entry, $variant->variant), $variant->version), $this->variants),
            ...[ReadVersion::at($this->placement, $this->placementVersion)],
        );
    }
}
