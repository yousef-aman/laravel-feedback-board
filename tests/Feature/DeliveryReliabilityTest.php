<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use YousefAman\FeedbackBoard\Actions\{CreateFeedbackItem, ReplyToFeedback, TransitionStatus};
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Notifications\NewFeedbackNotification;
use YousefAman\FeedbackBoard\Tests\TestUser;

// Simulates an unreachable SMTP server: every send throws like a real transport outage.
function useFailingMailer(): void
{
    Mail::extend('failing', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection could not be established');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
    config()->set('mail.mailers.failing', ['transport' => 'failing']);
    config()->set('mail.default', 'failing');
}

it('keeps the report when a model recipient needs the missing notifications table', function () {
    Exceptions::fake();
    config()->set('feedback-board.notifications.channels', ['mail', 'database']);
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']);
    FeedbackBoard::notifyDevelopersUsing(fn () => [$dev]);

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Kept')->exists())->toBeTrue();
    Exceptions::assertReported(\Illuminate\Database\QueryException::class);
});

it('keeps the report when the mail transport is down', function () {
    Exceptions::fake();
    useFailingMailer();
    config()->set('feedback-board.notifications.developers', ['dev@example.com']);

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Kept')->exists())->toBeTrue();
    Exceptions::assertReported(TransportException::class);
});

it('keeps the report when the notifyDevelopersUsing callback throws', function () {
    Exceptions::fake();
    FeedbackBoard::notifyDevelopersUsing(fn () => throw new RuntimeException('users table unavailable'));

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    expect(FeedbackItem::where('title', 'Kept')->exists())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'users table unavailable');
});

it('keeps the report when a destination delivery fails on the sync queue', function () {
    Exceptions::fake();
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.destinations.enabled', ['slack']);
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test/x');
    config()->set('feedback-board.destinations.lock_store', 'array');
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    $item = FeedbackItem::where('title', 'Kept')->first();
    expect($item)->not->toBeNull()
        ->and($item->external_refs['slack_last_error']['code'] ?? null)->toBe('connection_error');
    // Reported as the URL-free DeliveryFailed, never as the raw ConnectionException.
    Exceptions::assertReported(\YousefAman\FeedbackBoard\Destinations\Exceptions\DeliveryFailed::class);
});

it('keeps the report when AI triage fails on the sync queue', function () {
    Exceptions::fake();
    config()->set('queue.default', 'sync');
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'anthropic');
    config()->set('feedback-board.ai.anthropic.api_key', 'sk-ant-secret');
    Http::fake(['*' => Http::response(['error' => 'overloaded'], 529)]);

    app(CreateFeedbackItem::class)->handle(['title' => 'Triaged', 'type' => FeedbackType::Bug], null);

    $item = FeedbackItem::where('title', 'Triaged')->first();
    expect($item)->not->toBeNull()
        ->and($item->ai_state['triage']['status'] ?? null)->toBe('failed');
    Exceptions::assertReported(\YousefAman\FeedbackBoard\Triage\Exceptions\TriageException::class);
});

it('keeps a developer reply and a resolve when the reporter notification fails', function () {
    Exceptions::fake();
    useFailingMailer();
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create(['status' => FeedbackStatus::New]);

    app(ReplyToFeedback::class)->handle($item, 'On it', null, AuthorRole::Developer);
    app(TransitionStatus::class)->handle($item, FeedbackStatus::Resolved, null);

    expect(FeedbackComment::where('body', 'On it')->exists())->toBeTrue()
        ->and($item->fresh()->status)->toBe(FeedbackStatus::Resolved);
    Exceptions::assertReported(TransportException::class);
});

it('notifies the remaining developers when one recipient fails', function () {
    Exceptions::fake();
    config()->set('mail.default', 'array');
    $bad = TestUser::create(['name' => 'Bad', 'email' => 'not an email']);
    $good = TestUser::create(['name' => 'Good', 'email' => 'good@example.com']);
    FeedbackBoard::notifyDevelopersUsing(fn () => [$bad, $good]);

    app(CreateFeedbackItem::class)->handle(['title' => 'Kept', 'type' => FeedbackType::Bug], null);

    $recipients = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->flatMap(fn ($sent) => collect($sent->getEnvelope()->getRecipients())->map->getAddress());
    expect($recipients->all())->toContain('good@example.com');
    Exceptions::assertReported(\Symfony\Component\Mime\Exception\RfcComplianceException::class);
});

it('keeps a reporter reply when the developer notification fails', function () {
    Exceptions::fake();
    FeedbackBoard::notifyDevelopersUsing(fn () => throw new RuntimeException('boom'));
    $client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->for($client, 'reporter')->create();

    app(ReplyToFeedback::class)->handle($item, 'Still broken', $client, AuthorRole::Reporter);

    expect(FeedbackComment::where('body', 'Still broken')->exists())->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

it('sends nothing when the surrounding transaction rolls back', function () {
    Notification::fake();
    config()->set('feedback-board.notifications.developers', ['dev@example.com']);

    try {
        DB::transaction(function () {
            app(CreateFeedbackItem::class)->handle(['title' => 'Rolled back', 'type' => FeedbackType::Bug], null);
            throw new RuntimeException('later step failed');
        });
    } catch (RuntimeException) {
    }

    expect(FeedbackItem::count())->toBe(0);
    Notification::assertNothingSent();
});

it('notifies once, after the host app commits its own outer transaction', function () {
    Notification::fake();
    config()->set('feedback-board.notifications.developers', ['dev@example.com']);

    DB::transaction(function () {
        app(CreateFeedbackItem::class)->handle(['title' => 'Nested', 'type' => FeedbackType::Bug], null);
        Notification::assertNothingSent(); // still inside the host transaction
    });

    Notification::assertSentOnDemandTimes(NewFeedbackNotification::class, 1);
});

it('defaults to the mail channel only', function () {
    $item = FeedbackItem::factory()->create();

    expect((new NewFeedbackNotification($item))->via(new TestUser()))->toBe(['mail']);
});
