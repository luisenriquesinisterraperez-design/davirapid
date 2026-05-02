<?php
/**
 * @var \App\View\AppView $this
 * @var array $currentUser
 * @var string $currentRoleName
 * @var bool $isAdministrator
 * @var array<string, array<string, bool>> $userPermissions
 * @var array $sidebarCounters
 * @var array $breadcrumbs
 */
$this->loadHelper('Sidebar');
$controller = (string)$this->request->getParam('controller');
$visibleItems = $this->Sidebar->visibleItems($userPermissions ?? [], $controller);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <?= $this->Html->charset() ?>
    <title>
        <?= $this->fetch('title') ? h($this->fetch('title')) . ' · ' : '' ?>Davi Rapid Admin
    </title>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
<div class="dr-app-shell">

    <aside class="dr-sidebar">
        <div class="dr-sidebar-brand">
            <i class="bi bi-shop"></i>
            <span>Davi Rapid</span>
        </div>
        <nav class="dr-sidebar-nav" aria-label="Navegación principal">
            <?php foreach ($visibleItems as $item): ?>
                <?= $this->Html->link(
                    sprintf('<i class="bi %s"></i><span>%s</span>',
                        h($item['icon']),
                        h($item['label'])
                    ),
                    $item['url'],
                    [
                        'escape' => false,
                        'class' => 'dr-sidebar-item' . ($item['active'] ? ' active' : ''),
                    ]
                ) ?>
            <?php endforeach; ?>
        </nav>
    </aside>

    <header class="dr-topbar">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><?= $this->Html->link('Inicio', '/') ?></li>
                <?php foreach ($breadcrumbs ?? [] as $i => $crumb): ?>
                    <?php $isLast = $i === array_key_last($breadcrumbs); ?>
                    <li class="breadcrumb-item<?= $isLast ? ' active' : '' ?>"<?= $isLast ? ' aria-current="page"' : '' ?>>
                        <?php if (!$isLast && !empty($crumb['url'])): ?>
                            <?= $this->Html->link(h($crumb['label']), $crumb['url']) ?>
                        <?php else: ?>
                            <?= h($crumb['label']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>

        <div class="dr-topbar-user dropdown">
            <button class="btn btn-sm btn-light dropdown-toggle d-inline-flex align-items-center gap-2"
                    type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span class="d-none d-sm-inline">
                    <?= h($currentUser['name'] ?? 'Usuario') ?>
                </span>
                <small class="text-muted d-none d-md-inline">· <?= h($currentRoleName) ?></small>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li class="dropdown-item-text small text-muted">
                    <?= h($currentUser['username'] ?? '') ?>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-right"></i> Cerrar sesión',
                        ['controller' => 'Users', 'action' => 'logout'],
                        ['escape' => false, 'class' => 'dropdown-item']
                    ) ?>
                </li>
            </ul>
        </div>
    </header>

    <main class="dr-content">
        <?= $this->Flash->render() ?>
        <?= $this->fetch('content') ?>
    </main>

</div>

<?= $this->Html->script('vendor/bootstrap.bundle.min', ['defer' => true]) ?>
<?= $this->fetch('script') ?>
</body>
</html>
