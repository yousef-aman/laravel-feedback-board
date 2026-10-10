@assets
<link rel="stylesheet" href="{{ \YousefAman\FeedbackBoard\Support\Ui::stylesheetUrl() }}"@if ($fbNonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $fbNonce }}"@endif>
@endassets
