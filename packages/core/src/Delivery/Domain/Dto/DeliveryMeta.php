<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Schema\TypeName;
use InvalidArgumentException;

/**
 * What the record of a delivery answer is (PRD 8.9), the meta of delivery.v1.json and
 * delivery-explanation.v1.json: the canonical URL of the entry in the locale, or null when the
 * reader can read no canonical placement with a URL; the version of the record contract the record
 * is written in, RecordCodecs::VERSION; the locale; and the record's type.
 */
#[Experimental]
final readonly class DeliveryMeta
{
    /**
     * @throws InvalidArgumentException for another record contract than RecordCodecs::VERSION
     */
    public function __construct(
        public ?string $canonicalUrl,
        public int $contract,
        public Locale $locale,
        public TypeName $type,
    ) {
        if ($contract !== RecordCodecs::VERSION) {
            throw new InvalidArgumentException(sprintf('A delivery answer holds a record of contract version %d, got %d.', RecordCodecs::VERSION, $contract));
        }
    }
}
