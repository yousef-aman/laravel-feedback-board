{{--
    Needs, in an ancestor x-data: lightbox: null, zoom(el) { … }, closeLightbox() { … }.
    Escape is bound here with .stop, so closing the lightbox never closes the dialog under it.
--}}
<div class="fb-lightbox" x-show="lightbox" x-cloak role="dialog" aria-modal="true" aria-label="{{ __('feedback-board::feedback-board.images.preview') }}"
     x-trap.noscroll="lightbox" x-on:keydown.escape.stop="closeLightbox()" x-on:click.self="closeLightbox()">
    <div class="fb-lightbox__bar">
        <button type="button" class="fb-round-btn" x-on:click="closeLightbox()">
            <x-feedback-board::icon name="x-mark" />
            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.images.close') }}</span>
        </button>
        <span class="fb-lightbox__name" dir="auto" x-text="lightbox ? lightbox.name : ''"></span>
        <a class="fb-btn fb-btn--sm" download x-bind:href="lightbox ? lightbox.src : null">
            <x-feedback-board::icon name="arrow-down-tray" />{{ __('feedback-board::feedback-board.images.download') }}
        </a>
    </div>
    <img class="fb-lightbox__img" alt="" x-bind:src="lightbox ? lightbox.src : ''">
</div>
