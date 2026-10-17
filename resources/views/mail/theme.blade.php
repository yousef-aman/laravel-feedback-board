{{-- The app's mail theme (mail.markdown.theme), found the way Laravel's Markdown renderer finds it, plus right-aligned text in a right-to-left e-mail: Laravel's default theme sets every paragraph to text-align: left. --}}
@php($fbTheme = (string) config('mail.markdown.theme', 'default'))
@include(view()->exists(\Illuminate\Support\Str::start($fbTheme, 'mail.')) ? \Illuminate\Support\Str::start($fbTheme, 'mail.') : (str_contains($fbTheme, '::') ? $fbTheme : 'mail::themes.'.$fbTheme))

[dir="rtl"] h1, [dir="rtl"] h2, [dir="rtl"] h3, [dir="rtl"] p, [dir="rtl"] ul, [dir="rtl"] ol, [dir="rtl"] blockquote {
    text-align: right;
}
