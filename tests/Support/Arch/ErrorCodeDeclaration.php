<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * One place the source declares or uses an error code: a CODE constant, a case of an error-code
 * enum, or a named case of the catalog (ErrorCodeScan).
 */
final readonly class ErrorCodeDeclaration
{
    /**
     * @param  string  $declaredBy  the constant or case, as <class>::<name>
     * @param  string  $where  the repo-relative file
     */
    public function __construct(
        public string $code,
        public string $declaredBy,
        public string $where,
    ) {}
}
