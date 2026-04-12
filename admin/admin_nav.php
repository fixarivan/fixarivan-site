<?php
/**
 * Навигация админки: только рабочий стол (основное приложение) и настройки безопасности.
 * Set $adminNavActive before include: desktop | settings.
 */
if (!isset($adminNavActive)) {
    $adminNavActive = '';
}
if (empty($GLOBALS['fixarivan_admin_nav_styles'])) {
    $GLOBALS['fixarivan_admin_nav_styles'] = true;
    ?>
    <style>
        .admin-nav {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 12px;
            margin-bottom: 20px;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        .admin-nav a {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            color: #5a67d8;
            border: 1px solid transparent;
        }
        .admin-nav a:hover { background: #eef2ff; }
        .admin-nav a.is-active {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: #fff;
        }
        .admin-nav .admin-nav-spacer { flex: 1; min-width: 8px; }
    </style>
    <?php
}
?>
<nav class="admin-nav" aria-label="Администрирование">
    <a href="../index.php" class="<?= $adminNavActive === 'desktop' ? 'is-active' : '' ?>">Рабочий стол</a>
    <a href="settings.php" class="<?= $adminNavActive === 'settings' ? 'is-active' : '' ?>">Настройки</a>
    <span class="admin-nav-spacer"></span>
    <a href="logout.php" style="color:#c53030;">Выход</a>
</nav>
