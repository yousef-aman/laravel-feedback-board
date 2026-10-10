{{-- resources/views/pages/feedback.blade.php --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ \YousefAman\FeedbackBoard\Support\Ui::colorScheme() }}">
    <title>{{ __('feedback-board::feedback-board.board.my_reports') }}</title>
    @livewireStyles
</head>
<body class="fb-scope fb-standalone" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}">
    @include('feedback-board::partials.assets')
    <main class="fb-standalone__main fb-standalone__main--narrow">
        @livewire('feedback-board::my-reports-board')
    </main>
    @livewireScripts
</body>
</html>
