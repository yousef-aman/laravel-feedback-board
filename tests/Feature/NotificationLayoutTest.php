<?php

use Illuminate\Support\Facades\{Notification, View};
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification, NewFeedbackNotification, ReporterRepliedNotification};

beforeEach(function () {
    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('feedback-board.notifications.channels', ['mail']);
});

/** @return array{0: string, 1: string} the HTML and the plain-text part of the mail the notification sends */
function hLayoutMail(Illuminate\Notifications\Notification $notification): array
{
    Notification::route('mail', 'dev@team.test')->notify($notification);
    $email = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    return [(string) $email->getHtmlBody(), (string) $email->getTextBody()];
}

/**
 * The element in the mail body that carries a dir attribute: its direction, the text-align of
 * each paragraph and heading inside it, and its text.
 *
 * @return array{dir: ?string, aligns: list<string>, text: string}
 */
function hBody(string $html): array
{
    $doc = new DOMDocument;
    $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);
    $wrapper = $xpath->query("//td[contains(@class, 'content-cell')]//*[@dir]")->item(0);

    if ($wrapper === null) {
        return ['dir' => null, 'aligns' => [], 'text' => ''];
    }

    $aligns = [];
    foreach ($xpath->query('.//p | .//h1', $wrapper) as $block) {
        preg_match_all('/text-align:\s*([a-z]+)/', $block->getAttribute('style'), $m);
        $aligns[] = $m[1] === [] ? 'none' : end($m[1]);
    }

    return [
        'dir' => $wrapper->getAttribute('dir'),
        'aligns' => array_values(array_unique($aligns)),
        'text' => trim((string) preg_replace('/\s+/u', ' ', $wrapper->textContent)),
    ];
}

function hLayoutComment(AuthorRole $role): FeedbackComment
{
    $item = FeedbackItem::factory()->create(['title' => 'زر الحفظ (Orders) رقم 15']);

    return FeedbackComment::factory()->for($item, 'item')->create(['author_role' => $role, 'body' => 'جرّب (Ctrl+F5) أولًا.']);
}

dataset('feedback mails', [
    'new report' => fn () => new NewFeedbackNotification(FeedbackItem::factory()->create(['title' => 'زر الحفظ (Orders) رقم 15'])),
    'reply to the reporter' => fn () => new FeedbackRepliedNotification(hLayoutComment(AuthorRole::Developer)),
    'reporter reply' => fn () => new ReporterRepliedNotification(hLayoutComment(AuthorRole::Reporter)),
    'resolved' => fn () => new FeedbackResolvedNotification(FeedbackItem::factory()->create(['title' => 'زر الحفظ (Orders) رقم 15'])),
]);

it('lays the e-mail out right to left in Arabic', function (Illuminate\Notifications\Notification $notification) {
    app()->setLocale('ar');

    [$html] = hLayoutMail($notification);

    expect(hBody($html))->toMatchArray(['dir' => 'rtl', 'aligns' => ['right']]);
})->with('feedback mails');

it('greets and signs off in Arabic, in both parts of the e-mail', function (Illuminate\Notifications\Notification $notification) {
    app()->setLocale('ar');
    config()->set('app.name', 'متجري');

    [$html, $text] = hLayoutMail($notification);

    expect(hBody($html)['text'])->toStartWith('مرحبًا!')->toEndWith('مع التحية، متجري')
        ->and($text)->toContain('مرحبًا!')->toContain('مع التحية،')
        ->and($html.$text)->not->toContain('Hello!')->not->toContain('Regards,');
})->with('feedback mails');

it('keeps the English e-mail left to right, with its English greeting and sign-off', function (Illuminate\Notifications\Notification $notification) {
    config()->set('app.name', 'Shop');

    [$html] = hLayoutMail($notification);
    $body = hBody($html);

    expect($body['dir'])->toBe('ltr')
        ->and($body['aligns'])->not->toContain('right')
        ->and($body['text'])->toStartWith('Hello!')->toEndWith('Regards, Shop');
})->with('feedback mails');

