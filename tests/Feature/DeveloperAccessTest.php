<?php

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Actions\CreateFeedbackItem;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\NewFeedbackNotification;
use YousefAman\FeedbackBoard\Tests\Fixtures\DeveloperCheck;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    $this->dev = TestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    $this->client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
});

it('grants and denies through a registered resolveDevelopersUsing callback', function () {
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->name === 'Dev');

    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeTrue()
        ->and(Gate::forUser($this->client)->allows('feedback-board.manage'))->toBeFalse();
});

it('lets a registered callback take precedence over the config resolver', function () {
    config()->set('feedback-board.authorization.developer_resolver', fn () => true);
    FeedbackBoard::resolveDevelopersUsing(fn () => false);

    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeFalse();
});

it('accepts a public static method callable in config', function (mixed $callable) {
    config()->set('feedback-board.authorization.developer_resolver', $callable);

    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeTrue()
        ->and(Gate::forUser($this->client)->allows('feedback-board.manage'))->toBeFalse();
})->with([
    'array form' => [[DeveloperCheck::class, 'handle']],
    'string form' => [DeveloperCheck::class.'::handle'],
]);

it('denies everyone when nothing is configured', function () {
    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeFalse();
});

it('denies and reports a non-callable developer_resolver instead of failing silently', function () {
    // An invokable class name is not callable as a string; it used to deny every user without a trace.
    Exceptions::fake();
    config()->set('feedback-board.authorization.developer_resolver', DeveloperCheck::class);

    expect(Gate::forUser($this->dev)->allows('feedback-board.manage'))->toBeFalse();
    Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), 'feedback-board.authorization.developer_resolver'));
});

it('normalises every resolver result to a strict boolean', function (Closure $returns, bool $expected) {
    FeedbackBoard::resolveDevelopersUsing($returns);

    expect(FeedbackBoard::isDeveloper($this->dev))->toBe($expected);
})->with([
    'Response::deny' => [fn () => Response::deny(), false],
    'Response::allow' => [fn () => Response::allow(), true],
    'empty collection' => [fn () => collect(), false],
    'filled collection' => [fn () => collect([1]), false],
    'query builder' => [fn () => TestUser::query(), false],
    'int 1' => [fn () => 1, true],
    'string 1' => [fn () => '1', true],
    'int 0' => [fn () => 0, false],
    'null' => [fn () => null, false],
]);

it('reports a resolver that returns a non-bool object', function () {
    Exceptions::fake();
    FeedbackBoard::resolveDevelopersUsing(fn () => collect([1]));

    FeedbackBoard::isDeveloper($this->dev);

    Exceptions::assertReported(fn (UnexpectedValueException $e) => str_contains($e->getMessage(), Illuminate\Support\Collection::class)
        && str_contains($e->getMessage(), 'bool'));
});

it('reports an invalid config resolver only once per instance', function () {
    Exceptions::fake();
    config()->set('feedback-board.authorization.developer_resolver', DeveloperCheck::class);

    FeedbackBoard::isDeveloper($this->dev);
    FeedbackBoard::isDeveloper($this->dev);

    Exceptions::assertReportedCount(1);
});

it('sends to a single model returned by notifyDevelopersUsing', function () {
    Notification::fake();
    FeedbackBoard::notifyDevelopersUsing(fn () => $this->dev);

    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], $this->client);

    Notification::assertSentTo($this->dev, NewFeedbackNotification::class);
});

it('answers isDeveloper() through the gate, honouring Gate::before', function () {
    FeedbackBoard::resolveDevelopersUsing(fn () => false);
    Gate::before(fn ($user, string $ability) => $user->name === 'Dev' ? true : null);

    expect(FeedbackBoard::isDeveloper($this->dev))->toBeTrue()
        ->and(FeedbackBoard::isDeveloper($this->client))->toBeFalse();
});

it('treats a guest as not a developer', function () {
    FeedbackBoard::resolveDevelopersUsing(fn () => true);

    expect(FeedbackBoard::isDeveloper(null))->toBeFalse();
});

it('sends new-report notifications to notifyDevelopersUsing recipients', function () {
    Notification::fake();
    FeedbackBoard::notifyDevelopersUsing(fn () => [$this->dev]);

    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], $this->client);

    Notification::assertSentTo($this->dev, NewFeedbackNotification::class);
    Notification::assertNotSentTo($this->client, NewFeedbackNotification::class);
});

it('targets notifyDevelopersUsing recipients with the delivery-failure database notification', function () {
    \Illuminate\Support\Facades\Schema::create('notifications', function ($t) {
        $t->uuid('id')->primary();
        $t->string('type');
        $t->morphs('notifiable');
        $t->text('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });
    FeedbackBoard::notifyDevelopersUsing(fn () => collect([$this->dev]));
    $item = FeedbackItem::factory()->create();

    (new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-api'))
        ->failed(new \Illuminate\Http\Client\ConnectionException('connection refused'));

    expect($this->dev->notifications()->count())->toBe(1)
        ->and($this->client->notifications()->count())->toBe(0);
});

it('notifies only valid developer e-mails and warns once about the rest', function () {
    Notification::fake();
    Log::spy();
    config()->set('feedback-board.notifications.developers', ['dev@example.com', 1, 'not-an-email', 'a@x.com,b@x.com']);

    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], null);

    Notification::assertSentOnDemandTimes(NewFeedbackNotification::class, 1);
    Notification::assertSentOnDemand(
        NewFeedbackNotification::class,
        fn ($notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'dev@example.com',
    );
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'feedback-board.notifications.developers'));
});

it('accepts local, internationalised and padded developer addresses', function (string $email, string $routed) {
    Notification::fake();
    Log::spy();
    config()->set('feedback-board.notifications.developers', [$email]);

    app(CreateFeedbackItem::class)->handle(['title' => 'X', 'type' => FeedbackType::Bug], null);

    Notification::assertSentOnDemand(
        NewFeedbackNotification::class,
        fn ($notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === $routed,
    );
    Log::shouldNotHaveReceived('warning');
})->with([
    'local mail catcher' => ['dev@localhost', 'dev@localhost'],
    'surrounding whitespace' => [' dev@example.com ', 'dev@example.com'],
    'internationalised domain' => ['dev@exämple.com', 'dev@exämple.com'],
]);

it('reads a comma-separated FEEDBACK_BOARD_DEVELOPER_EMAIL', function () {
    $_SERVER['FEEDBACK_BOARD_DEVELOPER_EMAIL'] = 'dev@example.com, lead@example.com';

    try {
        $config = require __DIR__.'/../../config/feedback-board.php';
    } finally {
        unset($_SERVER['FEEDBACK_BOARD_DEVELOPER_EMAIL']);
    }

    expect(array_values($config['notifications']['developers']))->toBe(['dev@example.com', 'lead@example.com']);
});

it('stays silent on a default install with no developer e-mails', function () {
    Notification::fake();
    Log::spy();

    app(CreateFeedbackItem::class)->handle(['title' => 'Quiet', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Quiet')->exists())->toBeTrue();
    Notification::assertNothingSent();
    Log::shouldNotHaveReceived('warning');
});
