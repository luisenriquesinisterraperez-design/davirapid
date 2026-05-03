<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * Renderiza el sidebar autenticado. Cada item tiene un módulo asociado
 * y solo se muestra si el usuario tiene permiso 'view' sobre ese módulo.
 */
class SidebarHelper extends Helper
{
    public array $helpers = ['Url', 'Html'];

    /**
     * Catálogo de items del sidebar para Fase 0. Cada fase suma items.
     *
     * @var array<int, array{module:string, label:string, icon:string, url:array}>
     */
    private array $items = [
        [
            'module' => 'products',
            'label' => 'Productos',
            'icon' => 'bi-box-seam',
            'url' => ['controller' => 'Products', 'action' => 'index'],
        ],
        [
            'module' => 'customers',
            'label' => 'Clientes',
            'icon' => 'bi-people',
            'url' => ['controller' => 'Customers', 'action' => 'index'],
        ],
        [
            'module' => 'deliveries',
            'label' => 'Repartidores',
            'icon' => 'bi-truck',
            'url' => ['controller' => 'Deliveries', 'action' => 'index'],
        ],
        [
            'module' => 'users',
            'label' => 'Usuarios',
            'icon' => 'bi-person-badge',
            'url' => ['controller' => 'Users', 'action' => 'index'],
        ],
        [
            'module' => 'roles',
            'label' => 'Roles',
            'icon' => 'bi-shield',
            'url' => ['controller' => 'Roles', 'action' => 'index'],
        ],
    ];

    /**
     * Devuelve los items que el usuario actual puede ver.
     *
     * @param array<string, array<string, bool>> $permissions Matriz de la sesión actual.
     * @param string $currentController Controlador del request (para marcar el item activo).
     */
    public function visibleItems(array $permissions, string $currentController): array
    {
        $visible = [];
        foreach ($this->items as $item) {
            if (empty($permissions[$item['module']]['view'])) {
                continue;
            }
            $itemController = $item['url']['controller'] ?? '';
            $item['active'] = ($itemController === $currentController);
            $visible[] = $item;
        }
        return $visible;
    }
}
