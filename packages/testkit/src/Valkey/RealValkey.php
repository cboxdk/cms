<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Valkey;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Runs a Testbench test case against real Valkey (GUARDRAILS 9).
 *
 * Testbench calls setUpRealValkey() after the application has booted and tearDownRealValkey()
 * before it is destroyed. The work is in ValkeyHarness. Every Redis connection uses the test
 * database index and the run's key prefix, and the keys are removed after each test.
 *
 *     pest()->extend(TestCase::class)->use(RealValkey::class)->in('Postgres');
 */
#[Experimental]
trait RealValkey
{
    private ?ValkeyHarness $realValkeyHarness = null;

    protected function setUpRealValkey(): void
    {
        $this->realValkeyHarness = ValkeyHarness::start($this->app, $this->valkeyConnection());
    }

    protected function tearDownRealValkey(): void
    {
        $harness = $this->realValkeyHarness;
        $this->realValkeyHarness = null;

        $harness?->finish();
    }

    /**
     * The Redis connection the service check and the clean-up use.
     */
    protected function valkeyConnection(): string
    {
        return 'default';
    }
}
