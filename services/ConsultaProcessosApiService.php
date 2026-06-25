<?php

require_once __DIR__ . '/../config/env.php';

class ConsultaProcessosApiService
{
    private string $apiKey;
    private string $baseUrl;
    private string $documentCreateEndpoint;
    private string $documentResultEndpoint;
    private string $cnjEndpoint;
    private string $partiesCreateEndpoint;
    private string $partiesResultEndpoint;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey ?? bidmap_env('CONSULTA_PROCESSOS_API_KEY', '');
        $this->baseUrl = rtrim($baseUrl ?? bidmap_env('CONSULTA_PROCESSOS_BASE_URL', 'https://consultadeprocessos.com.br'), '/');
        $this->documentCreateEndpoint = bidmap_env('CONSULTA_PROCESSOS_DOCUMENT_CREATE_ENDPOINT', '/api/partner/v2/lawsuits/document');
        $this->documentResultEndpoint = bidmap_env('CONSULTA_PROCESSOS_DOCUMENT_RESULT_ENDPOINT', '/api/partner/v2/lawsuits/document/{requestId}');
        $this->cnjEndpoint = bidmap_env('CONSULTA_PROCESSOS_CNJ_ENDPOINT', '/api/partner/v2/lawsuits/cnj');
        $this->partiesCreateEndpoint = bidmap_env('CONSULTA_PROCESSOS_PARTIES_CREATE_ENDPOINT', '/api/partner/v2/lawsuits/parties');
        $this->partiesResultEndpoint = bidmap_env('CONSULTA_PROCESSOS_PARTIES_RESULT_ENDPOINT', '/api/partner/v2/lawsuits/parties/{requestId}');
    }

    public function criarConsultaDocumento(string $documento): array
    {
        return $this->request('POST', $this->documentCreateEndpoint, [
            'document' => $documento,
        ]);
    }

    public function obterResultadoDocumento(
        string $requestId,
        int $page = 1,
        int $perPage = 20,
        ?string $courtType = null,
        ?string $polarity = null,
        bool $includeFilters = true
    ): array {
        $query = [
            'page' => max(1, $page),
            'perPage' => min(100, max(1, $perPage)),
            'includeFilters' => $includeFilters ? 'true' : 'false',
        ];

        if ($courtType) {
            $query['courtType'] = $courtType;
        }

        if ($polarity) {
            $query['polarity'] = $polarity;
        }

        $endpoint = str_replace('{requestId}', rawurlencode($requestId), $this->documentResultEndpoint);
        return $this->request('GET', $endpoint, null, $query);
    }

    public function obterResultadoDocumentoCompleto(
        string $requestId,
        int $perPage = 100,
        ?string $courtType = null,
        ?string $polarity = null,
        bool $includeFilters = true,
        int $maxPages = 1000
    ): array {
        $perPage = min(100, max(1, $perPage));
        $maxPages = max(1, $maxPages);
        $primeiraPagina = $this->obterResultadoDocumento($requestId, 1, $perPage, $courtType, $polarity, $includeFilters);

        if (!$primeiraPagina['ok']) {
            return $primeiraPagina;
        }

        $raw = is_array($primeiraPagina['raw'] ?? null) ? $primeiraPagina['raw'] : [];
        $status = strtolower((string) ($raw['status'] ?? 'success'));

        if (in_array($status, ['fetching', 'pending', 'running', 'processing', 'error', 'timeout', 'blocked'], true)) {
            return $primeiraPagina;
        }

        $pagination = is_array($raw['pagination'] ?? null) ? $raw['pagination'] : [];
        $totalPages = min($maxPages, max(1, (int) ($pagination['totalPages'] ?? 1)));

        if ($totalPages <= 1) {
            return $primeiraPagina;
        }

        $processos = $this->extrairListaProcessos($raw);
        $avisos = [];

        for ($page = 2; $page <= $totalPages; $page++) {
            $pagina = $this->obterResultadoDocumento($requestId, $page, $perPage, $courtType, $polarity, false);

            if (!$pagina['ok']) {
                $avisos[] = [
                    'page' => $page,
                    'message' => $pagina['message'] ?? 'Nao foi possivel obter esta pagina.',
                    'http_code' => $pagina['http_code'] ?? null,
                ];
                break;
            }

            $rawPagina = is_array($pagina['raw'] ?? null) ? $pagina['raw'] : [];
            $statusPagina = strtolower((string) ($rawPagina['status'] ?? 'success'));

            if (in_array($statusPagina, ['fetching', 'pending', 'running', 'processing'], true)) {
                $avisos[] = [
                    'page' => $page,
                    'status' => $statusPagina,
                    'message' => $pagina['message'] ?? 'Pagina ainda em processamento.',
                ];
                break;
            }

            if (in_array($statusPagina, ['error', 'timeout', 'blocked'], true)) {
                $avisos[] = [
                    'page' => $page,
                    'status' => $statusPagina,
                    'message' => $pagina['message'] ?? 'Pagina retornou erro.',
                ];
                break;
            }

            $processos = array_merge($processos, $this->extrairListaProcessos($rawPagina));
        }

        $raw = $this->substituirListaProcessos($raw, $processos);
        $raw['pagination'] = array_merge($pagination, [
            'page' => 1,
            'perPage' => $perPage,
            'fetchedPages' => min($totalPages, 1 + (int) floor(max(0, count($processos) - 1) / $perPage)),
            'totalFetched' => count($processos),
        ]);

        if ($avisos !== []) {
            $raw['paginationWarnings'] = $avisos;
        }

        return array_merge($primeiraPagina, [
            'raw' => $raw,
            'message' => $primeiraPagina['message'] ?? 'Consulta realizada com sucesso',
        ]);
    }

    private function extrairListaProcessos(array $raw): array
    {
        $candidates = [
            $raw['lawsuits'] ?? null,
            $raw['processes'] ?? null,
            $raw['processos'] ?? null,
            $raw['items'] ?? null,
            $raw['data']['lawsuits'] ?? null,
            $raw['data']['processes'] ?? null,
            $raw['data']['processos'] ?? null,
            $raw['data']['items'] ?? null,
            $raw['result']['lawsuits'] ?? null,
            $raw['result']['processes'] ?? null,
            $raw['result']['processos'] ?? null,
            $raw['result']['items'] ?? null,
            $raw['results'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return [];
    }

    private function substituirListaProcessos(array $raw, array $processos): array
    {
        if (array_key_exists('lawsuits', $raw)) {
            $raw['lawsuits'] = $processos;
            return $raw;
        }

        if (array_key_exists('processes', $raw)) {
            $raw['processes'] = $processos;
            return $raw;
        }

        if (array_key_exists('processos', $raw)) {
            $raw['processos'] = $processos;
            return $raw;
        }

        if (isset($raw['data']) && is_array($raw['data'])) {
            if (array_key_exists('lawsuits', $raw['data'])) {
                $raw['data']['lawsuits'] = $processos;
                return $raw;
            }

            if (array_key_exists('processes', $raw['data'])) {
                $raw['data']['processes'] = $processos;
                return $raw;
            }

            if (array_key_exists('processos', $raw['data'])) {
                $raw['data']['processos'] = $processos;
                return $raw;
            }
        }

        if (isset($raw['result']) && is_array($raw['result'])) {
            if (array_key_exists('lawsuits', $raw['result'])) {
                $raw['result']['lawsuits'] = $processos;
                return $raw;
            }

            if (array_key_exists('processes', $raw['result'])) {
                $raw['result']['processes'] = $processos;
                return $raw;
            }

            if (array_key_exists('processos', $raw['result'])) {
                $raw['result']['processos'] = $processos;
                return $raw;
            }
        }

        $raw['lawsuits'] = $processos;
        return $raw;
    }

    public function consultarProcessoPorCnj(string $cnj, ?string $instance = null): array
    {
        $payload = ['cnj' => $cnj];

        if ($instance) {
            $payload['instance'] = $instance;
        }

        return $this->request('POST', $this->cnjEndpoint, $payload);
    }

    public function criarConsultaPartes(string $cnj, ?string $instance = null): array
    {
        $payload = ['cnj' => $cnj];

        if ($instance) {
            $payload['instance'] = $instance;
        }

        return $this->request('POST', $this->partiesCreateEndpoint, $payload);
    }

    public function obterResultadoPartes(string $requestId): array
    {
        $endpoint = str_replace('{requestId}', rawurlencode($requestId), $this->partiesResultEndpoint);
        return $this->request('GET', $endpoint);
    }

    private function request(string $method, string $endpoint, ?array $payload = null, array $query = []): array
    {
        if ($this->apiKey === '') {
            return [
                'ok' => false,
                'message' => 'Token da Consulta de Processos não configurado.',
                'http_code' => 0,
                'raw' => null,
            ];
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $curl = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
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

        if ($curlError) {
            return [
                'ok' => false,
                'message' => 'Erro de conexão com a Consulta de Processos: ' . $curlError,
                'http_code' => $httpCode,
                'raw' => null,
            ];
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'A Consulta de Processos retornou uma resposta inválida.',
                'http_code' => $httpCode,
                'raw' => $response,
            ];
        }

        $message = $decoded['message']
            ?? $decoded['mensagem']
            ?? $decoded['error']
            ?? ($httpCode >= 200 && $httpCode < 300 ? 'Consulta realizada com sucesso' : 'Consulta externa não retornou sucesso.');

        $status = strtolower((string) ($decoded['status'] ?? ''));
        $isAsyncPending = in_array($status, ['fetching', 'pending', 'running', 'processing'], true);

        return [
            'ok' => ($httpCode >= 200 && $httpCode < 300) || ($httpCode === 404 && $isAsyncPending),
            'message' => (string) $message,
            'http_code' => $httpCode,
            'raw' => $decoded,
        ];
    }
}
