<?php

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Vite;
use YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage;
use YousefAman\FeedbackBoard\Filament\PanelColors;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev'));

it('prints the panel primary as bridge tokens with a readable contrast colour', function (?array $primary, array $palette, string $contrast) {
    if ($primary !== null) {
        Filament::getPanel('admin')->colors(['primary' => $primary]);
    }

    $html = $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('<style>:root{--fb-panel-accent:'.$palette[600].';--fb-panel-accent-hover:'.$palette[500].';--fb-panel-accent-contrast:'.$contrast.'}</style>');
})->with([
    // Amber is Filament's default primary and a light one: white on amber-600 is 3.2:1, its 950 shade 5:1.
    'amber, a light primary' => [null, Color::Amber, Color::Amber[950]],
    // White on indigo-600 is 6.6:1, far from the threshold on every Filament minor.
    'indigo, a dark primary' => [Color::Indigo, Color::Indigo, '#ffffff'],
]);

it('carries the Vite CSP nonce when the app sets one', function () {
    Vite::useCspNonce('n0nce');

    $html = $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))->get(FeedbackBoardPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('<style nonce="n0nce">:root{--fb-panel-accent:');
});

it('prints nothing for a missing shade or a value outside the allow-list', function (array $palette) {
    expect(PanelColors::tokens($palette))->toBeNull();
})->with([
    'no 950 shade' => [[500 => Color::Pink[500], 600 => Color::Pink[600]]],
    'a CSS injection' => [[500 => Color::Pink[500], 600 => 'red;}</style><script>alert(1)</script>', 950 => Color::Pink[950]]],
    'a named colour' => [[500 => 'red', 600 => 'red', 950 => 'black']],
]);

it('accepts oklch numbers and hex only', function () {
    expect(PanelColors::allowed('oklch(0.592 0.249 0.584)'))->toBeTrue()
        ->and(PanelColors::allowed('oklch(0.59782352941176 0.16913636363636 0.584)'))->toBeTrue()
        ->and(PanelColors::allowed('#db2777'))->toBeTrue()
        ->and(PanelColors::allowed('oklch(var(--x) 0 0)'))->toBeFalse()
        ->and(PanelColors::allowed('rgb(0, 0, 0)'))->toBeFalse()
        ->and(PanelColors::allowed(null))->toBeFalse();
});

it('rejects a value with a trailing newline', function () {
    expect(PanelColors::allowed("#db2777\n"))->toBeFalse()
        ->and(PanelColors::allowed("oklch(0.592 0.249 0.584)\n"))->toBeFalse();
});
