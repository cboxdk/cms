<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The panel bundles of every installed addon with panel UI (PRD 13.4), sorted by namespace, as
 * the panel serves them and writes them into a page's import map.
 */
#[Internal]
final readonly class ServedBundles
{
    /** @var list<ServedBundle> */
    public array $bundles;

    /**
     * @param  list<ServedBundle>  $bundles
     *
     * @throws InvalidArgumentException when an addon has two bundles
     */
    public function __construct(array $bundles = [])
    {
        $byAddon = [];

        foreach ($bundles as $bundle) {
            if (isset($byAddon[$bundle->addon->value])) {
                throw new InvalidArgumentException("The addon {$bundle->addon->value} has two bundles.");
            }

            $byAddon[$bundle->addon->value] = $bundle;
        }

        ksort($byAddon, SORT_STRING);
        $this->bundles = array_values($byAddon);
    }

    public static function none(): self
    {
        return new self;
    }

    public function of(AddonNamespace $addon): ?ServedBundle
    {
        foreach ($this->bundles as $bundle) {
            if ($bundle->addon->equals($addon)) {
                return $bundle;
            }
        }

        return null;
    }
}
