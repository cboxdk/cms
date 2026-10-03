<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Domain\Dto;

/**
 * A stable panel point in the compatibility lock (PRD 13.4): its id `<name>@<version>`, its schema's
 * path below the root of cboxdk/cms, the SHA-256 of its contract and the contract itself, the
 * schema as canonical JSON without the keywords that only document it, which the next change of
 * the schema is compared with.
 */
final readonly class LockedPoint
{
    public function __construct(
        public string $point,
        public string $schema,
        public string $sha256,
        public string $contract,
    ) {}
}
