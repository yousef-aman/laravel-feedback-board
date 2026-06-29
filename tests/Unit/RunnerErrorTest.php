<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\Support\RunnerError;

function requestExceptionWith(int $status): RequestException {
    return new RequestException(new Response(
        new \GuzzleHttp\Psr7\Response($status, [], 'body')
    ));
}

it('passes a RunnerException through by its errorCode', function () {
    expect(RunnerError::code(new RunnerException('not_configured')))->toBe('not_configured');
});

it('maps a connection error', function () {
    expect(RunnerError::code(new ConnectionException('boom')))->toBe('connection_error');
});

it('maps HTTP statuses to the closed vocabulary', function () {
    expect(RunnerError::code(requestExceptionWith(401)))->toBe('auth_401')
        ->and(RunnerError::code(requestExceptionWith(403)))->toBe('auth_401')   // the non-obvious collapse
        ->and(RunnerError::code(requestExceptionWith(404)))->toBe('not_found_404')
        ->and(RunnerError::code(requestExceptionWith(422)))->toBe('validation_422')
        ->and(RunnerError::code(requestExceptionWith(429)))->toBe('http_429')
        ->and(RunnerError::code(requestExceptionWith(503)))->toBe('http_5xx');
});

it('defaults unknown for anything else', function () {
    expect(RunnerError::code(new RuntimeException('x')))->toBe('unknown');
});

it('never leaks the original message (no $previous on RunnerException)', function () {
    $e = new RunnerException('auth_401');
    expect((string) $e)->not->toContain('secret')
        ->and($e->getPrevious())->toBeNull();
});
