<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use DateTimeImmutable;
use Override;

/**
 * The registry cache exists, can be read, and is not older than vendor/ (PRD 13.2).
 *
 * cms:build compiles the registries of actions, commands and hooks from the installed code. A
 * cache older than Composer's last change to vendor/composer/installed.json may miss a hook or run
 * one that is gone, so it blocks the kernel.
 */
#[Internal]
final readonly class RegistryCacheCheck implements DoctorCheck
{
    public const string ID = 'registry.cache';

    public const string CODE_MISSING = 'doctor_registry_cache_missing';

    public const string CODE_DAMAGED = 'doctor_registry_cache_damaged';

    public const string CODE_STALE = 'doctor_registry_cache_stale';

    public const string CODE_NO_MANIFEST = 'doctor_vendor_manifest_missing';

    private const string TIME = 'Y-m-d\TH:i:s\Z';

    private const string BUILD = 'Run php artisan cms:build. Composer runs it after every install, update and dump-autoload; a deploy that copies vendor/ runs it after the copy.';

    public function __construct(private RegistryCacheProbe $registry) {}

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
        $state = $this->registry->state();

        if ($state->missingFiles !== [] || ! $state->builtAt instanceof DateTimeImmutable) {
            return $this->fail(
                self::CODE_MISSING,
                'The registry cache has not been built, so the kernel does not know its actions, commands and hooks.',
                sprintf('%s lacks %s.', $state->location, implode(', ', $state->missingFiles)),
                self::BUILD,
            );
        }

        if ($state->damage !== null) {
            return $this->fail(
                self::CODE_DAMAGED,
                'The registry cache cannot be read.',
                $state->damage,
                self::BUILD,
            );
        }

        if (! $state->manifestChangedAt instanceof DateTimeImmutable) {
            return $this->fail(
                self::CODE_NO_MANIFEST,
                'The doctor cannot tell whether the registry cache is older than vendor/, because Composer\'s record of the installed packages is missing.',
                sprintf('%s does not exist.', $state->manifest),
                'Run composer install, or set cms.doctor.vendor_manifest to the vendor/composer/installed.json of the application.',
            );
        }

        if ($state->builtAt < $state->manifestChangedAt) {
            return $this->fail(
                self::CODE_STALE,
                'The registry cache is older than vendor/, so it may miss actions and hooks from the installed packages or list ones that are gone.',
                sprintf(
                    'The oldest file in %s was written at %s; Composer wrote %s at %s.',
                    $state->location,
                    $state->builtAt->format(self::TIME),
                    $state->manifest,
                    $state->manifestChangedAt->format(self::TIME),
                ),
                self::BUILD,
            );
        }

        return CheckResult::pass($this->id(), true, sprintf(
            'The registry cache in %s was built at %s, after Composer last changed vendor/ at %s.',
            $state->location,
            $state->builtAt->format(self::TIME),
            $state->manifestChangedAt->format(self::TIME),
        ));
    }

    private function fail(string $code, string $explanation, string $cause, string $fix): CheckResult
    {
        return CheckResult::fail($this->id(), true, FailureKind::Violation, $code, $explanation, $cause, $fix);
    }
}
