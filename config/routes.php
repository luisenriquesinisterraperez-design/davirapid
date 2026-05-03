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

        // Home (placeholder dashboard, requiere sesión vía AppController::beforeFilter)
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'home']);

        // Acción custom: desbloquear cuenta de usuario.
        $builder->connect(
            '/users/unlock/{id}',
            ['controller' => 'Users', 'action' => 'unlock'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // Acción custom: alternar disponibilidad de un producto.
        $builder->connect(
            '/products/toggle-active/{id}',
            ['controller' => 'Products', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
        );

        // Acción custom: alternar disponibilidad de un cliente.
        $builder->connect(
            '/customers/toggle-active/{id}',
            ['controller' => 'Customers', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
        );

        // Acción custom: alternar disponibilidad de un repartidor.
        $builder->connect(
            '/deliveries/toggle-active/{id}',
            ['controller' => 'Deliveries', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
        );

        // CRUD estándar para Roles, Users (index/view/add/edit/delete) y home.
        $builder->fallbacks();
    });
};
