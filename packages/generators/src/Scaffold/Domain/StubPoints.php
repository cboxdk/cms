<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\StoryPoint;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\StubPoint;

/**
 * The panel points a stub can be written for, from the points cms:build compiled, by id.
 */
#[Internal]
final readonly class StubPoints
{
    /**
     * @param  array<string, StubPoint>  $points  by the point's id, `<name>@<version>`
     */
    private function __construct(private array $points) {}

    /**
     * @param  list<StoryPoint>  $points
     */
    public static function of(array $points): self
    {
        $byId = [];

        foreach ($points as $point) {
            $id = new PointId(new PointName($point->declaration->name), $point->declaration->version);
            $byId[$id->toString()] = new StubPoint(
                $id,
                $point->declaration->kind,
                ScaffoldNames::short($point->class),
                $point->stability === 'stable',
                $point->sample,
            );
        }

        return new self($byId);
    }

    /**
     * The point with the id.
     *
     * @throws GenerationFailed with generate_panel_point_unknown
     */
    public function get(PointId $id): StubPoint
    {
        $point = $this->points[$id->toString()] ?? null;

        if (! $point instanceof StubPoint) {
            $ids = array_keys($this->points);
            sort($ids, SORT_STRING);

            throw GenerationFailed::because(GenerateErrorCode::PanelPointUnknown, sprintf(
                'No installed package declares the panel point %s. The points cms:build compiled are: %s.',
                $id->toString(),
                $ids === [] ? 'none' : implode(', ', $ids),
            ));
        }

        return $point;
    }
}
