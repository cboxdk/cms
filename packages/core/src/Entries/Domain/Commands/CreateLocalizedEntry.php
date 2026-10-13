<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Entries\Domain\Dto\LocaleFields;
use InvalidArgumentException;
use Override;

/**
 * Creates an entry of any type with the first revision of each of its variants (PRD 5.4, 6.4, 10.1,
 * 10.3), version 2 of entry.create: the entry's id, its type, its home node, which owns its content
 * (PRD 5.10), the fields of its shared variant and the fields of each language's variant. A type
 * with localization none has the shared variant alone, so its languages are none; a type with
 * localization variants has a variant per language beside the shared one and needs at least one
 * language, so no entry of it is left without a language. Every revision goes in one changeset.
 *
 * The caller makes the entry's id, so a repeat of the call with the same idempotency key is the
 * same content, and the command expects the entry and each of its variants not to exist: a create
 * of an id that exists is version_conflict (invariant 11). Each variant's fields are validated
 * against the rules of the fields that variant holds (invariant 4), at the write stage, where an
 * extension field is never required (invariant 36).
 */
#[CommandName('entry.create', version: 2)]
#[Experimental]
final readonly class CreateLocalizedEntry implements ExpectsVersions
{
    /** @var list<LocaleFields> each locale once, in the order given */
    public array $locales;

    /**
     * @param  list<LocaleFields>  $locales
     *
     * @throws InvalidArgumentException for a locale given twice
     */
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
        public FieldValues $shared,
        array $locales,
    ) {
        $seen = [];

        foreach ($locales as $locale) {
            if (isset($seen[$locale->locale->value])) {
                throw new InvalidArgumentException(sprintf('The locale %s is given twice; each language of the entry is one variant.', $locale->locale->value));
            }

            $seen[$locale->locale->value] = true;
        }

        $this->locales = $locales;
    }

    /**
     * The variants the command creates: the shared one, then each language's.
     *
     * @return non-empty-list<VariantRef>
     */
    public function variants(): array
    {
        return [
            new VariantRef($this->entry, VariantKey::shared()),
            ...array_map(fn (LocaleFields $locale): VariantRef => new VariantRef($this->entry, $locale->variant()), $this->locales),
        ];
    }

    /**
     * The entry and each of its variants, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(
            ReadVersion::absent($this->entry),
            ...array_map(ReadVersion::absent(...), $this->variants()),
        );
    }
}
