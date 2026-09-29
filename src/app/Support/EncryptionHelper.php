<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

class EncryptionHelper
{
    public static function encryptIfNeeded(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        if (! $value) {
            return null;
        }
        if (str_starts_with($value, 'enc:')) {
            return $value;
        }

        return 'enc:'.Crypt::encryptString($value);
    }

    public static function decryptIfNeeded($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (! str_starts_with($value, 'enc:')) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, 4));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
