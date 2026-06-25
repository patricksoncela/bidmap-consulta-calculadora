<?php

function processos_action_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (empty($_SESSION['processos_action_token'])) {
        $_SESSION['processos_action_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['processos_action_token'];
}

function processos_validate_action_token(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    return is_string($token)
        && isset($_SESSION['processos_action_token'])
        && hash_equals((string) $_SESSION['processos_action_token'], $token);
}
