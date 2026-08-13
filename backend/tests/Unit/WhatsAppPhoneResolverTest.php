<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppPhoneResolver;
use Tests\TestCase;

class WhatsAppPhoneResolverTest extends TestCase
{
    public function test_infers_ninth_digit_only_for_a_brazilian_mobile_identity(): void
    {
        $result = new WhatsAppPhoneResolver()->resolve('556299990009');

        $this->assertSame('556299990009', $result['provider_identity']);
        $this->assertSame('5562999990009', $result['canonical_phone']);
        $this->assertTrue($result['normalization_applied']);
        $this->assertSame('brazilian_mobile_inferred', $result['source']);
    }

    public function test_does_not_add_ninth_digit_to_a_fixed_line(): void
    {
        $result = new WhatsAppPhoneResolver()->resolve('551132345678');

        $this->assertSame('551132345678', $result['canonical_phone']);
        $this->assertFalse($result['normalization_applied']);
        $this->assertSame('brazilian_fixed_line', $result['source']);
    }

    public function test_keeps_existing_canonical_phone_over_provider_representation(): void
    {
        $result = new WhatsAppPhoneResolver()->resolve('556299990009', '5562999990009');

        $this->assertSame('5562999990009', $result['canonical_phone']);
        $this->assertFalse($result['normalization_applied']);
        $this->assertSame('existing_canonical_phone', $result['source']);
    }

    public function test_does_not_invent_a_phone_for_an_ambiguous_identity(): void
    {
        $result = new WhatsAppPhoneResolver()->resolve('549912345678');

        $this->assertNull($result['canonical_phone']);
        $this->assertSame('none', $result['confidence']);
    }
}
