<?php
/**
 * @var \App\View\AppView $this
 * @var string $username
 */
$this->assign('title', 'Iniciar sesión');
?>
<h1 class="h4 text-center mb-4">Iniciar sesión</h1>

<?= $this->Form->create(null, ['url' => ['action' => 'login']]) ?>
<div class="mb-3">
    <label class="form-label" for="username">Usuario</label>
    <input type="text" name="username" id="username" class="form-control" required autofocus
           value="<?= h($username ?? '') ?>" autocomplete="username">
</div>
<div class="mb-4">
    <label class="form-label" for="password">Contraseña</label>
    <input type="password" name="password" id="password" class="form-control" required
           autocomplete="current-password">
</div>
<button type="submit" class="btn btn-primary w-100">
    <i class="bi bi-box-arrow-in-right"></i> Entrar
</button>
<?= $this->Form->end() ?>
