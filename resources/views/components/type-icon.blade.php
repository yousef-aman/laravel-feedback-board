@props(['type'])
<span {{ $attributes->class(['fb-type-icon', 'fb-type-icon--'.$type->value]) }} aria-hidden="true"><x-feedback-board::icon :name="$type->icon()" /></span>
