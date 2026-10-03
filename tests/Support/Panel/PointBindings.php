<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Panel;

use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;

/**
 * Holds the panel's points to their props schemas (GUARDRAILS 2.2, PRD 13.4): every point an addon
 * may contribute to, #[Stable] or #[Experimental], has a binding of its props class to its schema,
 * so its props reach a contribution only through a generated codec and an addon gets their
 * TypeScript; an #[Internal] point has none, and every binding binds a declared point. Each finding
 * names the point or the schema.
 */
final readonly class PointBindings
{
    /**
     * @param  list<PanelPointEntry>  $points
     * @param  list<SchemaBinding>  $bindings
     * @return list<string>
     */
    public static function findings(array $points, array $bindings): array
    {
        $bound = [];

        foreach ($bindings as $binding) {
            $class = $binding->objects['#'] ?? null;

            if ($class !== null) {
                $bound[strtolower($class)] = $binding;
            }
        }

        $findings = [];
        $declared = [];

        foreach ($points as $point) {
            $key = strtolower($point->class);
            $declared[$key] = true;
            $binding = $bound[$key] ?? null;

            if ($point->stability === PointStability::Internal) {
                if ($binding instanceof SchemaBinding) {
                    $findings[] = sprintf('%s (%s): an #[Internal] point, bound to %s; it has no schema binding and no TypeScript', $point->id()->toString(), $point->class, $binding->path());
                }

                continue;
            }

            if (! $binding instanceof SchemaBinding) {
                $findings[] = sprintf('%s (%s): no props schema is bound to it in PanelPointSchemas::all(); add %s.v%d.json and its binding, and run composer generate:protocol', $point->id()->toString(), $point->class, $point->declaration->name, $point->declaration->version);
            }
        }

        foreach ($bound as $key => $binding) {
            if (! isset($declared[$key])) {
                $findings[] = sprintf('%s: binds %s, which declares no panel point of the installation', $binding->path(), $binding->objects['#'] ?? '');
            }
        }

        return $findings;
    }
}
