<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Errors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The exit codes of the cms:* commands, part of the error catalog (PRD 6.1, GUARDRAILS 2.1): 0,
 * the codes of sysexits.h from 64 to 78, and 79, which cms:doctor gives when the kernel may start
 * but is not ready (PRD 3.3). Every entry of the catalog names the one a command exits with for its
 * code, and DoctorExitCode takes its values from here.
 *
 * 79 lies just above the sysexits range, so it has no other meaning there, and none of these can
 * be mistaken for 1, a general failure, or 2, wrong usage.
 */
#[Experimental]
enum ExitCode: int
{
    /** EX_OK: the command did what it was asked. */
    case Ok = 0;

    /** EX_USAGE: the command was called with wrong arguments or options. */
    case Usage = 64;

    /** EX_DATAERR: the input, a schema, a declaration or a command's data, is wrong. */
    case DataErr = 65;

    /** EX_NOINPUT: an input file or directory does not exist or cannot be read. */
    case NoInput = 66;

    /** EX_NOUSER: the user named does not exist. */
    case NoUser = 67;

    /** EX_NOHOST: the host named does not exist. */
    case NoHost = 68;

    /** EX_UNAVAILABLE: a service the command needs is not available. */
    case Unavailable = 69;

    /** EX_SOFTWARE: an internal error, a bug in the software. */
    case Software = 70;

    /** EX_OSERR: an error of the operating system, such as failing to fork. */
    case OsErr = 71;

    /** EX_OSFILE: a system file is missing or wrong. */
    case OsFile = 72;

    /** EX_CANTCREAT: an output file cannot be created or written. */
    case CantCreat = 73;

    /** EX_IOERR: reading or writing a file failed. */
    case IoErr = 74;

    /** EX_TEMPFAIL: a temporary failure; trying again later may succeed. */
    case TempFail = 75;

    /** EX_PROTOCOL: the other side of a protocol answered with something impossible. */
    case Protocol = 76;

    /** EX_NOPERM: the caller may not do this. */
    case NoPerm = 77;

    /** EX_CONFIG: the configuration is invalid, or the runtime contract is broken. */
    case Config = 78;

    /** Not in sysexits.h: the kernel may start, but a check that decides readiness failed (PRD 3.3). */
    case NotReady = 79;

    /**
     * The name of the code in sysexits.h, such as EX_TEMPFAIL, or NOT_READY for 79, which is not
     * in it.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Ok => 'EX_OK',
            self::Usage => 'EX_USAGE',
            self::DataErr => 'EX_DATAERR',
            self::NoInput => 'EX_NOINPUT',
            self::NoUser => 'EX_NOUSER',
            self::NoHost => 'EX_NOHOST',
            self::Unavailable => 'EX_UNAVAILABLE',
            self::Software => 'EX_SOFTWARE',
            self::OsErr => 'EX_OSERR',
            self::OsFile => 'EX_OSFILE',
            self::CantCreat => 'EX_CANTCREAT',
            self::IoErr => 'EX_IOERR',
            self::TempFail => 'EX_TEMPFAIL',
            self::Protocol => 'EX_PROTOCOL',
            self::NoPerm => 'EX_NOPERM',
            self::Config => 'EX_CONFIG',
            self::NotReady => 'NOT_READY',
        };
    }
}
