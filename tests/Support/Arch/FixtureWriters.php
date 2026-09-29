<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Cbox\Cms\Testkit\Phpstan\KernelTableWriteRule;

/**
 * The testkit's fixture writers (KernelTableWriteRule::FIXTURE_WRITERS) are the one writer of the
 * kernel's tables outside the kernel (PRD 6.5 invariants 1 and 13), and only because tests alone
 * run them. No production code may load them: a file outside packages/testkit/src that names a
 * class of the namespace, by an import, in code or in a string, is a violation.
 */
final readonly class FixtureWriters
{
    /** The directory of the namespace, relative to the root. */
    public const string DIRECTORY = 'packages/testkit/src/FixtureWriters';

    /**
     * The references to the fixture writers in files outside the testkit, one line each.
     *
     * @param  list<SourceFile>  $files
     * @return list<string>
     */
    public static function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            if (str_starts_with(Codebase::relative($file->path), 'packages/testkit/src/')) {
                continue;
            }

            foreach ($file->references as $reference) {
                $name = ltrim($reference->name, '\\');

                if (in_array($reference->kind, [ReferenceKind::ClassName, ReferenceKind::StringLiteral], true)
                    && ($name === KernelTableWriteRule::FIXTURE_WRITERS || str_starts_with($name, KernelTableWriteRule::FIXTURE_WRITERS.'\\'))) {
                    $violations[] = sprintf('%s: %s %s', Codebase::relative($reference->location()), $reference->kind->value, $name);
                }
            }
        }

        return $violations;
    }
}
