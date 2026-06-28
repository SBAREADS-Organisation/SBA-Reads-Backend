<x-mail::message>
<div style="text-align:center; margin-bottom: 8px;">
# Your Identity Has Been Verified 🎉
</div>

Hi {{ ($user->first_name && strtoupper(trim($user->first_name)) !== 'NO NAME') ? $user->first_name : 'there' }},

Great news! Your identity has been successfully verified on **SBA Reads**. Your author account is now fully active.

<x-mail::panel>
You can now publish books, reach readers, and receive payouts directly to your bank account. Everything is set up and ready to go.
</x-mail::panel>

Here's what you can do next:

- **Publish your books** — upload and list them for readers
- **Set up your payout method** — go to Wallet → Payout Method in the app
- **Share your author profile** — let your readers find you on SBA Reads

<x-mail::button :url="config('app.website_url')">
Go to Your Dashboard
</x-mail::button>

If you have any questions, contact us at [support@sbareads.com](mailto:support@sbareads.com).

Thanks,
**The {{ config('app.name') }} Team**
</x-mail::message>
