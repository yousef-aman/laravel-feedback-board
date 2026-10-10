@props(['name', 'size' => 'sm'])
@php($fbAvatarName = filled($name) ? (string) $name : '?')
<span {{ $attributes->class(['fb-avatar', 'fb-avatar--'.\YousefAman\FeedbackBoard\Support\Person::hue($fbAvatarName), 'fb-avatar--lg' => $size === 'lg']) }} aria-hidden="true">{{ \YousefAman\FeedbackBoard\Support\Person::initial($fbAvatarName) }}</span>
