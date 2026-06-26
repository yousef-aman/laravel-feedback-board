{{-- resources/views/pages/feedback.blade.php --}}
<!doctype html>
<html dir="{{ __('feedback-board::feedback-board.dir') }}">
<head><meta charset="utf-8"><title>{{ __('feedback-board::feedback-board.board.my_reports') }}</title>@livewireStyles</head>
<body>
    @livewire('feedback-board::my-reports-board')
    @livewireScripts
</body>
</html>
