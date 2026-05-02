<?php
/**
 * @var \App\View\AppView $this
 * @var array $currentUser
 * @var string $currentRoleName
 */
$this->assign('title', 'Inicio');
?>
<div class="dr-page-header">
    <div>
        <h1 class="dr-page-title">Hola, <?= h(explode(' ', (string)($currentUser['name'] ?? 'Usuario'))[0]) ?></h1>
        <p class="text-muted mb-0">Sesión iniciada como <strong><?= h($currentUser['username'] ?? '') ?></strong> · Rol: <?= h($currentRoleName) ?></p>
    </div>
</div>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-graph-up-arrow display-4 text-muted mb-3 d-block"></i>
        <h2 class="h4 mb-2">Dashboard</h2>
        <p class="text-muted mb-0">El panel de métricas está disponible a partir de la Fase 4.</p>
    </div>
</div>
