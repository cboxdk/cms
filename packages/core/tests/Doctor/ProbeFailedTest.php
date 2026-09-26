<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use RuntimeException;

/*
 * The failure a doctor probe throws: its kind, its cause as the message, what caused it, and the
 * same failure placed on a connection.
 */

it('keeps the kind, and the cause as the message with code 0 and what caused it', function (): void {
    $driver = new RuntimeException('SQLSTATE[08006] refused');
    $unavailable = ProbeFailed::unavailable('Connection refused', $driver);
    $violation = ProbeFailed::violation('Postgres 16.4');

    expect($unavailable->kind)->toBe(FailureKind::Unavailable)
        ->and($unavailable->cause)->toBe('Connection refused')
        ->and($unavailable->getMessage())->toBe('Connection refused')
        ->and($unavailable->getCode())->toBe(0)
        ->and($unavailable->getPrevious())->toBe($driver)
        ->and($violation->kind)->toBe(FailureKind::Violation)
        ->and($violation->getMessage())->toBe('Postgres 16.4')
        ->and($violation->getPrevious())->toBeNull();
});

it('puts the place in front of the cause and keeps the kind and the original failure', function (): void {
    $failed = ProbeFailed::violation('FATAL: password authentication failed');
    $placed = $failed->at('On the connection pgsql_owner');

    expect($placed->kind)->toBe(FailureKind::Violation)
        ->and($placed->cause)->toBe('On the connection pgsql_owner: FATAL: password authentication failed')
        ->and($placed->getMessage())->toBe('On the connection pgsql_owner: FATAL: password authentication failed')
        ->and($placed->getPrevious())->toBe($failed);
});
