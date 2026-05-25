<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * Renderiza el sidebar autenticado. Cada item tiene un módulo asociado
 * y solo se muestra si el usuario tiene permiso 'view' sobre ese módulo.
 * Los items se agrupan por sección (Operación, Inventario, Finanzas, Admin).
 */
class SidebarHelper extends Helper
{
    public array $helpers = ['Url', 'Html'];

    /**
     * @var list<array{section:string, items: list<array{module:string, label:string, icon:string, url:array}>}>
     */
    private array $groups = [
        [
            'section' => '',
            'items' => [
                [
                    'module' => 'dashboard',
                    'label' => 'Dashboard',
                    'icon' => 'bi-speedometer2',
                    'url' => ['controller' => 'Dashboard', 'action' => 'index'],
                ],
            ],
        ],
        [
            'section' => 'Operación',
            'items' => [
                [
                    'module' => 'orders',
                    'label' => 'Pedidos',
                    'icon' => 'bi-bag',
                    'url' => ['controller' => 'Orders', 'action' => 'index'],
                ],
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
            ],
        ],
        [
            'section' => 'Inventario',
            'items' => [
                [
                    'module' => 'ingredients',
                    'label' => 'Ingredientes',
                    'icon' => 'bi-egg-fried',
                    'url' => ['controller' => 'Ingredients', 'action' => 'index'],
                ],
                [
                    'module' => 'recipes',
                    'label' => 'Recetas',
                    'icon' => 'bi-journal-text',
                    'url' => ['controller' => 'Recipes', 'action' => 'index'],
                ],
                [
                    'module' => 'adjustments',
                    'label' => 'Ajustes',
                    'icon' => 'bi-arrow-left-right',
                    'url' => ['controller' => 'Adjustments', 'action' => 'index'],
                ],
            ],
        ],
        [
            'section' => 'Finanzas',
            'items' => [
                [
                    'module' => 'receivables',
                    'label' => 'Cuentas por Cobrar',
                    'icon' => 'bi-cash-coin',
                    'url' => ['controller' => 'Receivables', 'action' => 'index'],
                ],
                [
                    'module' => 'account_payments',
                    'label' => 'Abonos',
                    'icon' => 'bi-cash-stack',
                    'url' => ['controller' => 'AccountPayments', 'action' => 'index'],
                ],
                [
                    'module' => 'expenses',
                    'label' => 'Gastos',
                    'icon' => 'bi-receipt',
                    'url' => ['controller' => 'Expenses', 'action' => 'index'],
                ],
                [
                    'module' => 'cash_closes',
                    'label' => 'Cierre Diario',
                    'icon' => 'bi-calculator',
                    'url' => ['controller' => 'CashCloses', 'action' => 'index'],
                ],
            ],
        ],
        [
            'section' => 'Administración',
            'items' => [
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
                [
                    'module' => 'audit',
                    'label' => 'Auditoría',
                    'icon' => 'bi-clipboard-data',
                    'url' => ['controller' => 'OrderLogs', 'action' => 'index'],
                ],
            ],
        ],
    ];

    /**
     * Devuelve los grupos del sidebar con sus items visibles para el usuario.
     * Los grupos sin items visibles se omiten.
     *
     * @param array<string, array<string, bool>> $permissions Matriz de la sesión actual.
     * @param string $currentController Controlador del request (para marcar el item activo).
     * @return list<array{section:string, items: list<array<string, mixed>>}>
     */
    public function visibleGroups(array $permissions, string $currentController): array
    {
        $out = [];
        foreach ($this->groups as $group) {
            $visible = [];
            foreach ($group['items'] as $item) {
                if (empty($permissions[$item['module']]['view'])) {
                    continue;
                }
                $item['active'] = (($item['url']['controller'] ?? '') === $currentController);
                $visible[] = $item;
            }
            if ($visible !== []) {
                $out[] = ['section' => $group['section'], 'items' => $visible];
            }
        }

        return $out;
    }

    /**
     * Legacy flat list — kept for compatibility if any view uses it.
     *
     * @param array<string, array<string, bool>> $permissions
     * @return list<array<string, mixed>>
     */
    public function visibleItems(array $permissions, string $currentController): array
    {
        $out = [];
        foreach ($this->visibleGroups($permissions, $currentController) as $g) {
            foreach ($g['items'] as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
