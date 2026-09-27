<?php

declare(strict_types=1);

namespace Examples\Contract\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Override;

/**
 * A doctor check: the directory that uploads are written to exists and this process may write to
 * it. It only looks, with is_dir() and is_writable(), and never creates the directory: repairing
 * is for the operator, whom the fix tells how.
 *
 * It does not block the kernel from starting. Without the directory an upload fails, but every
 * other request is served, so the check only affects readiness.
 */
final readonly class UploadsDirectoryCheck implements DoctorCheck
{
    public const string ID = 'uploads.directory';

    public const string CODE_MISSING = 'uploads_directory_missing';

    public const string CODE_READ_ONLY = 'uploads_directory_read_only';

    public function __construct(private string $directory) {}

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
        if (! is_dir($this->directory)) {
            return CheckResult::fail(
                $this->id(),
                $this->blocking(),
                FailureKind::Violation,
                self::CODE_MISSING,
                'Uploads are written to a directory that must exist before the application starts.',
                sprintf('%s does not exist or is not a directory.', $this->directory),
                sprintf('Create %s and give the user that runs PHP write access to it.', $this->directory),
            );
        }

        if (! is_writable($this->directory)) {
            return CheckResult::fail(
                $this->id(),
                $this->blocking(),
                FailureKind::Violation,
                self::CODE_READ_ONLY,
                'Uploads are written to a directory that this process must be able to write to.',
                sprintf('%s exists, but this process may not write to it.', $this->directory),
                sprintf('Give the user that runs PHP write access to %s.', $this->directory),
            );
        }

        return CheckResult::pass($this->id(), $this->blocking(), sprintf('Uploads can be written to %s.', $this->directory));
    }
}
