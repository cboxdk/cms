<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * An installed addon's UI as cms:panel:types writes its types (PRD 13.4): its namespace, the
 * absolute directory of its Composer package, its contributions that run code, sorted by id, and
 * the commands its UI may issue, sorted by name and version.
 */
#[Internal]
final readonly class AddonUi
{
    /** @var list<UiContribution> */
    public array $contributions;

    /** @var list<ContractShape> */
    public array $issues;

    /**
     * @param  list<UiContribution>  $contributions
     * @param  list<ContractShape>  $issues
     */
    public function __construct(
        public AddonNamespace $namespace,
        public string $root,
        array $contributions,
        array $issues,
    ) {
        usort($contributions, static fn (UiContribution $a, UiContribution $b): int => strcmp($a->id->value, $b->id->value));
        usort($issues, static fn (ContractShape $a, ContractShape $b): int => [$a->ref->name->value, $a->ref->version] <=> [$b->ref->name->value, $b->ref->version]);
        $this->contributions = $contributions;
        $this->issues = $issues;
    }
}
