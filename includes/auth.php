<?php
declare(strict_types=1);

// Simple session-based authentication gate.
// Include this file at the top of every page that requires login.
// Credentials are read from .env (ADMIN_USER / ADMIN_PASS).

session_start();

function auth_credentials(): array
{
    return [
        'user' => getenv('ADMIN_USER') ?: 'admin',
        'pass' => getenv('ADMIN_PASS') ?: 'pinoyride2026',
    ];
}

function is_logged_in(): bool
{
    return isset($_SESSION['auth_logged_in']) && $_SESSION['auth_logged_in'] === true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}
