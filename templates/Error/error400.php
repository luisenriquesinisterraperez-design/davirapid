<?php
/**
 * @var \App\View\AppView $this
 * @var \Throwable $error
 * @var int $code
 */
use Cake\Core\Configure;

$this->disableAutoLayout();
$message = $error->getMessage();
$code = (int)($code ?? $error->getCode() ?: 400);
$title = match ($code) {
    403 => 'Acceso denegado',
    404 => 'No encontrado',
    default => 'Error',
};
$icon = match ($code) {
    403 => 'bi-shield-lock',
    404 => 'bi-question-circle',
    default => 'bi-exclamation-triangle',
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= h($title) ?> · Davi Rapid Admin</title>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
</head>
<body>
<div class="dr-error-shell">
    <i class="bi <?= h($icon) ?> dr-error-icon"></i>
    <h1 class="h3 mb-2"><?= h($title) ?></h1>
    <p class="text-muted mb-4"><?= h($message) ?></p>
    <?php if (Configure::read('debug')): ?>
        <details class="mb-4 text-start" style="max-width: 720px;">
            <summary class="text-muted">Detalles técnicos (solo en debug)</summary>
            <pre class="small mt-2 p-3 bg-light rounded"><?= h((string)$error) ?></pre>
        </details>
    <?php endif; ?>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left"></i> Volver al inicio',
        '/',
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>
</body>
</html>
