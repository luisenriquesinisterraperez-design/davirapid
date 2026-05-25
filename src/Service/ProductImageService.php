<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ProductConstants;
use App\Model\Entity\Product;
use Cake\Log\Log;
use FilesystemIterator;
use finfo;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

final class ProductImageService
{
    /**
     * Stores a new uploaded image for a product. Returns the relative path
     * (from webroot/) on success.
     *
     * @return array{success: bool, path?: string, errors?: array<string>}
     */
    public function store(UploadedFileInterface $file, int $productId): array
    {
        $errors = $this->validate($file);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $dir = WWW_ROOT . 'uploads' . DS . 'products' . DS . $productId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'errors' => ['No se pudo crear el directorio de imagen.']];
        }

        $filename = 'product_' . bin2hex(random_bytes(8)) . '.jpg';
        $absolute = $dir . DS . $filename;

        $tmp = tempnam(sys_get_temp_dir(), 'prod_');
        $file->moveTo($tmp);

        try {
            $this->resize($tmp, $absolute);
        } catch (Throwable $e) {
            @unlink($tmp);
            @unlink($absolute);
            Log::error('Failed to resize product image: {msg}', ['msg' => $e->getMessage(), 'scope' => ['products']]);

            return ['success' => false, 'errors' => ['No se pudo procesar la imagen.']];
        }
        @unlink($tmp);

        $relative = 'uploads/products/' . $productId . '/' . $filename;

        return ['success' => true, 'path' => $relative];
    }

    /**
     * Replaces a product's existing image. Deletes the old file on success.
     *
     * @return array{success: bool, path?: string, errors?: array<string>}
     */
    public function replace(UploadedFileInterface $file, Product $product): array
    {
        $oldPath = $product->image_path;
        $result = $this->store($file, (int)$product->id);
        if (!$result['success']) {
            return $result;
        }

        if (!empty($oldPath)) {
            $absolute = WWW_ROOT . ltrim((string)$oldPath, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        return $result;
    }

    /**
     * Deletes the product's image file from disk (if any). Does not modify the entity.
     */
    public function deleteFile(Product $product): void
    {
        if (empty($product->image_path)) {
            return;
        }
        $absolute = WWW_ROOT . ltrim((string)$product->image_path, '/');
        if (is_file($absolute)) {
            @unlink($absolute);
        }
        $dir = dirname($absolute);
        if (is_dir($dir) && (new FilesystemIterator($dir))->valid() === false) {
            @rmdir($dir);
        }
    }

    /**
     * @return array<string>
     */
    private function validate(UploadedFileInterface $file): array
    {
        $errors = [];

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['Error al subir el archivo (código ' . $file->getError() . ').'];
        }

        if ($file->getSize() > ProductConstants::IMAGE_MAX_SIZE_BYTES) {
            $errors[] = 'La imagen supera el tamaño máximo permitido (10 MB).';
        }

        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(4096);
        $stream->rewind();

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($head) ?: '';
        if (!in_array($mime, ProductConstants::IMAGE_ALLOWED_MIME, true)) {
            $errors[] = 'Formato de imagen no permitido. Usá JPG, PNG o WebP.';
        }

        return $errors;
    }

    /**
     * Resizes the source image into a contained 800x800 JPEG-85 at the target path.
     * Uses GD. The aspect ratio is preserved; smaller images are NOT upscaled.
     */
    private function resize(string $source, string $target): void
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('GD extension is not available.');
        }

        $data = file_get_contents($source);
        if ($data === false) {
            throw new RuntimeException('Cannot read source image.');
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            throw new RuntimeException('Cannot decode source image.');
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $maxW = ProductConstants::IMAGE_TARGET_WIDTH;
        $maxH = ProductConstants::IMAGE_TARGET_HEIGHT;

        $ratio = min($maxW / $sw, $maxH / $sh, 1.0);
        $tw = max(1, (int)round($sw * $ratio));
        $th = max(1, (int)round($sh * $ratio));

        $dst = imagecreatetruecolor($tw, $th);
        // Fill with white in case the source has alpha (will be flattened to JPEG).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        if (!imagejpeg($dst, $target, ProductConstants::IMAGE_JPEG_QUALITY)) {
            imagedestroy($src);
            imagedestroy($dst);
            throw new RuntimeException('Failed to write JPEG output.');
        }

        imagedestroy($src);
        imagedestroy($dst);
    }
}
