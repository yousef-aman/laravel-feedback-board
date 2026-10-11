{{-- The standalone pages' app bar: the app's name and a way back. ['wide' => true] for the full-width board. --}}
<header @class(['fb-appbar', 'fb-appbar--wide' => $wide ?? false])>
    <div class="fb-appbar__inner">
        <span class="fb-appbar__name" dir="auto">{{ config('app.name') }}</span>
        <a class="fb-appbar__back" href="{{ url('/') }}"><x-feedback-board::icon name="home" />{{ __('feedback-board::feedback-board.standalone.back') }}</a>
    </div>
</header>
