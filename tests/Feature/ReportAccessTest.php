<?php

use Illuminate\Support\Facades\{Exceptions, Gate};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Tests\Fixtures\ReporterCheck;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    $this->allowed = TestUser::create(['name' => 'Allowed', 'email' => 'a@x.com']);
    $this->blocked = TestUser::create(['name' => 'Blocked', 'email' => 'b@x.com']);
});

it('lets every signed-in user report until a resolver is registered', function () {
    expect(FeedbackBoard::canReport($this->allowed))->toBeTrue()
        ->and(FeedbackBoard::canReport($this->blocked))->toBeTrue()
        ->and(Gate::forUser($this->blocked)->allows('create', FeedbackItem::class))->toBeTrue();
});

it('never lets a guest report', function () {
    FeedbackBoard::resolveReportersUsing(fn () => true);

    expect(FeedbackBoard::canReport(null))->toBeFalse()
        ->and((new FeedbackItemPolicy())->create(null))->toBeFalse();
});

it('decides through a registered resolveReportersUsing callback, for the create policy too', function () {
    FeedbackBoard::resolveReportersUsing(fn ($user) => $user->name === 'Allowed');

    expect(FeedbackBoard::canReport($this->allowed))->toBeTrue()
        ->and(FeedbackBoard::canReport($this->blocked))->toBeFalse()
        ->and(Gate::forUser($this->allowed)->allows('create', FeedbackItem::class))->toBeTrue()
        ->and(Gate::forUser($this->blocked)->allows('create', FeedbackItem::class))->toBeFalse();
});

it('lets the registered callback take precedence over the config resolver', function () {
    config()->set('feedback-board.authorization.reporter_resolver', [ReporterCheck::class, 'handle']);
    FeedbackBoard::resolveReportersUsing(fn () => false);

    expect(FeedbackBoard::canReport($this->allowed))->toBeFalse();
});

it('accepts a public static method callable in config', function (mixed $callable) {
    config()->set('feedback-board.authorization.reporter_resolver', $callable);

    expect(FeedbackBoard::canReport($this->allowed))->toBeTrue()
        ->and(FeedbackBoard::canReport($this->blocked))->toBeFalse();
})->with([
    'array form' => [[ReporterCheck::class, 'handle']],
    'string form' => [ReporterCheck::class.'::handle'],
]);

it('denies and reports, once, a reporter_resolver that is not callable', function () {
    Exceptions::fake();
    config()->set('feedback-board.authorization.reporter_resolver', ReporterCheck::class);

    expect(FeedbackBoard::canReport($this->allowed))->toBeFalse()
        ->and(FeedbackBoard::canReport($this->blocked))->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), 'feedback-board.authorization.reporter_resolver')
        && str_contains($e->getMessage(), 'FeedbackBoard::resolveReportersUsing()'));
});

it('denies and reports a reporter resolver that returns an object', function () {
    Exceptions::fake();
    FeedbackBoard::resolveReportersUsing(fn () => collect([1]));

    expect(FeedbackBoard::canReport($this->allowed))->toBeFalse();
    Exceptions::assertReported(fn (UnexpectedValueException $e) => str_contains($e->getMessage(), 'A reporter resolver returned Illuminate\Support\Collection'));
});

it('denies and reports, once, a resolver that throws', function (string $kind) {
    Exceptions::fake();
    $throws = fn () => throw new LogicException('roles table missing');
    $kind === 'developer' ? FeedbackBoard::resolveDevelopersUsing($throws) : FeedbackBoard::resolveReportersUsing($throws);
    $check = fn ($user): bool => $kind === 'developer' ? FeedbackBoard::isDeveloper($user) : FeedbackBoard::canReport($user);

    expect($check($this->allowed))->toBeFalse()
        ->and($check($this->blocked))->toBeFalse();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getPrevious() instanceof LogicException
        && str_contains($e->getMessage(), 'A '.$kind.' resolver threw LogicException'));
})->with(['developer', 'reporter']);

it('denies instead of failing the page when a resolver calls a method the model lacks', function () {
    Exceptions::fake();
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->hasRole('developer'));
    FeedbackBoard::resolveReportersUsing(fn ($user) => $user->hasRole('client'));

    expect(FeedbackBoard::isDeveloper($this->allowed))->toBeFalse()
        ->and(FeedbackBoard::canReport($this->allowed))->toBeFalse();
    Exceptions::assertReportedCount(2);
});

it('honours Gate::before for the report gate', function () {
    FeedbackBoard::resolveReportersUsing(fn () => false);
    Gate::before(fn ($user, string $ability) => $ability === 'feedback-board.report' && $user->name === 'Allowed' ? true : null);

    expect(FeedbackBoard::canReport($this->allowed))->toBeTrue()
        ->and(FeedbackBoard::canReport($this->blocked))->toBeFalse();
});

it('lets the reporter view and reply to their own report without asking the developer resolver', function () {
    $asked = 0;
    FeedbackBoard::resolveDevelopersUsing(function () use (&$asked) {
        $asked++;

        return false;
    });
    FeedbackBoard::resolveReportersUsing(fn () => false);
    $item = FeedbackItem::factory()->for($this->blocked, 'reporter')->create();
    $policy = new FeedbackItemPolicy();

    expect($policy->view($this->blocked, $item))->toBeTrue()
        ->and($policy->reply($this->blocked, $item))->toBeTrue()
        ->and($asked)->toBe(0);
});
