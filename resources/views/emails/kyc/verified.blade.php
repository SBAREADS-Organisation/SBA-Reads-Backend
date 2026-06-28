<x-mail::message>
# Your Identity Has Been Verified

Hi {{ ($user->first_name && strtoupper($user->first_name) !== 'NO NAME') ? $user->first_name : 'there' }},

Great news! Your identity has been successfully verified on **SBA Reads**. Your author account is now fully active.

<x-mail::panel>
You can now publish books, reach readers, and receive payouts directly to your bank account. Everything is set up and ready to go.
</x-mail::panel>

Here's what you can do next:

- Upload and publish your books
- Set up or confirm your payout method in the app
- Share your author profile with your readers

<x-mail::button :url="config('app.frontend_url', 'https://sbareads.com')">
Go to Your Dashboard
</x-mail::button>

If you have any questions, reply to this email or contact us at **support@sbareads.com**.

Thanks,
{{ config('app.name') }} Team
</x-mail::message>
