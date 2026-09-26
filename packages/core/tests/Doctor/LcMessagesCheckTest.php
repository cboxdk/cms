<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Checks\LcMessagesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresQueryFailure;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeLcMessagesProbe;
use Closure;

/*
 * postgres.lc_messages with a fake probe (PRD 4.2): the app role, the owner role and the PHP
 * process must each have English messages, because the kernel reads the text of some errors.
 */

/**
 * @param  Closure(FakeLcMessagesProbe): void  $change
 */
function lcMessages(Closure $change): FakeLcMessagesProbe
{
    $probe = new FakeLcMessagesProbe;
    $change($probe);

    return $probe;
}

it('passes C, POSIX and English locales for both roles and the process', function (string $locale): void {
    foreach (['appRole', 'ownerRole', 'process'] as $where) {
        $result = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe) use ($where, $locale): void {
            $probe->{$where} = $locale;
        }))->run();

        expect($result->status)->toBe(CheckStatus::Pass, (string) $result->cause)
            ->and($result->explanation)->toContain($locale);
    }
})->with(['C', 'POSIX', 'en_US.UTF-8', 'en_US.utf8', 'en_GB', 'C.UTF-8', 'C.utf8']);

it('fails another language for either role or the process as a violation with its own code', function (string $where, string $cause, string $fix): void {
    $result = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe) use ($where): void {
        $probe->{$where} = 'de_DE.UTF-8';
    }))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->code)->toBe(LcMessagesCheck::CODE)
        ->and($result->code)->toBe('doctor_lc_messages_not_english')
        ->and($result->blocking)->toBeTrue()
        ->and($result->cause)->toBe($cause)
        ->and($result->fix)->toContain($fix);
})->with([
    'the app role' => ['appRole', 'lc_messages is \'de_DE.UTF-8\' for the role cms_app on the connection pgsql; Postgres took it from "user".', "ALTER ROLE cms_app SET lc_messages = 'C'"],
    'the owner role' => ['ownerRole', 'lc_messages is \'de_DE.UTF-8\' for the role cms_owner on the connection pgsql_owner; Postgres took it from "user".', "ALTER ROLE cms_owner SET lc_messages = 'C'"],
    'the process' => ['process', "LC_MESSAGES of the PHP process is 'de_DE.UTF-8'.", "setlocale(LC_MESSAGES, 'C')"],
]);

it('fails locales that are not English, and an empty lc_messages, which follows the server environment', function (string $locale): void {
    expect(LcMessagesCheck::isEnglish($locale))->toBeFalse()
        ->and(new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe) use ($locale): void {
            $probe->ownerRole = $locale;
        }))->run()->code)->toBe(LcMessagesCheck::CODE);
})->with(['de_DE.UTF-8', 'da_DK.utf8', 'fr_FR', 'sv_SE.UTF-8', '', 'c', 'english', 'EN_us', 'Cx']);

it('names every place in the cause and every fix, with ALTER SYSTEM for the server default', function (): void {
    $result = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe): void {
        $probe->appRole = 'da_DK.UTF-8';
        $probe->ownerRole = 'de_DE.UTF-8';
        $probe->ownerSource = 'configuration file';
        $probe->process = 'fr_FR.UTF-8';
    }))->run();

    expect($result->cause)->toBe(
        'lc_messages is \'da_DK.UTF-8\' for the role cms_app on the connection pgsql; Postgres took it from "user". '
        .'lc_messages is \'de_DE.UTF-8\' for the role cms_owner on the connection pgsql_owner; Postgres took it from "configuration file". '
        ."LC_MESSAGES of the PHP process is 'fr_FR.UTF-8'.",
    )->and($result->fix)->toContain("ALTER SYSTEM SET lc_messages = 'C' and SELECT pg_reload_conf()")
        ->and($result->fix)->toContain("ALTER ROLE cms_app SET lc_messages = 'C' and ALTER ROLE cms_owner SET lc_messages = 'C'")
        ->and($result->fix)->toContain("setlocale(LC_MESSAGES, 'C')");
});

it('leaves the process out of the fix when only a role is wrong, and the roles out when only the process is', function (): void {
    $role = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe): void {
        $probe->appRole = 'de_DE.UTF-8';
    }))->run();
    $process = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe): void {
        $probe->process = 'de_DE.UTF-8';
    }))->run();

    expect($role->fix)->not->toContain('setlocale')
        ->and($role->fix)->not->toContain('cms_owner')
        ->and($process->fix)->not->toContain('ALTER');
});

it('reports an owner connection it cannot read with its kind and the connection in the cause', function (FailureKind $kind): void {
    $failure = $kind === FailureKind::Violation
        ? ProbeFailed::violation('FATAL: password authentication failed for user "cms_owner"')
        : ProbeFailed::unavailable('Connection refused');

    $result = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe) use ($failure): void {
        $probe->ownerFailure = $failure->at('On the connection pgsql_owner');
    }))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe($kind)
        ->and($result->code)->toBe(PostgresQueryFailure::CODE)
        ->and($result->cause)->toStartWith('On the connection pgsql_owner: ')
        ->and($result->fix)->toContain('cms.database.owner_connection');
})->with([FailureKind::Violation, FailureKind::Unavailable]);

it('runs after postgres.reachable and blocks the kernel', function (): void {
    $check = new LcMessagesCheck(new FakeLcMessagesProbe);

    expect($check->id()->value)->toBe('postgres.lc_messages')
        ->and($check->blocking())->toBeTrue()
        ->and(array_map(static fn (CheckId $id): string => $id->value, $check->requires()))->toBe(['postgres.reachable']);
});

it('names each role\'s value and the process\'s in the explanation of a pass', function (): void {
    $result = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe): void {
        $probe->appRole = 'POSIX';
        $probe->ownerRole = 'en_GB.UTF-8';
        $probe->process = 'C.UTF-8';
    }))->run();

    expect($result->explanation)->toBe('Messages are English: lc_messages is POSIX for the role cms_app and en_GB.UTF-8 for the role cms_owner, and LC_MESSAGES of the PHP process is C.UTF-8.');
});

it('asks for one ALTER ROLE when the app and the owner connection share a role', function (): void {
    $result = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe): void {
        $probe->appRoleName = 'cms_owner';
        $probe->appRole = 'de_DE.UTF-8';
        $probe->ownerRole = 'de_DE.UTF-8';
    }))->run();

    expect(substr_count((string) $result->fix, "ALTER ROLE cms_owner SET lc_messages = 'C'"))->toBe(1)
        ->and($result->fix)->toContain("and ALTER ROLE cms_owner SET lc_messages = 'C'. A value set");
});

it('blocks the kernel when it cannot read the messages\' language', function (): void {
    $result = new LcMessagesCheck(lcMessages(static function (FakeLcMessagesProbe $probe): void {
        $probe->ownerFailure = ProbeFailed::unavailable('Connection refused');
    }))->run();

    expect($result->blocking)->toBeTrue()
        ->and($result->explanation)->toBe('The doctor could not read the language of the messages from Postgres or from the PHP process.');
});
