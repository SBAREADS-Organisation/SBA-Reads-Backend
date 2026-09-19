<?php

namespace App\Http\Controllers\Referral;

use App\Http\Controllers\Controller;
use App\Models\ReferralCode;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ReferralCodeController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $codes = ReferralCode::withCount('signups')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'code'       => $c->code,
                'label'      => $c->label,
                'active'     => $c->active,
                'signups'    => $c->signups_count,
                'created_at' => $c->created_at,
            ]);

        return $this->success($codes, 'Referral codes retrieved.');
    }

    public function store(Request $request)
    {
        // Normalise to uppercase before validation so the unique check is case-insensitive
        $input = $request->all();
        if (! empty($input['code'])) {
            $input['code'] = strtoupper($input['code']);
        }

        $validator = Validator::make($input, [
            'code'  => 'nullable|string|max:50|alpha_dash|unique:referral_codes,code',
            'label' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed.', 422, $validator->errors());
        }

        $code = ! empty($input['code']) ? $input['code'] : strtoupper(Str::random(8));

        $referral = ReferralCode::create([
            'code'       => $code,
            'label'      => $request->label,
            'created_by' => $request->user()->id,
            'active'     => true,
        ]);

        return $this->success([
            'id'    => $referral->id,
            'code'  => $referral->code,
            'label' => $referral->label,
            'link'  => 'sbareads://signup?referral=' . $referral->code,
        ], 'Referral code created.', 201);
    }

    public function show(string $code)
    {
        $referral = ReferralCode::where('code', strtoupper($code))->firstOrFail();

        $signups = $referral->signups()
            ->select('id', 'email', 'account_type', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'id'         => $referral->id,
            'code'       => $referral->code,
            'label'      => $referral->label,
            'active'     => $referral->active,
            'signups'    => $signups->count(),
            'link'       => 'sbareads://signup?referral=' . $referral->code,
            'users'      => $signups,
            'created_at' => $referral->created_at,
        ], 'Referral code details.');
    }

    public function destroy(string $code)
    {
        $referral = ReferralCode::where('code', strtoupper($code))->firstOrFail();
        $referral->update(['active' => false]);

        return $this->success(null, 'Referral code deactivated.');
    }
}
