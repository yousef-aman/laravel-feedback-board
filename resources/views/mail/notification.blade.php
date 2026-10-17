{{-- Laravel's notification template (notifications::email) inside the app's mail layout. The body and the link help take the app's text direction, and the default greeting, sign-off, button help and link help come from the package's translations. --}}
@php($fbDir = __('feedback-board::feedback-board.dir') === 'rtl' ? 'rtl' : 'ltr')
<x-mail::message>
<div dir="{!! $fbDir !!}">

@if (! empty($greeting))
# {{ $greeting }}
@elseif ($level === 'error')
# @lang('Whoops!')
@else
# {{ __('feedback-board::feedback-board.mail.greeting') }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach
@isset($actionText)
@php($fbColor = in_array($level, ['success', 'error'], true) ? $level : 'primary')
<x-mail::button :url="$actionUrl" :color="$fbColor">
{{ $actionText }}
</x-mail::button>

@endisset
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
@if (! empty($salutation))
{{ $salutation }}
@else
{{ __('feedback-board::feedback-board.mail.salutation') }}<br>
{{ config('app.name') }}
@endif

</div>
@isset($actionText)
<x-slot:subcopy>
<div dir="{!! $fbDir !!}">

{{ __('feedback-board::feedback-board.mail.link_help', ['action' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>

</div>
</x-slot:subcopy>
@endisset
</x-mail::message>
