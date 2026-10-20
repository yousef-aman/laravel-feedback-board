<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\AuthorRole;
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackItemModal};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};
use YousefAman\FeedbackBoard\Support\Markdown;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

it('formats the AI assistant’s Markdown on the developer card', function () {
    $this->actingAs(TestUser::create(['name' => 'Dev']));
    $item = FeedbackItem::factory()->create();
    FeedbackComment::factory()->for($item, 'item')->create([
        'author_role' => AuthorRole::Ai,
        'is_internal' => true,
        'body' => "### ملخص للمطور\n\n**السبب:** `discount()` returned the percent.\n\n- one\n- two",
    ]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<h3 dir="auto">ملخص للمطور</h3>')
        ->assertSeeHtml('<strong>السبب:</strong>')
        ->assertSeeHtml('<code>discount()</code>')
        ->assertSeeHtml('<li dir="auto">one</li>')
        ->assertDontSee('### ملخص');
});

it('keeps what people write as plain text', function () {
    $this->actingAs(TestUser::create(['name' => 'Dev']));
    $item = FeedbackItem::factory()->create();
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Developer, 'body' => '**not bold** # not a heading']);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('**not bold** # not a heading')
        ->assertDontSeeHtml('<strong>not bold</strong>');
});

it('formats a public AI reply for the reporter too', function () {
    $me = TestUser::create(['name' => 'Me', 'email' => 'me@x.com']);
    $item = FeedbackItem::factory()->for($me, 'reporter')->create();
    FeedbackComment::factory()->for($item, 'item')->create(['author_role' => AuthorRole::Ai, 'body' => 'Try **Clear cache** first.']);

    Livewire::actingAs($me)->test(FeedbackItemModal::class, ['item' => $item])
        ->assertSeeHtml('<strong>Clear cache</strong>');
});

it('escapes raw HTML in the Markdown', function () {
    $html = (string) Markdown::html("<script>alert(1)</script>\n\n<b onmouseover=x>hi</b>");

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('<b ')
        ->and($html)->toContain('&lt;script&gt;');
});

it('drops links that are not http, https or mailto', function () {
    $html = (string) Markdown::html('[a](javascript:alert(1)) [b](data:text/html,x) [c](vbscript:x) [d](JaVaScRiPt:x) [e](src/Cart.php) [f](/admin/logout) [g](mailto:dev@example.com)');

    expect($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('JaVaScRiPt:')
        ->and($html)->not->toContain('data:text')
        ->and($html)->not->toContain('vbscript:')
        ->and($html)->not->toContain('href="src/Cart.php"')
        ->and($html)->not->toContain('href="/admin/logout"')
        ->and($html)->toContain('href="mailto:dev@example.com"')
        ->and(substr_count($html, '<a '))->toBe(1);
});

it('opens links in a new tab without telling the site where they came from', function () {
    expect((string) Markdown::html('[PR](https://github.com/o/r/pull/5)'))
        ->toContain('href="https://github.com/o/r/pull/5"')
        ->toContain('target="_blank"')
        ->toContain('rel="nofollow noopener noreferrer"');
});

it('never loads an image, so a reply cannot track who reads it', function () {
    $html = (string) Markdown::html('![pixel](https://tracker.example/p.png)');

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('pixel');
});

it('sets each block’s direction from its own text, and keeps code left to right', function () {
    $html = (string) Markdown::html("English first.\n\nثم عربي.\n\n```php\n\$a = 1;\n```");

    expect($html)->toContain('<p dir="auto">English first.</p>')
        ->and($html)->toContain('<p dir="auto">ثم عربي.</p>')
        ->and($html)->toContain('<pre dir="ltr">');
});

it('keeps an http(s) link only when it names a site', function () {
    $html = (string) Markdown::html('[a](https:/admin/logout) [b](https:///evil.com/x) <https:///evil.com/y> [c](http:evil.com)');

    expect($html)->not->toContain('<a ');
});

it('drops unsafe targets however the link is written', function () {
    $html = (string) Markdown::html("<javascript:alert(1)> [ref][x] [ent](&#106;avascript:alert(1)) ![img][y]\n\n[x]: javascript:alert(2)\n[y]: https://tracker.example/p.png");

    expect($html)->not->toContain('<a ')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('href="javascript');
});

it('opens bare www links in a new tab too', function () {
    expect((string) Markdown::html('see www.example.com'))
        ->toContain('<a rel="nofollow noopener noreferrer" target="_blank" href="http://www.example.com">');
});

it('shows a very long text as plain text, so formatting it cannot stall the page', function () {
    $html = (string) Markdown::html(str_repeat('**a** ', 4000)."\n<b>x</b>");

    expect($html)->not->toContain('<strong>')
        ->and($html)->toContain('**a**')
        ->and($html)->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->and($html)->toStartWith('<p dir="auto">');
});

it('marks task list items, so they show a checkbox instead of a bullet', function () {
    expect((string) Markdown::html("- [x] done\n- [ ] next\n- plain"))
        ->toContain('<li dir="auto" class="fb-md__task"><input checked="" disabled="" type="checkbox"> done</li>')
        ->toContain('<li dir="auto" class="fb-md__task"><input disabled="" type="checkbox"> next</li>')
        ->toContain('<li dir="auto">plain</li>');
});

it('lets a list item or a quote take its direction from the paragraphs inside it', function () {
    $html = (string) Markdown::html("- أولاً\n\n- ثانياً\n\n> ملاحظة مهمة");

    expect($html)->toContain("<li dir=\"auto\">\n<p>أولاً</p>")
        ->and($html)->toContain("<blockquote dir=\"auto\">\n<p>ملاحظة مهمة</p>");
});
