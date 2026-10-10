<div class="fb-scope" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @include('feedback-board::partials.assets')
    <button type="button" class="fb-launcher" x-on:click="open = true; $dispatch('fb-launcher-opened')" aria-haspopup="dialog" x-bind:aria-expanded="open">
        <svg class="fb-launcher__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span class="fb-launcher__label">{{ __('feedback-board::feedback-board.launcher') }}</span>
    </button>
    <div class="fb-modal" x-show="open" x-cloak x-on:click.self="open = false">
        <div class="fb-modal__panel" role="dialog" aria-modal="true" aria-labelledby="fb-launcher-title" x-trap.noscroll="open">
            <div class="fb-modal__header">
                <h2 id="fb-launcher-title" class="fb-modal__title">{{ __('feedback-board::feedback-board.launcher') }}</h2>
                <button type="button" class="fb-icon-btn" x-on:click="open = false" aria-label="{{ __('feedback-board::feedback-board.close') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="fb-modal__body">
                @livewire('feedback-board::feedback-form')
            </div>
        </div>
    </div>
</div>
