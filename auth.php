<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function requireAuth(): void
{
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $password = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($user === '' && $password === '') {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', (string)$authorization, $matches)) {
            $decoded = base64_decode($matches[1], true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $password] = explode(':', $decoded, 2);
            }
        }
    }

    if (!hash_equals(APP_AUTH_USER, $user) || !hash_equals(APP_AUTH_PASSWORD, $password)) {
        header('WWW-Authenticate: Basic realm="Closed Hours"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Авторизация не пройдена';
        exit;
    }
}
