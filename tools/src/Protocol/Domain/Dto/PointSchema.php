<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Domain\Dto;

use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;

/**
 * The props schema of one panel point, read (GUARDRAILS 2.2, PRD 13.4): its binding, the codec
 * contract the schema and binding give, the point its props class declares with #[PanelPoint],
 * whether the class is #[Stable], so the compatibility lock holds its schema, the schema's
 * contract as the lock compares it (PointsLock::contract()) and the sample props made from the
 * schema, as a TypeScript literal.
 */
final readonly class PointSchema
{
    public function __construct(
        public SchemaBinding $binding,
        public CodecContract $contract,
        public PointId $point,
        public bool $stable,
        public string $lockedContract,
        public Literal $sample,
    ) {}
}
