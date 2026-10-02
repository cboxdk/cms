<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The id the CMS gives a SCIM resource (RFC 7643 3.1), a user or a group: 1 to MAX_LENGTH visible
 * ASCII characters, compared exactly, never bulkId. The provisioning assigns it when it creates the
 * resource, and it never changes.
 */
#[Experimental]
final readonly class ScimResourceId
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    /**
     * @throws InvalidIdentity when the value is not in the form
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || $value === 'bulkId') {
            throw InvalidIdentity::signalValue('SCIM resource id', '1 to 255 visible ASCII characters other than bulkId');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
