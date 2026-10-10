{{-- The new-report sheet inside a slide-over: heading, intro and close, then the form. Needs $titleId. --}}
<div class="fb-slide-over__head">
    <div class="fb-slide-over__titles">
        <h2 id="{{ $titleId }}" class="fb-slide-over__title">{{ __('feedback-board::feedback-board.form.heading') }}</h2>
        <p class="fb-slide-over__sub">{{ __('feedback-board::feedback-board.form.intro') }}</p>
    </div>
    <button type="button" class="fb-icon-btn" x-on:click="$dispatch('fb-overlay-close')" aria-label="{{ __('feedback-board::feedback-board.close') }}">
        <x-feedback-board::icon name="x-mark" />
    </button>
</div>
@livewire('feedback-board::feedback-form')
