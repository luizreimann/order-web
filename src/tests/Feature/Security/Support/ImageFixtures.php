<?php

namespace Tests\Feature\Security\Support;

/**
 * Bytes de imagens REAIS geradas à mão (o container não tem GD, então nada de
 * `UploadedFile::fake()->image()`). Os formatos reais importam: o hardening olha
 * o conteúdo (magic bytes), não o nome.
 */
final class ImageFixtures
{
    /** PNG com cabeçalho/IHDR coerentes (`$width`x`$height`) e dados mínimos. */
    public static function png(int $width = 1, int $height = 1): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $idat = gzcompress(str_repeat("\0", 1 + 3)); // um pixel; só o cabeçalho é lido pelo getimagesize

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IDAT', $idat).$chunk('IEND', '');
    }

    public static function gif(): string
    {
        return (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    public static function jpeg(): string
    {
        return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    public static function webp(): string
    {
        return (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
    }

    /** ICO com um PNG embutido (formato "Vista"), 1x1. */
    public static function ico(): string
    {
        $png = self::png();

        return pack('vvv', 0, 1, 1).pack('CCCCvvVV', 1, 1, 0, 0, 1, 32, strlen($png), 22).$png;
    }

    /** Preenche até `$bytes` com lixo APÓS o fim da imagem (mantém o tipo e o cabeçalho). */
    public static function padded(string $image, int $bytes): string
    {
        return $image.str_repeat('A', max(0, $bytes - strlen($image)));
    }

    public static function php(): string
    {
        return "<?php system(\$_GET['c']); ?>\n";
    }

    public static function svgWithScript(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(document.cookie)</script></svg>';
    }

    public static function html(): string
    {
        return '<html><body><script>alert(1)</script></body></html>';
    }
}
