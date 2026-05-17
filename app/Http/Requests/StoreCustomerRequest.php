<?php

namespace App\Http\Requests;

use App\Services\PhoneNormalizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Permission already enforced by the route middleware
     * (`permission:customers.create`); this just authorises the
     * authenticated user to actually perform the action.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $allowedCountryCodes = array_keys(PhoneNormalizationService::COUNTRY_RULES);

        return [
            'name' => ['required', 'string', 'max:255'],
            'primary_phone' => ['required', 'string', 'max:32'],
            // O-2: country picker. Optional in the payload — when absent
            // the normalization service falls back to the system default
            // (`+20`). The list comes from
            // PhoneNormalizationService::COUNTRY_RULES so adding a new
            // country is a one-line change there.
            'country_code' => ['nullable', 'string', Rule::in($allowedCountryCodes)],
            'secondary_country_code' => ['nullable', 'string', Rule::in($allowedCountryCodes)],
            'secondary_phone' => ['nullable', 'string', 'max:32'],
            'primary_phone_whatsapp' => ['nullable', 'boolean'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'default_address' => ['required', 'string'],
            'customer_type' => ['nullable', 'in:Normal,VIP,Watchlist,Blacklist'],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ];
    }

    /**
     * O-2: enforce phone normalization at validation time. The form
     * field stays user-friendly (`primary_phone` is whatever the operator
     * typed) — the after-hook parses it and adds a field-level error if
     * the result is invalid for the chosen country.
     *
     * Service errors land on the `primary_phone` / `secondary_phone` key
     * so the existing UI hint surface lights up.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $svc = app(PhoneNormalizationService::class);
        $validator->after(function ($v) use ($svc) {
            $primary = (string) $this->input('primary_phone', '');
            if ($primary !== '') {
                $res = $svc->normalize($primary, $this->input('country_code'));
                if (! $res['valid']) {
                    $v->errors()->add('primary_phone', $res['error'] ?? 'Phone is invalid.');
                }
            }
            $secondary = (string) $this->input('secondary_phone', '');
            if ($secondary !== '') {
                $res = $svc->normalize($secondary, $this->input('secondary_country_code'));
                if (! $res['valid']) {
                    $v->errors()->add('secondary_phone', $res['error'] ?? 'Secondary phone is invalid.');
                }
            }
        });
    }
}
