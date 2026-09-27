<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * One public extension point (GUARDRAILS 2.4, PRD 14.4): a fully qualified class name, or the
 * repo-relative path of a JSON schema.
 */
final readonly class ExtensionPoint
{
    public function __construct(
        public string $name,
        public ExtensionPointKind $kind,
    ) {}
}
