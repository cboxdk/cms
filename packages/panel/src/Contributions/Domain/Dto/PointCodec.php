<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * The generated JSON codec of one panel point's props (GUARDRAILS 2.2, PRD 13.4), by the point's
 * id: what the panel encodes the props with for each contribution to the point, at the lower of
 * the viewer's classification access and the addon's reads. The panel's own are listed by the
 * generated PanelPointCodecs; a module or addon that declares points registers theirs under
 * PointCodecs::TAG.
 */
#[Experimental]
final readonly class PointCodec
{
    /**
     * @param  JsonCodec<covariant object>  $codec
     */
    public function __construct(
        public PointId $point,
        public JsonCodec $codec,
    ) {}
}
