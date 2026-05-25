<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class PermissionsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        // Cajero: ingredients view + create + edit, NO delete.
        [
            'id' => 1,
            'role_id' => 2,
            'module' => 'ingredients',
            'can_view' => 1,
            'can_create' => 1,
            'can_edit' => 1,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        // Solo lectura: ingredients view only.
        [
            'id' => 2,
            'role_id' => 3,
            'module' => 'ingredients',
            'can_view' => 1,
            'can_create' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        // Cajero: products full (covers test scenario where role can see Products
        // but the actionModuleMap forces 'recipes' check on recipe actions).
        [
            'id' => 3,
            'role_id' => 2,
            'module' => 'products',
            'can_view' => 1,
            'can_create' => 1,
            'can_edit' => 1,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        // Cajero: recipes view + create + edit, NO delete (so we can test
        // both the "allowed" and "delete forbidden" branches in a single role).
        [
            'id' => 4,
            'role_id' => 2,
            'module' => 'recipes',
            'can_view' => 1,
            'can_create' => 1,
            'can_edit' => 1,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        // Solo lectura: products view only, recipes view 0 (used to test
        // the actionModuleMap override — Products allowed but recipe denied).
        [
            'id' => 5,
            'role_id' => 3,
            'module' => 'products',
            'can_view' => 1,
            'can_create' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        [
            'id' => 6,
            'role_id' => 3,
            'module' => 'recipes',
            'can_view' => 0,
            'can_create' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        // Cajero: adjustments view + create only. NO edit (append-only), NO delete.
        [
            'id' => 7,
            'role_id' => 2,
            'module' => 'adjustments',
            'can_view' => 1,
            'can_create' => 1,
            'can_edit' => 0,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        // Solo lectura: no adjustments access at all.
        [
            'id' => 8,
            'role_id' => 3,
            'module' => 'adjustments',
            'can_view' => 0,
            'can_create' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        // Cajero: account_payments view + create only. NO edit (append-only), NO delete (sensitive).
        [
            'id' => 9,
            'role_id' => 2,
            'module' => 'account_payments',
            'can_view' => 1,
            'can_create' => 1,
            'can_edit' => 0,
            'can_delete' => 0,
            'created' => '2026-05-25 09:00:00',
            'modified' => '2026-05-25 09:00:00',
        ],
        // Solo lectura: no account_payments access at all.
        [
            'id' => 10,
            'role_id' => 3,
            'module' => 'account_payments',
            'can_view' => 0,
            'can_create' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'created' => '2026-05-25 09:00:00',
            'modified' => '2026-05-25 09:00:00',
        ],
    ];
}
