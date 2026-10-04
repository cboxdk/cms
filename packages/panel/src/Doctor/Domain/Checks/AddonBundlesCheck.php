<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Checks\RegistryCacheCheck;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Panel\Doctor\Domain\Dto\BundleState;
use Cbox\Cms\Panel\Doctor\Domain\Probes\AddonBundlesProbe;
use Override;

/**
 * The panel bundles of the installed addons on disk are what cms:build compiled (PRD 13.4): every
 * file the compiled registry lists has the SHA-384 it lists, and the process knows each bundle's
 * directory. A file that differs is refused when the panel serves it, so the addon's UI does not
 * load. It does not block: the panel runs without that addon's UI, so the installation is not
 * ready until cms:build has run again. It requires registry.cache, because it reads the registry.
 */
#[Internal]
final readonly class AddonBundlesCheck implements DoctorCheck
{
    public const string ID = 'panel.addons';

    public const string CODE = 'doctor_panel_addons_changed';

    public function __construct(private AddonBundlesProbe $probe) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return false;
    }

    #[Override]
    public function requires(): array
    {
        return [new CheckId(RegistryCacheCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $bundles = $this->probe->bundles();
        } catch (RegistryCacheMissing|MalformedRegistryCache $unreadable) {
            return $this->fail('The compiled registry cannot be read, so the addons\' bundles cannot be checked.', $unreadable->getMessage(), 'Run cms:build, then run cms:doctor again.');
        }

        $changed = array_values(array_filter($bundles, static fn (BundleState $bundle): bool => ! $bundle->matches()));

        if ($changed !== []) {
            $causes = [];

            foreach ($changed as $bundle) {
                $causes[] = sprintf('%s: %s', $bundle->addon->value, implode('; ', $bundle->problems));
            }

            return $this->fail(
                sprintf('The panel bundle of %s is not what cms:build compiled, so the panel refuses to serve its files and the addon\'s UI does not load.', count($changed) === 1 ? 'one addon' : count($changed).' addons'),
                implode(' ', $causes),
                'Run cms:build after a change of an addon, and never edit the built files; then run cms:doctor again.',
            );
        }

        return CheckResult::pass($this->id(), false, $bundles === []
            ? 'No installed addon ships panel UI.'
            : sprintf('The panel bundle of %s on disk %s what cms:build compiled: %s.', count($bundles) === 1 ? 'one addon' : count($bundles).' addons', count($bundles) === 1 ? 'is' : 'are', implode(', ', array_map(static fn (BundleState $bundle): string => $bundle->addon->value, $bundles))));
    }

    private function fail(string $explanation, string $cause, string $fix): CheckResult
    {
        return CheckResult::fail($this->id(), false, FailureKind::Violation, self::CODE, $explanation, $cause, $fix);
    }
}
