Welcome {{ ucfirst($accountType) }} {{ $name }},

We're thrilled to have you join our E-Library platform!

@if($accountType === 'reader')
You can now enjoy thousands of books, bookmark your favorites, and track your reading journey.
@else
You're now a step closer to becoming a published author on our platform!
Once your documents are verified, you’ll be able to upload and share your books globally.
@endif

Thanks,
The Sbareads-Library Team
{{ config('app.url') }}
