<?php

if (!isset($arrematacaoBaseSensibilidade, $vendaBaseSensibilidade)) {
    return;
}

if (!function_exists('classeRoiSensibilidade')) {
    function classeRoiSensibilidade($roi)
    {
        if ($roi < -50) {
            return 'roi-menos-50';
        }

        if ($roi < -25) {
            return 'roi-menos-25';
        }

        if ($roi < 0) {
            return 'roi-menos-0';
        }

        if ($roi < 25) {
            return 'roi-0-25';
        }

        if ($roi < 50) {
            return 'roi-25-50';
        }

        if ($roi < 100) {
            return 'roi-50-100';
        }

        if ($roi < 200) {
            return 'roi-100-200';
        }

        return 'roi-mais-200';
    }
}

if (!function_exists('normalizarNumeroSensibilidade')) {
    function normalizarNumeroSensibilidade($valor)
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $valor = trim((string) $valor);

        if ($valor === '') {
            return 0.0;
        }

        $valor = preg_replace('/[^\d,.-]/', '', $valor);

        if (strpos($valor, ',') !== false) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } else {
            $valor = str_replace(',', '', $valor);
        }

        return is_numeric($valor) ? (float) $valor : 0.0;
    }
}

if (!function_exists('calcularJurosSensibilidade')) {
    function calcularJurosSensibilidade($modalidade, $arrematacao, $entrada, $parcelas, $prazo, $jurosMensal)
    {
        $entrada = max(0, min(100, normalizarNumeroSensibilidade($entrada)));
        $parcelas = max(1, (int) normalizarNumeroSensibilidade($parcelas));
        $prazo = max(1, (int) normalizarNumeroSensibilidade($prazo));
        $jurosMensal = max(0, normalizarNumeroSensibilidade($jurosMensal)) / 100;
        $saldoInicial = max(0, normalizarNumeroSensibilidade($arrematacao) * (1 - ($entrada / 100)));

        if ($saldoInicial <= 0 || $jurosMensal <= 0 || $modalidade === 'vista') {
            return 0;
        }

        $limiteMeses = min($parcelas, $prazo);
        $jurosTotal = 0;

        if ($modalidade === 'price') {
            $saldo = $saldoInicial;
            $prestacao = ($saldoInicial * $jurosMensal) / (1 - pow(1 + $jurosMensal, -$parcelas));

            for ($mes = 1; $mes <= $limiteMeses; $mes++) {
                $jurosMes = $saldo * $jurosMensal;
                $amortizacao = $prestacao - $jurosMes;
                $saldo = max(0, $saldo - $amortizacao);
                $jurosTotal += $jurosMes;
            }

            return $jurosTotal;
        }

        $amortizacao = $saldoInicial / $parcelas;

        for ($mes = 1; $mes <= $limiteMeses; $mes++) {
            if ($modalidade === 'parcelado') {
                $jurosTotal += (pow(1 + $jurosMensal, $mes) - 1) * $amortizacao;
                continue;
            }

            $saldo = max(0, $saldoInicial - ($amortizacao * ($mes - 1)));
            $jurosTotal += $saldo * $jurosMensal;
        }

        return $jurosTotal;
    }
}

if (!function_exists('custoSensibilidadePorBase')) {
    // Usa custo em porcentagem quando fornecido, se não, mantém o valor fixo.
    function custoSensibilidadePorBase($base, $valorReais, $valorPorcentagem)
    {
        $base = normalizarNumeroSensibilidade($base);
        $valorReais = normalizarNumeroSensibilidade($valorReais);
        $valorPorcentagem = normalizarNumeroSensibilidade($valorPorcentagem);

        if ($valorPorcentagem > 0) {
            return ($valorPorcentagem / 100) * $base;
        }

        return $valorReais;
    }
}

