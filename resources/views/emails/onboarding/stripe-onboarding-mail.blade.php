<x-mail::message>
# Set up your payouts with Stripe

Hi {{ $user->name }},

To receive payouts from Sbareads-Library, please finish your Stripe onboarding. This is a secure process handled by Stripe.

<x-mail::panel>
What to expect: Stripe will ask for information to verify your identity and your payout details (like bank account). This usually takes just a few minutes.
</x-mail::panel>

<x-mail::button :url="$url">
Complete Stripe Onboarding
</x-mail::button>

<x-mail::subcopy>
If you’re having trouble clicking the "Complete Stripe Onboarding" button, copy and paste this URL into your web browser:
<br>
<a href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a>
</x-mail::subcopy>

Thanks,
{{ config('app.name') }} Team
</x-mail::message>
