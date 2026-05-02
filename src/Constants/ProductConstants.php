<?php
declare(strict_types=1);

namespace App\Constants;

final class ProductConstants
{
    public const IMAGE_MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    public const IMAGE_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    public const IMAGE_TARGET_WIDTH = 800;
    public const IMAGE_TARGET_HEIGHT = 800;
    public const IMAGE_JPEG_QUALITY = 85;
    public const PRICE_MIN = 1;
    public const CODE_MAX_LENGTH = 20;
    public const CODE_PATTERN = '/^[A-Za-z0-9-]+$/';
}