if (!function_exists('calcularCustosCenarioSensibilidade')) {
    // Refatora valor de aquisição e venda para cada caso simulado no heatmap.
    function calcularCustosCenarioSensibilidade($params, $arrematacao, $venda)
    {
        $comissao = custoSensibilidadePorBase($arrematacao, $params['comissao'], $params['comissaoPct']);
        $itbi = custoSensibilidadePorBase($arrematacao, $params['itbi'], $params['itbiPct']);
        $asses1 = custoSensibilidadePorBase($arrematacao, $params['asses1'], $params['asses1Pct']);
        $corretor = custoSensibilidadePorBase($venda, $params['corretor'], $params['corretorPct']);
        $asses2 = custoSensibilidadePorBase($venda, $params['asses2'], $params['asses2Pct']);

        return [
            'aquisicaoSemDivida' => $comissao + $itbi + $asses1 + $params['registro'] + $params['reforma'] + $params['custos'],
            'aquisicaoTotal' => $comissao + $itbi + $asses1 + $params['dividaprop'] + $params['registro'] + $params['reforma'] + $params['custos'],
            'venda' => $corretor + $asses2,
        ];
    }
}

if (!function_exists('calcularImpostoVendaSensibilidade')) {
    // Espelha as regras de IR da calculadora por cenário: PF, PJ, ou valor manual.
    function calcularImpostoVendaSensibilidade($params, $arrematacao, $venda, $custos)
    {
        $opcaoIR = $params['opcaoIR'];

        if ($opcaoIR === 'Imposto de Renda PF') {
            $ir = 0.15 * ($venda - $custos['aquisicaoSemDivida'] - $custos['venda'] - $arrematacao);
            return max(0, $ir);
        }

        if ($opcaoIR === 'Imposto de Renda PJ') {
            $primeiraParte = $venda * 0.0593;
            $receitaMultiplicada = $venda * 0.08;
            $segundaParte = $receitaMultiplicada > 60000 ? ($receitaMultiplicada - 60000) * 0.10 : 0;
            return max(0, $primeiraParte + $segundaParte);
        }

        return max(0, normalizarNumeroSensibilidade($params['irManual']));
    }
}

if (!function_exists('calcularCenarioSensibilidade')) {
    function calcularCenarioSensibilidade($modalidade, $params, $arrematacao, $venda)
    {
        $custosCenario = calcularCustosCenarioSensibilidade($params, $arrematacao, $venda);
        $impostoVenda = calcularImpostoVendaSensibilidade($params, $arrematacao, $venda, $custosCenario);
        $fluxo = calcularFluxoCenarioSensibilidade($modalidade, $params, $arrematacao, $venda, $custosCenario, $impostoVenda);
        $capitalNecessario = $fluxo['capitalNecessario'];
        $custoAcumuladoFinal = $fluxo['custoAcumuladoFinal'];
        $lucro = $fluxo['lucro'];
        $roi = ($custoAcumuladoFinal > 0) ? ($lucro / $custoAcumuladoFinal) * 100 : 0;

        return [
            'roi' => $roi,
            'arrematacao' => $arrematacao,
            'venda' => $venda,
            'imposto' => $impostoVenda,
            'lucro' => $lucro,
            'valor' => $lucro,
            'capitalTotal' => $capitalNecessario,
            'capitalNecessario' => $capitalNecessario,
            'custoAcumuladoFinal' => $custoAcumuladoFinal,
        ];
    }
}

