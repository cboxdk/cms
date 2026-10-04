<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Domain;

use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Cbox\Cms\Panel\Domain\Dto\PagePolicy;
use InvalidArgumentException;

/*
 * The nonce and the Content-Security-Policy of a panel page (GUARDRAILS 6: the control panel has a
 * strict policy with nonces).
 */

it('makes a new nonce of 16 random bytes in base64 for every response', function (): void {
    $nonces = array_map(static fn (): string => CspNonce::random()->value, range(1, 50));

    expect(array_unique($nonces))->toHaveCount(50);

    foreach ($nonces as $nonce) {
        expect($nonce)->toMatch(CspNonce::PATTERN)
            ->and(strlen((string) base64_decode($nonce, true)))->toBe(CspNonce::BYTES);
    }
});

it('refuses a nonce that is not 16 bytes in base64', function (string $value): void {
    expect(fn (): CspNonce => new CspNonce($value))->toThrow(InvalidArgumentException::class, 'A CSP nonce is 16 bytes in base64');
})->with([
    'empty' => [''],
    'too short' => [base64_encode('fifteen bytes..')],
    'too long' => [base64_encode('seventeen bytes..')],
    'a quote that would end the source' => ["abcdefghijklmnopqrstu'=="],
    'a space' => ['abcdefghijklm opqrstuv=='],
]);

it('allows styles by the nonce and scripts by the panel\'s origin and the hashes of the inline scripts, with no unsafe-inline or unsafe-eval', function (): void {
    $nonce = new CspNonce('MDEyMzQ1Njc4OWFiY2RlZg==');
    $hash = PagePolicy::hashOf('{}');
    $header = ContentSecurityPolicy::header(new PagePolicy($nonce, [$hash]));
    $directives = [];

    foreach (explode('; ', $header) as $directive) {
        $parts = explode(' ', $directive);
        $directives[array_shift($parts)] = $parts;
    }

    expect($header)->not->toContain('unsafe-');
    expect($header)->not->toContain('*');
    expect(ContentSecurityPolicy::HEADER)->toBe('Content-Security-Policy');
    expect($directives)->toBe([
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'sha256-{$hash}'"],
        'style-src' => ["'self'", "'nonce-MDEyMzQ1Njc4OWFiY2RlZg=='"],
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'frame-src' => ["'none'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'none'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ]);
});
