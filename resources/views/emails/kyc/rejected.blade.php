<x-mail::message>
<div style="text-align:center; margin-bottom: 8px;">
# Identity Verification Unsuccessful
</div>

Hi {{ ($user->first_name && strtoupper(trim($user->first_name)) !== 'NO NAME') ? $user->first_name : 'there' }},

We were unable to complete your identity verification on **SBA Reads**. This is often due to one of the following reasons:

<x-mail::panel>
- The document uploaded was unclear, expired, or not accepted
- The information submitted did not match your ID document
- Your verification session was not completed within the required timeframe
</x-mail::panel>

**You can reapply at any time.** Open the SBA Reads app, go to **Profile → Author Verification**, and start the process again. A fresh verification link will be generated for you. Make sure to:

- Use a clear, well-lit photo of a valid government-issued ID
- Ensure all details match exactly what's on your document
- Complete the process in one session without closing the app

<x-mail::button :url="config('app.website_url')" color="red">
Open SBA Reads
</x-mail::button>

If you believe this is a mistake or need help, contact us at [support@sbareads.com](mailto:support@sbareads.com) and we'll assist you.

Thanks,
**The {{ config('app.name') }} Team**
</x-mail::message>
