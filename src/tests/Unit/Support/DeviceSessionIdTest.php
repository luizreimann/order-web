<?php

namespace Tests\Unit\Support;

use App\Support\DeviceSessionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeviceSessionIdTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function valid(): array
    {
        return [
            'forma real do MP (armor.hex.hex)' => ['armor.017d80a3b45471b5b9eecdbcddf55d126d241d9e90d3a3addb94810ff230720207081f92c26a21345ebe7f4316609c916a48861bc2b513fc660fcdce49703083b0e1c04e2f7288d45703aef6ba3cb4d9617ffc40c802660c29cf78ef16ee3a75.f9e8332c25040a69dce3ed322340fd7a'],
            'letras, dígitos, _ e -' => ['abc_DEF-123456'],
            'tamanho máximo' => [str_repeat('a', 512)],
        ];
    }

    #[DataProvider('valid')]
    public function test_a_plausible_device_id_is_kept_as_is(string $value): void
    {
        $this->assertSame($value, DeviceSessionId::sanitize($value));
    }

    /** @return array<string, array{mixed}> */
    public static function invalid(): array
    {
        return [
            'null' => [null],
            'vazio' => [''],
            'CRLF' => ["abcdefgh12345\r\nX-Evil: 1"],
            'LF final (o `$` do PCRE aceitaria)' => ["abcdefgh12345\n"],
            'espaço' => ['abcdefgh 12345'],
            'tab' => ["abcdefgh\t12345"],
            'curto demais' => ['abc'],
            '513 caracteres' => [str_repeat('a', 513)],
            '10 KB' => [str_repeat('a', 10240)],
            'aspas' => ['abcdefgh"12345'],
            'unicode' => ['abcdefgh12345é'],
            'array' => [['abcdefgh12345']],
            'inteiro' => [123456789012],
            'bool' => [true],
        ];
    }

    #[DataProvider('invalid')]
    public function test_anything_that_is_not_an_identifier_is_discarded_whole(mixed $value): void
    {
        $this->assertNull(DeviceSessionId::sanitize($value));
    }
}
