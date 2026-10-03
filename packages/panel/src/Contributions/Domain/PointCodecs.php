<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointCodec;
use InvalidArgumentException;

/**
 * The PointCodec of each panel point whose props a contribution is handed (GUARDRAILS 2.2, PRD
 * 13.4): the codecs the panel's service provider finds under the container tag TAG, one per point
 * id. The panel registers the generated PanelPointCodecs; a module or addon that declares points
 * tags its own. A point without a codec has no contributions on a page, because the panel cannot
 * hand them its props.
 */
#[Experimental]
final readonly class PointCodecs
{
    /** The container tag the codecs are registered under. */
    public const string TAG = 'cbox-cms.panel.point-codecs';

    /** @var array<string, PointCodec> by point id */
    private array $codecs;

    /**
     * @throws InvalidArgumentException when two codecs are given for one point
     */
    public function __construct(PointCodec ...$codecs)
    {
        $byPoint = [];

        foreach ($codecs as $codec) {
            $key = $codec->point->toString();

            if (isset($byPoint[$key])) {
                throw new InvalidArgumentException(sprintf('Two codecs are registered for the panel point %s.', $key));
            }

            $byPoint[$key] = $codec;
        }

        $this->codecs = $byPoint;
    }

    /**
     * The codec of the point's props, or null when none is registered.
     */
    public function find(PointId $point): ?PointCodec
    {
        return $this->codecs[$point->toString()] ?? null;
    }
}
