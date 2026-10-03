<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandFile;
use Cbox\Cms\Panel\Branding\Domain\Dto\BrandImage;
use Cbox\Cms\Panel\Branding\Domain\InvalidBranding;
use Cbox\Cms\Panel\Doctor\Domain\Probes\BrandingProbe;
use Override;

/**
 * The installation's brand in cbox-cms.panel.branding can be used (PRD 13.4): every key is of its
 * form, the name is at most 60 characters, every logo has its alternative text, and every file is
 * a readable SVG or PNG inside the application. It does not block: the panel shows Cbox CMS
 * without branding meanwhile, so the installation is not ready until the brand is corrected.
 */
#[Internal]
final readonly class BrandingCheck implements DoctorCheck
{
    public const string ID = 'panel.branding';

    public const string CODE = 'doctor_panel_branding_invalid';

    public function __construct(private BrandingProbe $probe) {}

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
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $branding = $this->probe->branding();
        } catch (InvalidBranding $invalid) {
            return CheckResult::fail(
                $this->id(),
                false,
                FailureKind::Violation,
                self::CODE,
                'cbox-cms.panel.branding cannot be used, so the panel shows Cbox CMS without the installation\'s name, logos and favicon.',
                $invalid->getMessage(),
                'Correct cbox-cms.panel.branding as the cause says, as docs/developers/panel-branding.md describes it, then run cms:doctor again.',
            );
        }

        return CheckResult::pass($this->id(), false, $branding->name === null && ! $branding->logo instanceof BrandImage && ! $branding->favicon instanceof BrandFile
            ? 'The panel has no branding and shows Cbox CMS.'
            : sprintf('The panel shows the brand %s, and every file it names is a readable SVG or PNG inside the application.', $branding->name()));
    }
}
