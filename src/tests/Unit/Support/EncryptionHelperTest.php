<?php

namespace Tests\Unit\Support;

use App\Support\EncryptionHelper;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class EncryptionHelperTest extends TestCase
{
    public function test_encrypt_if_needed_returns_null_for_null(): void
    {
        $this->assertNull(EncryptionHelper::encryptIfNeeded(null));
    }

    public function test_encrypt_if_needed_returns_null_for_empty_string(): void
    {
        $this->assertNull(EncryptionHelper::encryptIfNeeded(''));
        $this->assertNull(EncryptionHelper::encryptIfNeeded('   '));
    }

    public function test_encrypt_if_needed_returns_same_value_if_already_encrypted(): void
    {
        $encrypted = 'enc:already_encrypted_value';
        $this->assertEquals($encrypted, EncryptionHelper::encryptIfNeeded($encrypted));
    }

    public function test_encrypt_if_needed_encrypts_new_value(): void
    {
        $value = 'my_secret_token';
        $result = EncryptionHelper::encryptIfNeeded($value);

        $this->assertStringStartsWith('enc:', $result);
        $this->assertNotEquals('enc:' . $value, $result);
    }

    public function test_decrypt_if_needed_returns_null_for_null(): void
    {
        $this->assertNull(EncryptionHelper::decryptIfNeeded(null));
    }

    public function test_decrypt_if_needed_returns_null_for_empty_string(): void
    {
        $this->assertNull(EncryptionHelper::decryptIfNeeded(''));
    }

    public function test_decrypt_if_needed_returns_same_value_if_not_encrypted(): void
    {
        $value = 'plain_text_value';
        $this->assertEquals($value, EncryptionHelper::decryptIfNeeded($value));
    }

    public function test_decrypt_if_needed_decrypts_encrypted_value(): void
    {
        $original = 'my_secret_token';
        $encrypted = 'enc:' . Crypt::encryptString($original);

        $this->assertEquals($original, EncryptionHelper::decryptIfNeeded($encrypted));
    }

    public function test_decrypt_if_needed_returns_null_for_invalid_encrypted_value(): void
    {
        $invalid = 'enc:invalid_encrypted_data';
        $this->assertNull(EncryptionHelper::decryptIfNeeded($invalid));
    }

    public function test_encrypt_and_decrypt_roundtrip(): void
    {
        $original = 'APP_USR-1234567890';
        $encrypted = EncryptionHelper::encryptIfNeeded($original);
        $decrypted = EncryptionHelper::decryptIfNeeded($encrypted);

        $this->assertEquals($original, $decrypted);
    }
}
