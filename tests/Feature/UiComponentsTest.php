<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Blade, Storage};
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Services\AttachmentStorage;
use YousefAman\FeedbackBoard\Support\{Icons, Person};

it('renders inline Heroicons and rejects unknown names', function () {
    $html = Blade::render('<x-feedback-board::icon name="x-mark" class="fb-launcher__icon" />');

    expect($html)->toContain('class="fb-icon fb-launcher__icon"')
        ->toContain('aria-hidden="true"')
        ->toContain('<path d="M6 18 18 6M6 6l12 12"/>');
    expect(fn () => Icons::path('nope'))->toThrow(InvalidArgumentException::class);
});

it('gives every report type an icon, a hint and a Filament colour', function () {
    foreach (FeedbackType::cases() as $type) {
        expect(Icons::path($type->icon()))->toBeString()
            ->and($type->hint())->not->toStartWith('feedback-board::')
            ->and($type->color())->toBeIn(['danger', 'primary', 'info', 'gray']);
    }
});

it('draws an avatar from one letter and a stable hue', function () {
    expect(Person::initial('lena'))->toBe('L')
        ->and(Person::initial('لانا'))->toBe('ل')
        ->and(Person::initial('  '))->toBe('?')
        ->and(Person::hue('Lena'))->toBe(Person::hue('Lena'))
        ->and(Person::hue('Lena'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4);

    expect(Blade::render('<x-feedback-board::avatar name="Lena" size="lg" />'))
        ->toContain('class="fb-avatar fb-avatar--'.Person::hue('Lena').' fb-avatar--lg" aria-hidden="true">L</span>');
});

it('renders a type tile', function () {
    expect(Blade::render('<x-feedback-board::type-icon :type="$type" class="fb-type-icon--sm" />', ['type' => FeedbackType::Bug]))
        ->toContain('class="fb-type-icon fb-type-icon--bug fb-type-icon--sm" aria-hidden="true"');
});

it('renders the slide-over dialog contract', function () {
    $html = Blade::render('<x-feedback-board::slide-over id="fb-test-sheet" labelledby="fb-test-title" wide>Body</x-feedback-board::slide-over>');

    expect($html)->toContain('class="fb-slide-over fb-slide-over--wide" id="fb-test-sheet"')
        ->toContain('x-show="open" x-cloak')
        ->toContain("x-init=\"\$watch('open', (value) => value || refocus())\"")
        ->toContain('x-on:keydown.escape.window="open && (open = false)"')
        ->toContain('x-on:fb-overlay-close.stop="open = false"')
        ->toContain('<div class="fb-slide-over__backdrop" x-on:click="open = false"></div>')
        ->toContain('role="dialog" aria-modal="true" aria-labelledby="fb-test-title" x-trap.noscroll="open"')
        ->toContain('Body');
});

it('lets the caller drive a slide-over from the server', function () {
    $html = Blade::render('<x-feedback-board::slide-over id="fb-test-sheet" labelledby="fb-test-title" show="true" close="$wire.close()">Body</x-feedback-board::slide-over>');

    expect($html)->toContain('x-on:keydown.escape.window="true && ($wire.close())"')
        ->toContain('x-trap.noscroll="true"')
        ->not->toContain('fb-slide-over--wide');
});

it('renders a thumbnail whose file name reaches only data attributes and text', function () {
    Storage::fake('local');
    $item = FeedbackItem::factory()->create();
    $image = app(AttachmentStorage::class)->store(UploadedFile::fake()->image('shot.png'), $item);

    expect(Blade::render('<x-feedback-board::thumb :attachment="$image" />', ['image' => $image]))
        ->toContain('class="fb-thumb"')
        ->toContain('data-fb-src="'.$image->url().'"')
        ->toContain('data-fb-name="shot.png"')
        ->toContain('x-on:click="zoom($el)"')
        ->toContain('alt="" loading="lazy" decoding="async"')
        ->toContain('<span class="fb-sr-only">Enlarge shot.png</span>');
});

it('renders a lightbox that closes on Escape without closing the dialog under it', function () {
    expect(view('feedback-board::partials.lightbox')->render())
        ->toContain('x-on:keydown.escape.stop="closeLightbox()"')
        ->toContain('x-trap.noscroll="lightbox"')
        ->toContain('aria-label="Image preview"')
        ->toContain('download x-bind:href="lightbox ? lightbox.src : null"');
});
