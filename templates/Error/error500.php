<?php
/**
 * @var \App\View\AppView $this
 * @var \Throwable $error
 */
use Cake\Core\Configure;

$this->disableAutoLayout();
$debug = Configure::read('debug');
$message = $debug ? $error->getMessage() : 'Algo salió mal del lado del servidor.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Error · Davi Rapid Admin</title>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
</head>
<body>
<div class="dr-error-shell">
    <i class="bi bi-exclamation-octagon dr-error-icon text-danger"></i>
    <h1 class="h3 mb-2">Error interno</h1>
    <p class="text-muted mb-4"><?= h($message) ?></p>
    <?php if ($debug): ?>
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
