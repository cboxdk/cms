<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Login\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\LoginNotice;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\InvalidPanelActivation;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\Withheld;
use Cbox\Cms\Panel\Domain\Dto\LoginNoticeProp;
use Cbox\Cms\Panel\Login\Domain\Dto\LoginNotices;
use Cbox\Cms\Panel\Login\Domain\Login;

/**
 * Works out the notices the login page shows (PRD 13.4, section 3.11 of the panel extension
 * architecture): the LoginNotice contributions to login.notice@1 of the compiled registry, with
 * the installation's overrides cms:build applied, that the activation state of now
 * (cbox-cms.panel.disabled) leaves enabled, in render order, priority with the lowest first, then
 * the addon's namespace, then the contribution's id. The page has no viewer, so a notice is never
 * held to a permission; it is data, so nothing of the addon is loaded. When the registry or the
 * activation state cannot be read, the page shows no notice and the reason is recorded in
 * telemetry (Withheld): nothing an addon does keeps a person from the login form.
 */
#[Experimental]
final readonly class ResolveLoginNotices
{
    public function __construct(
        private RegistryCache $registry,
        private PanelActivation $activation,
        private ContributionTelemetry $telemetry,
    ) {}

    public function resolve(): LoginNotices
    {
        try {
            $point = $this->registry->read()->panelPoint(Login::notices());
            $disabled = $this->activation->disabled();
        } catch (RegistryCacheMissing) {
            return $this->none(Withheld::RegistryMissing);
        } catch (MalformedRegistryCache) {
            return $this->none(Withheld::RegistryMalformed);
        } catch (InvalidPanelActivation) {
            return $this->none(Withheld::ActivationInvalid);
        }

        if (! $point instanceof PanelPointEntry) {
            return LoginNotices::none();
        }

        $notices = [];

        foreach ($disabled->apply($point)->fills as $fill) {
            $notice = $this->noticeOf($fill);

            if ($notice instanceof LoginNoticeProp) {
                $notices[] = $notice;
            }
        }

        return new LoginNotices(...$notices);
    }

    private function noticeOf(PanelFill $fill): ?LoginNoticeProp
    {
        $declaration = $fill->declaration;

        if (! $fill->enabled || ! $declaration instanceof LoginNotice) {
            return null;
        }

        return new LoginNoticeProp($fill->addon(), $fill->contribution, $declaration->message, $declaration->tone);
    }

    private function none(Withheld $reason): LoginNotices
    {
        $this->telemetry->withheld(Login::page(), $reason, Login::notices());

        return LoginNotices::none();
    }
}
