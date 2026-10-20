<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\{Exceptions, Notification};
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\DeliveryFailedNotification;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.destinations.lock_store', 'array');
    config()->set('feedback-board.notifications.developers', ['dev@team.test']);
    Notification::fake();
});

// The delivery job as it runs in an app without Filament (the test suite has Filament installed).
function failedWithoutFilament(FeedbackItem $item, string $driver): void
{
    $job = Mockery::mock(DeliverToDestination::class, [new FeedbackItemCreated($item), $driver, 'dlv-'.$item->id.'-'.$driver])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('usesFilamentBell')->andReturn(false);

    $job->failed(new ConnectionException('cURL error 6: Could not resolve host'));
}

it('e-mails the developers once a day for each failing destination when Filament is not installed', function () {
    $first = FeedbackItem::factory()->create();
    $second = FeedbackItem::factory()->create();

    failedWithoutFilament($first, 'slack');
    failedWithoutFilament($second, 'slack');
    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 1);
    Notification::assertSentOnDemand(DeliveryFailedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'dev@team.test'
        && $channels === ['mail'] && $notification->item->is($first) && $notification->driver === 'slack' && $notification->code === 'connection_error');

    failedWithoutFilament($second, 'github');
    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 2);

    $this->travel(23)->hours();
    failedWithoutFilament($second, 'slack');
    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 2);

    $this->travel(1)->hours();
    failedWithoutFilament($second, 'slack');
    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 3);
});

it('e-mails developers who are user models by mail only, whatever the notification channels', function () {
    config()->set('feedback-board.notifications.channels', ['mail', 'database']);
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'dev@team.test']);
    config()->set('feedback-board.notifications.developers', fn () => collect([$dev]));

    failedWithoutFilament(FeedbackItem::factory()->create(), 'webhook');

    Notification::assertSentTo($dev, DeliveryFailedNotification::class, fn ($notification, $channels) => $channels === ['mail']);
});

it('sends no e-mail when Filament is installed, whose bell tells the developers', function () {
    $item = FeedbackItem::factory()->create();

    (new DeliverToDestination(new FeedbackItemCreated($item), 'slack', 'dlv-filament'))
        ->failed(new ConnectionException('cURL error 6: Could not resolve host'));

    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 0);
});

it('tries again 15 minutes later, not on every failure, when no developer could be e-mailed', function () {
    Exceptions::fake();
    $start = now();
    $tries = [];
    Notification::shouldReceive('send')->twice()->andReturnUsing(function () use ($start, &$tries) {
        $tries[] = (int) $start->diffInMinutes(now());

        if (count($tries) === 1) {
            throw new RuntimeException('smtp down');
        }
    });

    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');
    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');
    $this->travel(15)->minutes();
    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');

    expect($tries)->toBe([0, 15]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'smtp down');
});

it("holds the day's e-mail once one developer got it", function () {
    Exceptions::fake();
    config()->set('feedback-board.notifications.developers', ['first@team.test', 'second@team.test']);
    $calls = 0;
    Notification::shouldReceive('send')->twice()->andReturnUsing(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('mailbox full');
        }
    });

    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');
    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');
});

it('skips a developer with no e-mail address, without using up the day', function () {
    $nameless = TestUser::create(['name' => 'No mail', 'email' => '']);
    config()->set('feedback-board.notifications.developers', fn () => collect([$nameless]));
    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');

    config()->set('feedback-board.notifications.developers', ['dev@team.test']);
    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');

    Notification::assertNothingSentTo($nameless);
    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 1);
});

it('sends no failure e-mail when the app turned developer e-mail off', function () {
    config()->set('feedback-board.notifications.channels', ['database']);

    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');

    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 0);
});

it("keeps the day's e-mail while there is no developer to tell", function () {
    config()->set('feedback-board.notifications.developers', []);
    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');

    config()->set('feedback-board.notifications.developers', ['dev@team.test']);
    failedWithoutFilament(FeedbackItem::factory()->create(), 'slack');

    Notification::assertSentOnDemandTimes(DeliveryFailedNotification::class, 1);
});

it('names the destination, the reason, the report and the pause until the next e-mail', function () {
    $item = FeedbackItem::factory()->create(['title' => 'Checkout <b>breaks</b>']);

    $mail = (new DeliveryFailedNotification($item, 'github', 'auth_401'))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Delivery to GitHub failed')
        ->and($html)->toContain('Delivery to GitHub failed: access was refused; check the token and its permissions.')
        ->and($html)->toContain('It happened while sending report #'.$item->id.':')
        ->and($html)->not->toContain('<b>breaks</b>')
        ->and($html)->toContain('You will get no other e-mail about GitHub for 24 hours.')
        ->and($html)->toContain('/feedback-board/feedback/board?report='.$item->id);
});

it('writes the failure e-mail in Arabic', function () {
    app()->setLocale('ar');
    $item = FeedbackItem::factory()->create();

    $mail = (new DeliveryFailedNotification($item, 'slack', 'not_found_404'))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('تعذّر الإرسال إلى Slack')
        ->and((string) $mail->render())->toContain('لن يصلك بريد آخر عن Slack قبل 24 ساعة.');
});
