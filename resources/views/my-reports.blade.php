<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ __('feedback-board::feedback-board.board.my_reports') }}</title>
    @livewireStyles
</head>
<body>
    @livewire('feedback-board::my-reports-list')
    @livewireScripts
</body>
</html>
