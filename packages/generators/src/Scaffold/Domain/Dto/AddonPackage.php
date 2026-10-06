<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * An addon's Composer package as its composer.json describes it, for the files the scaffold
 * writes: its name, the PHP namespace of its code, the namespace of its tests, and its service
 * provider, the class that declares the manifest, or null when composer.json names none.
 */
#[Internal]
final readonly class AddonPackage
{
    public function __construct(
        public string $name,
        public string $namespace,
        public string $testNamespace,
        public ?string $provider,
    ) {}

    /**
     * The npm name of the addon's panel package: the Composer vendor as the scope and the package
     * as the name, such as `@acme/cms-approvals` for `acme/cms-approvals`.
     */
    public function npmName(): string
    {
        [$vendor, $package] = explode('/', $this->name, 2) + [1 => $this->name];

        return '@'.$vendor.'/'.$package;
    }
}
