<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Product;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

final class ProductService
{
    use LocatorAwareTrait;

    private ProductImageService $images;

    public function __construct(?ProductImageService $images = null)
    {
        $this->images = $images ?? new ProductImageService();
    }

    /**
     * @return array{success: bool, product?: \App\Model\Entity\Product, errors?: array<string>}
     */
    public function create(array $data, ?UploadedFileInterface $image = null): array
    {
        $table = $this->fetchTable('Products');
        unset($data['image_path']); // never trust client-supplied path
        $hasImage = $this->isUploadPresent($image);

        $connection = ConnectionManager::get('default');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];

        $connection->transactional(function () use ($table, $data, $image, $hasImage, &$resultBox): bool {
            $product = $table->newEmptyEntity();
            $product = $table->patchEntity($product, $data);

            if (!$table->save($product)) {
                $resultBox = ['success' => false, 'errors' => $this->flattenErrors($product->getErrors()), 'product' => $product];

                return false;
            }

            if ($hasImage) {
                $imgResult = $this->images->store($image, (int)$product->id);
                if (!$imgResult['success']) {
                    $resultBox = ['success' => false, 'errors' => $imgResult['errors'] ?? ['No se pudo guardar la imagen.'], 'product' => $product];

                    return false;
                }
                $product->image_path = $imgResult['path'];
                if (!$table->save($product)) {
                    $resultBox = ['success' => false, 'errors' => $this->flattenErrors($product->getErrors()), 'product' => $product];

                    return false;
                }
            }

            Log::info('Product created: id={id} name={name}', [
                'id' => $product->id,
                'name' => $product->name,
                'scope' => ['products'],
            ]);

            $resultBox = ['success' => true, 'product' => $product];

            return true;
        });

        return $resultBox;
    }

    /**
     * @return array{success: bool, product?: \App\Model\Entity\Product, errors?: array<string>}
     */
    public function update(Product $product, array $data, ?UploadedFileInterface $image = null): array
    {
        $table = $this->fetchTable('Products');
        unset($data['image_path']);
        $hasImage = $this->isUploadPresent($image);

        $connection = ConnectionManager::get('default');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];

        $connection->transactional(function () use ($table, $product, $data, $image, $hasImage, &$resultBox): bool {
            $patched = $table->patchEntity($product, $data);

            if (!$table->save($patched)) {
                $resultBox = ['success' => false, 'errors' => $this->flattenErrors($patched->getErrors()), 'product' => $patched];

                return false;
            }

            if ($hasImage) {
                $imgResult = $this->images->replace($image, $patched);
                if (!$imgResult['success']) {
                    $resultBox = ['success' => false, 'errors' => $imgResult['errors'] ?? ['No se pudo guardar la imagen.'], 'product' => $patched];

                    return false;
                }
                $patched->image_path = $imgResult['path'];
                if (!$table->save($patched)) {
                    $resultBox = ['success' => false, 'errors' => $this->flattenErrors($patched->getErrors()), 'product' => $patched];

                    return false;
                }
            }

            $resultBox = ['success' => true, 'product' => $patched];

            return true;
        });

        return $resultBox;
    }

    /**
     * @return array{success: bool, errors?: array<string>}
     */
    public function delete(Product $product): array
    {
        if ($this->hasSales($product)) {
            return [
                'success' => false,
                'errors' => ['No se puede eliminar un producto con ventas. Desactivalo en su lugar.'],
            ];
        }

        $this->images->deleteFile($product);

        $table = $this->fetchTable('Products');
        if (!$table->delete($product)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar el producto.']];
        }

        Log::info('Product deleted: id={id} name={name}', [
            'id' => $product->id,
            'name' => $product->name,
            'scope' => ['products'],
        ]);

        return ['success' => true];
    }

    /**
     * @return array{success: bool, product?: \App\Model\Entity\Product, errors?: array<string>}
     */
    public function toggleActive(Product $product): array
    {
        $product->is_active = !$product->is_active;
        $table = $this->fetchTable('Products');
        if (!$table->save($product)) {
            return ['success' => false, 'errors' => $this->flattenErrors($product->getErrors()), 'product' => $product];
        }

        return ['success' => true, 'product' => $product];
    }

    /**
     * Checks whether the product has associated sales. Tolerant of OrderItems
     * not yet existing — returns false in Phase 1.
     */
    public function hasSales(Product $product): bool
    {
        $locator = $this->getTableLocator();
        if (!$locator->exists('OrderItems')) {
            try {
                $locator->get('OrderItems');
            } catch (Throwable) {
                return false;
            }
        }

        try {
            $count = $locator->get('OrderItems')
                ->find()
                ->where(['OrderItems.product_id' => $product->id])
                ->count();

            return $count > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function isUploadPresent(?UploadedFileInterface $image): bool
    {
        return $image !== null && $image->getError() === UPLOAD_ERR_OK && $image->getSize() > 0;
    }

    /**
     * @return array<string>
     */
    private function flattenErrors(array $errors): array
    {
        $flat = [];
        array_walk_recursive($errors, function ($message) use (&$flat): void {
            if (is_string($message) && $message !== '') {
                $flat[] = $message;
            }
        });

        return $flat ?: ['Datos inválidos.'];
    }
}
