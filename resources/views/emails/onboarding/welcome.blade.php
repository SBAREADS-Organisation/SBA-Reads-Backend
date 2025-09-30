<x-mail::message>
# Welcome {{ ucfirst($accountType) }} {{ $name }},

We're thrilled to have you join our Sbareads-Library platform!

@if($accountType === 'reader')
You can now enjoy thousands of books, bookmark your favorites, and track your reading journey.
@else
You're now a step closer to becoming a published author on our platform! Once your documents are verified, you’ll be able to upload and share your books globally.
@endif

<x-mail::button :url="config('app.url')">
Visit Sbareads-Library
</x-mail::button>

Thanks,
The Sbareads-Library Team
</x-mail::message>
