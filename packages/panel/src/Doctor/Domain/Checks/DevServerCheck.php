<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Panel\Doctor\Domain\Probes\DevServerProbe;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\Dto\DevServer;
use Cbox\Cms\Panel\Domain\InvalidDevAddons;
use Override;

/**
 * CBOX_CMS_PANEL_DEV_ADDONS, which loads an addon's panel UI from a Vite dev server and widens the
 * panel's content security policy to it, is set only in the local environment and can be read
 * (PRD 13.4). It blocks: the panel's provider refuses to boot a process that serves HTTP with it
 * elsewhere, and the doctor, a console process, says why before that happens.
 */
#[Internal]
final readonly class DevServerCheck implements DoctorCheck
{
    public const string ID = 'panel.dev_server';

    public const string CODE = 'doctor_panel_dev_server_forbidden';

    /** The environment the variable may be set in. */
    public const string LOCAL = 'local';

    public function __construct(private DevServerProbe $probe) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return true;
    }

    #[Override]
    public function requires(): array
    {
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        $setting = $this->probe->setting();

        if ($setting === null) {
            return CheckResult::pass($this->id(), true, sprintf('%s is not set: every addon\'s panel UI comes from its bundle.', DevAddons::VARIABLE));
        }

        $environment = $this->probe->environment();

        if ($environment !== self::LOCAL) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE,
                sprintf('%s is set outside the local environment, so a process that serves HTTP refuses to boot.', DevAddons::VARIABLE),
                sprintf('The application\'s environment is "%s", and a dev server for an addon\'s panel UI widens the panel\'s content security policy to the server\'s origin, which only development may do.', $environment),
                sprintf('Unset %s in this environment, or build the addon\'s bundle and name it in its manifest.', DevAddons::VARIABLE),
            );
        }

        try {
            $addons = $this->probe->addons();
        } catch (InvalidDevAddons $invalid) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE,
                sprintf('%s cannot be read, so a process that serves HTTP refuses to boot.', DevAddons::VARIABLE),
                $invalid->getMessage(),
                sprintf('Set %s to <namespace>=<origin> pairs joined by commas, such as approvals=http://localhost:5174, or unset it.', DevAddons::VARIABLE),
            );
        }

        return CheckResult::pass($this->id(), true, sprintf(
            'The local application loads the panel UI of %s from a dev server: %s.',
            count($addons->servers) === 1 ? 'one addon' : count($addons->servers).' addons',
            implode(', ', array_map(static fn (DevServer $server): string => $server->addon->value.' from '.$server->origin, $addons->servers)),
        ));
    }
}
