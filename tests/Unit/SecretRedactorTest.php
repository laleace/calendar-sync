<?php

use App\CalSync\Support\SecretRedactor;

it('masks literal secrets', function () {
    expect(SecretRedactor::redact('login failed for abcd-efgh-ijkl-mnop', ['abcd-efgh-ijkl-mnop']))
        ->toBe('login failed for ***');
});

it('ignores empty or very short secrets', function () {
    expect(SecretRedactor::redact('a b c', ['', null, 'a']))->toBe('a b c');
});

it('masks bearer and basic credentials', function () {
    expect(SecretRedactor::redact('Authorization: Basic bWU6c2VjcmV0 sent'))->toBe('Authorization: Basic *** sent')
        ->and(SecretRedactor::redact('header Bearer ya29.a0AfH6SMB-x_y.z'))->toBe('header Bearer ***');
});

it('masks token-like key/value pairs', function () {
    expect(SecretRedactor::redact('{"access_token":"ya29.abc","expires_in":3599}'))->toBe('{"access_token":"***","expires_in":3599}')
        ->and(SecretRedactor::redact('refresh_token=1//0gabc&client_secret=GOCSPX-xyz'))->toBe('refresh_token=***&client_secret=***');
});

it('masks sensitive headers but keeps the auth scheme', function () {
    expect(SecretRedactor::maskHeaders([
        'Authorization' => ['Basic bWU6c2VjcmV0'],
        'Cookie' => 'session=abc',
        'Depth' => ['1'],
        'X-Weird' => 'ok',
    ]))->toBe([
        'Authorization' => 'Basic ***',
        'Cookie' => '***',
        'Depth' => '1',
        'X-Weird' => 'ok',
    ]);
});

it('fully masks an authorization header without a scheme', function () {
    expect(SecretRedactor::maskHeaders(['authorization' => 'rawsecretvalue']))->toBe(['authorization' => '***']);
});