if (!function_exists('calcularFluxoCenarioSensibilidade')) {
    function calcularFluxoCenarioSensibilidade($modalidade, $params, $arrematacao, $venda, $custosCenario, $impostoVenda)
    {
        $entrada = max(0, min(100, normalizarNumeroSensibilidade($params['entrada'])));
        $parcelas = max(1, (int) normalizarNumeroSensibilidade($params['parcelas']));
        $prazo = max(1, (int) normalizarNumeroSensibilidade($params['prazo']));
        $jurosMensal = max(0, normalizarNumeroSensibilidade($params['jurosMensal'])) / 100;
        $inicioReceita = (int) normalizarNumeroSensibilidade($params['inicioReceita'] ?? 1);
        $duracaoAluguel = (int) normalizarNumeroSensibilidade($params['duracaoAluguel'] ?? 0);
        $receitaRecorrente = normalizarNumeroSensibilidade($params['receitaRecorrente'] ?? 0);
        $custoRecorrente = normalizarNumeroSensibilidade($params['custoRecorrente'] ?? 0);
        $indiceIR = normalizarNumeroSensibilidade($params['indiceIR'] ?? 0);

        $saldoInicial = max(0, normalizarNumeroSensibilidade($arrematacao) * (1 - ($entrada / 100)));
        $desembolsoInicial = $modalidade === 'vista' ? $arrematacao : ($arrematacao * ($entrada / 100));
        $saldo = $modalidade === 'vista' ? 0.0 : $saldoInicial;
        $jurosAcumulado = 0.0;
        $fluxoAcumulado = -$desembolsoInicial - $custosCenario['aquisicaoTotal'];
        $menorFluxoAcumulado = $fluxoAcumulado;
        $custoAcumulado = -$desembolsoInicial - $custosCenario['aquisicaoTotal'];

        for ($mes = 1; $mes <= $prazo; $mes++) {
            $desembolso = 0.0;

            if ($modalidade === 'price' && $mes <= $parcelas && $saldoInicial > 0) {
                if ($jurosMensal > 0) {
                    $jurosMes = $saldo * $jurosMensal;
                    $prestacao = ($saldoInicial * $jurosMensal) / (1 - pow(1 + $jurosMensal, -$parcelas));
                    $amortizacao = $prestacao - $jurosMes;
                    $desembolso = $prestacao;
                } else {
                    $jurosMes = 0.0;
                    $amortizacao = $saldoInicial / $parcelas;
                    $desembolso = $amortizacao;
                }

                $saldo = max(0, $saldo - $amortizacao);
                $jurosAcumulado += $jurosMes;
            } elseif (($modalidade === 'sac' || $modalidade === 'parcelado') && $mes <= $parcelas && $saldoInicial > 0) {
                $amortizacao = $saldoInicial / $parcelas;

                if ($modalidade === 'parcelado') {
                    $jurosMes = (pow(1 + $jurosMensal, $mes) - 1) * $amortizacao;
                } else {
                    $jurosMes = $saldo * $jurosMensal;
                }

                $desembolso = $jurosMes + $amortizacao;
                $saldo = max(0, $saldo - $amortizacao);
                $jurosAcumulado += $jurosMes;
            }

            $custoRecorrenteMes = -$custoRecorrente;
            $receitaRecorrenteMes = ($mes > $inicioReceita && $mes <= ($inicioReceita + $duracaoAluguel))
                ? $receitaRecorrente
                : 0.0;

            if ($mes === $prazo) {
                $impostoFinal = $modalidade === 'vista'
                    ? max(0, $impostoVenda)
                    : max(0, $impostoVenda + ($jurosAcumulado * $indiceIR));
                $fluxoMes = -$desembolso - $custosCenario['venda'] - $impostoFinal + $receitaRecorrenteMes + $custoRecorrenteMes + $venda - $saldo;
                $custoAcumulado += -$desembolso + $custoRecorrenteMes;
            } else {
                $fluxoMes = -$desembolso + $receitaRecorrenteMes + $custoRecorrenteMes;
                $custoAcumulado += -$desembolso + $custoRecorrenteMes;
            }

            $fluxoAcumulado += $fluxoMes;
            $menorFluxoAcumulado = min($menorFluxoAcumulado, $fluxoAcumulado);
        }

        return [
            'lucro' => $fluxoAcumulado,
            'capitalNecessario' => abs($menorFluxoAcumulado),
            'custoAcumuladoFinal' => abs($custoAcumulado),
        ];
    }
}

