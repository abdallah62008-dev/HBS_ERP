<?php

namespace App\Http\Requests;

use App\Services\PhoneNormalizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $allowedCountryCodes = array_keys(PhoneNormalizationService::COUNTRY_RULES);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'primary_phone' => ['sometimes', 'required', 'string', 'max:32'],
            // O-2: phone triple is optional on update — re-uses StoreRequest semantics.
            'country_code' => ['nullable', 'string', Rule::in($allowedCountryCodes)],
            'secondary_country_code' => ['nullable', 'string', Rule::in($allowedCountryCodes)],
            'secondary_phone' => ['nullable', 'string', 'max:32'],
            'primary_phone_whatsapp' => ['nullable', 'boolean'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'required', 'string', 'max:255'],
            'default_address' => ['sometimes', 'required', 'string'],
            'customer_type' => ['nullable', 'in:Normal,VIP,Watchlist,Blacklist'],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ];
    }

    /**
     * O-2: same after-hook as StoreCustomerRequest — keep the contract
     * symmetrical so create and edit paths reject the same bad inputs.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $svc = app(PhoneNormalizationService::class);
        $validator->after(function ($v) use ($svc) {
            if ($this->has('primary_phone')) {
                $primary = (string) $this->input('primary_phone', '');
                if ($primary !== '') {
                    $res = $svc->normalize($primary, $this->input('country_code'));
                    if (! $res['valid']) {
                        $v->errors()->add('primary_phone', $res['error'] ?? 'Phone is invalid.');
                    }
                }
            }
            if ($this->has('secondary_phone')) {
                $secondary = (string) $this->input('secondary_phone', '');
                if ($secondary !== '') {
                    $res = $svc->normalize($secondary, $this->input('secondary_country_code'));
                    if (! $res['valid']) {
                        $v->errors()->add('secondary_phone', $res['error'] ?? 'Secondary phone is invalid.');
                    }
                }
            }
        });
    }
}
