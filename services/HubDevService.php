<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/DocumentoValidator.php';

class HubDevService
{
    private string $token;
    private string $baseUrl;
    private string $contract;

    public function __construct(?string $token = null, ?string $baseUrl = null, ?string $contract = null)
    {
        $this->token = $token ?? bidmap_env('HUBDEV_TOKEN', '');
        $this->baseUrl = rtrim($baseUrl ?? bidmap_env('HUBDEV_BASE_URL', 'https://ws.hubdodesenvolvedor.com.br/v2'), '/');
        $this->contract = $this->normalizeContract($contract ?? bidmap_env('HUBDEV_CONTRACT', ''));
    }

    public function consultarCpf(string $cpf): array
    {
        $cpf = DocumentoValidator::onlyDigits($cpf);

        if (!DocumentoValidator::isCpf($cpf)) {
            return $this->invalidResponse('CPF invalido.');
        }

        return $this->request('/cadastropf/', ['cpf' => $cpf], 35);
    }

    public function consultarCnpj(string $cnpj, array $options = []): array
    {
        $cnpj = DocumentoValidator::onlyDigits($cnpj);

        if (!DocumentoValidator::isCnpj($cnpj)) {
            return $this->invalidResponse('CNPJ invalido.');
        }

        $params = ['cnpj' => $cnpj];

        if (!empty($options['ignore_db'])) {
            $params['ignore_db'] = 1;
        }

        if (!empty($options['ie'])) {
            $params['ie'] = (string) $options['ie'];
        }

        $timeout = !empty($options['ie']) && (string) $options['ie'] === '1' ? 360 : 310;

        return $this->request('/cnpj/', $params, $timeout);
    }

    public function consultarUltimaAtualizacaoCnpj(string $cnpj): array
    {
        $cnpj = DocumentoValidator::onlyDigits($cnpj);

        if (!DocumentoValidator::isCnpj($cnpj)) {
            return $this->invalidResponse('CNPJ invalido.');
        }

        return $this->request('/cnpj/', ['cnpj' => $cnpj, 'last_update' => 2], 35);
    }

    private function request(string $path, array $params, int $timeoutSeconds): array
    {
        if ($this->token === '') {
            return $this->invalidResponse('Token da HubDev nao configurado.');
        }

        $params['token'] = $this->token;

        if ($this->contract !== '') {
            $params['contract'] = $this->contract;
        }

        $url = $this->baseUrl . $path . '?' . http_build_query($params);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $rawBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($rawBody === false) {
            return $this->invalidResponse($curlError ?: 'Erro ao consultar HubDev.', $httpCode);
        }

        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            return $this->invalidResponse('Retorno invalido da HubDev.', $httpCode, $rawBody);
        }

        return [
            'ok' => ($decoded['return'] ?? null) === 'OK',
            'return' => $decoded['return'] ?? null,
            'message' => $decoded['message'] ?? null,
            'consumed' => (int) ($decoded['consumed'] ?? 0),
            'result' => $decoded['result'] ?? null,
            'http_code' => $httpCode,
            'raw' => $decoded,
        ];
    }

    private function invalidResponse(string $message, int $httpCode = 0, ?string $rawBody = null): array
    {
        return [
            'ok' => false,
            'return' => 'NOK',
            'message' => $message,
            'consumed' => 0,
            'result' => null,
            'http_code' => $httpCode,
            'raw' => $rawBody,
        ];
    }

    private function normalizeContract(?string $contract): string
    {
        $contract = trim((string) $contract);

        if ($contract === '') {
            return '';
        }

        if (substr($contract, 0, 1) === '&') {
            $contract = substr($contract, 1);
        }

        if (substr($contract, 0, strlen('contract=')) === 'contract=') {
            $contract = substr($contract, strlen('contract='));
        }

        return $contract;
    }
}
