@component('mail::message')
# 🎯 {{ $milestone }}% Completed!

Hey {{ $notifiable->name }},<br>

You've just reached **{{ $milestone }}%** of your reading journey in "**{{ $book->title }}**".

@component('mail::button', ['url' => url("/books/{$book->id}")])
Continue Reading
@endcomponent

Happy reading! 📚

Thanks,
{{ config('app.name') }}
@endcomponent
