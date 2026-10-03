<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Adapter;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Registry\Domain\InvalidPanelActivation;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;
use Illuminate\Contracts\Config\Repository;

/**
 * The activation state of the panel's contributions from cbox-cms.panel.disabled (PRD 13.5),
 * read at each call, so a change takes effect at the next request without cms:build:
 *
 *     'panel' => ['disabled' => ['addons' => ['approvals'], 'contributions' => ['reviews.badge']]],
 */
#[Internal]
final readonly class ConfigPanelActivation implements PanelActivation
{
    public const string KEY = 'cbox-cms.panel.disabled';

    public function __construct(private Repository $config) {}

    public function disabled(): DisabledContributions
    {
        $value = $this->config->get(self::KEY, []);

        if (! is_array($value) || array_diff(array_map(strval(...), array_keys($value)), ['addons', 'contributions']) !== []) {
            throw new InvalidPanelActivation(sprintf('The setting %s must be a map with addons, a list of addon namespaces, and contributions, a list of contribution ids.', self::KEY));
        }

        $addons = [];

        foreach ($this->list($value['addons'] ?? [], 'addons') as $namespace) {
            try {
                $addons[] = new AddonNamespace($namespace);
            } catch (InvalidAddonManifest|ReservedAddonNamespace $invalid) {
                throw new InvalidPanelActivation(sprintf('The setting %s.addons names "%s". %s', self::KEY, $namespace, $invalid->getMessage()), 0, $invalid);
            }
        }

        $contributions = [];

        foreach ($this->list($value['contributions'] ?? [], 'contributions') as $id) {
            try {
                $contributions[] = new ContributionId($id);
            } catch (InvalidPanelPoint $invalid) {
                throw new InvalidPanelActivation(sprintf('The setting %s.contributions names "%s". %s', self::KEY, $id, $invalid->getMessage()), 0, $invalid);
            }
        }

        return new DisabledContributions($addons, $contributions);
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value, string $member): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidPanelActivation(sprintf('The setting %s.%s must be a list of strings; it is %s.', self::KEY, $member, get_debug_type($value)));
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidPanelActivation(sprintf('The setting %s.%s must be a list of strings; it holds %s.', self::KEY, $member, get_debug_type($item)));
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
