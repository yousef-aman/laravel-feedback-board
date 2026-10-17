<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\{FeedbackClosedNotification, FeedbackResolvedNotification};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('feedback-board.notifications.channels', ['mail']);
    $this->client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
});

function hClosedItem(FeedbackStatus $status): FeedbackItem
{
    return FeedbackItem::factory()->for(test()->client, 'reporter')->create(['title' => 'Export is slow', 'status' => $status]);
}

it('tells the reporter when an open report is closed or declined', function (FeedbackStatus $from, FeedbackStatus $to, string $subject, string $line) {
    $item = hClosedItem($from);

    app(TransitionStatus::class)->handle($item, $to, null);

    Notification::assertSentTo($this->client, FeedbackClosedNotification::class, function (FeedbackClosedNotification $notification) use ($subject, $line) {
        $mail = $notification->toMail($this->client);

        return $mail->subject === $subject && $mail->introLines === [$line];
    });
})->with([
    'new, closed' => [FeedbackStatus::New, FeedbackStatus::Closed, 'Closed: Export is slow', 'Your report was closed.'],
    'in review, declined' => [FeedbackStatus::InReview, FeedbackStatus::Declined, 'Declined: Export is slow', 'Your report was declined.'],
    'in progress, closed' => [FeedbackStatus::InProgress, FeedbackStatus::Closed, 'Closed: Export is slow', 'Your report was closed.'],
]);

it('says it in Arabic', function (FeedbackStatus $to, string $subject, string $line) {
    app()->setLocale('ar');
    $item = hClosedItem(FeedbackStatus::New);

    $mail = (new FeedbackClosedNotification($item, $to))->toMail($this->client);

    expect($mail->subject)->toBe($subject)->and($mail->introLines)->toBe([$line]);
})->with([
    'closed' => [FeedbackStatus::Closed, 'تم الإغلاق: Export is slow', 'تم إغلاق ملاحظتك.'],
    'declined' => [FeedbackStatus::Declined, 'تم الرفض: Export is slow', 'تم رفض ملاحظتك.'],
]);

it('stays quiet when a finished report is only filed away', function (FeedbackStatus $from, FeedbackStatus $to) {
    $item = hClosedItem($from);

    app(TransitionStatus::class)->handle($item, $to, null);

    Notification::assertNothingSentTo($this->client);
})->with([
    'resolved, closed' => [FeedbackStatus::Resolved, FeedbackStatus::Closed],
    'declined, closed' => [FeedbackStatus::Declined, FeedbackStatus::Closed],
    'closed, declined' => [FeedbackStatus::Closed, FeedbackStatus::Declined],
]);

it('still tells the reporter about a resolution, and nothing else', function () {
    $item = hClosedItem(FeedbackStatus::InProgress);

    app(TransitionStatus::class)->handle($item, FeedbackStatus::Resolved, null);

    Notification::assertSentTo($this->client, FeedbackResolvedNotification::class);
    Notification::assertNotSentTo($this->client, FeedbackClosedNotification::class);
});

it('puts the escaped title, the kind and a link to My reports in the bell', function () {
    $item = hClosedItem(FeedbackStatus::New);
    $item->forceFill(['title' => '<a href="https://evil.test">x</a>'])->save();

    $data = (new FeedbackClosedNotification($item, FeedbackStatus::Declined))->toArray(new AnonymousNotifiable);

    expect($data['kind'])->toBe('declined')
        ->and($data['title'])->toContain('&lt;a href=')->not->toContain('<a href=')
        ->and($data['feedback_url'])->toBe('http://localhost/feedback-board/feedback?report='.$item->id);
});
