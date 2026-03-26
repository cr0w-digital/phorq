<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'phorq example' ?></title>
    <?php foreach ($meta as $name => $content): ?>
        <meta name="<?= $name ?>" content="<?= $content ?>">
    <?php endforeach ?>
    <script src="https://unpkg.com/htmx.org@2/dist/htmx.min.js"></script>
    <script src="https://unpkg.com/htmx-ext-sse@2/sse.js"></script>
    <script type="module" src="https://cdn.jsdelivr.net/gh/starfederation/datastar@1.0.0-RC.8/bundles/datastar.js"></script>
    <link rel="stylesheet" href="/app.css">
</head>
<body hx-boost="true">
    <?= $content ?>
    <div id="toast" class="toast"></div>
    <script>
        document.body.addEventListener('toast', (e) => {
            const toast = document.getElementById('toast');
            toast.textContent = e.detail.message;
            toast.classList.add('toast--show');
            setTimeout(() => toast.classList.remove('toast--show'), 3000);
        });
    </script>
</body>
</html>