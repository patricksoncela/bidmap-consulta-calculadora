<?php

require_once __DIR__ . '/../config/env.php';

class RedisQueueClient
{
    private string $host;
    private int $port;
    private ?string $password;
    private int $database;
    private float $timeout;
    private string $keyPrefix;
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $password = null,
        ?int $database = null,
        ?float $timeout = null,
        ?string $keyPrefix = null
    ) {
        $this->host = $host ?? (string) bidmap_env('REDIS_HOST', '127.0.0.1');
        $this->port = $port ?? (int) bidmap_env('REDIS_PORT', '6379');
        $envPassword = bidmap_env('REDIS_PASSWORD', '');
        $this->password = $password ?? ($envPassword !== '' ? $envPassword : null);
        $this->database = $database ?? (int) bidmap_env('REDIS_DB', '0');
        $this->timeout = $timeout ?? (float) bidmap_env('REDIS_TIMEOUT', '2.5');
        $this->keyPrefix = trim((string) ($keyPrefix ?? bidmap_env('REDIS_QUEUE_PREFIX', '')));
    }

    public function push(string $queue, string $value): int
    {
        $response = $this->command('RPUSH', $this->queueKey($queue), $value);

        if (!is_int($response)) {
            throw new RuntimeException('Redis retornou uma resposta inesperada ao publicar na fila.');
        }

        return $response;
    }

    public function popBlocking(array $queues, int $timeoutSeconds = 5): ?array
    {
        $queues = array_values(array_filter(array_map('trim', $queues)));

        if ($queues === []) {
            throw new InvalidArgumentException('Informe ao menos uma fila Redis para consumir.');
        }

        $parts = ['BRPOP'];
        foreach ($queues as $queue) {
            $parts[] = $this->queueKey($queue);
        }
        $parts[] = (string) max(0, $timeoutSeconds);

        $response = $this->command(...$parts);

        if ($response === null) {
            return null;
        }

        if (!is_array($response) || count($response) !== 2) {
            throw new RuntimeException('Redis retornou resposta inesperada ao consumir fila.');
        }

        return [
            'queue' => $this->stripQueuePrefix((string) $response[0]),
            'raw_queue' => (string) $response[0],
            'value' => (string) $response[1],
        ];
    }

    public function ping(): bool
    {
        return $this->command('PING') === 'PONG';
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function queueKey(string $queue): string
    {
        $queue = trim($queue);

        if ($this->keyPrefix === '') {
            return $queue;
        }

        return rtrim($this->keyPrefix, ':') . ':' . ltrim($queue, ':');
    }

    private function stripQueuePrefix(string $queue): string
    {
        if ($this->keyPrefix === '') {
            return $queue;
        }

        $prefix = rtrim($this->keyPrefix, ':') . ':';

        if (str_starts_with($queue, $prefix)) {
            return substr($queue, strlen($prefix));
        }

        return $queue;
    }

    private function command(string ...$parts)
    {
        $this->connect();
        $payload = '*' . count($parts) . "\r\n";

        foreach ($parts as $part) {
            $payload .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        $totalWritten = 0;
        $payloadLength = strlen($payload);

        while ($totalWritten < $payloadLength) {
            $written = fwrite($this->socket, substr($payload, $totalWritten));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Falha ao escrever comando no Redis.');
            }

            $totalWritten += $written;
        }

        return $this->readResponse();
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!is_resource($socket)) {
            throw new RuntimeException("Nao foi possivel conectar ao Redis {$this->host}:{$this->port}: {$errstr}");
        }

        $readTimeout = max((int) ceil($this->timeout), (int) bidmap_env('REDIS_READ_TIMEOUT', '30'));
        stream_set_timeout($socket, $readTimeout);
        $this->socket = $socket;

        if ($this->password !== null && $this->password !== '') {
            $this->command('AUTH', $this->password);
        }

        if ($this->database > 0) {
            $this->command('SELECT', (string) $this->database);
        }
    }

    private function readResponse()
    {
        $line = fgets($this->socket);

        if ($line === false) {
            throw new RuntimeException('Redis fechou a conexao sem resposta.');
        }

        $prefix = $line[0];
        $payload = rtrim(substr($line, 1), "\r\n");

        if ($prefix === '+') {
            return $payload;
        }

        if ($prefix === ':') {
            return (int) $payload;
        }

        if ($prefix === '-') {
            throw new RuntimeException('Erro retornado pelo Redis: ' . $payload);
        }

        if ($prefix === '$') {
            $length = (int) $payload;
            if ($length === -1) {
                return null;
            }

            $data = '';
            while (strlen($data) < $length) {
                $chunk = fread($this->socket, $length - strlen($data));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Resposta bulk do Redis incompleta.');
                }
                $data .= $chunk;
            }
            $this->readExact(2);

            return $data;
        }

        if ($prefix === '*') {
            $count = (int) $payload;
            if ($count === -1) {
                return null;
            }

            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $items[] = $this->readResponse();
            }

            return $items;
        }

        throw new RuntimeException('Resposta desconhecida do Redis: ' . $line);
    }

    private function readExact(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Resposta do Redis incompleta.');
            }

            $data .= $chunk;
        }

        return $data;
    }
}
