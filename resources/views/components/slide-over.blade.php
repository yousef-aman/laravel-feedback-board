{{--
    An off-canvas dialog on the inline-end edge (left in RTL). `show` (a boolean) and `close` (a
    statement) are Alpine code from the caller's scope: pass package-defined code only, never user
    data. Children close it with the bubbling browser event `fb-overlay-close`. x-trap returns focus
    to the opener; refocus() moves it to the page heading when nothing else holds it (deep links).
--}}
@props(['id', 'labelledby', 'wide' => false, 'show' => 'open', 'close' => 'open = false'])
<div {{ $attributes->class(['fb-slide-over', 'fb-slide-over--wide' => $wide]) }} id="{{ $id }}"
     x-data="{ refocus() { setTimeout(() => { if (document.activeElement && document.activeElement !== document.body) return; const heading = document.querySelector('h1'); if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); } }, 50) }, destroy() { this.refocus() } }"
     x-show="{{ $show }}" x-cloak
     x-init="$watch('{{ $show }}', (value) => value || refocus())"
     x-on:keydown.escape.window="{{ $show }} && ({{ $close }})"
     x-on:fb-overlay-close.stop="{{ $close }}">
    <div class="fb-slide-over__backdrop" x-on:click="{{ $close }}"></div>
    <div class="fb-slide-over__panel" role="dialog" aria-modal="true" aria-labelledby="{{ $labelledby }}" x-trap.noscroll="{{ $show }}">
        {{ $slot }}
    </div>
</div>
