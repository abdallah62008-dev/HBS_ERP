<?php

namespace App\Services;

/**
 * Orders & Products O-2 — phone number normalization.
 *
 * Parses an operator-typed phone number plus a country code into the
 * triple stored on `customers` (and `customer_addresses`, later):
 *
 *   country_code      — `+20` / `+966` / `+971` / `+964`
 *   local_phone       — what the operator typed (kept raw for audit)
 *   normalized_phone  — E.164 form (`+201012345678`)
 *
 * Rules per country live in {@see self::COUNTRY_RULES}. Adding a country
 * is a code-only change — no DB migration, no `countries` table touch.
 * The doc-spec called for the rules to live on the `countries` table
 * (PHONE_ADDRESS_AND_WHATSAPP_READINESS.md §4); O-2 keeps them in PHP
 * for simplicity. If the rule set grows, promoting to a DB lookup is a
 * straightforward refactor — the public API of this service won't change.
 *
 * The service is pure: no DB reads, no DB writes. Callers persist the
 * returned triple.
 */
class PhoneNormalizationService
{
    /** Default country dial code when nothing better is known. */
    public const DEFAULT_COUNTRY = '+20';

    /**
     * Per-country normalization + validation rules.
     *
     * - `national_prefix`     — strip this when the local form leads with it
     *                          (Egypt: `0`).
     * - `mobile_min_digits` / `mobile_max_digits` — length of the local part
     *                          AFTER the national prefix is stripped.
     * - `mobile_starts_with` — regex anchored at the start of the stripped
     *                          local part. Catches non-mobile or malformed
     *                          numbers without needing a full lib.
     *
     * @var array<string, array{name:string, national_prefix:string, mobile_min_digits:int, mobile_max_digits:int, mobile_starts_with:string}>
     */
    public const COUNTRY_RULES = [
        '+20' => [
            'name' => 'Egypt',
            'national_prefix' => '0',
            // Egypt mobile post-prefix: 10 digits like 1012345678. With
            // the leading `0` it's 11. We strip the prefix first.
            'mobile_min_digits' => 10,
            'mobile_max_digits' => 10,
            'mobile_starts_with' => '/^1[0125]/',
        ],
        '+966' => [
            'name' => 'Saudi Arabia',
            'national_prefix' => '0',
            'mobile_min_digits' => 9,
            'mobile_max_digits' => 9,
            'mobile_starts_with' => '/^5/',
        ],
        '+971' => [
            'name' => 'UAE',
            'national_prefix' => '0',
            'mobile_min_digits' => 9,
            'mobile_max_digits' => 9,
            'mobile_starts_with' => '/^5/',
        ],
        '+964' => [
            'name' => 'Iraq',
            'national_prefix' => '0',
            'mobile_min_digits' => 10,
            'mobile_max_digits' => 10,
            'mobile_starts_with' => '/^7/',
        ],
    ];