it('keeps the markup out of the plain-text part', function (Illuminate\Notifications\Notification $notification) {
    app()->setLocale('ar');

    [, $text] = hLayoutMail($notification);

    expect($text)->not->toContain('<div')->not->toContain('dir=');
})->with('feedback mails');

it("applies the app's own mail theme along with the right-to-left alignment", function () {
    $views = sys_get_temp_dir().'/fb-host-views-'.getmypid();
    @mkdir($views.'/mail', 0777, true);
    file_put_contents($views.'/mail/fb-host.css', ".content-cell { color: #123456; }\np { text-align: left; }\n");
    View::addLocation($views);
    config()->set('mail.markdown.theme', 'fb-host');
    app()->setLocale('ar');

    try {
        [$html] = hLayoutMail(new NewFeedbackNotification(FeedbackItem::factory()->create()));
    } finally {
        @unlink($views.'/mail/fb-host.css');
        @rmdir($views.'/mail');
        @rmdir($views);
    }

    expect($html)->toContain('color: #123456')
        ->and(hBody($html)['aligns'])->toBe(['right']);
});

function hItemId(Illuminate\Notifications\Notification $notification): int
{
    return $notification instanceof FeedbackRepliedNotification || $notification instanceof ReporterRepliedNotification
        ? $notification->comment->feedback_item_id
        : $notification->item->id;
}

/** @return array{href: ?string, text: string} the e-mail's button */
function hButton(string $html): array
{
    $doc = new DOMDocument;
    $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $a = (new DOMXPath($doc))->query("//a[contains(@class, 'button')]")->item(0);

    return ['href' => $a?->getAttribute('href'), 'text' => trim((string) $a?->textContent)];
}

dataset('feedback mails and their links', [
    'new report, to the board' => [fn () => new NewFeedbackNotification(FeedbackItem::factory()->create()), '/feedback-board/feedback/board?report='],
    'reply, to My reports' => [fn () => new FeedbackRepliedNotification(hLayoutComment(AuthorRole::Developer)), '/feedback-board/feedback?report='],
    'reporter reply, to the board' => [fn () => new ReporterRepliedNotification(hLayoutComment(AuthorRole::Reporter)), '/feedback-board/feedback/board?report='],
    'resolved, to My reports' => [fn () => new FeedbackResolvedNotification(FeedbackItem::factory()->create()), '/feedback-board/feedback?report='],
]);

it('opens the report from a button, with the link spelled out under it and in the plain-text part', function (Illuminate\Notifications\Notification $notification, string $path) {
    $url = 'http://localhost'.$path.hItemId($notification);

    [$html, $text] = hLayoutMail($notification);

    expect(hButton($html))->toBe(['href' => $url, 'text' => 'View report'])
        ->and($html)->toContain('If the “View report” button does not work, copy this link into your browser:')
        ->and($text)->toContain('View report: '.$url);
})->with('feedback mails and their links');

it('labels the button and the link help in Arabic, right to left', function () {
    app()->setLocale('ar');

    [$html] = hLayoutMail(new NewFeedbackNotification(FeedbackItem::factory()->create()));

    $doc = new DOMDocument;
    $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $subcopy = (new DOMXPath($doc))->query("//table[contains(@class, 'subcopy')]//*[@dir]")->item(0);

    expect(hButton($html)['text'])->toBe('عرض البلاغ')
        ->and($subcopy?->getAttribute('dir'))->toBe('rtl')
        ->and($subcopy?->textContent)->toContain('إذا لم يعمل زر «عرض البلاغ»، انسخ هذا الرابط إلى المتصفح:');
});

