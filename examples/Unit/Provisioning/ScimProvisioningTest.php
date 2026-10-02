<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimErrorCode;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimRefused;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUser;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserName;
use Cbox\Cms\Testkit\Provisioning\FakeScimProvisioning;

// An identity provider provisions a person, then sends active=false twice, as it does when it did
// not get the first answer. The first PATCH deactivates the actor; the second asks for the state
// the user has, so it changes nothing. Another connection's token cannot see the user (PRD 5.16).

it('deactivates a provisioned user once and keeps it from other connections', function (): void {
    $scim = new FakeScimProvisioning;
    $acme = new ConnectionId('entra-acme');
    $ada = new ScimUser(new Subject('00u-ada'), new ScimUserName('ada@acme.example'), new DisplayName('Ada Lovelace'), null);

    $id = $scim->createUser($acme, $ada)->user->id;
    $first = $scim->setUserActive($acme, $id, false);
    $repeat = $scim->setUserActive($acme, $id, false);
    $refusal = null;

    try {
        $scim->user(new ConnectionId('okta-globex'), $id);
    } catch (ScimRefused $refused) {
        $refusal = $refused->reason;
    }

    expect($first->changes)->toBe([ScimChange::Deactivated])
        ->and($first->key?->unitOfWork()->value)->toStartWith('scim:')
        ->and($repeat->changed())->toBeFalse()
        ->and($repeat->user->version->value)->toBe(2)
        ->and($refusal)->toBe(ScimErrorCode::ResourceNotFound);
});
