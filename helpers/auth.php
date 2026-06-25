<?php

function bidmap_auth_env_bool(string $key, bool $default = false): bool
{
    $value = function_exists('bidmap_env') ? bidmap_env($key) : ($_ENV[$key] ?? getenv($key));

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function bidmap_creditos_reais_ativos(): bool
{
    if (bidmap_portfolio_demo_mode()) {
        return false;
    }

    return !bidmap_auth_env_bool('DADOS_PESSOAIS_SKIP_CREDITOS', false);
}

function bidmap_portfolio_demo_mode(): bool
{
    return bidmap_auth_env_bool('BIDMAP_PORTFOLIO_DEMO', true);
}

function bidmap_bootstrap_portfolio_demo_session(): void
{
    if (!bidmap_portfolio_demo_mode()) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
        $_SESSION['cliente'] = [];
    }

    $_SESSION['cliente'] = array_replace([
        'id' => (int) (function_exists('bidmap_env') ? bidmap_env('DADOS_PESSOAIS_TEST_USER_ID', '1') : 1),
        'email' => 'avaliador@example.com',
        'nome' => 'Avaliador Portfolio',
        'saldo' => (float) (function_exists('bidmap_env') ? bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '100') : 100),
        'creditos' => (float) (function_exists('bidmap_env') ? bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '100') : 100),
        'liberado' => true,
    ], $_SESSION['cliente']);
}

function bidmap_usuario_id_sessao(): ?int
{
    $ids = bidmap_usuario_ids_sessao();

    return $ids[0] ?? null;
}

function bidmap_usuario_ids_sessao(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $sources = [];

    foreach (['cliente', 'usuario', 'user'] as $sessionKey) {
        if (isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
            $sources[] = $_SESSION[$sessionKey];
        }
    }

    $sources[] = $_SESSION;

    $keys = [
        'id',
        'id_usuario',
        'usuario_id',
        'idusuario',
        'idUsuario',
        'id_cliente',
        'cliente_id',
        'idcliente',
        'idCliente',
        'idusuario_acesso',
        'idUsuarioAcesso',
    ];

    $ids = [];

    foreach ($sources as $source) {
        foreach ($keys as $key) {
            if (!empty($source[$key]) && is_numeric($source[$key])) {
                $ids[] = (int) $source[$key];
            }
        }
    }

    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

function bidmap_admin_allowed_ids(): array
{
    $allowed = trim((string) (function_exists('bidmap_env')
        ? bidmap_env('BIDMAP_ADMIN_ALLOWED_IDS', '')
        : ($_ENV['BIDMAP_ADMIN_ALLOWED_IDS'] ?? getenv('BIDMAP_ADMIN_ALLOWED_IDS') ?: '')));

    if ($allowed === '' && bidmap_local_login_enabled()) {
        $allowed = (string) (function_exists('bidmap_env')
            ? bidmap_env('DADOS_PESSOAIS_TEST_USER_ID', '1478')
            : '1478');
    }

    $ids = [];

    foreach (explode(',', $allowed) as $id) {
        $id = trim($id);

        if ($id !== '' && ctype_digit($id)) {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_unique($ids));
}

function bidmap_usuario_pode_acessar_dashboard(): bool
{
    $allowedIds = bidmap_admin_allowed_ids();

    if ($allowedIds === []) {
        return false;
    }

    return array_intersect(bidmap_usuario_ids_sessao(), $allowedIds) !== [];
}

function bidmap_usuario_logado(): bool
{
    return bidmap_usuario_id_sessao() !== null;
}

function bidmap_require_login_for_creditos(): void
{
    bidmap_bootstrap_portfolio_demo_session();

    if (!bidmap_creditos_reais_ativos() || bidmap_usuario_logado()) {
        return;
    }

    $loginUrl = bidmap_local_login_enabled()
        ? 'nao_enviar_prod/login_local/dev_login.php'
        : (string) (function_exists('bidmap_env')
        ? bidmap_env('BIDMAP_TOOLS_LOGIN_URL', 'consultar_processos.php')
        : 'consultar_processos.php');

    header('Location: ' . $loginUrl);
    exit;
}

function bidmap_require_login_for_creditos_json(): void
{
    bidmap_bootstrap_portfolio_demo_session();

    if (!bidmap_creditos_reais_ativos() || bidmap_usuario_logado()) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'Sessão expirada. Faça login no BidMap para continuar.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function bidmap_local_login_enabled(): bool
{
    if (!bidmap_auth_env_bool('BIDMAP_LOCAL_LOGIN_ENABLED', false)) {
        return false;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: '';

    $blockedHosts = [
        'bidmap.com.br',
        'www.bidmap.com.br',
        'tools.bidmap.com.br',
    ];

    return !in_array($host, $blockedHosts, true);
}

