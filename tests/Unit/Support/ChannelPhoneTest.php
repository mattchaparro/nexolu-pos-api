<?php

namespace Tests\Unit\Support;

use App\Support\ChannelPhone;
use PHPUnit\Framework\TestCase;

class ChannelPhoneTest extends TestCase
{
    public function test_prepends_the_colombia_country_code_to_a_local_mobile_number(): void
    {
        $this->assertSame('573001234567', ChannelPhone::normalize('3001234567'));
    }

    public function test_accepts_a_number_already_carrying_the_country_code(): void
    {
        $this->assertSame('573001234567', ChannelPhone::normalize('573001234567'));
    }

    public function test_strips_non_digit_characters(): void
    {
        $this->assertSame('573001234567', ChannelPhone::normalize('+57 300 123 4567'));
    }

    public function test_rejects_a_number_that_is_too_short(): void
    {
        $this->assertNull(ChannelPhone::normalize('12345'));
    }

    public function test_rejects_a_number_that_is_too_long(): void
    {
        $this->assertNull(ChannelPhone::normalize('1234567890123456'));
    }
}
