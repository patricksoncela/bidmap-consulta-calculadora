<?php

require_once __DIR__ . '/UsuarioCreditoService.php';
require_once __DIR__ . '/../database/models/ExtratoCreditoUsuarioModel.php';

class CreditoExtratoSyncService
{
    private ExtratoCreditoUsuarioModel $extratoModel;
    private UsuarioCreditoService $usuarioCreditoService;

    public function __construct(mysqli $mysqli, ?UsuarioCreditoService $usuarioCreditoService = null)
    {
        $this->extratoModel = new ExtratoCreditoUsuarioModel($mysqli);
        $this->usuarioCreditoService = $usuarioCreditoService ?? new UsuarioCreditoService();
    }

    public function sincronizarUsuario(int $idUsuario): array
    {
        $payload = $this->usuarioCreditoService->consultarUsuario($idUsuario);
        $movimentacoes = $payload['item']['movimentacoes'] ?? $payload['movimentacoes'] ?? [];

        if (!is_array($movimentacoes)) {
            $movimentacoes = [];
        }

        $sincronizadas = 0;

        foreach ($movimentacoes as $movimentacao) {
            if (!is_array($movimentacao)) {
                continue;
            }

            $normalizada = $this->normalizarMovimentacao($idUsuario, $movimentacao);

            if ($normalizada === null) {
                continue;
            }

            $this->extratoModel->upsertMovimentacao($normalizada);
            $sincronizadas++;
        }

        return [
            'ok' => true,
            'saldo' => isset($payload['item']['saldo']) ? (float) $payload['item']['saldo'] : null,
            'sincronizadas' => $sincronizadas,
            'raw' => $payload,
        ];
    }

    private function normalizarMovimentacao(int $idUsuario, array $movimentacao): ?array
    {
        $data = trim((string) ($movimentacao['data_movimentacao'] ?? ''));
        $tipo = strtoupper(trim((string) ($movimentacao['tipo'] ?? '')));
        $valor = (float) ($movimentacao['valor'] ?? 0);
        $descricao = trim((string) ($movimentacao['descricao'] ?? ''));

        if ($data === '' || !in_array($tipo, ['C', 'D'], true)) {
            return null;
        }

        $idTransacao = trim((string) ($movimentacao['idtransacao'] ?? ''));
        $ativo = (bool) ($movimentacao['ativo'] ?? true);
        $status = $this->inferirStatus($descricao, $ativo);
        $hash = hash('sha256', implode('|', [
            $idUsuario,
            $data,
            $tipo,
            number_format($valor, 2, '.', ''),
            $descricao,
            $idTransacao,
        ]));

        return [
            'id_usuario' => $idUsuario,
            'hash_movimentacao' => $hash,
            'idtransacao' => $idTransacao,
            'tipo' => $tipo,
            'valor' => $valor,
            'descricao' => $descricao,
            'ativo' => $ativo,
            'status' => $status,
            'data_movimentacao' => $data,
            'origem' => 'api_creditos',
            'id_consulta_usuario' => null,
            'id_consulta' => null,
            'raw' => $movimentacao,
        ];
    }

    private function inferirStatus(string $descricao, bool $ativo): string
    {
        $texto = strtolower($descricao);

        if (strpos($texto, 'estorno:') === 0 || strpos($texto, 'estornado') !== false) {
            return 'estorno';
        }

        if (strpos($texto, 'cancelado') !== false || strpos($texto, 'refunded') !== false) {
            return 'cancelado';
        }

        return $ativo ? 'ativo' : 'inativo';
    }
}
