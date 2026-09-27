<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Doctor;

/**
 * How a broken check breaks the contract.
 */
enum Breach
{
    /** run() throws instead of returning a failure. */
    case Throws;

    /** The result names another check. */
    case AnswersForAnother;

    /** The result says the check does not block when the check says it does. */
    case ContradictsBlocking;

    /** The failing state skips itself instead of failing. */
    case SkipsItself;

    /** The failing state passes, so the check cannot see the problem. */
    case NeverFails;

    /** The cause repeats the fix. */
    case FixAsCause;

    /** Every run gives another explanation. */
    case ChangesBetweenRuns;

    /** The check requires itself. */
    case RequiresItself;

    /** The failing state has another id than the passing state. */
    case ChangesId;
}
