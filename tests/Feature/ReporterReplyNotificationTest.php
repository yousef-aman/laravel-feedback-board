<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use YousefAman\FeedbackBoard\Actions\ReplyToFeedback;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, ReporterRepliedNotification};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    config()->set('feedback-board.notifications.developers', ['dev@team.com']);
    config()->set('feedback-board.notifications.channels', ['mail']);
    $this->client = TestUser::create(['name' => 'C', 'email' => 'c@x.com']);
    $this->item = FeedbackItem::factory()->for($this->client, 'reporter')->create(['title' => 'Checkout button broken']);
});

it('tells developers which report the reporter answered', function () {
    app(ReplyToFeedback::class)->handle($this->item, 'Still broken on my side', $this->client, AuthorRole::Reporter);

    Notification::assertSentOnDemand(ReporterRepliedNotification::class, function (ReporterRepliedNotification $notification, array $channels, object $notifiable) {
        $mail = $notification->toMail($notifiable);

        return $mail->subject === 'Reporter replied: Checkout button broken'
            && $mail->introLines === ['The reporter replied on report #'.$this->item->id.'.', "\u{2068}Still broken on my side\u{2069}"];
    });
    Notification::assertSentOnDemandTimes(FeedbackRepliedNotification::class, 0);
});

it('names the report when the reply carries only images', function () {
    app(ReplyToFeedback::class)->handle($this->item, '', $this->client, AuthorRole::Reporter);

    Notification::assertSentOnDemand(ReporterRepliedNotification::class, function (ReporterRepliedNotification $notification, array $channels, object $notifiable) {
        $mail = $notification->toMail($notifiable);

        return $mail->subject === 'Reporter replied: Checkout button broken'
            && $mail->introLines === ['The reporter replied on report #'.$this->item->id.'.', 'A reply with images was added.'];
    });
});

it('mails e-mail-only developer recipients even with the database channel on', function () {
    config()->set('feedback-board.notifications.channels', ['mail', 'database']);
    $comment = app(ReplyToFeedback::class)->handle($this->item, 'x', $this->client, AuthorRole::Reporter);

    expect((new ReporterRepliedNotification($comment))->via(new AnonymousNotifiable))->toBe(['mail']);
});

it('still tells the reporter about a developer reply, and nobody else', function () {
    app(ReplyToFeedback::class)->handle($this->item, 'Fixed now', null, AuthorRole::Developer);

    Notification::assertSentTo($this->client, FeedbackRepliedNotification::class);
    Notification::assertSentOnDemandTimes(ReporterRepliedNotification::class, 0);
});

it('speaks Arabic', function () {
    app()->setLocale('ar');
    $comment = app(ReplyToFeedback::class)->handle($this->item, 'x', $this->client, AuthorRole::Reporter);

    $mail = (new ReporterRepliedNotification($comment))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('ردّ المُبلِّغ على: Checkout button broken')
        ->and($mail->introLines[0])->toBe('ردّ المُبلِّغ على البلاغ رقم '.$this->item->id.'.');
});