if (!function_exists('calcularLanceRentabilidadeSensibilidade')) {
    function calcularLanceRentabilidadeSensibilidade($modalidade, $params)
    {
        $rentabilidadeDesejada = normalizarNumeroSensibilidade($params['rentabilidadeDesejada'] ?? 0);
        $venda = normalizarNumeroSensibilidade($params['venda']);

        if ($venda <= 0) {
            return null;
        }

        $atingeRentabilidade = function ($lance) use ($modalidade, $params, $venda, $rentabilidadeDesejada) {
            $cenario = calcularCenarioSensibilidade($modalidade, $params, $lance, $venda);
            return $cenario['roi'] >= $rentabilidadeDesejada;
        };

        if (!$atingeRentabilidade(0)) {
            return null;
        }

        $baixo = 0.0;
        $alto = max($venda, normalizarNumeroSensibilidade($params['arrematacao']) * 2, 1);

        for ($i = 0; $i < 40 && $atingeRentabilidade($alto); $i++) {
            $baixo = $alto;
            $alto *= 2;
        }

        for ($i = 0; $i < 80; $i++) {
            $meio = ($baixo + $alto) / 2;

            if ($atingeRentabilidade($meio)) {
                $baixo = $meio;
            } else {
                $alto = $meio;
            }
        }

        return $baixo;
    }
}

if (!function_exists('gerarTabelaSensibilidade')) {
    function gerarTabelaSensibilidade($modalidade, $params)
    {
        $variacoes = $params['variacoes'];
        $tabela = [];
        $roiMin = PHP_FLOAT_MAX;
        $roiMax = -PHP_FLOAT_MAX;
        $cenariosPositivos = 0;
        $totalCenarios = 0;

        foreach ($variacoes as $varArrematacao) {
            $linha = [];

            foreach ($variacoes as $varVenda) {
                $arrematacaoSimulada = $params['arrematacao'] * (1 + $varArrematacao / 100);
                $vendaSimulada = $params['venda'] * (1 + $varVenda / 100);
                $juros = calcularJurosSensibilidade(
                    $modalidade,
                    $arrematacaoSimulada,
                    $params['entrada'],
                    $params['parcelas'],
                    $params['prazo'],
                    $params['jurosMensal']
                );
                // Cada célula do heatmap tem seus próprios custos e IR porque as variações de lance/venda alteram a base de cálculo.
                $custosCenario = calcularCustosCenarioSensibilidade($params, $arrematacaoSimulada, $vendaSimulada);
                $impostoVenda = calcularImpostoVendaSensibilidade($params, $arrematacaoSimulada, $vendaSimulada, $custosCenario);
                $efeitoJuros = $juros * (1 - $params['indiceIR']);
                $capitalTotal = $arrematacaoSimulada + $custosCenario['aquisicaoTotal'] + $custosCenario['venda'] + $impostoVenda + $efeitoJuros;
                $lucro = $vendaSimulada - $capitalTotal;
                $roi = ($capitalTotal > 0) ? ($lucro / $capitalTotal) * 100 : 0;

                $roiMin = min($roiMin, $roi);
                $roiMax = max($roiMax, $roi);

                if ($roi > 0) {
                    $cenariosPositivos++;
                }

                $totalCenarios++;

                $linha[] = [
                    'roi' => $roi,
                    'arrematacao' => $arrematacaoSimulada,
                    'venda' => $vendaSimulada,
                    'imposto' => $impostoVenda,
                    'lucro' => $lucro,
                    'valor' => $lucro,
                    'capitalTotal' => $capitalTotal,
                ];
            }

            $tabela[] = $linha;
        }

        $jurosBase = calcularJurosSensibilidade(
            $modalidade,
            $params['arrematacao'],
            $params['entrada'],
            $params['parcelas'],
            $params['prazo'],
            $params['jurosMensal']
        );
        $custosBase = calcularCustosCenarioSensibilidade($params, $params['arrematacao'], $params['venda']);
        $impostoBase = calcularImpostoVendaSensibilidade($params, $params['arrematacao'], $params['venda'], $custosBase);
        $capitalBase = $params['arrematacao'] + $custosBase['aquisicaoTotal'] + $custosBase['venda'] + $impostoBase + ($jurosBase * (1 - $params['indiceIR']));
        $lucroBase = $params['venda'] - $capitalBase;
        $indiceVariacoes = array_flip($variacoes);
        $celulaCenario = function ($variacaoArrematacao, $variacaoVenda) use ($tabela, $indiceVariacoes) {
            if (!isset($indiceVariacoes[$variacaoArrematacao], $indiceVariacoes[$variacaoVenda])) {
                return null;
            }

            return $tabela[$indiceVariacoes[$variacaoArrematacao]][$indiceVariacoes[$variacaoVenda]] ?? null;
        };

        return [
            'tabela' => $tabela,
            'roiMin' => $roiMin,
            'roiMax' => $roiMax,
            'roiBase' => ($capitalBase > 0) ? ($lucroBase / $capitalBase) * 100 : 0,
            'lanceRentabilidade' => calcularLanceRentabilidadeSensibilidade($modalidade, $params),
            'lucroVendaMenos10' => $celulaCenario(0, -10)['lucro'] ?? 0,
            'lucroVendaBase' => $celulaCenario(0, 0)['lucro'] ?? 0,
            'lucroVendaMais10' => $celulaCenario(0, 10)['lucro'] ?? 0,
            'cenariosPositivos' => $cenariosPositivos,
            'totalCenarios' => $totalCenarios,
        ];
    }
}

