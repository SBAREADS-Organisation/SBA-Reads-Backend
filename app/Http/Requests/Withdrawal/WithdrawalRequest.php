<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1.00',
            'currency' => 'required|string|size:3|in:usd,eur,gbp',
            'bank_account_id' => 'nullable|string',
            'withdrawal_method' => 'nullable|string|in:bank_transfer,paypal,check',
            'description' => 'nullable|string|max:255',
        ];
    }

    /**
     * Get custom error messages
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Withdrawal amount is required',
            'amount.numeric' => 'Withdrawal amount must be a valid number',
            'amount.min' => 'Minimum withdrawal amount is $1.00',
            'currency.required' => 'Currency is required',
            'currency.size' => 'Currency must be a 3-letter code',
            'currency.in' => 'Supported currencies: USD, EUR, GBP',
            'description.max' => 'Description must not exceed 255 characters',
        ];
    }
}
