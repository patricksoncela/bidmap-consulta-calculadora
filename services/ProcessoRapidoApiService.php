<?php

require_once __DIR__ . '/../config/env.php';

class ProcessoRapidoApiService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey ?? bidmap_env('PROCESSO_RAPIDO_API_KEY', '');
        $this->baseUrl = rtrim($baseUrl ?? bidmap_env('PROCESSO_RAPIDO_BASE_URL', 'https://api.processorapido.com'), '/');
    }

    public function criarRequisicao(string $numeroProcesso): array
    {
        return $this->requestForm('POST', '/req/criar', [
            'processos[]' => [$numeroProcesso],
        ]);
    }

    public function obterRequisicao(int $reqId): array
    {
        return $this->request('GET', '/req/obter', null, ['req_id' => $reqId]);
    }

    public function pesquisarRequisicao(string $numeroProcesso): array
    {
        return $this->request('GET', '/req/pesquisar', null, ['keyword' => $numeroProcesso]);
    }

    public function baixarArquivo(string $url): array
    {
        $curl = curl_init($this->normalizarUrlDownload($url));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($curlError) {
            return [
                'ok' => false,
                'message' => 'Erro ao baixar PDF do Processo Rápido: ' . $curlError,
                'http_code' => $httpCode,
            ];
        }

        $body = substr((string) $response, $headerSize);

        if ($httpCode < 200 || $httpCode >= 300 || $body === '') {
            return [
                'ok' => false,
                'message' => 'O Processo Rápido não retornou um PDF válido.',
                'http_code' => $httpCode,
            ];
        }

        if (stripos($contentType, 'html') !== false && strncmp($body, '%PDF', 4) !== 0) {
            return [
                'ok' => false,
                'message' => 'O link retornou uma página HTML em vez do PDF.',
                'http_code' => $httpCode,
            ];
        }

        return [
            'ok' => true,
            'content' => $body,
            'http_code' => $httpCode,
        ];
    }

    public function baixarArquivoParaArquivo(string $url, string $destinationPath): array
    {
        $directory = dirname($destinationPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $tempPath = $destinationPath . '.download';
        @unlink($tempPath);

        $handle = fopen($tempPath, 'wb');

        if (!$handle) {
            return [
                'ok' => false,
                'message' => 'Nao foi possivel criar o arquivo local do PDF.',
                'http_code' => 0,
            ];
        }

        $curl = curl_init($this->normalizarUrlDownload($url));
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);

        $executed = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        fclose($handle);

        if ($executed === false || $curlError) {
            @unlink($tempPath);

            return [
                'ok' => false,
                'message' => 'Erro ao baixar PDF do Processo Rapido: ' . $curlError,
                'http_code' => $httpCode,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300 || !is_file($tempPath) || filesize($tempPath) <= 0) {
            @unlink($tempPath);

            return [
                'ok' => false,
                'message' => 'O Processo Rapido nao retornou um PDF valido.',
                'http_code' => $httpCode,
            ];
        }

        $firstBytes = (string) @file_get_contents($tempPath, false, null, 0, 4);

        if (stripos($contentType, 'html') !== false && $firstBytes !== '%PDF') {
            @unlink($tempPath);

            return [
                'ok' => false,
                'message' => 'O link retornou uma pagina HTML em vez do PDF.',
                'http_code' => $httpCode,
            ];
        }

        if (!@rename($tempPath, $destinationPath)) {
            @unlink($destinationPath);

            if (!@rename($tempPath, $destinationPath)) {
                @unlink($tempPath);

                return [
                    'ok' => false,
                    'message' => 'Nao foi possivel mover o PDF para o armazenamento local.',
                    'http_code' => $httpCode,
                ];
            }
        }

        return [
            'ok' => true,
            'file_path' => $destinationPath,
            'http_code' => $httpCode,
        ];
    }

    private function request(string $method, string $endpoint, ?array $payload = null, array $query = []): array
    {
        if ($this->apiKey === '') {
            return $this->erroConfiguracao();
        }

        $query['api_key'] = $this->apiKey;
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . '?' . http_build_query($query);

        $curl = curl_init($url);
        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload ?? [], JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $this->formatarResposta($response, $curlError, $httpCode);
    }

    private function requestForm(string $method, string $endpoint, array $fields = [], array $query = []): array
    {
        if ($this->apiKey === '') {
            return $this->erroConfiguracao();
        }

        $query['api_key'] = $this->apiKey;
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . '?' . http_build_query($query);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_POST => $method === 'POST',
            CURLOPT_POSTFIELDS => $this->formEncode($fields),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $this->formatarResposta($response, $curlError, $httpCode);
    }

    private function formEncode(array $fields): string
    {
        $parts = [];

        foreach ($fields as $key => $value) {
            $values = is_array($value) ? $value : [$value];

            foreach ($values as $singleValue) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $singleValue);
            }
        }

        return implode('&', $parts);
    }

    private function formatarResposta($response, string $curlError, int $httpCode): array
    {
        if ($curlError) {
            return [
                'ok' => false,
                'message' => 'Erro de conexão com o Processo Rápido: ' . $curlError,
                'http_code' => $httpCode,
                'raw' => null,
            ];
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            $texto = trim(strip_tags((string) $response));
            $texto = preg_replace('/\s+/', ' ', $texto) ?: '';

            return [
                'ok' => false,
                'message' => $texto !== ''
                    ? 'Processo Rápido retornou HTTP ' . $httpCode . ': ' . $texto
                    : 'O Processo Rápido retornou uma resposta inválida.',
                'http_code' => $httpCode,
                'raw' => $response,
            ];
        }

        $message = $decoded['message']
            ?? $decoded['msg']
            ?? $decoded['erro']
            ?? $decoded['error']
            ?? ($httpCode >= 200 && $httpCode < 300 ? 'Requisição realizada com sucesso' : 'Processo Rápido não retornou sucesso.');

        return [
            'ok' => $httpCode >= 200 && $httpCode < 300,
            'message' => (string) $message,
            'http_code' => $httpCode,
            'raw' => $decoded,
        ];
    }

    private function normalizarUrlDownload(string $url): string
    {
        if (stripos($url, 'dropbox.com') === false) {
            return $url;
        }

        if (strpos($url, 'dl=0') !== false) {
            return str_replace('dl=0', 'dl=1', $url);
        }

        return $url . (strpos($url, '?') === false ? '?dl=1' : '&dl=1');
    }

    private function erroConfiguracao(): array
    {
        return [
            'ok' => false,
            'message' => 'Chave da API Processo Rápido não configurada.',
            'http_code' => 0,
            'raw' => null,
        ];
    }
}