if (!function_exists('gerarTabelaSensibilidadeFluxo')) {
    function gerarTabelaSensibilidadeFluxo($modalidade, $params)
    {
        $variacoes = $params['variacoes'];
        $tabela = [];
        $roiMin = PHP_FLOAT_MAX;
        $roiMax = -PHP_FLOAT_MAX;
        $cenariosPositivos = 0;
        $totalCenarios = 0;

        foreach ($variacoes as $varArrematacao) {
            $linha = [];

            foreach ($variacoes as $varVenda) {
                $arrematacaoSimulada = $params['arrematacao'] * (1 + $varArrematacao / 100);
                $vendaSimulada = $params['venda'] * (1 + $varVenda / 100);
                $cenario = calcularCenarioSensibilidade($modalidade, $params, $arrematacaoSimulada, $vendaSimulada);
                $roi = $cenario['roi'];

                $roiMin = min($roiMin, $roi);
                $roiMax = max($roiMax, $roi);

                if ($roi > 0) {
                    $cenariosPositivos++;
                }

                $totalCenarios++;
                $linha[] = $cenario;
            }

            $tabela[] = $linha;
        }

        $indiceVariacoes = array_flip($variacoes);
        $celulaCenario = function ($variacaoArrematacao, $variacaoVenda) use ($tabela, $indiceVariacoes) {
            if (!isset($indiceVariacoes[$variacaoArrematacao], $indiceVariacoes[$variacaoVenda])) {
                return null;
            }

            return $tabela[$indiceVariacoes[$variacaoArrematacao]][$indiceVariacoes[$variacaoVenda]] ?? null;
        };
        $cenarioBase = $celulaCenario(0, 0);

        return [
            'tabela' => $tabela,
            'roiMin' => $roiMin,
            'roiMax' => $roiMax,
            'roiBase' => $cenarioBase['roi'] ?? 0,
            'lanceRentabilidade' => calcularLanceRentabilidadeSensibilidade($modalidade, $params),
            'lucroVendaMenos10' => $celulaCenario(0, -10)['lucro'] ?? 0,
            'lucroVendaBase' => $celulaCenario(0, 0)['lucro'] ?? 0,
            'lucroVendaMais10' => $celulaCenario(0, 10)['lucro'] ?? 0,
            'precoVendaMenos10' => $celulaCenario(0, -10)['venda'] ?? 0,
            'precoVendaBase' => $celulaCenario(0, 0)['venda'] ?? 0,
            'precoVendaMais10' => $celulaCenario(0, 10)['venda'] ?? 0,
            'cenariosPositivos' => $cenariosPositivos,
            'totalCenarios' => $totalCenarios,
        ];
    }
}

