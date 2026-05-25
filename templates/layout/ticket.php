<?php
/**
 * Ticket layout — no sidebar/topbar, monospace, optimised for 80mm thermal printers.
 *
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= $this->fetch('title') ? h($this->fetch('title')) : 'Ticket' ?></title>
    <style>
        body {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 12px;
            max-width: 280px;
            margin: 0 auto;
            padding: 8px;
            color: #000;
        }
        .ticket-section { margin: 8px 0; }
        .ticket-sep { border-top: 1px dashed #000; margin: 4px 0; }
        .ticket-center { text-align: center; }
        .ticket-right { text-align: right; }
        .ticket-row { display: flex; justify-content: space-between; }
        h1, h2, h3 { font-size: 13px; margin: 4px 0; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
<?= $this->Flash->render() ?>
<?= $this->fetch('content') ?>
<div class="no-print" style="text-align:center;margin-top:16px;">
    <button onclick="window.print()" class="btn btn-secondary">Imprimir</button>
</div>
</body>
</html>
