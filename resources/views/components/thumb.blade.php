{{-- The file name reaches only data-* (read through $el.dataset) and text, never an expression. --}}
@props(['attachment'])
<button type="button" {{ $attributes->class(['fb-thumb']) }} data-fb-src="{{ $attachment->url() }}" data-fb-name="{{ $attachment->original_name }}" x-on:click="zoom($el)">
    <img src="{{ $attachment->url() }}" alt="" loading="lazy" decoding="async">
    <span class="fb-sr-only">{{ __('feedback-board::feedback-board.images.enlarge', ['name' => $attachment->original_name]) }}</span>
</button>