$variacoesSensibilidade = [-15, -10, -5, 0, 5, 10, 15];
$paramsSensibilidade = [
    'arrematacao' => normalizarNumeroSensibilidade($arrematacaoBaseSensibilidade),
    'venda' => normalizarNumeroSensibilidade($vendaBaseSensibilidade),
    'comissao' => normalizarNumeroSensibilidade($comissao ?? 0),
    'comissaoPct' => normalizarNumeroSensibilidade($comissaoporc ?? 0),
    'itbi' => normalizarNumeroSensibilidade($ITBI ?? 0),
    'itbiPct' => normalizarNumeroSensibilidade($ITBIporc ?? 0),
    'asses1' => normalizarNumeroSensibilidade($asses1 ?? 0),
    'asses1Pct' => normalizarNumeroSensibilidade($asses1porc ?? 0),
    'dividaprop' => normalizarNumeroSensibilidade($dividaprop ?? 0),
    'registro' => normalizarNumeroSensibilidade($registro ?? 0),
    'reforma' => normalizarNumeroSensibilidade($reforma ?? 0),
    'custos' => normalizarNumeroSensibilidade($custos ?? 0),
    'corretor' => normalizarNumeroSensibilidade($corretor ?? 0),
    'corretorPct' => normalizarNumeroSensibilidade($corretorporc ?? 0),
    'asses2' => normalizarNumeroSensibilidade($asses2 ?? 0),
    'asses2Pct' => normalizarNumeroSensibilidade($asses2porc ?? 0),
    'irManual' => normalizarNumeroSensibilidade($IREmReais ?? 0),
    'opcaoIR' => $opcaoSelecionada ?? '',
    'rentabilidadeDesejada' => normalizarNumeroSensibilidade($rentabilidade ?? 0),
    'entrada' => normalizarNumeroSensibilidade($entrada ?? 100),
    'parcelas' => (int) max(1, normalizarNumeroSensibilidade($parcela ?? 1)),
    'prazo' => (int) max(1, normalizarNumeroSensibilidade($prazo ?? 1)),
    'jurosMensal' => normalizarNumeroSensibilidade($juros ?? 0),
    'indiceIR' => normalizarNumeroSensibilidade($IndiceIR ?? 0),
    'receitaRecorrente' => normalizarNumeroSensibilidade($receitaRecorrente ?? 0),
    'custoRecorrente' => normalizarNumeroSensibilidade($custoRecorrente ?? 0),
    'inicioReceita' => (int) normalizarNumeroSensibilidade($inicioReceita ?? 1),
    'duracaoAluguel' => (int) normalizarNumeroSensibilidade($duracaoAluguel ?? 0),
    'variacoes' => $variacoesSensibilidade,
];

$modalidadesSensibilidade = [
    'vista' => 'À Vista',
    'price' => 'Price',
    'sac' => 'SAC',
    'parcelado' => 'Parcelado',
];

$tabelasSensibilidadePorModalidade = [];

foreach ($modalidadesSensibilidade as $modalidade => $titulo) {
    $tabelasSensibilidadePorModalidade[$modalidade] = gerarTabelaSensibilidadeFluxo($modalidade, $paramsSensibilidade);
    $tabelasSensibilidadePorModalidade[$modalidade]['titulo'] = $titulo;
}

$tabelaSensibilidade = $tabelasSensibilidadePorModalidade['vista']['tabela'];
$tabelaSensibilidadeRoiMin = $tabelasSensibilidadePorModalidade['vista']['roiMin'];
$tabelaSensibilidadeRoiMax = $tabelasSensibilidadePorModalidade['vista']['roiMax'];
$roiBaseSensibilidade = $tabelasSensibilidadePorModalidade['vista']['roiBase'];
$tabelaSensibilidadeCenariosPositivos = $tabelasSensibilidadePorModalidade['vista']['cenariosPositivos'];
$tabelaSensibilidadeTotalCenarios = $tabelasSensibilidadePorModalidade['vista']['totalCenarios'];
