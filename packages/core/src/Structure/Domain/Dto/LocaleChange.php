<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Structure\Domain\LocaleChangeKind;

/**
 * One locale cms:sites:sync added to or removed from a registered site (PRD 11.14): the locale,
 * which way, the changeset that committed it, null for a dry run or a rejection, and the errors of
 * a rejection, each with its catalog code.
 */
#[Internal]
final readonly class LocaleChange
{
    /**
     * @param  list<CatalogError>  $errors
     */
    public function __construct(
        public Locale $locale,
        public LocaleChangeKind $kind,
        public ?ChangesetId $changeset,
        public array $errors = [],
    ) {}

    public function rejected(): bool
    {
        return $this->errors !== [];
    }
}
