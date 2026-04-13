<?php
declare(strict_types=1);

session_start([
    'cookie_path' => '/',
    'cookie_samesite' => 'Lax',
    'cookie_httponly' => true,
]);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php?next=settings.php');
    exit();
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/api/lib/admin_auth.php';
require_once dirname(__DIR__) . '/api/lib/company_profile.php';
require_once dirname(__DIR__) . '/api/lib/security_settings.php';

$adminNavActive = 'settings';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = (string)($_POST['form_type'] ?? 'auth');
    if ($formType === 'company') {
        try {
            $currentLogo = trim((string)(fixarivan_company_profile_load()['company_logo'] ?? ''));
            $logoPath = $currentLogo;
            if (!empty($_FILES['company_logo_file']['tmp_name']) && is_uploaded_file((string)$_FILES['company_logo_file']['tmp_name'])) {
                $ext = strtolower((string)pathinfo((string)$_FILES['company_logo_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
                if (!in_array($ext, $allowed, true)) {
                    throw new RuntimeException('Логотип: допустимы PNG, JPG, GIF, WEBP, SVG');
                }
                $assetsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets';
                if (!is_dir($assetsDir) && !mkdir($assetsDir, 0775, true) && !is_dir($assetsDir)) {
                    throw new RuntimeException('Не удалось создать каталог assets/');
                }
                $saveExt = $ext === 'jpeg' ? 'jpg' : $ext;
                $dest = $assetsDir . DIRECTORY_SEPARATOR . 'company_logo.' . $saveExt;
                if (!move_uploaded_file((string)$_FILES['company_logo_file']['tmp_name'], $dest)) {
                    throw new RuntimeException('Не удалось сохранить файл логотипа');
                }
                $logoPath = 'assets/company_logo.' . $saveExt;
            }
            fixarivan_company_profile_save([
                'company_name' => (string)($_POST['company_name'] ?? ''),
                'company_phone' => (string)($_POST['company_phone'] ?? ''),
                'company_email' => (string)($_POST['company_email'] ?? ''),
                'company_website' => (string)($_POST['company_website'] ?? ''),
                'company_address' => (string)($_POST['company_address'] ?? ''),
                'y_tunnus' => (string)($_POST['y_tunnus'] ?? ''),
                'iban' => (string)($_POST['iban'] ?? ''),
                'bic' => (string)($_POST['bic'] ?? ''),
                'bank_name' => (string)($_POST['bank_name'] ?? ''),
                'company_logo' => $logoPath,
            ]);
            $message = 'Реквизиты компании обновлены.';
            $messageType = 'ok';
        } catch (Throwable $e) {
            $message = 'Ошибка сохранения реквизитов: ' . $e->getMessage();
            $messageType = 'err';
        }
    } elseif ($formType === 'delete_security') {
        $newDeletePassword = (string)($_POST['delete_password'] ?? '');
        $newDeletePassword2 = (string)($_POST['delete_password_confirm'] ?? '');
        if ($newDeletePassword === '' || $newDeletePassword2 === '') {
            $message = 'Заполните пароль удаления и подтверждение.';
            $messageType = 'err';
        } elseif ($newDeletePassword !== $newDeletePassword2) {
            $message = 'Пароль удаления и подтверждение не совпадают.';
            $messageType = 'err';
        } else {
            try {
                fixarivan_set_delete_password($newDeletePassword);
                $message = 'Пароль удаления обновлён.';
                $messageType = 'ok';
            } catch (Throwable $e) {
                $message = 'Ошибка сохранения пароля удаления: ' . $e->getMessage();
                $messageType = 'err';
            }
        }
    } else {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newUser = trim((string) ($_POST['new_username'] ?? ''));
    $newPass = (string) ($_POST['new_password'] ?? '');
    $newPass2 = (string) ($_POST['new_password_confirm'] ?? '');

    $sessionUser = trim((string) ($_SESSION['admin_username'] ?? ''));

    if ($currentPassword === '' || $newUser === '' || $newPass === '') {
        $message = 'Заполните текущий пароль, новый логин и новый пароль.';
        $messageType = 'err';
    } elseif ($newPass !== $newPass2) {
        $message = 'Новый пароль и подтверждение не совпадают.';
        $messageType = 'err';
    } elseif ($sessionUser === '' || !fixarivan_admin_verify($sessionUser, $currentPassword)) {
        $message = 'Неверный текущий пароль.';
        $messageType = 'err';
    } elseif (strlen($newPass) < 8) {
        $message = 'Новый пароль: минимум 8 символов.';
        $messageType = 'err';
    } else {
        try {
            fixarivan_admin_save_credentials($newUser, $newPass);
            $_SESSION['admin_username'] = $newUser;
            $message = 'Логин и пароль обновлены. Данные сохранены в storage/admin_auth.json (хеш пароля).';
            $messageType = 'ok';
        } catch (Throwable $e) {
            $message = 'Ошибка сохранения: ' . $e->getMessage();
            $messageType = 'err';
        }
    }
    }
}

$displayUser = fixarivan_admin_effective_username();
if ($displayUser === '' && isset($_SESSION['admin_username'])) {
    $displayUser = (string) $_SESSION['admin_username'];
}
$companyProfile = fixarivan_company_profile_load();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки — FixariVan</title>
    <link rel="icon" href="../favicon.svg" type="image/svg+xml">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        .container { max-width: 640px; margin: 0 auto; padding: 24px; }
        .container a { color: #5a67d8; }
        h1 { color: #fff; margin-bottom: 8px; font-size: 1.6rem; text-shadow: 0 1px 2px rgba(0,0,0,0.15); }
        .sub { color: rgba(255,255,255,0.9); margin-bottom: 20px; font-size: 0.95rem; }
        .card {
            background: rgba(255,255,255,0.97);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
        }
        label { display: block; font-weight: 600; margin-bottom: 6px; color: #4a5568; font-size: 0.9rem; }
        input[type="password"], input[type="text"] {
            width: 100%; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px;
            font-size: 1rem; margin-bottom: 16px;
        }
        button[type="submit"] {
            width: 100%; padding: 14px; border: none; border-radius: 12px;
            background: linear-gradient(45deg, #667eea, #764ba2); color: #fff;
            font-weight: 700; font-size: 1rem; cursor: pointer;
        }
        button[type="submit"]:hover { filter: brightness(1.05); }
        .hint { font-size: 0.85rem; color: #718096; margin-top: -8px; margin-bottom: 16px; line-height: 1.4; }
        .msg { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 0.95rem; }
        .msg.ok { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
        .msg.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Админ: безопасность и настройки</h1>
        <p class="sub">Здесь только учётная запись и служебные опции. Рабочий стол с заказами и складом — в <a href="../index.php" style="color:#fff;font-weight:600;">index.php</a>. Текущий пользователь: <strong><?= htmlspecialchars($displayUser) ?></strong></p>

        <?php require __DIR__ . '/admin_nav.php'; ?>

        <div class="card">
            <?php if ($message !== ''): ?>
                <div class="msg <?= $messageType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <p class="hint">После сохранения учётные данные хранятся в <code>storage/admin_auth.json</code> (пароль — только в виде bcrypt). Файл в .gitignore. Чтобы снова использовать только <code>config.local.php</code>, удалите этот JSON на сервере.</p>

            <form method="post" autocomplete="off">
                <input type="hidden" name="form_type" value="auth">
                <label for="current_password">Текущий пароль</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">

                <label for="new_username">Новый логин</label>
                <input type="text" id="new_username" name="new_username" required value="<?= htmlspecialchars($displayUser) ?>" autocomplete="username">

                <label for="new_password">Новый пароль</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">

                <label for="new_password_confirm">Повторите новый пароль</label>
                <input type="password" id="new_password_confirm" name="new_password_confirm" required minlength="8" autocomplete="new-password">

                <button type="submit">Сохранить</button>
            </form>
        </div>

        <div class="card" style="margin-top: 20px;">
            <p style="font-weight: 600; margin-bottom: 8px;">Пароль для опасных удалений</p>
            <p class="hint">Один пароль для удаления клиентов, позиций склада и отдельных документов (акты, квитанции и т.д.). По умолчанию: <code>1989</code>.</p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="form_type" value="delete_security">
                <label for="delete_password">Новый пароль удаления</label>
                <input type="password" id="delete_password" name="delete_password" minlength="4" required autocomplete="new-password">
                <label for="delete_password_confirm">Повторите пароль удаления</label>
                <input type="password" id="delete_password_confirm" name="delete_password_confirm" minlength="4" required autocomplete="new-password">
                <button type="submit">Сохранить пароль удаления</button>
            </form>
        </div>

        <div class="card" style="margin-top: 20px;">
            <p style="font-weight: 600; margin-bottom: 8px;">Реквизиты компании для документов</p>
            <p class="hint">Эти данные используются в шапке квитанций/PDF и для выставления счетов компаниям.</p>
            <form method="post" autocomplete="off" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="company">

                <label for="company_logo_file">Логотип для счетов и PDF (PNG/JPG/SVG и др.)</label>
                <input type="file" id="company_logo_file" name="company_logo_file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                <?php if (!empty($companyProfile['company_logo'])): ?>
                    <p class="hint">Текущий файл: <code><?= htmlspecialchars((string)$companyProfile['company_logo']) ?></code> — загрузите новый, чтобы заменить.</p>
                <?php endif; ?>

                <label for="company_name">Название компании</label>
                <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($companyProfile['company_name'] ?? '') ?>">

                <label for="company_phone">Телефон</label>
                <input type="text" id="company_phone" name="company_phone" value="<?= htmlspecialchars($companyProfile['company_phone'] ?? '') ?>">

                <label for="company_email">Email</label>
                <input type="text" id="company_email" name="company_email" value="<?= htmlspecialchars($companyProfile['company_email'] ?? '') ?>">

                <label for="company_website">Сайт</label>
                <input type="text" id="company_website" name="company_website" value="<?= htmlspecialchars($companyProfile['company_website'] ?? '') ?>">

                <label for="company_address">Адрес</label>
                <input type="text" id="company_address" name="company_address" value="<?= htmlspecialchars($companyProfile['company_address'] ?? '') ?>">

                <label for="y_tunnus">Y-tunnus</label>
                <input type="text" id="y_tunnus" name="y_tunnus" value="<?= htmlspecialchars($companyProfile['y_tunnus'] ?? '') ?>">

                <label for="iban">IBAN (FI...)</label>
                <input type="text" id="iban" name="iban" value="<?= htmlspecialchars($companyProfile['iban'] ?? '') ?>">

                <label for="bic">BIC / SWIFT</label>
                <input type="text" id="bic" name="bic" value="<?= htmlspecialchars($companyProfile['bic'] ?? '') ?>">

                <label for="bank_name">Название банка</label>
                <input type="text" id="bank_name" name="bank_name" value="<?= htmlspecialchars($companyProfile['bank_name'] ?? '') ?>">

                <button type="submit">Сохранить реквизиты</button>
            </form>
        </div>

        <div class="card" style="margin-top: 20px; border: 2px solid #c53030; background: #fff8f8;">
            <p style="font-weight: 700; margin-bottom: 8px; color: #991b1b;">Опасная зона: удаление всех документов</p>
            <p class="hint" style="color: #7f1d1d;">Это действие удалит все акты, квитанции и отчёты из базы и связанные JSON-файлы. <strong>Восстановление невозможно.</strong> Склад (инвентарь) не затрагивается.</p>
            <p class="hint">Введите слово <strong>DELETE</strong> (заглавными) и пароль удаления — тот же, что для удаления клиентов и отдельных документов (см. блок выше).</p>
            <label for="clear_confirm_text">Подтверждение текстом</label>
            <input type="text" id="clear_confirm_text" name="clear_confirm_text" autocomplete="off" placeholder="DELETE">
            <label for="clear_delete_password">Пароль удаления</label>
            <input type="password" id="clear_delete_password" name="clear_delete_password" autocomplete="off">
            <button type="button" id="btnClearAllDocuments" style="width: 100%; padding: 14px; border: none; border-radius: 12px; background: #c53030; color: #fff; font-weight: 700; cursor: pointer; margin-top: 8px;">Удалить все документы</button>
            <p id="clearDocsResult" class="hint" style="margin-top: 12px; display: none;"></p>
        </div>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('btnClearAllDocuments');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var c = document.getElementById('clear_confirm_text');
            var p = document.getElementById('clear_delete_password');
            var out = document.getElementById('clearDocsResult');
            if (!c || !p) return;
            var t = (c.value || '').trim();
            var pw = p.value || '';
            if (t !== 'DELETE') {
                alert('Введите слово DELETE заглавными для подтверждения.');
                return;
            }
            if (!pw) {
                alert('Введите пароль удаления.');
                return;
            }
            if (!confirm('Последнее предупреждение: все документы будут удалены без возможности восстановления. Продолжить?')) {
                return;
            }
            btn.disabled = true;
            fetch('../api/clear_database.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ confirm: 'YES_DELETE_ALL', delete_password: pw })
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    btn.disabled = false;
                    if (out) {
                        out.style.display = 'block';
                        out.style.color = json.success ? '#065f46' : '#991b1b';
                        out.textContent = json.success
                            ? ('Готово. Удалено записей: ' + (json.total_deleted != null ? json.total_deleted : '—') + '. Обновите рабочий стол (index.php).')
                            : (json.message || 'Ошибка');
                    }
                    if (json.success) {
                        c.value = '';
                        p.value = '';
                    } else {
                        alert(json.message || 'Ошибка');
                    }
                })
                .catch(function (e) {
                    btn.disabled = false;
                    alert('Ошибка сети: ' + (e && e.message ? e.message : String(e)));
                });
        });
    })();
    </script>
</body>
</html>
