<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity;

use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The wire form of a service credential (PRD 5.16): 256 random bits and a checksum, refused
 * without a lookup when the checksum does not match, and stored only as a hash.
 */

const TOKEN_SECRET = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

it('writes the prefix, the 256 bits as hex and a CRC-32 checksum', function (): void {
    $token = ServiceCredentialToken::fromSecret(TOKEN_SECRET)->credential()->reveal();
    $body = 'cms_sc_'.bin2hex(TOKEN_SECRET);

    expect($token)->toBe($body.hash('crc32b', $body))
        ->and(strlen($token))->toBe(79);
});

it('reads a token back and hashes it with SHA-256', function (): void {
    $token = ServiceCredentialToken::fromSecret(TOKEN_SECRET);
    $parsed = ServiceCredentialToken::parse($token->credential());

    expect($parsed->hash())->toBe(hash('sha256', $token->credential()->reveal()))
        ->and($parsed->hash())->toBe($token->hash())
        ->and(ServiceCredentialToken::fromSecret(str_repeat("\x01", 32))->hash())->not->toBe($token->hash());
});

it('refuses a token that is not in the form, or whose checksum does not match, as malformed', function (string $value): void {
    try {
        ServiceCredentialToken::parse(new TransportCredential($value));
    } catch (CredentialRejected $rejected) {
        expect($rejected->reason)->toBe(CredentialErrorCode::Malformed)
            ->and($rejected->getMessage())->not->toContain($value === '' ? 'cms_sc_' : $value);

        return;
    }

    throw new AssertionFailedError('The token was read.');
})->with(function (): array {
    $valid = ServiceCredentialToken::fromSecret(TOKEN_SECRET)->credential()->reveal();
    $body = substr($valid, 0, -8);

    return [
        'empty' => [''],
        'another prefix' => ['cms_pt_'.substr($valid, 7)],
        'upper case' => [strtoupper($valid)],
        'one digit short' => [substr($valid, 0, -1)],
        'one digit more' => [$valid.'0'],
        'wrong checksum' => [$body.'00000000'],
        'a flipped secret digit' => [substr_replace($valid, $valid[10] === 'a' ? 'b' : 'a', 10, 1)],
        'trailing newline' => [$valid."\n"],
    ];
});

it('takes exactly 32 secret bytes', function (int $length): void {
    expect(fn (): ServiceCredentialToken => ServiceCredentialToken::fromSecret(str_repeat("\x01", $length)))
        ->toThrow(InvalidIdentity::class, sprintf('The secret of a service credential is 32 random bytes, got %d.', $length));
})->with([0, 31, 33, 64]);

it('keeps the secret out of dumps', function (): void {
    $token = ServiceCredentialToken::fromSecret(TOKEN_SECRET);
    $credential = $token->credential();

    expect(print_r($credential, true))->not->toContain($credential->reveal())
        ->and(print_r($token, true))->not->toContain($credential->reveal())
        ->and(var_export($credential->__debugInfo(), true))->toContain('[secret]');
});
