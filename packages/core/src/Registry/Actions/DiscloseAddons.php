<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonDisclosure;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;

/**
 * The install screen's disclosure of every installed addon (PRD 13.1, 13.4), from the compiled
 * registry: its capabilities and panel contributions as addons.php holds them, the points its
 * contributions touch with the experimental ones it accepts, the number of its hooks and
 * subscribers, and the trust statement of its panel UI. Sorted by namespace.
 */
#[Experimental]
final readonly class DiscloseAddons
{
    public function __construct(private RegistryCache $cache) {}

    /**
     * @return list<AddonDisclosure>
     *
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    public function disclose(): array
    {
        $registry = $this->cache->read();

        return array_map(static function (AddonEntry $addon) use ($registry): AddonDisclosure {
            $points = [];
            $experimental = [];

            foreach ($registry->panel as $point) {
                foreach ($point->fills as $fill) {
                    if ($fill->addon()->equals($addon->namespace)) {
                        $points[$point->id()->toString()] = $point->id();

                        if ($point->stability === PointStability::Experimental) {
                            $experimental[$point->id()->toString()] = $point->id();
                        }
                    }
                }
            }

            ksort($points, SORT_STRING);
            ksort($experimental, SORT_STRING);

            return new AddonDisclosure(
                $addon,
                array_values($points),
                array_values($experimental),
                count(array_filter($registry->hooks, static fn (HookEntry $hook): bool => $hook->addon?->equals($addon->namespace) === true)),
                count(array_filter($registry->subscribers, static fn (SubscriberEntry $subscriber): bool => $subscriber->addon?->equals($addon->namespace) === true)),
            );
        }, $registry->addons);
    }
}
