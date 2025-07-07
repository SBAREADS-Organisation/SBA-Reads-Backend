{{-- <x-mail::message>
# Introduction

The body of your message.

<x-mail::button :url="''">
Button Text
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message> --}}
@component('mail::message')
# Hello {{ $recipientName }},

Your new book titled **"{{ $book->title }}"** has been successfully created in the system.

@component('mail::panel')
- **Status**: {{ ucfirst($book->status) }}
- **Created At**: {{ $book->created_at->format('F j, Y g:i A') }}
@endcomponent

@if ($book->status === 'pending_review')
We will notify you once the book has been reviewed by the admin team.
@endif

@component('mail::button', ['url' => url("/books/{$book->id}")])
View Book
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
