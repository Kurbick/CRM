<?php

namespace Tests\Unit\Support\Auth;

use App\Support\Auth\PasswordPolicy;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_policy_requires_length_case_number_and_symbol(): void
    {
        foreach (['short', 'lowercasepassword1!', 'UPPERCASEPASSWORD1!', 'NoNumbersHere!', 'NoSymbolsHere12'] as $password) {
            $this->assertTrue(Validator::make(['password' => $password], ['password' => PasswordPolicy::rule()])->fails());
        }

        $this->assertFalse(Validator::make(['password' => 'Strong!Password12'], ['password' => PasswordPolicy::rule()])->fails());
    }
}
