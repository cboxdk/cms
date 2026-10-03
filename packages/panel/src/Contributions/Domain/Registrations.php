<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\IssuedCommand;
use Cbox\Cms\Core\Registry\Domain\PanelCompiler;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActivePoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonRegistration;

/**
 * The runtime registration check's side on the server (PRD 13.4): for each addon with an active
 * contribution that runs code on a page, the digest of what its code must register, which the
 * panel's host compares with the ids the addon's definePanelAddon() registered before it renders
 * any of the addon's contributions. The digest is the SHA-256, in lowercase hexadecimal, of the ids
 * of every contribution of the addon that runs code in the compiled registry, enabled or not,
 * sorted and joined by line feeds, so it names no contribution a viewer does not get, and the
 * host computes the same from the registration (js/panel/src/host/registration.ts).
 */
#[Experimental]
final readonly class Registrations
{
    /** The separator of the ids the digest is taken over. */
    public const string SEPARATOR = "\n";

    private function __construct() {}

    /**
     * The registration of each addon with an active fill that runs code on the page, sorted by
     * namespace.
     *
     * @param  list<ActivePoint>  $points
     * @return list<AddonRegistration>
     */
    public static function of(CompiledRegistry $registry, array $points): array
    {
        $addons = [];

        foreach ($points as $point) {
            foreach ($point->fills as $fill) {
                if ($fill->fill->declaration->runsCode()) {
                    $addons[$fill->fill->addon()->value] = $fill->fill->addon();
                }
            }
        }

        ksort($addons, SORT_STRING);
        $registrations = [];

        foreach ($addons as $namespace => $addon) {
            $core = $namespace === PanelCompiler::CORE_NAMESPACE;
            $entry = $core ? null : $registry->addon($addon);

            $registrations[] = new AddonRegistration(
                $addon,
                self::digest(self::idsRunningCode($registry, $addon)),
                $entry instanceof AddonEntry ? array_map(static fn (IssuedCommand $issued): CommandRef => $issued->command, $entry->issues) : [],
                $core,
            );
        }

        return $registrations;
    }

    /**
     * The digest of the ids, as the host computes it from an addon's registration.
     *
     * @param  list<string>  $ids
     */
    public static function digest(array $ids): string
    {
        sort($ids, SORT_STRING);

        return hash('sha256', implode(self::SEPARATOR, $ids));
    }

    /**
     * The ids of every contribution of the addon that runs code, in any point of the registry.
     *
     * @return list<string>
     */
    private static function idsRunningCode(CompiledRegistry $registry, AddonNamespace $addon): array
    {
        $ids = [];

        foreach ($registry->panel as $point) {
            foreach ($point->fills as $fill) {
                if ($fill->addon()->equals($addon) && $fill->declaration->runsCode()) {
                    $ids[] = $fill->contribution->value;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