it('leaves the button out when the app gives no link', function () {
    YousefAman\FeedbackBoard\Facades\FeedbackBoard::linkReportsUsing(fn () => null);

    [$html, $text] = hLayoutMail(new NewFeedbackNotification(FeedbackItem::factory()->create()));

    expect(hButton($html)['href'])->toBeNull()
        ->and($html)->not->toContain('subcopy')
        ->and($text)->not->toContain('View report');
});

it("keeps a greeting, a sign-off or an error level that an app's own notification sets", function () {
    $item = FeedbackItem::factory()->create();

    $custom = (string) YousefAman\FeedbackBoard\Support\MailLayout::message($item, 'developer')
        ->greeting('Hi team')->salutation('Thanks, Ops')->line('Body')->render();
    $error = (string) YousefAman\FeedbackBoard\Support\MailLayout::message($item, 'developer')->error()->line('Body')->render();

    expect(hBody($custom)['text'])->toStartWith('Hi team')->toEndWith('Thanks, Ops')
        ->and(hBody($error)['text'])->toStartWith('Whoops!')
        ->and(hButton($error)['href'])->toBe('http://localhost/feedback-board/feedback/board?report='.$item->id);
});

it('previews the e-mail with render() right to left, with the theme applied', function () {
    app()->setLocale('ar');

    $html = (string) (new NewFeedbackNotification(FeedbackItem::factory()->create()))->toMail(new Illuminate\Notifications\AnonymousNotifiable)->render();

    expect(hBody($html))->toMatchArray(['dir' => 'rtl', 'aligns' => ['right']]);
});

it("lays the e-mail out right to left for a notification sent in the recipient's Arabic", function () {
    [$html] = hLayoutMail((new NewFeedbackNotification(FeedbackItem::factory()->create()))->locale('ar'));

    expect(hBody($html)['dir'])->toBe('rtl')
        ->and(hButton($html)['text'])->toBe('عرض البلاغ');
});

it("applies an app theme that lives in a view namespace", function () {
    $views = sys_get_temp_dir().'/fb-host-ns-'.getmypid();
    @mkdir($views, 0777, true);
    file_put_contents($views.'/brand.css', ".content-cell { color: #654321; }\n");
    View::addNamespace('host', $views);
    config()->set('mail.markdown.theme', 'host::brand');
    app()->setLocale('ar');

    try {
        [$html] = hLayoutMail(new NewFeedbackNotification(FeedbackItem::factory()->create()));
    } finally {
        @unlink($views.'/brand.css');
        @rmdir($views);
    }

    expect($html)->toContain('color: #654321')
        ->and(hBody($html)['aligns'])->toBe(['right']);
});

it("falls back to Laravel's default theme when the app's theme is the package's own", function () {
    config()->set('mail.markdown.theme', 'feedback-board::mail.theme');

    [$html] = hLayoutMail(new NewFeedbackNotification(FeedbackItem::factory()->create()));

    expect($html)->toContain('class="button button-primary"')
        ->and(hBody($html)['dir'])->toBe('ltr');
});

it('puts a button that opens the report in the bell, and the link in the payload', function (Illuminate\Notifications\Notification $notification, string $path) {
    $url = 'http://localhost'.$path.hItemId($notification);

    $data = $notification->toArray(new Illuminate\Notifications\AnonymousNotifiable);

    expect($data['feedback_url'])->toBe($url)
        ->and($data['actions'])->toHaveCount(1)
        ->and($data['actions'][0])->toMatchArray(['label' => 'View report', 'url' => $url, 'shouldMarkAsRead' => true]);
})->with('feedback mails and their links');

it('leaves the bell button out when the app gives no link', function () {
    YousefAman\FeedbackBoard\Facades\FeedbackBoard::linkReportsUsing(fn () => null);

    $data = (new NewFeedbackNotification(FeedbackItem::factory()->create()))->toArray(new Illuminate\Notifications\AnonymousNotifiable);

    expect($data['feedback_url'])->toBeNull()->and($data['actions'])->toBe([]);
});
