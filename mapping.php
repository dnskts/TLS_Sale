<?php
/**
 * mapping.php — страница редактирования соответствий колонок Excel → поля парсера.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Маппинг полей — TLS Sale</title>
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="stylesheet" href="assets/php-app.css?v=20260716b">
</head>
<body class="parser-spec-page mapping-page">
<div class="parser-spec-wrap" id="mapping-app">
    <p class="tab-note">Загрузка…</p>
</div>
<script src="assets/js/mapping_editor.js?v=20260716b"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    window.MappingEditor.mount(document.getElementById('mapping-app'));
  });
</script>
</body>
</html>
