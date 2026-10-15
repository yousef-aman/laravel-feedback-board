<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Exceptions;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::create(['name' => 'U', 'email' => 'u@x.com']);
});

dataset('familiar resolver results', [
    'true' => [true, true],
    'int 1' => [1, true],
    "string '1'" => ['1', true],
    'Response::allow' => [Response::allow(), true],
    'false' => [false, false],
    'int 0' => [0, false],
    "string '0'" => ['0', false],
    'null' => [null, false],
    'Response::deny' => [Response::deny(), false],
]);

dataset('unexpected resolver results', [
    "'N'" => ['N'],
    "'false'" => ['false'],
    "'admin'" => ['admin'],
    'empty string' => [''],
    "' 1'" => [' 1'],
    "'0.0'" => ['0.0'],
    'int 2' => [2],
    'int 42' => [42],
    'float 1.0' => [1.0],
]);

it('answers familiar developer results silently', function (mixed $result, bool $expected) {
    Exceptions::fake();
    FeedbackBoard::resolveDevelopersUsing(fn () => $result);

    expect(FeedbackBoard::isDeveloper($this->user))->toBe($expected);
    Exceptions::assertNothingReported();
})->with('familiar resolver results');

it('answers familiar reporter results silently', function (mixed $result, bool $expected) {
    Exceptions::fake();
    FeedbackBoard::resolveReportersUsing(fn () => $result);

    expect(FeedbackBoard::canReport($this->user))->toBe($expected);
    Exceptions::assertNothingReported();
})->with('familiar resolver results');

it('denies any other developer result and reports its type once', function (mixed $result) {
    Exceptions::fake();
    FeedbackBoard::resolveDevelopersUsing(fn () => $result);

    expect(FeedbackBoard::isDeveloper($this->user))->toBeFalse()
        ->and(FeedbackBoard::isDeveloper($this->user))->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (UnexpectedValueException $e) => $e->getMessage()
        === 'A developer resolver returned '.get_debug_type($result).'; it must return a bool. Access was denied.');
})->with('unexpected resolver results');

it('denies any other reporter result and reports its type once', function (mixed $result) {
    Exceptions::fake();
    FeedbackBoard::resolveReportersUsing(fn () => $result);

    expect(FeedbackBoard::canReport($this->user))->toBeFalse()
        ->and(FeedbackBoard::canReport($this->user))->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (UnexpectedValueException $e) => $e->getMessage()
        === 'A reporter resolver returned '.get_debug_type($result).'; it must return a bool. Access was denied.');
})->with('unexpected resolver results');
