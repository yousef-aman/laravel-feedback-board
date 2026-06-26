<div x-data="{ open: false }">
    <button type="button" @click="open = true" class="fb-launcher">
        {{ __('feedback-board::feedback-board.launcher') }}
    </button>
    <div x-show="open" x-cloak class="fb-modal">
        <div class="fb-modal__panel">
            <button type="button" @click="open = false" class="fb-modal__close">&times;</button>
            @livewire('feedback-board::feedback-form')
        </div>
    </div>
</div>
