<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * One cboxCms.internalUse error as PHPStan built it during the analysis of a file, on its way
 * from InternalUseIgnoreErrorExtension to InternalUseRule.
 *
 * $file is the analysed file and $description the name PHPStan reports the error under: the
 * same path, or "Trait.php (in context of class Foo)" for code of a trait. $source is the file
 * that holds $line, where an ignore comment for it is written: the trait's file for code of a
 * trait, else $file.
 */
#[Internal]
final readonly class InternalUseSite
{
    public function __construct(
        public string $file,
        public string $description,
        public string $source,
        public int $line,
        public string $message,
    ) {}
}
