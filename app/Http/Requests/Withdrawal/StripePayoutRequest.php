<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;


class StripePayoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->account_type === 'author';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'The payout amount is required.',
            'amount.numeric' => 'The payout amount must be a number.',
            'amount.min' => 'The payout amount must be at least 1.',
            'currency.required' => 'The currency code is required.',
            'currency.string' => 'The currency code must be a string.',
            'currency.size' => 'The currency code must be exactly 3 characters.',
        ];
    }
}
