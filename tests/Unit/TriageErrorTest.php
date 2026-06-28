<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageError;

function reqError(int $status): RequestException {
    return new RequestException(new Response(
        new \GuzzleHttp\Psr7\Response($status, [], 'body')
    ));
}

it('passes through a TriageException code', function () {
    expect(TriageError::code(new TriageException('not_configured')))->toBe('not_configured')
        ->and(TriageError::code(new TriageException('invalid_output')))->toBe('invalid_output');
});

it('maps connection and http errors to the closed vocabulary', function () {
    expect(TriageError::code(new ConnectionException('x')))->toBe('connection_error')
        ->and(TriageError::code(reqError(401)))->toBe('auth_401')
        ->and(TriageError::code(reqError(403)))->toBe('auth_401')
        ->and(TriageError::code(reqError(404)))->toBe('not_found_404')
        ->and(TriageError::code(reqError(422)))->toBe('validation_422')
        ->and(TriageError::code(reqError(429)))->toBe('http_429')
        ->and(TriageError::code(reqError(503)))->toBe('http_5xx')
        ->and(TriageError::code(new \RuntimeException('boom')))->toBe('unknown');
});

it('never includes the original message (no previous on TriageException)', function () {
    $e = new TriageException('auth_401');
    expect((string) $e)->not->toContain('secret')
        ->and($e->getPrevious())->toBeNull();
});
