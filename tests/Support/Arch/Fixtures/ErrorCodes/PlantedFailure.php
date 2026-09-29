<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes;

/**
 * A class that declares codes as ErrorCodeScan reads them: CODE and CODE_<NAME> with a string
 * value. The other constants are no codes.
 */
class PlantedFailure
{
    public const string CODE = 'planted_failure';

    public const string CODE_OTHER = 'planted_other';

    public const string CODE_PATTERN = '/\A[a-z]+\z/';

    public const int CODE_LENGTH = 63;

    public const string NAME = 'planted_name';
}
