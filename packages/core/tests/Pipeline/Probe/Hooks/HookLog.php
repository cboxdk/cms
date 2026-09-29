<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks;

use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use PHPUnit\Framework\Assert;

/**
 * What test hooks saw and did, for the pipeline's hook tests: the names of the hooks that ran, in
 * order, and the views they got. A test may set the errors a validate hook answers with.
 */
final class HookLog
{
    /** @var list<string> */
    public array $ran = [];

    /** @var list<PlanView> */
    public array $views = [];

    public HookErrors $errors;

    public function __construct()
    {
        $this->errors = HookErrors::none();
    }

    public function ran(string $name): void
    {
        $this->ran[] = $name;
    }

    public function saw(PlanView $view): void
    {
        $this->views[] = $view;
    }

    public function view(int $index): PlanView
    {
        Assert::assertArrayHasKey($index, $this->views);

        return $this->views[$index];
    }

    /**
     * The fields of the variant's revision in the view the hook with the index got.
     */
    public function fields(int $index, VariantRef $variant): FieldValues
    {
        $revision = $this->view($index)->revision($variant);
        Assert::assertNotNull($revision);

        return $revision->fields;
    }
}
