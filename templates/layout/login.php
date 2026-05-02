<?php
/**
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $this->Html->charset() ?>
    <title><?= $this->fetch('title') ? h($this->fetch('title')) . ' · ' : '' ?>Davi Rapid Admin</title>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
<div class="dr-login-shell">
    <div class="dr-login-card">
        <div class="dr-login-brand">
            <i class="bi bi-shop"></i> Davi Rapid
        </div>
        <?= $this->Flash->render() ?>
        <?= $this->fetch('content') ?>
    </div>
</div>

<?= $this->Html->script('vendor/bootstrap.bundle.min', ['defer' => true]) ?>
<?= $this->fetch('script') ?>
</body>
</html>
