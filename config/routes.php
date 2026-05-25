<?php
declare(strict_types=1);

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

return function (RouteBuilder $routes): void {
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        // Públicas
        $builder->connect('/login', ['controller' => 'Users', 'action' => 'login']);
        $builder->connect('/logout', ['controller' => 'Users', 'action' => 'logout']);

        // Home → Dashboard (requiere sesión vía AppController::beforeFilter).
        $builder->connect('/', ['controller' => 'Dashboard', 'action' => 'index']);

        // Acción custom: desbloquear cuenta de usuario.
        $builder->connect(
            '/users/unlock/{id}',
            ['controller' => 'Users', 'action' => 'unlock'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Acción custom: alternar disponibilidad de un producto.
        $builder->connect(
            '/products/toggle-active/{id}',
            ['controller' => 'Products', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );

        // Acción custom: alternar disponibilidad de un cliente.
        $builder->connect(
            '/customers/toggle-active/{id}',
            ['controller' => 'Customers', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );

        // Acción custom: alternar disponibilidad de un repartidor.
        $builder->connect(
            '/deliveries/toggle-active/{id}',
            ['controller' => 'Deliveries', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );

        // Recetas: edición nested bajo producto (lectura).
        $builder->connect(
            '/products/recipe/{id}',
            ['controller' => 'Products', 'action' => 'recipe'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // Recetas: mutaciones sobre líneas (solo POST).
        $builder->connect(
            '/products/add-recipe-line/{id}',
            ['controller' => 'Products', 'action' => 'addRecipeLine'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );
        $builder->connect(
            '/products/update-recipe-line/{id}/{lineId}',
            ['controller' => 'Products', 'action' => 'updateRecipeLine'],
            ['id' => '\d+', 'lineId' => '\d+', 'pass' => ['id', 'lineId'], '_method' => 'POST'],
        );
        $builder->connect(
            '/products/remove-recipe-line/{id}/{lineId}',
            ['controller' => 'Products', 'action' => 'removeRecipeLine'],
            ['id' => '\d+', 'lineId' => '\d+', 'pass' => ['id', 'lineId'], '_method' => 'POST'],
        );

        // Pedidos: acciones de pipeline e impresión.
        $builder->connect(
            '/orders/advance/{id}',
            ['controller' => 'Orders', 'action' => 'advance'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );
        $builder->connect(
            '/orders/cancel/{id}',
            ['controller' => 'Orders', 'action' => 'cancel'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );
        $builder->connect(
            '/orders/reactivate/{id}',
            ['controller' => 'Orders', 'action' => 'reactivate'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );
        $builder->connect(
            '/orders/ticket/{id}',
            ['controller' => 'Orders', 'action' => 'ticket'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'GET'],
        );

        // Cuentas por Cobrar: marcar como pagado (acción custom).
        $builder->connect(
            '/receivables/mark-paid/{id}',
            ['controller' => 'Receivables', 'action' => 'markPaid'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
        );

        // Auditoría: listado global y filtrado por pedido (path-positional).
        $builder->connect(
            '/audit',
            ['controller' => 'OrderLogs', 'action' => 'index'],
        );
        $builder->connect(
            '/audit/order/{id}',
            ['controller' => 'OrderLogs', 'action' => 'index'],
            ['id' => '\d+', 'pass' => ['id']],
        );

        // CRUD estándar para Roles, Users (index/view/add/edit/delete) y home.
        $builder->fallbacks();
    });
};
