<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../services/DocumentoService.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

bidmap_require_login_for_creditos_json();

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = bidmap_env($key);

    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function database_connection(): mysqli
{
    $host = bidmap_env('DB_HOST', '127.0.0.1');
    $user = bidmap_env('DB_USER', 'root');
    $password = bidmap_env('DB_PASSWORD', '');
    $database = bidmap_env('DB_DATABASE', 'bidmap_dev');

    $mysqli = new mysqli($host, $user, $password, $database);

    if ($mysqli->connect_error) {
        json_response([
            'ok' => false,
            'message' => 'Erro na conexao com o banco local.',
            'detail' => $mysqli->connect_error,
        ], 500);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function usuario_credito_service(): UsuarioCreditoService
{
    static $service = null;

    if ($service instanceof UsuarioCreditoService) {
        return $service;
    }

    if (!env_bool('DADOS_PESSOAIS_SKIP_CREDITOS', false)) {
        $service = new UsuarioCreditoService();
        return $service;
    }

    $service = new class extends UsuarioCreditoService {
        public function getUsuarioId(): int
        {
            return (int) bidmap_env('DADOS_PESSOAIS_TEST_USER_ID', '1');
        }

        public function getSaldo(int $idUsuario): float
        {
            return (float) bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '0');
        }

        public function debitar(int $idUsuario, float $creditos, string $referencia): array
        {
            $saldoAnterior = $this->getSaldo($idUsuario);

            return [
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoAnterior,
                'bypass_creditos' => true,
            ];
        }

        public function estornar(int $idUsuario, float $creditos, string $referencia): array
        {
            $saldoAnterior = $this->getSaldo($idUsuario);

            return [
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoAnterior,
                'bypass_creditos' => true,
            ];
        }
    };

    return $service;
}

$entrada = $_REQUEST['q'] ?? '';
$tipoConsulta = $_REQUEST['tipo_consulta'] ?? null;

if (!in_array($tipoConsulta, ['cpf', 'cnpj'], true)) {
    json_response([
        'ok' => false,
        'message' => 'Selecione CPF ou CNPJ para consultar dados pessoais.',
    ], 400);
}

if (trim($entrada) === '') {
    json_response([
        'ok' => false,
        'message' => 'Informe um CPF ou CNPJ.',
    ], 400);
}

if (function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) {
    json_response([
        'ok' => true,
        'demo' => true,
        'message' => 'Modo demo: dados ficticios retornados sem acionar APIs externas.',
        'tipo_consulta' => $tipoConsulta,
        'documento' => preg_replace('/\D+/', '', (string) $entrada),
        'dados' => [
            'nome' => $tipoConsulta === 'cpf' ? 'Pessoa Demo' : 'Empresa Demo LTDA',
            'documento' => preg_replace('/\D+/', '', (string) $entrada),
            'situacao' => 'Demonstração',
            'origem' => 'Portfolio local',
        ],
        'saldo' => (float) bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '100'),
    ]);
}

try {
    $mysqli = database_connection();
    $service = new DocumentoService($mysqli, null, usuario_credito_service());
    $result = $service->consultarDadosPessoais($entrada, $tipoConsulta);
    $mysqli->close();

    json_response($result, $result['ok'] ? 200 : 422);
} catch (Throwable $exception) {
    json_response([
        'ok' => false,
        'message' => 'Erro ao consultar dados pessoais.',
        'detail' => $exception->getMessage(),
    ], 500);
}
