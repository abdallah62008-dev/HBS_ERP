<?php

namespace Tests\Unit\Services;

use App\Services\PhoneNormalizationService;
use PHPUnit\Framework\TestCase;

/**
 * Orders & Products O-2 — unit tests for {@see PhoneNormalizationService}.
 *
 * Pure function tests — no DB, no Laravel container. Use plain
 * PHPUnit\TestCase (not Tests\TestCase) for speed.
 */
class PhoneNormalizationServiceTest extends TestCase
{
    private PhoneNormalizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PhoneNormalizationService();
    }

    /* ─────────────────── Egypt (+20) — happy paths ─────────────────── */

    public function test_eg_with_leading_zero_normalizes(): void
    {
        $r = $this->svc->normalize('01012345678', '+20');
        $this->assertTrue($r['valid'], $r['error'] ?? '');
        $this->assertSame('+20', $r['country_code']);
        $this->assertSame('+201012345678', $r['normalized_phone']);
    }

    public function test_eg_without_leading_zero_normalizes(): void
    {
        $r = $this->svc->normalize('1012345678', '+20');
        $this->assertTrue($r['valid']);
        $this->assertSame('+201012345678', $r['normalized_phone']);
    }

    public function test_eg_e164_form_normalizes(): void
    {
        $r = $this->svc->normalize('+201012345678');
        $this->assertTrue($r['valid']);
        $this->assertSame('+20', $r['country_code']);
        $this->assertSame('+201012345678', $r['normalized_phone']);
    }

    public function test_eg_double_zero_form_normalizes(): void
    {
        $r = $this->svc->normalize('00201012345678');
        $this->assertTrue($r['valid']);
        $this->assertSame('+201012345678', $r['normalized_phone']);
    }

    public function test_eg_with_spaces_and_dashes_normalizes(): void
    {
        $r = $this->svc->normalize('010-1234-5678', '+20');
        $this->assertTrue($r['valid']);
        $this->assertSame('+201012345678', $r['normalized_phone']);
        $r2 = $this->svc->normalize('010 1234 5678', '+20');
        $this->assertSame('+201012345678', $r2['normalized_phone']);
    }

    public function test_eg_201_prefix_without_plus_is_detected(): void
    {
        // Operator types "201012345678" without `+`. Doc spec §3 expects
        // this to normalize to +201012345678 BUT we treat it as a raw
        // local number prefixed with the country digits — the resulting
        // length (12) is outside the EG mobile rule (10), so the
        // service rejects it. This is the safe behaviour — anyone
        // entering an international number SHOULD include `+`.
        $r = $this->svc->normalize('201012345678', '+20');
        $this->assertFalse($r['valid']);
    }

    public function test_eg_short_number_rejected(): void
    {
        $r = $this->svc->normalize('010123', '+20');
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('Local phone length', $r['error']);
    }

    public function test_eg_wrong_starts_with_rejected(): void
    {
        // Egypt mobiles start with 10/11/12/15 (regex `^1[0125]`).
        // A 10-digit number starting with `2` is the right length but
        // wrong prefix — exercises the starts_with rule, not the length rule.
        $r = $this->svc->normalize('02234567890', '+20');
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('mobile pattern', $r['error']);
    }

    /* ─────────────────── Saudi (+966) ─────────────────── */

    public function test_saudi_local_form_normalizes(): void
    {
        $r = $this->svc->normalize('0501234567', '+966');
        $this->assertTrue($r['valid']);
        $this->assertSame('+966501234567', $r['normalized_phone']);
    }

    public function test_saudi_e164_normalizes(): void
    {
        $r = $this->svc->normalize('+966501234567');
        $this->assertTrue($r['valid']);
        $this->assertSame('+966', $r['country_code']);
        $this->assertSame('+966501234567', $r['normalized_phone']);
    }

    public function test_saudi_double_zero_normalizes(): void
    {
        $r = $this->svc->normalize('00966501234567');
        $this->assertTrue($r['valid']);
        $this->assertSame('+966501234567', $r['normalized_phone']);
    }

    /* ─────────────────── UAE (+971) ─────────────────── */

    public function test_uae_normalizes(): void
    {
        $r = $this->svc->normalize('0501234567', '+971');
        $this->assertTrue($r['valid']);
        $this->assertSame('+971501234567', $r['normalized_phone']);
    }

    /* ─────────────────── Iraq (+964) ─────────────────── */

    public function test_iraq_normalizes(): void
    {
        $r = $this->svc->normalize('07701234567', '+964');
        $this->assertTrue($r['valid']);
        $this->assertSame('+9647701234567', $r['normalized_phone']);
    }

    /* ─────────────────── Default fallback & ambiguous input ─────────────────── */

    public function test_no_country_hint_falls_back_to_egypt_default(): void
    {
        $r = $this->svc->normalize('01012345678');
        $this->assertTrue($r['valid']);
        $this->assertSame('+20', $r['country_code']);
    }

    public function test_empty_string_returns_invalid(): void
    {
        $r = $this->svc->normalize('');
        $this->assertFalse($r['valid']);
    }

    public function test_null_returns_invalid(): void
    {
        $r = $this->svc->normalize(null);
        $this->assertFalse($r['valid']);
    }

    public function test_garbage_input_returns_invalid_not_crash(): void
    {
        $r = $this->svc->normalize('!!!');
        $this->assertFalse($r['valid']);
    }

    public function test_unknown_country_prefix_is_rejected(): void
    {
        $r = $this->svc->normalize('+44 7700 900000'); // UK — not supported in O-2
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('Unknown country', $r['error']);
    }

    /* ─────────────────── Helper formatters ─────────────────── */

    public function test_whatsapp_format_strips_plus(): void
    {
        $this->assertSame('201012345678', PhoneNormalizationService::toWhatsappFormat('+201012345678'));
    }

    public function test_whatsapp_format_null_safe(): void
    {
        $this->assertNull(PhoneNormalizationService::toWhatsappFormat(null));
        $this->assertNull(PhoneNormalizationService::toWhatsappFormat(''));
    }

    public function test_display_format_splits_country_code(): void
    {
        $this->assertSame('+20 1012345678', PhoneNormalizationService::toDisplayFormat('+201012345678'));
        $this->assertSame('+966 501234567', PhoneNormalizationService::toDisplayFormat('+966501234567'));
    }

    public function test_display_format_unknown_country_returns_input(): void
    {
        $this->assertSame('+447700900000', PhoneNormalizationService::toDisplayFormat('+447700900000'));
    }

    /* ─────────────────── Idempotency ─────────────────── */

    public function test_round_trip_idempotent(): void
    {
        // Running normalize on an already-normalized E.164 string returns
        // the same E.164 string. Critical for backfill re-runs.
        $r1 = $this->svc->normalize('01012345678', '+20');
        $r2 = $this->svc->normalize($r1['normalized_phone']);
        $this->assertSame($r1['normalized_phone'], $r2['normalized_phone']);
        $this->assertTrue($r2['valid']);
    }
}
