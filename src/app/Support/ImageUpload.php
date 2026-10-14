<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Política ÚNICA de upload de imagens do admin (E5.3 / UI-009).
 * Usada por Products\Form (image), Tenant\Customization (logo, banner, favicon) e
 * Tenant\SeoSettings (og_image).
 *
 * O que o cliente declara (nome, extensão, Content-Type) NÃO é confiável. A política:
 *
 *  1. Validação por CONTEÚDO: `mimes`/`mimetypes` do Laravel usam o Symfony MimeTypes
 *     (finfo / magic bytes), e `dimensions` exige que `getimagesize()` consiga PARSEAR
 *     a imagem — texto/PHP/HTML renomeado para .jpg falha aí.
 *  2. Formatos aceitos: jpg, png, webp, gif (favicon: ico, png). **SVG é proibido**: é
 *     XML que pode conter <script>/handlers e seria servido de `/storage` na mesma origem
 *     da loja (XSS armazenado). Também sai `bmp`/`tiff`/etc.: sem necessidade de produto.
 *  3. Dimensões máximas (anti decompression-bomb / abuso de banda): 4096x4096
 *     (favicon: 512x512). Tamanho em KB continua por campo.
 *  4. A extensão gravada é DERIVADA do conteúdo (mime detectado + tipo do `getimagesize`,
 *     que precisam concordar) — nunca de `getClientOriginalExtension()`.
 *  5. O nome gravado é um UUID (nenhum fragmento do nome do cliente), em
 *     `tenants/{tenantId}/…`. O arquivo antigo só é apagado se estiver sob o diretório
 *     do mesmo tenant (um caminho adulterado no banco não vira "delete arbitrário").
 *
 * Limite conhecido: não re-encodamos a imagem. Um poliglota (imagem válida + bytes extras)
 * é aceito, mas é gravado só com extensão de imagem e servido como `image/*` pelo nginx
 * (que só executa `\.php$`, fora de /storage) — nunca é executado.
 */
final class ImageUpload
{
    public const MAX_WIDTH = 4096;

    public const MAX_HEIGHT = 4096;

    public const FAVICON_MAX_SIZE = 512;

    /** @var array<string, string> mime detectado => extensão gravada */
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** @var array<string, string> */
    private const FAVICON_TYPES = [
        'image/png' => 'png',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /** @var array<int, string> IMAGETYPE_* do getimagesize => extensão */
    private const IMAGETYPE_EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_ICO => 'ico',
    ];

    /**
     * Regras de validação de um campo de imagem.
     *
     * @return list<string>
     */
    public static function rules(int $maxKb, bool $favicon = false): array
    {
        $types = $favicon ? self::FAVICON_TYPES : self::IMAGE_TYPES;
        $size = $favicon ? self::FAVICON_MAX_SIZE : null;
        $width = $size ?? self::MAX_WIDTH;
        $height = $size ?? self::MAX_HEIGHT;

        return [
            'nullable',
            'file',
            'mimes:'.implode(',', array_values(array_unique($types))), // o Laravel já trata jpg como jpeg
            'mimetypes:'.implode(',', array_keys($types)),
            'max:'.$maxKb,
            "dimensions:max_width={$width},max_height={$height}",
        ];
    }

    /**
     * Grava a imagem (já validada) e devolve o caminho relativo ao disco `public`.
     * Se `$oldPath` existir sob `tenants/{tenantId}/`, é removido DEPOIS do novo ser gravado.
     *
     * @param  string  $directory  ex.: `tenants/{id}` ou `tenants/{id}/products/{pid}`
     */
    public static function store(UploadedFile $file, string $tenantId, string $directory, ?string $oldPath = null, bool $favicon = false): string
    {
        self::assertInsideTenant($tenantId, $directory.'/');

        $extension = self::extensionFor($file, $favicon);
        $name = Str::uuid()->toString().'.'.$extension;

        $stored = $file->storePubliclyAs($directory, $name, 'public');

        if ($stored === false) {
            throw new RuntimeException('Falha ao gravar a imagem enviada.');
        }

        if ($oldPath && $oldPath !== $stored
            && str_starts_with($oldPath, "tenants/{$tenantId}/")
            && ! str_contains($oldPath, '..')
            && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        return $stored;
    }

    /**
     * Extensão derivada do CONTEÚDO. Rejeita se o mime detectado não estiver na lista ou se
     * o parser de imagem discordar dele.
     */
    public static function extensionFor(UploadedFile $file, bool $favicon = false): string
    {
        $types = $favicon ? self::FAVICON_TYPES : self::IMAGE_TYPES;

        $byMime = $types[(string) $file->getMimeType()] ?? null;
        $info = @getimagesize($file->getRealPath());
        $byParser = is_array($info) ? (self::IMAGETYPE_EXTENSIONS[$info[2]] ?? null) : null;

        if ($byMime === null || $byMime !== $byParser) {
            throw ValidationException::withMessages(['image' => 'O arquivo enviado não é uma imagem permitida.']);
        }

        return $byMime;
    }

    private static function assertInsideTenant(string $tenantId, string $directory): void
    {
        if (! str_starts_with($directory, "tenants/{$tenantId}/") || str_contains($directory, '..')) {
            throw new RuntimeException('Diretório de upload fora do isolamento do tenant.');
        }
    }
}
