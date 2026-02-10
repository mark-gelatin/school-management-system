<?php
/**
 * Shared page header/topbar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/functions.php';

$pageTitle = isset($title) && is_string($title) ? $title : APP_NAME;
$user = current_user();
$flash = get_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | Colegio De Amore</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-root">
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="icon-button" id="sidebarToggle" aria-label="Toggle menu">☰</button>
            <a class="brand-link" href="<?= e(app_url('index.php')) ?>">
                <img src="<?= e(app_url('assets/images/logo.png')) ?>" alt="Colegio De Amore logo" class="brand-logo">
                <span>Colegio De Amore</span>
            </a>
        </div>
        <div class="topbar-right">
            <?php if ($user): ?>
                <span class="user-chip"><?= e(current_user_name()) ?> (<?= e(ucfirst($user['role'])) ?>)</span>
                <a href="<?= e(app_url('auth/logout.php')) ?>" class="btn btn-outline btn-sm">Logout</a>
            <?php else: ?>
                <a href="<?= e(app_url('auth/login.php')) ?>" class="btn btn-primary btn-sm">Login</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="app-layout">
