<?php

class UsuarioCreditoService
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private bool $logEnabled;
    private string $logFile;
    private string $authorizationPrefix;
    private string $hostHeader;
    private bool $followRedirects;
    private string $fallbackBaseUrl;
    private string $fallbackHostHeader;
    private bool $fallbackFollowRedirects;
    private int $fallbackUserId;
    private ?float $fallbackSaldo;
    private bool $allowStaticFallback;
    private int $sessionCacheTtl;
    private int $connectTimeout;
    private array $saldoCache = [];

    public function __construct(?string $baseUrl = null, ?string $token = null, int $timeout = 20)
    {
        $this->baseUrl = rtrim((string) ($baseUrl ?? $this->env('BIDMAP_USUARIOS_API_BASE_URL', 'https://bidmap.com.br/api/usuarios')), '/');
        $this->token = (string) ($token ?? $this->env('BIDMAP_USUARIOS_API_TOKEN', ''));
        $this->timeout = max(1, (int) $this->env('BIDMAP_CREDITOS_API_TIMEOUT', (string) $timeout));
        $this->connectTimeout = max(1, (int) $this->env('BIDMAP_CREDITOS_API_CONNECT_TIMEOUT', '3'));
        $this->logEnabled = filter_var($this->env('BIDMAP_CREDITOS_LOG_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
        $this->logFile = (string) $this->env('BIDMAP_CREDITOS_LOG_FILE', dirname(__DIR__) . '/storage/logs/creditos-api.log');
        $this->authorizationPrefix = trim((string) $this->env('BIDMAP_USUARIOS_API_AUTH_PREFIX', 'token'));
        $this->hostHeader = trim((string) $this->env('BIDMAP_USUARIOS_API_HOST_HEADER', ''));
        $this->followRedirects = filter_var($this->env('BIDMAP_USUARIOS_API_FOLLOW_REDIRECTS', 'true'), FILTER_VALIDATE_BOOLEAN);
        $this->fallbackBaseUrl = rtrim(trim((string) $this->env('BIDMAP_USUARIOS_API_FALLBACK_BASE_URL', 'http://127.0.0.1/api/usuarios')), '/');
        $this->fallbackHostHeader = trim((string) $this->env('BIDMAP_USUARIOS_API_FALLBACK_HOST_HEADER', 'bidmap.com.br'));
        $this->fallbackFollowRedirects = filter_var($this->env('BIDMAP_USUARIOS_API_FALLBACK_FOLLOW_REDIRECTS', 'false'), FILTER_VALIDATE_BOOLEAN);
        $this->fallbackUserId = (int) $this->env('BIDMAP_CREDITOS_FALLBACK_USER_ID', '0');
        $fallbackSaldo = trim((string) $this->env('BIDMAP_CREDITOS_FALLBACK_SALDO', ''));
        $this->fallbackSaldo = is_numeric($fallbackSaldo) ? (float) $fallbackSaldo : null;
        $this->allowStaticFallback = filter_var($this->env('BIDMAP_CREDITOS_ALLOW_STATIC_FALLBACK', 'false'), FILTER_VALIDATE_BOOLEAN);
        $this->sessionCacheTtl = max(0, (int) $this->env('BIDMAP_CREDITOS_SESSION_CACHE_TTL', '15'));
    }

    public function getUsuarioId(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionSources = [];

        foreach (['cliente', 'usuario', 'user'] as $sessionKey) {
            if (isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
                $sessionSources[] = $_SESSION[$sessionKey];
            }
        }

        $sessionSources[] = $_SESSION;
        $idKeys = $this->sessionIdKeys();

        foreach ($sessionSources as $source) {
            foreach ($idKeys as $key) {
                if (!empty($source[$key]) && is_numeric($source[$key])) {
                    return (int) $source[$key];
                }
            }
        }

        $allowTestUser = PHP_SAPI === 'cli'
            || filter_var($this->env('BIDMAP_CREDITOS_ALLOW_TEST_USER', 'false'), FILTER_VALIDATE_BOOLEAN)
            || filter_var($this->env('DADOS_PESSOAIS_SKIP_CREDITOS', 'false'), FILTER_VALIDATE_BOOLEAN);

        if (!$allowTestUser) {
            throw new RuntimeException('ID do usuario nao encontrado na sessao.');
        }

        $fallback = (int) $this->env('DADOS_PESSOAIS_TEST_USER_ID', '0');

        if ($fallback > 0) {
            return $fallback;
        }

        throw new RuntimeException('ID do usuario nao encontrado na sessao.');
    }

    public function getSaldo(int $idUsuario): float
    {
        if (array_key_exists($idUsuario, $this->saldoCache)) {
            return $this->saldoCache[$idUsuario];
        }

        $saldoSessaoRecente = $this->saldoSessaoRecente($idUsuario);

        if ($saldoSessaoRecente !== null) {
            $this->saldoCache[$idUsuario] = $saldoSessaoRecente;
            return $saldoSessaoRecente;
        }

        try {
            $payload = $this->request('GET', $idUsuario);
            $saldo = $this->extrairSaldo($payload);
        } catch (RuntimeException $exception) {
            if ($this->podeUsarSaldoSessaoComoFallback()) {
                $saldoSessao = $this->saldoSessao($idUsuario);

                if ($saldoSessao !== null) {
                    $this->saldoCache[$idUsuario] = $saldoSessao;
                    return $saldoSessao;
                }
            }

            if (!$this->deveUsarFallbackUsuario($idUsuario, $exception)) {
                throw $exception;
            }

            $saldo = (float) $this->fallbackSaldo;
        }

        $this->atualizarSessaoCredito($idUsuario, $saldo);
        $this->saldoCache[$idUsuario] = $saldo;

        return $saldo;
    }

    public function consultarUsuario(int $idUsuario): array
    {
        $payload = $this->request('GET', $idUsuario);
        $saldo = $this->extrairSaldo($payload, false);

        if ($saldo !== null) {
            $this->atualizarSessaoCredito($idUsuario, $saldo);
            $this->saldoCache[$idUsuario] = $saldo;
        }

        return $payload;
    }

    public function debitar(int $idUsuario, float $creditos, string $referencia): array
    {
        return $this->movimentar($idUsuario, 'D', $creditos, $referencia);
    }

    public function estornar(int $idUsuario, float $creditos, string $referencia): array
    {
        return $this->movimentar($idUsuario, 'C', $creditos, 'Estorno: ' . $referencia);
    }

    public function creditar(int $idUsuario, float $creditos, string $referencia): array
    {
        return $this->movimentar($idUsuario, 'C', $creditos, $referencia);
    }

    private function movimentar(int $idUsuario, string $tipo, float $creditos, string $referencia): array
    {
        if ($creditos <= 0) {
            $saldo = $this->getSaldo($idUsuario);
            return [
                'saldo_anterior' => $saldo,
                'saldo_posterior' => $saldo,
            ];
        }

        $saldoAnterior = $this->getSaldo($idUsuario);

        $payload = $this->request('POST', $idUsuario, [
            'tipo' => $tipo,
            'valor' => $this->formatarValor($creditos),
            'descricao' => $referencia,
        ]);

        $saldoPosterior = $this->extrairSaldo($payload, false);

        if ($saldoPosterior === null) {
            $this->limparSaldoCache($idUsuario);
            $saldoPosterior = $this->getSaldo($idUsuario);
        }

        $this->atualizarSessaoCredito($idUsuario, $saldoPosterior);
        $this->saldoCache[$idUsuario] = $saldoPosterior;

        return [
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior' => $saldoPosterior,
            'transacao_id' => $this->extrairIdTransacao($payload),
            'operacao' => $tipo === 'D' ? 'debito' : 'credito',
            'descricao' => $referencia,
            'data_operacao' => $this->extrairDataOperacao($payload) ?? date('Y-m-d H:i:s'),
            'api_response' => $payload,
        ];
    }

    private function request(string $method, int $idUsuario, ?array $body = null): array
    {
        if ($this->token === '') {
            throw new RuntimeException('Token da API de creditos nao configurado.');
        }

        $url = $this->baseUrl . '/idusuario/' . rawurlencode((string) $idUsuario);
        $headers = $this->requestHeaders($this->hostHeader);

        [$response, $statusCode, $curlError] = $this->executeRequest($url, $method, $headers, $body, $this->followRedirects);
        $logError = $curlError ?: null;

        if ($response === false && $this->deveTentarFallback($curlError)) {
            $fallbackUrl = $this->fallbackBaseUrl . '/idusuario/' . rawurlencode((string) $idUsuario);
            $fallbackHeaders = $this->requestHeaders($this->fallbackHostHeader);
            [$fallbackResponse, $fallbackStatusCode, $fallbackCurlError] = $this->executeRequest(
                $fallbackUrl,
                $method,
                $fallbackHeaders,
                $body,
                $this->fallbackFollowRedirects
            );

            if ($fallbackResponse !== false) {
                $response = $fallbackResponse;
                $statusCode = $fallbackStatusCode;
                $curlError = $fallbackCurlError;
                $logError = 'Fallback interno usado apos erro primario: ' . $logError;
            }
        }

        $this->logRequest($method, $idUsuario, $statusCode, $logError, $body, $response);

        if ($response === false) {
            throw new RuntimeException('Erro ao conectar na API de creditos: ' . ($curlError ?: 'sem resposta'));
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('API de creditos retornou uma resposta invalida.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['message'] ?? $decoded['mensagem'] ?? $decoded['erro'] ?? 'API de creditos retornou erro HTTP ' . $statusCode . '.';
            throw new RuntimeException((string) $message);
        }

        return $decoded;
    }

    private function requestHeaders(string $hostHeader): array
    {
        $headers = [
            'Authorization: ' . $this->authorizationValue(),
            'Accept: application/json',
        ];

        if ($hostHeader !== '') {
            $headers[] = 'Host: ' . $hostHeader;
        }

        return $headers;
    }

    private function executeRequest(string $url, string $method, array $headers, ?array $body, bool $followRedirects): array
    {
        if (!function_exists('curl_init')) {
            return $this->requestWithStream($url, $method, $headers, $body);
        }

        return $this->requestWithCurl($url, $method, $headers, $body, $followRedirects);
    }

    private function deveTentarFallback(string $curlError): bool
    {
        if ($this->fallbackBaseUrl === '' || $curlError === '') {
            return false;
        }

        return preg_match('/ssl|tls|certificate|issuer|sni|unrecognized name/i', $curlError) === 1;
    }

    private function deveUsarFallbackUsuario(int $idUsuario, RuntimeException $exception): bool
    {
        if (
            !$this->allowStaticFallback
            || $this->fallbackUserId <= 0
            || $this->fallbackSaldo === null
            || $idUsuario !== $this->fallbackUserId
        ) {
            return false;
        }

        return str_starts_with($exception->getMessage(), 'Erro ao conectar na API de creditos:');
    }

    private function podeUsarSaldoSessaoComoFallback(): bool
    {
        return filter_var($this->env('DADOS_PESSOAIS_SKIP_CREDITOS', 'false'), FILTER_VALIDATE_BOOLEAN)
            || filter_var($this->env('BIDMAP_LOCAL_LOGIN_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    private function authorizationValue(): string
    {
        if ($this->authorizationPrefix === '') {
            return $this->token;
        }

        return $this->authorizationPrefix . ' ' . $this->token;
    }

    private function requestWithCurl(string $url, string $method, array $headers, ?array $body, bool $followRedirects): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
        ]);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$response, $statusCode, $curlError];
    }

    private function requestWithStream(string $url, string $method, array $headers, ?array $body): array
    {
        $content = null;
        $headerLines = $headers;

        if ($body !== null) {
            $content = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headerLines[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => $this->timeout,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = 0;

        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $statusCode = (int) $match[1];
                break;
            }
        }

        $error = $response === false ? 'file_get_contents falhou ao chamar a API de creditos.' : '';

        return [$response, $statusCode, $error];
    }

    private function extrairSaldo(array $payload, bool $obrigatorio = true): ?float
    {
        $saldo = $this->buscarPrimeiroNumero($payload, [
            'creditos',
            'credito',
            'saldo_creditos',
            'saldoCredito',
            'saldo_credito',
            'saldo',
        ]);

        if ($saldo === null && $obrigatorio) {
            throw new RuntimeException('Nao foi possivel identificar o saldo de creditos no retorno da API.');
        }

        return $saldo;
    }

    private function buscarPrimeiroNumero(array $payload, array $keys): ?float
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $found = $this->buscarPrimeiroNumero($value, $keys);

                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if (!in_array((string) $key, $keys, true)) {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if (is_string($value)) {
                $normalized = trim($value);
                $normalized = preg_replace('/[^\d,.-]/', '', $normalized) ?? $normalized;

                if (strpos($normalized, ',') !== false) {
                    $normalized = str_replace('.', '', $normalized);
                    $normalized = str_replace(',', '.', $normalized);
                }

                if (is_numeric($normalized)) {
                    return (float) $normalized;
                }
            }
        }

        return null;
    }

    private function extrairIdTransacao(array $payload): ?string
    {
        $id = $this->buscarPrimeiroValor($payload, [
            'id_transacao',
            'transacao_id',
            'transaction_id',
            'id_movimentacao',
            'movimentacao_id',
            'movement_id',
            'id_lancamento',
            'lancamento_id',
        ]);

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function extrairDataOperacao(array $payload): ?string
    {
        $data = $this->buscarPrimeiroValor($payload, [
            'data_operacao',
            'data_movimentacao',
            'created_at',
            'updated_at',
            'data',
        ]);

        if (!$data) {
            return null;
        }

        $timestamp = strtotime((string) $data);

        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function buscarPrimeiroValor(array $payload, array $keys)
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $found = $this->buscarPrimeiroValor($value, $keys);

                if ($found !== null && $found !== '') {
                    return $found;
                }

                continue;
            }

            if (in_array((string) $key, $keys, true)) {
                return $value;
            }
        }

        return null;
    }

    private function atualizarSessaoCredito(int $idUsuario, float $saldo): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
            return;
        }

        $idSessao = null;

        foreach ($this->sessionIdKeys() as $key) {
            if (!empty($_SESSION['cliente'][$key]) && is_numeric($_SESSION['cliente'][$key])) {
                $idSessao = (int) $_SESSION['cliente'][$key];
                break;
            }
        }

        if ($idSessao !== null && $idSessao !== $idUsuario) {
            return;
        }

        $_SESSION['cliente']['creditos'] = $saldo;
        $_SESSION['cliente']['saldo'] = number_format($saldo, 2, '.', '');
        $_SESSION['cliente']['creditos_cached_at'] = time();
    }

    private function limparSaldoCache(int $idUsuario): void
    {
        unset($this->saldoCache[$idUsuario]);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
            return;
        }

        unset($_SESSION['cliente']['creditos_cached_at']);
    }

    private function saldoSessaoRecente(int $idUsuario): ?float
    {
        if ($this->sessionCacheTtl <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
            return null;
        }

        $cachedAt = $_SESSION['cliente']['creditos_cached_at'] ?? null;

        if (!is_numeric($cachedAt)) {
            return null;
        }

        if ((time() - (int) $cachedAt) > $this->sessionCacheTtl) {
            return null;
        }

        return $this->saldoSessao($idUsuario);
    }

    private function saldoSessao(int $idUsuario): ?float
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
            return null;
        }

        $idSessao = null;

        foreach ($this->sessionIdKeys() as $key) {
            if (!empty($_SESSION['cliente'][$key]) && is_numeric($_SESSION['cliente'][$key])) {
                $idSessao = (int) $_SESSION['cliente'][$key];
                break;
            }
        }

        if ($idSessao !== null && $idSessao !== $idUsuario) {
            return null;
        }

        foreach (['creditos', 'saldo', 'credito', 'saldo_creditos'] as $key) {
            if (!array_key_exists($key, $_SESSION['cliente'])) {
                continue;
            }

            $value = $_SESSION['cliente'][$key];

            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if (is_string($value)) {
                $normalized = trim($value);
                $normalized = preg_replace('/[^\d,.-]/', '', $normalized) ?? $normalized;

                if (strpos($normalized, ',') !== false) {
                    $normalized = str_replace('.', '', $normalized);
                    $normalized = str_replace(',', '.', $normalized);
                }

                if (is_numeric($normalized)) {
                    return (float) $normalized;
                }
            }
        }

        return null;
    }

    private function sessionIdKeys(): array
    {
        return [
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
    }

    private function formatarValor(float $valor)
    {
        return floor($valor) === $valor ? (int) $valor : $valor;
    }

    private function env(string $key, ?string $default = null): ?string
    {
        if (function_exists('bidmap_env')) {
            return bidmap_env($key, $default);
        }

        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    private function logRequest(
        string $method,
        int $idUsuario,
        int $statusCode,
        ?string $curlError,
        ?array $body,
        $response
    ): void {
        if (!$this->logEnabled) {
            return;
        }

        $dir = dirname($this->logFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $message = null;

        if (is_array($decoded)) {
            $message = $decoded['message'] ?? $decoded['mensagem'] ?? $decoded['erro'] ?? $decoded['error'] ?? null;
        }

        $entry = [
            'time' => date('c'),
            'method' => $method,
            'id_usuario' => $idUsuario,
            'base_url' => $this->baseUrl,
            'http_code' => $statusCode,
            'curl_error' => $curlError,
            'request' => $body ? [
                'tipo' => $body['tipo'] ?? null,
                'valor' => $body['valor'] ?? null,
                'descricao_hash' => isset($body['descricao']) ? sha1((string) $body['descricao']) : null,
            ] : null,
            'response_message' => $message,
        ];

        @file_put_contents(
            $this->logFile,
            json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
