{{-- Laravel's notification template (notifications::email) without the button, inside the app's mail layout. The body takes the app's text direction, and the greeting and sign-off come from the package's translations. --}}
<x-mail::message>
<div dir="{!! __('feedback-board::feedback-board.dir') === 'rtl' ? 'rtl' : 'ltr' !!}">

# {{ __('feedback-board::feedback-board.mail.greeting') }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
{{ __('feedback-board::feedback-board.mail.salutation') }}<br>
{{ config('app.name') }}

</div>
</x-mail::message>
