<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Panel\Domain\Dto\LoginNoticeProp;

/**
 * The notices the login page shows (PRD 13.4): the enabled LoginNotice contributions to
 * login.notice@1, in render order, each as the page's prop carries it. None when the registry or
 * the activation state cannot be read: nothing an addon does keeps a person from the login form.
 */
#[Experimental]
final readonly class LoginNotices
{
    /** @var list<LoginNoticeProp> */
    public array $notices;

    public function __construct(LoginNoticeProp ...$notices)
    {
        $this->notices = array_values($notices);
    }

    public static function none(): self
    {
        return new self;
    }
}