    /**
     * Normalize a raw phone string.
     *
     * Result shape:
     *
     *   [
     *     'valid' => true|false,
     *     'country_code' => '+20',
     *     'local_phone' => '01012345678',     // as the operator typed it (cleaned of whitespace)
     *     'normalized_phone' => '+201012345678', // E.164
     *     'error' => null | 'reason',
     *   ]
     *
     * For `valid=false`, the other keys still come back populated as best-
     * effort so the caller (backfill / UI) can show what was parsed. The
     * controller / form should use `valid` as the gate.
     *
     * @return array{valid:bool, country_code:?string, local_phone:?string, normalized_phone:?string, error:?string}
     */
    public function normalize(?string $raw, ?string $countryCodeHint = null): array
    {
        $countryCode = $countryCodeHint ?: self::DEFAULT_COUNTRY;

        if ($raw === null || trim($raw) === '') {
            return $this->result(false, null, null, null, 'Phone is empty.');
        }

        // 1. Strip all whitespace, dashes, parentheses, dots, leading
        //    Unicode invisibles. Keep `+` and digits only.
        $cleaned = preg_replace('/[^\d+]/', '', $raw) ?? '';
        $original = trim($raw);

        if ($cleaned === '') {
            return $this->result(false, null, $original, null, 'Phone contains no digits.');
        }

        // 2. If starts with `00`, swap to `+`. International dialling prefix.
        if (str_starts_with($cleaned, '00')) {
            $cleaned = '+' . substr($cleaned, 2);
        }

        // 3. If starts with `+`, the prefix is the country code; auto-
        //    detect from the known list. Override the hint.
        if (str_starts_with($cleaned, '+')) {
            $detected = $this->detectCountryByPrefix($cleaned);
            if ($detected !== null) {
                $countryCode = $detected;
            } else {
                // Unknown country prefix — store as-is. We can't validate
                // the local length without rules, so flag as invalid.
                return $this->result(false, null, $original, $cleaned, 'Unknown country code in number.');
            }
            // Drop the country code from the cleaned string so we can
            // apply national-prefix logic.
            $cleaned = substr($cleaned, strlen($countryCode));
        }

        $rules = self::COUNTRY_RULES[$countryCode] ?? null;
        if ($rules === null) {
            return $this->result(false, $countryCode, $original, null, "Unsupported country: {$countryCode}.");
        }

        // 4. Strip national prefix if present.
        $nationalPrefix = $rules['national_prefix'];
        if ($nationalPrefix !== '' && str_starts_with($cleaned, $nationalPrefix)) {
            $cleaned = substr($cleaned, strlen($nationalPrefix));
        }

        // 5. Length check.
        $len = strlen($cleaned);
        if ($len < $rules['mobile_min_digits'] || $len > $rules['mobile_max_digits']) {
            return $this->result(
                false,
                $countryCode,
                $original,
                null,
                sprintf(
                    'Local phone length %d is outside the expected range %d–%d for %s.',
                    $len,
                    $rules['mobile_min_digits'],
                    $rules['mobile_max_digits'],
                    $rules['name'],
                ),
            );
        }

        // 6. Starts-with rule.
        if (! preg_match($rules['mobile_starts_with'], $cleaned)) {
            return $this->result(
                false,
                $countryCode,
                $original,
                null,
                "Local phone does not match a mobile pattern for {$rules['name']}.",
            );
        }

        // Compose E.164.
        $normalized = $countryCode . $cleaned;

        return $this->result(true, $countryCode, $original, $normalized, null);
    }

    /**
     * Detect a known country code by E.164 prefix on the cleaned string.
     * Iterates longest-first so `+966` wins over `+9` in degenerate cases.
     */
    private function detectCountryByPrefix(string $cleaned): ?string
    {
        $codes = array_keys(self::COUNTRY_RULES);
        // Sort by length descending — `+966` before `+9`.
        usort($codes, static fn ($a, $b) => strlen($b) - strlen($a));
        foreach ($codes as $code) {
            if (str_starts_with($cleaned, $code)) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Build the result array. Centralised so the shape stays consistent
     * everywhere.
     *
     * @return array{valid:bool, country_code:?string, local_phone:?string, normalized_phone:?string, error:?string}
     */
    private function result(bool $valid, ?string $countryCode, ?string $local, ?string $normalized, ?string $error): array
    {
        return [
            'valid' => $valid,
            'country_code' => $countryCode,
            'local_phone' => $local,
            'normalized_phone' => $normalized,
            'error' => $error,
        ];
    }

    /**
     * WhatsApp Business API expects the E.164 number WITHOUT the leading
     * `+` — e.g. `201012345678`. Static helper because no DB / state is
     * involved.
     */
    public static function toWhatsappFormat(?string $normalized): ?string
    {
        if ($normalized === null || $normalized === '') {
            return null;
        }
        return ltrim($normalized, '+');
    }

    /**
     * Country-aware display form for the operator. Returns the local
     * phone with the country dial code separated by a space.
     *
     * Example: `+20 1012345678`.
     *
     * Falls back to the normalized string when the country isn't known.
     */
    public static function toDisplayFormat(?string $normalized): ?string
    {
        if ($normalized === null || $normalized === '') {
            return null;
        }
        if (! str_starts_with($normalized, '+')) {
            return $normalized;
        }
        foreach (array_keys(self::COUNTRY_RULES) as $code) {
            if (str_starts_with($normalized, $code)) {
                return $code . ' ' . substr($normalized, strlen($code));
            }
        }
        return $normalized;
    }
}
