<?php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/helpers/auth.php';

bidmap_bootstrap_portfolio_demo_session();

if (!isset($_SESSION['cliente']['email'])) {
    header('Location: ' . bidmap_env('BIDMAP_TOOLS_LOGIN_URL', 'consultar_processos.php'));
    exit();
}

$email = $_SESSION['cliente']['email'];

$arrematacao = 0;
$venda = 0;
$entrada = 100;
$juros_anual = 0;
$desconto = 0;
$parcela = 1;
$prazo = 12;
$comissaoporc = 0;
$comissao = 0;
$ITBI = 0;
$ITBIporc = 0;
$asses1 = 0;
$asses1porc = 0;
$registro = 0;
$reforma = 0;
$custos = 0;
$dividaprop = 0;
$custoaquisicao = 0;
$custovenda = 0;
$asses2 = 0;
$asses2porc = 0;
$corretor = 0;
$corretorporc = 0;
$IREmPorcentagem = 15;
$IREmReais = 0;
$inicioReceita = 1;
$aluguelLiquido = 0;
$duracaoAluguel = 0;
$IRRecorrenteEmPorcentagem = 15;
$IRRecorrente = 0;
$condominio = 0;
$IPTU = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $arrematacao = isset($_POST['valor1']) ? floatval($_POST['valor1']) : 0.0;
    $venda = isset($_POST['valor2']) ? floatval($_POST['valor2']) : 0.0;
    $entrada = isset($_POST["porcentagem1"]) ? $_POST["porcentagem1"] : 100;
    $juros_anual = isset($_POST["porcentagem2"]) ? $_POST["porcentagem2"] : 0;
    $desconto = isset($_POST["porcentagem4"]) ? $_POST["porcentagem4"] : 0;
    $parcela = isset($_POST["parcelas1"]) ? floatval($_POST["parcelas1"]) : 1.0;
    $prazo = isset($_POST["parcelas2"]) ? floatval($_POST["parcelas2"]) : 12;
    $comissaoporc = isset($_POST["comissaoEmPorcentagem"]) ? floatval($_POST["comissaoEmPorcentagem"]) : 0.0;
    $comissao = isset($_POST["comissaoEmReais"]) ? $_POST["comissaoEmReais"] : 0;
    $dividaprop = isset($_POST["dividaprop"]) ? $_POST["dividaprop"] : 0;
    $ITBI = isset($_POST["ITBI"]) ? $_POST["ITBI"] : 0;
    $ITBIporc = isset($_POST["ITBIporc"]) ? $_POST["ITBIporc"] : 0;
    $asses1 = isset($_POST["asses1"]) ? $_POST["asses1"] : 0;
    $asses1porc = isset($_POST["asses1porc"]) ? $_POST["asses1porc"] : 0;
    $registro = isset($_POST["registro"]) ? $_POST["registro"] : 0;
    $reforma = isset($_POST["reforma"]) ? $_POST["reforma"] : 0;
    $custos = isset($_POST["custos"]) ? $_POST["custos"] : 0;
    $asses2 = isset($_POST["asses2"]) ? $_POST["asses2"] : 0;
    $asses2porc = isset($_POST["asses2porc"]) ? $_POST["asses2porc"] : 0;
    $IREmReais = isset($_POST["IREmReais"]) ? $_POST["IREmReais"] : 0;
    $corretor = isset($_POST["corretor"]) ? $_POST["corretor"] : 0;
    $corretorporc = isset($_POST["corretorporc"]) ? $_POST["corretorporc"] : 0;
    $duracaoAluguel = isset($_POST["duracaoAluguel"]) ? $_POST["duracaoAluguel"] : 0;
    $aluguelLiquido = isset($_POST["aluguelLiquido"]) ? $_POST["aluguelLiquido"] : 0;
    $inicioReceita = isset($_POST["inicioReceita"]) ? $_POST["inicioReceita"] : 1;
    $IRRecorrenteEmPorcentagem = isset($_POST["IRRecorrenteEmPorcentagem"]) ? $_POST["IRRecorrenteEmPorcentagem"] : 15;
    $IRRecorrente = isset($_POST["IRRecorrente"]) ? $_POST["IRRecorrente"] : 0;
    $IPTU = isset($_POST["IPTU"]) ? $_POST["IPTU"] : 0;
    $condominio = isset($_POST["condominio"]) ? $_POST["condominio"] : 0;
    $custoaquisicao = $comissao + $ITBI + $dividaprop + $asses1 + $registro + $reforma + $custos;
    $custovenda = $corretor + $asses2;
    $receitaRecorrente = $aluguelLiquido - $IRRecorrente;
    $custoRecorrente = $IPTU + $condominio;

    $juros_int = (pow((1 + ($juros_anual / 100)), 1 / 12) - 1) * 100;
    $juros = number_format($juros_int, 2);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $IndiceIR = 0;
    if (isset($_POST['flexRadioDefault'])) {

        $opcaoSelecionada = $_POST['flexRadioDefault'];

        if ($opcaoSelecionada === "Imposto de Renda PF") {
            $IndiceIR = 0.15;
        }
    }

    /* declaração das arrays gerais*/
    $Custo_Negocio = [];
    $Receita_Recorrente = [];
    $Custo_Recorrente = [];
    $Venda = [];

    /* declara as arrays do SAC */
    $Desembolso_SAC = [];
    $Saldo_Devedor_SAC = [];
    $Juros_SAC = [];
    $Amortizacao_SAC = [];
    $Fluxo_Caixa_SAC = [];
    $Fluxo_Acumulado_SAC = [];
    $Custo_Acumulado_SAC = [];
    $Receita_Acumulada_SAC = [];
    $Saida_SAC = []; //Custo_Acumulado_SAC sem o mês anterior
    $Entrada_SAC = []; //Receita_Acumulada_SAC sem o mês anterior 

    /* declara as arrays do Parcelado */
    $Desembolso_Parcelado = [];
    $Saldo_Devedor_Parcelado = [];
    $Juros_Parcelado = [];
    $Amortizacao_Parcelado = [];
    $Fluxo_Caixa_Parcelado = [];
    $Fluxo_Acumulado_Parcelado = [];
    $Custo_Acumulado_Parcelado = [];
    $Receita_Acumulada_Parcelado = [];
    $Saida_Parcelado = []; //Custo_Acumulado_Parcelado sem o mês anterior
    $Entrada_Parcelado = []; //Receita_Acumulada_Parcelado sem o mês anterior 



    /* declara as arrays do Price */
    $Desembolso_Price = [];
    $Saldo_Devedor_Price = [];
    $Juros_Price = [];
    $Amortizacao_Price = [];
    $Fluxo_Caixa_Price = [];
    $Fluxo_Acumulado_Price = [];
    $Custo_Acumulado_Price = [];
    $Receita_Acumulada_Price = [];
    $Saida_Price = []; //Custo_Acumulado_Price sem o mês anterior
    $Entrada_Price = []; //Receita_Acumulada_Price sem o mês anterior 

    /* declara as arrays para pagamento a Vista */
    $Desembolso_Vista = [];
    $Saldo_Devedor_Vista = [];
    $Juros_Vista = [];
    $Amortizacao_Vista = [];
    $Fluxo_Caixa_Vista = [];
    $Fluxo_Acumulado_Vista = [];
    $Custo_Acumulado_Vista = [];
    $Receita_Acumulada_Vista = [];
    $Saida_Vista = []; //Custo_Acumulado_Vista sem o mês anterior
    $Entrada_Vista = []; //Receita_Acumulada_Vista sem o mês anterior 

    $IREmReaistabsac = 0;
    $IREmReaistabParcelado = 0;
    $IREmReaistabprice = 0;
    $IREmReaistabvista = 0;
    $Juros_Acumulado_Price = 0;

    for ($mes = 0; $mes <= $prazo; $mes++) {

        $Custo_Recorrente[$mes] = -$custoRecorrente;

        if ($mes == 0) {
            $Custo_Negocio[$mes] = -$custoaquisicao;
            $Venda[$mes] = 0;
            $Custo_Recorrente[$mes] = 0;
        } elseif ($mes == $prazo) {
            $Custo_Negocio[$mes] = -$custovenda;
            $Venda[$mes] = $venda;
        } else {
            $Custo_Negocio[$mes] = 0;
            $Venda[$mes] = 0;
        }
        if ($mes > $inicioReceita && $mes <= ($inicioReceita + $duracaoAluguel)) {
            $Receita_Recorrente[$mes] = $receitaRecorrente;
        } else {
            $Receita_Recorrente[$mes] = 0;
        }
    }
    /* declara as arrays do SAC no mes 0 */
    if ($entrada == 0) {
        $Saldo_Devedor_SAC[0] = - ($arrematacao);
        $Desembolso_SAC[0] = 0;
    } else {
        $Saldo_Devedor_SAC[0] = - ($arrematacao - ($arrematacao * ($entrada / 100)));
        $Desembolso_SAC[0] = -$arrematacao * ($entrada / 100);
    }
    $Amortizacao_SAC[0] = 0;
    $Juros_SAC[0] = 0;
    $Fluxo_Caixa_SAC[0] = $Desembolso_SAC[0] + $Custo_Negocio[0] + $Receita_Recorrente[0] + $Custo_Recorrente[0] + $Venda[0];
    $Fluxo_Acumulado_SAC[0] = $Fluxo_Caixa_SAC[0];
    $Custo_Acumulado_SAC[0] = $Desembolso_SAC[0] + $Custo_Negocio[0] + $Custo_Recorrente[0];
    $Receita_Acumulada_SAC[0] = $Receita_Recorrente[0] + $Venda[0];
    $Juros_Acumulado_SAC = 0;

    /* declara as arrays do Parcelado no mes 0 */
    if ($entrada == 0) {
        $Saldo_Devedor_Parcelado[0] = - ($arrematacao);
        $Desembolso_Parcelado[0] = 0;
    } else {
        $Saldo_Devedor_Parcelado[0] = - ($arrematacao - ($arrematacao * ($entrada / 100)));
        $Desembolso_Parcelado[0] = -$arrematacao * ($entrada / 100);
    }
    $Amortizacao_Parcelado[0] = 0;
    $Juros_Parcelado[0] = 0;
    $Fluxo_Caixa_Parcelado[0] = $Desembolso_Parcelado[0] + $Custo_Negocio[0] + $Receita_Recorrente[0] + $Custo_Recorrente[0] + $Venda[0];
    $Fluxo_Acumulado_Parcelado[0] = $Fluxo_Caixa_Parcelado[0];
    $Custo_Acumulado_Parcelado[0] = $Desembolso_Parcelado[0] + $Custo_Negocio[0] + $Custo_Recorrente[0];
    $Receita_Acumulada_Parcelado[0] = $Receita_Recorrente[0] + $Venda[0];
    $Juros_Acumulado_Parcelado = 0;



    /* declara as arrays do Price no mes 0 */
    $Saldo_Devedor_Price[0] = - ($arrematacao - ($arrematacao * ($entrada / 100)));
    $Desembolso_Price[0] = -$arrematacao * ($entrada / 100);
    $Amortizacao_Price[0] = 0;
    $Juros_Price[0] = 0;
    $Fluxo_Caixa_Price[0] = $Desembolso_Price[0] + $Custo_Negocio[0] + $Receita_Recorrente[0] + $Custo_Recorrente[0] + $Venda[0];
    $Fluxo_Acumulado_Price[0] = $Fluxo_Caixa_Price[0];
    $Custo_Acumulado_Price[0] = $Desembolso_Price[0] + $Custo_Negocio[0] + $Custo_Recorrente[0];
    $Receita_Acumulada_Price[0] = $Receita_Recorrente[0] + $Venda[0];

    /* declara as arrays para pagamento a Vista do mes 0 em diante*/
    $Desembolso_Vista[0] = -$arrematacao;
    $Saldo_Devedor_Vista[0] = 0;
    $Amortizacao_Vista[0] = 0;
    $Juros_Vista[0] = 0;
    $Fluxo_Caixa_Vista[0] = $Desembolso_Vista[0] + $Custo_Negocio[0] + $Receita_Recorrente[0] + $Custo_Recorrente[0] + $Venda[0];
    $Fluxo_Acumulado_Vista[0] = $Fluxo_Caixa_Vista[0];
    $Custo_Acumulado_Vista[0] = $Desembolso_Vista[0] + $Custo_Negocio[0] + $Custo_Recorrente[0];
    $Receita_Acumulada_Vista[0] = $Receita_Recorrente[0] + $Venda[0];

    for ($mes = 1; $mes <= $prazo; $mes++) {
        /* declara as arrays do SAC do mes 1 em diante*/
        if ($mes < $parcela) {
            $Amortizacao_SAC[$mes] = $Saldo_Devedor_SAC[0] / $parcela;
            $Saldo_Devedor_SAC[$mes] = $Saldo_Devedor_SAC[$mes - 1] - $Amortizacao_SAC[$mes];
        } elseif ($mes == $parcela) {
            $Amortizacao_SAC[$mes] = $Saldo_Devedor_SAC[0] / $parcela;
            $Saldo_Devedor_SAC[$mes] = 0;
        } else {
            $Amortizacao_SAC[$mes] = 0;
            $Saldo_Devedor_SAC[$mes] = 0;
        }
        $Juros_SAC[$mes] = $Saldo_Devedor_SAC[$mes - 1] * ($juros / 100);

        $Desembolso_SAC[$mes] = $Juros_SAC[$mes] + $Amortizacao_SAC[$mes];

        $Custo_Acumulado_SAC[$mes] = $Desembolso_SAC[$mes] + $Custo_Negocio[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_SAC[$mes - 1];
        $Fluxo_Caixa_SAC[$mes] = $Desembolso_SAC[$mes] + $Custo_Negocio[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes];
        $Receita_Acumulada_SAC[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes];
        $Juros_Acumulado_SAC =  $Juros_Acumulado_SAC + $Juros_SAC[$mes];
        if ($mes == $prazo) {
            $IREmReaistabsac = $IREmReais + ($Juros_Acumulado_SAC * $IndiceIR);

            if ($IREmReaistabsac <= 0) {
                $IREmReaistabsac = 0;
            }

            $Custo_Negocio_SAC[$mes] = $Custo_Negocio[$mes] - $IREmReaistabsac;
            $Custo_Acumulado_SAC[$mes] = $Desembolso_SAC[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_SAC[$mes - 1];
            $Fluxo_Caixa_SAC[$mes] = $Desembolso_SAC[$mes] + $Custo_Negocio_SAC[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_SAC[$mes];
            $Receita_Acumulada_SAC[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_SAC[$mes] + $Custo_Negocio_SAC[$mes];
        }
        $Fluxo_Acumulado_SAC[$mes] = $Fluxo_Acumulado_SAC[$mes - 1] + $Fluxo_Caixa_SAC[$mes];
        $Saida_SAC[$mes] = $Desembolso_SAC[$mes] + $Custo_Negocio[$mes] + $Custo_Recorrente[$mes];
        $Entrada_SAC[$mes] = 0;

        /* declara as arrays do Parcelado do mes 1 em diante*/
        if ($mes < $parcela) {
            $Amortizacao_Parcelado[$mes] = $Saldo_Devedor_Parcelado[0] / $parcela;
            $Saldo_Devedor_Parcelado[$mes] = $Saldo_Devedor_Parcelado[$mes - 1] - $Amortizacao_Parcelado[$mes];
        } elseif ($mes == $parcela) {
            $Amortizacao_Parcelado[$mes] = $Saldo_Devedor_Parcelado[0] / $parcela;
            $Saldo_Devedor_Parcelado[$mes] = 0;
        } else {
            $Amortizacao_Parcelado[$mes] = 0;
            $Saldo_Devedor_Parcelado[$mes] = 0;
        }
        $Juros_Parcelado[$mes] = (((1 + ($juros / 100)) ** $mes) - 1) * $Amortizacao_Parcelado[$mes];

        $Desembolso_Parcelado[$mes] = $Juros_Parcelado[$mes] + $Amortizacao_Parcelado[$mes];

        $Custo_Acumulado_Parcelado[$mes] = $Desembolso_Parcelado[$mes] + $Custo_Negocio[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_Parcelado[$mes - 1];
        $Fluxo_Caixa_Parcelado[$mes] = $Desembolso_Parcelado[$mes] + $Custo_Negocio[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes];
        $Receita_Acumulada_Parcelado[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes];
        $Juros_Acumulado_Parcelado =  $Juros_Acumulado_Parcelado + $Juros_Parcelado[$mes];
        if ($mes == $prazo) {
            $IREmReaistabParcelado = $IREmReais + ($Juros_Acumulado_Parcelado * $IndiceIR);

            if ($IREmReaistabParcelado <= 0) {
                $IREmReaistabParcelado = 0;
            }

            $Custo_Negocio_Parcelado[$mes] = $Custo_Negocio[$mes] - $IREmReaistabParcelado;
            $Custo_Acumulado_Parcelado[$mes] = $Desembolso_Parcelado[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_Parcelado[$mes - 1];
            $Fluxo_Caixa_Parcelado[$mes] = $Desembolso_Parcelado[$mes] + $Custo_Negocio_Parcelado[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_Parcelado[$mes];
            $Receita_Acumulada_Parcelado[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_Parcelado[$mes] + $Custo_Negocio_Parcelado[$mes];
        }
        $Fluxo_Acumulado_Parcelado[$mes] = $Fluxo_Acumulado_Parcelado[$mes - 1] + $Fluxo_Caixa_Parcelado[$mes];
        $Saida_Parcelado[$mes] = $Desembolso_Parcelado[$mes] + $Custo_Negocio[$mes] + $Custo_Recorrente[$mes];
        $Entrada_Parcelado[$mes] = 0;


        /* declara as arrays do Price do mes 1 em diante*/

        if ($mes <= $parcela) {
            if ($juros != 0) {
                $Juros_Price[$mes] = $Saldo_Devedor_Price[$mes - 1] * ($juros / 100);
                $Desembolso_Price[$mes] = ($Saldo_Devedor_Price[0] * ($juros / 100)) / (1 - pow(1 + ($juros / 100), -$parcela));
                $Amortizacao_Price[$mes] = $Desembolso_Price[$mes] - $Juros_Price[$mes];
            } else {
                $Juros_Price[$mes] = 0;
                $Amortizacao_Price[$mes] = $Saldo_Devedor_Price[0] / $parcela;
                $Desembolso_Price[$mes] = $Amortizacao_Price[$mes];
            }

            $Saldo_Devedor_Price[$mes] = $Saldo_Devedor_Price[$mes - 1] - $Amortizacao_Price[$mes];
        } else {
            $Juros_Price[$mes] = $Saldo_Devedor_Price[$mes - 1] * ($juros / 100);
            $Desembolso_Price[$mes] = 0;
            $Saldo_Devedor_Price[$mes] = 0;
            $Amortizacao_Price[$mes] = 0;
        }
        $Custo_Acumulado_Price[$mes] = $Desembolso_Price[$mes] + $Custo_Negocio[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_Price[$mes - 1];
        $Fluxo_Caixa_Price[$mes] = $Desembolso_Price[$mes] + $Custo_Negocio[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes];
        $Receita_Acumulada_Price[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes];
        $Juros_Acumulado_Price =  $Juros_Acumulado_Price + $Juros_Price[$mes];
        /* if ($mes == $prazo) {
            if (isset($_POST['flexRadioDefault'])) {

                $opcaoSelecionada = $_POST['flexRadioDefault'];

                if ($opcaoSelecionada === "Imposto de Renda PF") {
                    $IREmReaistabprice = $IREmReais + ($Juros_Acumulado_Price * $IndiceIR);
                }
            }
            if ($IREmReaistabprice <= 0) {
                $IREmReaistabprice = 0;
            } */

        if ($mes == $prazo) {
            $IREmReaistabprice = $IREmReais + ($Juros_Acumulado_Price * $IndiceIR);
            if ($IREmReaistabprice <= 0) {
                $IREmReaistabprice = 0;
            }

            $Custo_Negocio_Price[$mes] = $Custo_Negocio[$mes] - $IREmReaistabprice;
            $Custo_Acumulado_Price[$mes] = $Desembolso_Price[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_Price[$mes - 1];
            $Fluxo_Caixa_Price[$mes] = $Desembolso_Price[$mes] + $Custo_Negocio_Price[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_Price[$mes];
            $Receita_Acumulada_Price[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_Price[$mes] + $Custo_Negocio_Price[$mes];
        }
        $Fluxo_Acumulado_Price[$mes] = $Fluxo_Acumulado_Price[$mes - 1] + $Fluxo_Caixa_Price[$mes];

        /* declara as arrays para pagamento a Vista do mes 1 em diante*/
        $Desembolso_Vista[$mes] = 0;
        $Saldo_Devedor_Vista[$mes] = 0;
        $Amortizacao_Vista[$mes] = 0;
        $Juros_Vista[$mes] = 0;
        $Custo_Acumulado_Vista[$mes] = $Desembolso_Vista[$mes] + $Custo_Negocio[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_Vista[$mes - 1];
        $Fluxo_Caixa_Vista[$mes] = $Desembolso_Vista[$mes] + $Custo_Negocio[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes];
        $Receita_Acumulada_Vista[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes];
        if ($mes == $prazo) {
            if ($IREmReais <= 0) {
                $IREmReaistabvista = 0;
            } else {
                $IREmReaistabvista = $IREmReais;
            }

            $Custo_Negocio_Vista[$mes] = $Custo_Negocio[$mes] - $IREmReaistabvista;
            $Custo_Acumulado_Vista[$mes] = $Desembolso_Vista[$mes] + $Custo_Recorrente[$mes] + $Custo_Acumulado_Vista[$mes - 1];
            $Fluxo_Caixa_Vista[$mes] = $Desembolso_Vista[$mes] + $Custo_Negocio_Vista[$mes] + $Receita_Recorrente[$mes] + $Custo_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_Vista[$mes];
            $Receita_Acumulada_Vista[$mes] = $Receita_Recorrente[$mes] + $Venda[$mes] + $Saldo_Devedor_Vista[$mes] + $Custo_Negocio_Vista[$mes];
        }
        $Fluxo_Acumulado_Vista[$mes] = $Fluxo_Acumulado_Vista[$mes - 1] + $Fluxo_Caixa_Vista[$mes];
    }

    // Método da bisseção para encontrar a TIR
    function calcularTIR_AM($fluxosDeCaixa)
    {
        $precisao = 0.00001;
        $maxIteracoes = 1000;
        $taxaBaixa = 0;
        $taxaAlta = 1;

        if ($fluxosDeCaixa[0] != 0) {
            for ($i = 0; $i < $maxIteracoes; $i++) {
                $taxaMedia = ($taxaBaixa + $taxaAlta) / 2;
                $vpl = calcularVPL($taxaMedia * 100, $fluxosDeCaixa);

                if (abs($vpl) < $precisao) {
                    return $taxaMedia;
                }

                if ($vpl > 0) {
                    $taxaBaixa = $taxaMedia;
                } else {
                    $taxaAlta = $taxaMedia;
                }
            }
        }

        return 0; // Não convergiu
    }
    // Função para calcular a TIR em termos anuais (A.A)
    function calcularTIR_AA($Fluxo_Caixa)
    {
        $tir_am = calcularTIR_AM($Fluxo_Caixa);
        $tir_aa = pow(1 + $tir_am, 12) - 1;

        return $tir_aa;
    }

    function calcularVPL($taxa, $Fluxo_Caixa)
    {
        $vpl = 0;
        $c0 = $Fluxo_Caixa[0];

        for ($t = 1; $t < count($Fluxo_Caixa); $t++) {
            $vpl += $Fluxo_Caixa[$t] / pow(1 + ($taxa / 100), $t);
        }

        $vpl += $c0;

        return $vpl;
    }

    // Cálculos para a Tabela SAC
    $Desembolso_Total_SAC = min($Fluxo_Acumulado_SAC); //$Custo_Acumulado_SAC[$prazo]; //Tinham essas 2 opções, o “erro mental” que ta dando é que no mês 12 ta dando um Custo Acumulado maior, do que eu to falando na tabela que é o Desembolso total. Mas ta certo, pq no último mês você vende, então entra caixa e não é o mês que vc mais vai precisar de dinheiro

    $Custo_SAC = $Custo_Acumulado_SAC[$prazo];
    $Receita_SAC = $Receita_Acumulada_SAC[$prazo];
    $Lucro_SAC = $Fluxo_Acumulado_SAC[$prazo];
    if (
        $Lucro_SAC != 0 && $Desembolso_Total_SAC
        != 0
    ) {
        $Taxa_Lucro_SAC = - ($Lucro_SAC / $Custo_Acumulado_SAC[$prazo]); //- ($Lucro_SAC / $Desembolso_Total_SAC); Aqui não podemos considerar o lucro do negócio com o maior desembolso, mas sim uma foto de como ele estará no final
    } else {
        $Taxa_Lucro_SAC = 0;
    }
    $TIR_AM_SAC = calcularTIR_AM($Fluxo_Caixa_SAC);
    $TIR_AA_SAC = calcularTIR_AA($Fluxo_Caixa_SAC);
    $VPL_SAC = calcularVPL($desconto, $Fluxo_Caixa_SAC);
    if (
        $Receita_Recorrente[$inicioReceita + 1] != 0 &&
        $arrematacao != 0 &&
        ($prazo - 1) >= 0 &&
        isset($Custo_Acumulado_SAC[$prazo - 1], $Custo_Recorrente[$inicioReceita + 1]) &&
        $Custo_Acumulado_SAC[$prazo - 1] != 0
    ) {
        $Retorno_Aluguel_SAC = ($Receita_Recorrente[$inicioReceita + 1] + $Custo_Recorrente[$inicioReceita + 1]) / -$Custo_Acumulado_SAC[$prazo];
    } else {
        $Retorno_Aluguel_SAC = 0;
    }

    // Cálculos para a Tabela Parcelado
    $Desembolso_Total_Parcelado = min($Fluxo_Acumulado_Parcelado); //$Custo_Acumulado_Parcelado[$prazo]; //Tinham essas 2 opções, o “erro mental” que ta dando é que no mês 12 ta dando um Custo Acumulado maior, do que eu to falando na tabela que é o Desembolso total. Mas ta certo, pq no último mês você vende, então entra caixa e não é o mês que vc mais vai precisar de dinheiro

    $Custo_Parcelado = $Custo_Acumulado_Parcelado[$prazo];
    $Receita_Parcelado = $Receita_Acumulada_Parcelado[$prazo];
    $Lucro_Parcelado = $Fluxo_Acumulado_Parcelado[$prazo];
    if (
        $Lucro_Parcelado != 0 && $Desembolso_Total_Parcelado
        != 0
    ) {
        $Taxa_Lucro_Parcelado = - ($Lucro_Parcelado / $Custo_Acumulado_Parcelado[$prazo]); //- ($Lucro_Parcelado / $Desembolso_Total_Parcelado); Aqui não podemos considerar o lucro do negócio com o maior desembolso, mas sim uma foto de como ele estará no final
    } else {
        $Taxa_Lucro_Parcelado = 0;
    }
    $TIR_AM_Parcelado = calcularTIR_AM($Fluxo_Caixa_Parcelado);
    $TIR_AA_Parcelado = calcularTIR_AA($Fluxo_Caixa_Parcelado);
    $VPL_Parcelado = calcularVPL($desconto, $Fluxo_Caixa_Parcelado);
    if (
        $Receita_Recorrente[$inicioReceita + 1] != 0 &&
        $arrematacao != 0 &&
        ($prazo - 1) >= 0 &&
        isset($Custo_Acumulado_Parcelado[$prazo - 1], $Custo_Recorrente[$inicioReceita + 1]) &&
        $Custo_Acumulado_Parcelado[$prazo - 1] != 0
    ) {
        $Retorno_Aluguel_Parcelado = ($Receita_Recorrente[$inicioReceita + 1] + $Custo_Recorrente[$inicioReceita + 1]) / -$Custo_Acumulado_Parcelado[$prazo];
    } else {
        $Retorno_Aluguel_Parcelado = 0;
    }




    // Cálculos para a Tabela Price
    $Desembolso_Total_Price = min($Fluxo_Acumulado_Price); //$Custo_Acumulado_Price[$prazo];
    $Custo_Price = $Custo_Acumulado_Price[$prazo];
    $Receita_Price = $Receita_Acumulada_Price[$prazo];
    $Lucro_Price = $Fluxo_Acumulado_Price[$prazo];
    if (
        $Lucro_Price != 0 && $Desembolso_Total_Price
        != 0
    ) {
        $Taxa_Lucro_Price = - ($Lucro_Price / $Custo_Acumulado_Price[$prazo]);
    } else {
        $Taxa_Lucro_Price = 0;
    }
    $TIR_AM_Price = calcularTIR_AM($Fluxo_Caixa_Price);
    $TIR_AA_Price = calcularTIR_AA($Fluxo_Caixa_Price);
    $VPL_Price = calcularVPL($desconto, $Fluxo_Caixa_Price);
    if (
        $Receita_Recorrente[$inicioReceita + 1] != 0 &&
        $arrematacao != 0 &&
        ($prazo - 1) >= 0 &&
        isset($Custo_Acumulado_Price[$prazo - 1], $Custo_Recorrente[$inicioReceita + 1]) &&
        $Custo_Acumulado_Price[$prazo - 1] != 0
    ) {
        $Retorno_Aluguel_Price = ($Receita_Recorrente[$inicioReceita + 1] + $Custo_Recorrente[$inicioReceita + 1]) / -$Custo_Acumulado_Price[$prazo];
    } else {
        $Retorno_Aluguel_Price = 0;
    }

    // Cálculos para o Pagamento à Vista
    $Desembolso_Total_Vista = min($Fluxo_Acumulado_Vista);
    $Custo_Vista = $Custo_Acumulado_Vista[$prazo];
    $Receita_Vista = $Receita_Acumulada_Vista[$prazo];
    $Lucro_Vista = $Fluxo_Acumulado_Vista[$prazo];
    if (
        $Lucro_Vista != 0 && $Desembolso_Total_Vista
        != 0
    ) {
        $Taxa_Lucro_Vista = - ($Lucro_Vista / $Custo_Acumulado_Vista[$prazo]);
    } else {
        $Taxa_Lucro_Vista = 0;
    }
    $TIR_AM_Vista = calcularTIR_AM($Fluxo_Caixa_Vista);
    $TIR_AA_Vista = calcularTIR_AA($Fluxo_Caixa_Vista);
    $VPL_Vista = calcularVPL($desconto, $Fluxo_Caixa_Vista);
    if (
        $Receita_Recorrente[$inicioReceita + 1] != 0 &&
        $arrematacao != 0 &&
        ($prazo - 1) >= 0 &&
        isset($Custo_Acumulado_Vista[$prazo - 1], $Custo_Recorrente[$inicioReceita + 1]) &&
        $Custo_Acumulado_Vista[$prazo - 1] != 0
    ) {
        $Retorno_Aluguel_Vista = ($Receita_Recorrente[$inicioReceita + 1] + $Custo_Recorrente[$inicioReceita + 1]) / -$Custo_Acumulado_Vista[$prazo];
    } else {
        $Retorno_Aluguel_Vista = 0;
    }


    // Formatação para 000.000,00
    $Desembolso_Total_SAC = number_format(abs($Desembolso_Total_SAC), 2, ',', '.');
    $Desembolso_Total_Parcelado = number_format(abs($Desembolso_Total_Parcelado), 2, ',', '.');
    $Desembolso_Total_Price = number_format(abs($Desembolso_Total_Price), 2, ',', '.');
    $Desembolso_Total_Vista = number_format(abs($Desembolso_Total_Vista), 2, ',', '.');

    $Custo_SAC = number_format($Custo_SAC, 2, ',', '.');
    $Custo_Parcelado = number_format($Custo_Parcelado, 2, ',', '.');
    $Custo_Price = number_format($Custo_Price, 2, ',', '.');
    $Custo_Vista = number_format($Custo_Vista, 2, ',', '.');

    $Receita_SAC = number_format($Receita_SAC, 2, ',', '.');
    $Receita_Parcelado = number_format($Receita_Parcelado, 2, ',', '.');
    $Receita_Price = number_format($Receita_Price, 2, ',', '.');
    $Receita_Vista = number_format($Receita_Vista, 2, ',', '.');

    $Lucro_SAC = number_format($Lucro_SAC, 2, ',', '.');
    $Lucro_Parcelado = number_format($Lucro_Parcelado, 2, ',', '.');
    $Lucro_Price = number_format($Lucro_Price, 2, ',', '.');
    $Lucro_Vista = number_format($Lucro_Vista, 2, ',', '.');

    $VPL_SAC = number_format($VPL_SAC, 2, ',', '.');
    $VPL_Parcelado = number_format($VPL_Parcelado, 2, ',', '.');
    $VPL_Price = number_format($VPL_Price, 2, ',', '.');
    $VPL_Vista = number_format($VPL_Vista, 2, ',', '.');

    // Formatação para 0,00%
    $Retorno_Aluguel_SAC = number_format($Retorno_Aluguel_SAC * 100, 2, ',', ',') . '%';
    $Retorno_Aluguel_Parcelado = number_format($Retorno_Aluguel_Parcelado * 100, 2, ',', ',') . '%';
    $Retorno_Aluguel_Price = number_format($Retorno_Aluguel_Price * 100, 2, ',', ',') . '%';
    $Retorno_Aluguel_Vista = number_format($Retorno_Aluguel_Vista * 100, 2, ',', ',') . '%';

    $TIR_AA_SAC = number_format($TIR_AA_SAC * 100, 2, ',', ',') . '%';
    $TIR_AA_Parcelado = number_format($TIR_AA_Parcelado * 100, 2, ',', ',') . '%';
    $TIR_AA_Price = number_format($TIR_AA_Price * 100, 2, ',', ',') . '%';
    $TIR_AA_Vista = number_format($TIR_AA_Vista * 100, 2, ',', ',') . '%';

    $TIR_AM_SAC = number_format($TIR_AM_SAC * 100, 2, ',', ',') . '%';
    $TIR_AM_Parcelado = number_format($TIR_AM_Parcelado * 100, 2, ',', ',') . '%';
    $TIR_AM_Price = number_format($TIR_AM_Price * 100, 2, ',', ',') . '%';
    $TIR_AM_Vista = number_format($TIR_AM_Vista * 100, 2, ',', ',') . '%';

    $Taxa_Lucro_SAC = number_format($Taxa_Lucro_SAC * 100, 2, ',', ',') . '%';
    $Taxa_Lucro_Parcelado = number_format($Taxa_Lucro_Parcelado * 100, 2, ',', ',') . '%';
    $Taxa_Lucro_Price = number_format($Taxa_Lucro_Price * 100, 2, ',', ',') . '%';
    $Taxa_Lucro_Vista = number_format($Taxa_Lucro_Vista * 100, 2, ',', ',') . '%';

    $Desembolso_Total_SAC = 'R$ ' . $Desembolso_Total_SAC;
    $Desembolso_Total_Parcelado = 'R$ ' . $Desembolso_Total_Parcelado;
    $Desembolso_Total_Price = 'R$ ' . $Desembolso_Total_Price;
    $Desembolso_Total_Vista = 'R$ ' . $Desembolso_Total_Vista;

    $Custo_SAC = 'R$ ' . $Custo_SAC;
    $Custo_Parcelado = 'R$ ' . $Custo_Parcelado;
    $Custo_Price = 'R$ ' . $Custo_Price;
    $Custo_Vista = 'R$ ' . $Custo_Vista;

    $Receita_SAC = 'R$ ' . $Receita_SAC;
    $Receita_Parcelado = 'R$ ' . $Receita_Parcelado;
    $Receita_Price = 'R$ ' . $Receita_Price;
    $Receita_Vista = 'R$ ' . $Receita_Vista;

    $Lucro_SAC = 'R$ ' . $Lucro_SAC;
    $Lucro_Parcelado = 'R$ ' . $Lucro_Parcelado;
    $Lucro_Price = 'R$ ' . $Lucro_Price;
    $Lucro_Vista = 'R$ ' . $Lucro_Vista;

    $VPL_SAC = 'R$ ' . $VPL_SAC;
    $VPL_Parcelado = 'R$ ' . $VPL_Parcelado;
    $VPL_Price = 'R$ ' . $VPL_Price;
    $VPL_Vista = 'R$ ' . $VPL_Vista;

    $dados_serializados = json_encode(array(
        'prazo' => $prazo,
        'Custo_Negocio' => $Custo_Negocio,
        'Receita_Recorrente' => $Receita_Recorrente,
        'Custo_Recorrente' => $Custo_Recorrente,
        'Venda' => $Venda,

        'Desembolso_SAC' => $Desembolso_SAC,
        'Saldo_Devedor_SAC' => $Saldo_Devedor_SAC,
        'Juros_SAC' => $Juros_SAC,
        'Amortizacao_SAC' => $Amortizacao_SAC,
        'Fluxo_Caixa_SAC' => $Fluxo_Caixa_SAC,
        'Fluxo_Acumulado_SAC' => $Fluxo_Acumulado_SAC,
        'Custo_Acumulado_SAC' => $Custo_Acumulado_SAC,
        'Receita_Acumulada_SAC' => $Receita_Acumulada_SAC,

        'Desembolso_Parcelado' => $Desembolso_Parcelado,
        'Saldo_Devedor_Parcelado' => $Saldo_Devedor_Parcelado,
        'Juros_Parcelado' => $Juros_Parcelado,
        'Amortizacao_Parcelado' => $Amortizacao_Parcelado,
        'Fluxo_Caixa_Parcelado' => $Fluxo_Caixa_Parcelado,
        'Fluxo_Acumulado_Parcelado' => $Fluxo_Acumulado_Parcelado,
        'Custo_Acumulado_Parcelado' => $Custo_Acumulado_Parcelado,
        'Receita_Acumulada_Parcelado' => $Receita_Acumulada_Parcelado,

        'Desembolso_Price' => $Desembolso_Price,
        'Saldo_Devedor_Price' => $Saldo_Devedor_Price,
        'Juros_Price' => $Juros_Price,
        'Amortizacao_Price' => $Amortizacao_Price,
        'Fluxo_Caixa_Price' => $Fluxo_Caixa_Price,
        'Fluxo_Acumulado_Price' => $Fluxo_Acumulado_Price,
        'Custo_Acumulado_Price' => $Custo_Acumulado_Price,
        'Receita_Acumulada_Price' => $Receita_Acumulada_Price,

        'Desembolso_Vista' => $Desembolso_Vista,
        'Saldo_Devedor_Vista' => $Saldo_Devedor_Vista,
        'Juros_Vista' => $Juros_Vista,
        'Amortizacao_Vista' => $Amortizacao_Vista,
        'Fluxo_Caixa_Vista' => $Fluxo_Caixa_Vista,
        'Fluxo_Acumulado_Vista' => $Fluxo_Acumulado_Vista,
        'Custo_Acumulado_Vista' => $Custo_Acumulado_Vista,
        'Receita_Acumulada_Vista' => $Receita_Acumulada_Vista

    ));
    //SAC
    $Grafico_Receita_SAC = [];
    $Grafico_Lucro_SAC = [];
    $Grafico_Lucro_PCT_SAC = [];
    $Grafico_Custo_Mensal_SAC = [];
    $Grafico_IREmReais_SAC = [];
    $Grafico_Juros_Acumulado_SAC = [];
    $Grafico_Receita_Recorrente_SAC = [];
    $Grafico_Desconto = [];
    $Custo_Mensal_SAC = [];

    for ($mes = 0; $mes <= $prazo; $mes++) {
        if ($mes == 0) {
            $Grafico_Juros_Acumulado_SAC[$mes] = $Juros_SAC[$mes];
            $Grafico_Receita_Recorrente_SAC[$mes] = 0;
            $Grafico_Desconto[$mes] = 0;
            $Custo_Mensal_SAC[$mes] = 0;
        } else {
            $Custo_Mensal_SAC[$mes] = - ($Desembolso_SAC[$mes] + $Custo_Recorrente[$mes]);
            $Grafico_Juros_Acumulado_SAC[$mes] = $Grafico_Juros_Acumulado_SAC[$mes - 1] + $Juros_SAC[$mes];
            $Grafico_Receita_Recorrente_SAC[$mes] = $Grafico_Receita_Recorrente_SAC[$mes - 1] + $Receita_Recorrente[$mes];
        }
        $Grafico_Desconto[$mes] = number_format((((pow((1 + ($desconto / 100)), $mes)) - 1) * 100), 2);
        $Grafico_IREmReais_SAC[$mes] = -$IREmReais - ($Grafico_Juros_Acumulado_SAC[$mes] * $IndiceIR);
        $Grafico_Receita_SAC[$mes] = $Grafico_Receita_Recorrente_SAC[$mes]  +  $venda + $Saldo_Devedor_SAC[$mes] + (-$custovenda) + $Grafico_IREmReais_SAC[$mes];
        $Grafico_Custo_Mensal_SAC[$mes] = - ($Custo_Acumulado_SAC[$mes]);
        $Grafico_Lucro_SAC[$mes] = $Grafico_Receita_SAC[$mes] - $Grafico_Custo_Mensal_SAC[$mes];
        if ($Grafico_Lucro_SAC[$mes] != 0 && $Grafico_Custo_Mensal_SAC[$mes] != 0) {
            $Grafico_Lucro_PCT_SAC[$mes] = number_format(($Grafico_Lucro_SAC[$mes] / $Grafico_Custo_Mensal_SAC[$mes]) * 100, 2);
        } else {
            $Grafico_Lucro_PCT_SAC[$mes] = 0;
        }
    }

    //Parcelado
    $Grafico_Receita_Parcelado = [];
    $Grafico_Lucro_Parcelado = [];
    $Grafico_Lucro_PCT_Parcelado = [];
    $Grafico_Custo_Mensal_Parcelado = [];
    $Grafico_IREmReais_Parcelado = [];
    $Grafico_Juros_Acumulado_Parcelado = [];
    $Grafico_Receita_Recorrente_Parcelado = [];
    $Grafico_Desconto = [];
    $Custo_Mensal_Parcelado = [];

    for ($mes = 0; $mes <= $prazo; $mes++) {
        if ($mes == 0) {
            $Grafico_Juros_Acumulado_Parcelado[$mes] = $Juros_Parcelado[$mes];
            $Grafico_Receita_Recorrente_Parcelado[$mes] = 0;
            $Grafico_Desconto[$mes] = 0;
            $Custo_Mensal_Parcelado[$mes] = 0;
        } else {
            $Custo_Mensal_Parcelado[$mes] = - ($Desembolso_Parcelado[$mes] + $Custo_Recorrente[$mes]);
            $Grafico_Juros_Acumulado_Parcelado[$mes] = $Grafico_Juros_Acumulado_Parcelado[$mes - 1] + $Juros_Parcelado[$mes];
            $Grafico_Receita_Recorrente_Parcelado[$mes] = $Grafico_Receita_Recorrente_Parcelado[$mes - 1] + $Receita_Recorrente[$mes];
        }
        $Grafico_Desconto[$mes] = number_format((((pow((1 + ($desconto / 100)), $mes)) - 1) * 100), 2);
        $Grafico_IREmReais_Parcelado[$mes] = -$IREmReais - ($Grafico_Juros_Acumulado_Parcelado[$mes] * $IndiceIR);
        $Grafico_Receita_Parcelado[$mes] = $Grafico_Receita_Recorrente_Parcelado[$mes]  +  $venda + $Saldo_Devedor_Parcelado[$mes] + (-$custovenda) + $Grafico_IREmReais_Parcelado[$mes];
        $Grafico_Custo_Mensal_Parcelado[$mes] = - ($Custo_Acumulado_Parcelado[$mes]);
        $Grafico_Lucro_Parcelado[$mes] = $Grafico_Receita_Parcelado[$mes] - $Grafico_Custo_Mensal_Parcelado[$mes];
        if ($Grafico_Lucro_Parcelado[$mes] != 0 && $Grafico_Custo_Mensal_Parcelado[$mes] != 0) {
            $Grafico_Lucro_PCT_Parcelado[$mes] = number_format(($Grafico_Lucro_Parcelado[$mes] / $Grafico_Custo_Mensal_Parcelado[$mes]) * 100, 2);
        } else {
            $Grafico_Lucro_PCT_Parcelado[$mes] = 0;
        }
    }


    //PRICE
    $Grafico_Receita_Price = [];
    $Grafico_Lucro_Price = [];
    $Grafico_Lucro_PCT_Price = [];
    $Grafico_Custo_Mensal_Price = [];
    $Grafico_IREmReais_Price = [];
    $Grafico_Juros_Acumulado_Price = [];
    $Grafico_Receita_Recorrente_Price = [];
    $Custo_Mensal_Price = [];

    for ($mes = 0; $mes <= $prazo; $mes++) {
        if ($mes == 0) {
            $Grafico_Juros_Acumulado_Price[$mes] = $Juros_Price[$mes];
            $Grafico_Receita_Recorrente_Price[$mes] = 0;
            $Custo_Mensal_Price[$mes] = 0;
        } else {
            $Custo_Mensal_Price[$mes] = - ($Desembolso_Price[$mes] + $Custo_Recorrente[$mes]);
            $Grafico_Juros_Acumulado_Price[$mes] = $Grafico_Juros_Acumulado_Price[$mes - 1] + $Juros_Price[$mes];
            $Grafico_Receita_Recorrente_Price[$mes] = $Grafico_Receita_Recorrente_Price[$mes - 1] + $Receita_Recorrente[$mes];
        }
        $Grafico_IREmReais_Price[$mes] = -$IREmReais - ($Grafico_Juros_Acumulado_Price[$mes] * $IndiceIR); //Somente no Price
        $Grafico_Receita_Price[$mes] = $Grafico_Receita_Recorrente_Price[$mes]  +  $venda + $Saldo_Devedor_Price[$mes] + (-$custovenda) + $Grafico_IREmReais_Price[$mes];
        $Grafico_Custo_Mensal_Price[$mes] = - ($Custo_Acumulado_Price[$mes]);
        $Grafico_Lucro_Price[$mes] = $Grafico_Receita_Price[$mes] - $Grafico_Custo_Mensal_Price[$mes];
        if ($Grafico_Lucro_Price[$mes] != 0 && $Grafico_Custo_Mensal_Price[$mes] != 0) {
            $Grafico_Lucro_PCT_Price[$mes] = number_format(($Grafico_Lucro_Price[$mes] / $Grafico_Custo_Mensal_Price[$mes]) * 100, 2);
        } else {
            $Grafico_Lucro_PCT_Price[$mes] = 0;
        }
    }
    //A Vista
    $Grafico_Receita_Vista = [];
    $Grafico_Lucro_Vista = [];
    $Grafico_Lucro_PCT_Vista = [];
    $Grafico_Custo_Mensal_Vista = [];
    $Grafico_IREmReais_Vista = [];
    $Grafico_Juros_Acumulado_Vista = [];
    $Grafico_Receita_Recorrente_Vista = [];
    $Grafico_mes = [];
    $Custo_Mensal_Vista = [];
    for ($mes = 0; $mes <= $prazo; $mes++) {
        if ($mes == 0) {
            $Grafico_Juros_Acumulado_Vista[$mes] = $Juros_Vista[$mes];
            $Grafico_Receita_Recorrente_Vista[$mes] = 0;
            $Custo_Mensal_Vista[$mes] = 0;
        } else {
            $Grafico_Juros_Acumulado_Vista[$mes] = $Grafico_Juros_Acumulado_Vista[$mes - 1] + $Juros_Vista[$mes];
            $Grafico_Receita_Recorrente_Vista[$mes] = $Grafico_Receita_Recorrente_Vista[$mes - 1] + $Receita_Recorrente[$mes];
            $Custo_Mensal_Vista[$mes] = - ($Desembolso_Vista[$mes] + $Custo_Recorrente[$mes]);
        }
        $Grafico_mes[$mes] = $mes;
        $Grafico_IREmReais_Vista[$mes] = -$IREmReais - ($Grafico_Juros_Acumulado_Vista[$mes] * $IndiceIR); //Somente no Vista
        $Grafico_Receita_Vista[$mes] = $Grafico_Receita_Recorrente_Vista[$mes]  +  $venda + $Saldo_Devedor_Vista[$mes] + (-$custovenda) + $Grafico_IREmReais_Vista[$mes];
        $Grafico_Custo_Mensal_Vista[$mes] = - ($Custo_Acumulado_Vista[$mes]);
        $Grafico_Lucro_Vista[$mes] = $Grafico_Receita_Vista[$mes] - $Grafico_Custo_Mensal_Vista[$mes];
        if ($Grafico_Lucro_Vista[$mes] != 0 && $Grafico_Custo_Mensal_Vista[$mes] != 0) {
            $Grafico_Lucro_PCT_Vista[$mes] = number_format(($Grafico_Lucro_Vista[$mes] / $Grafico_Custo_Mensal_Vista[$mes]) * 100, 2);
        } else {
            $Grafico_Lucro_PCT_Vista[$mes] = 0;
        }
    }


    $chartData1 = [
        'labels' => range(0, $prazo), // Eixo X com valores de 1 a $prazo
        'datasets' => [
            [
                'label' => 'Custo Mensal (R$)',
                'data' => $Custo_Mensal_SAC,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(118,0,0)',
                'borderColor' => 'rgb(118,0,0)',
                'type' => 'bar',
            ],
            [
                'label' => 'Taxa de Lucro (%)',
                'data' => $Grafico_Lucro_PCT_SAC,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(61,69,81)',
                'borderColor' => 'rgb(61,69,81)',
                'type' => 'line',
            ],
            [
                'label' => 'Taxa Comparativa (%)',
                'data' => $Grafico_Desconto,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(110, 70, 100)',
                'borderColor' => 'rgb(110, 70, 100)',
                'type' => 'line',
            ],
            [
                'label' => 'Custo Acumulado (R$)',
                'data' => $Grafico_Custo_Mensal_SAC,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(155,178,209)',
                'borderColor' => 'rgb(183,204,232)',
                'type' => 'bar',
            ],
            [
                'label' => 'Lucro Líquido (R$)',
                'data' => $Grafico_Lucro_SAC,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(90,109,138)',
                'borderColor' => 'rgb(112,137,170)',
                'type' => 'bar',
            ],
        ],
    ];
    $chartData2 = [
        'labels' => range(0, $prazo), // Eixo X com valores de 1 a $prazo
        'datasets' => [
            [
                'label' => 'Custo Mensal (R$)',
                'data' => $Custo_Mensal_Price,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(118,0,0)',
                'borderColor' => 'rgb(118,0,0)',
                'type' => 'bar',
            ],
            [
                'label' => 'Lucro Líquido (%)',
                'data' => $Grafico_Lucro_PCT_Price,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(61,69,81)',
                'borderColor' => 'rgb(61,69,81)',
                'type' => 'line',
            ],
            [
                'label' => 'Taxa Comparativa (%)',
                'data' => $Grafico_Desconto,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(110, 70, 100)',
                'borderColor' => 'rgb(110, 70, 100)',
                'type' => 'line',
            ],
            [
                'label' => 'Custo Acumulado (R$)',
                'data' => $Grafico_Custo_Mensal_Price,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(155,178,209)',
                'borderColor' => 'rgb(183,204,232)',
                'type' => 'bar',
            ],
            [
                'label' => 'Lucro Líquido (R$)',
                'data' => $Grafico_Lucro_Price,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(90,109,138)',
                'borderColor' => 'rgb(112,137,170)',
                'type' => 'bar',
            ],
        ],
    ];
    $chartData3 = [
        'labels' => range(0, $prazo), // Eixo X com valores de 1 a $prazo
        'datasets' => [
            [
                'label' => 'Custo Mensal (R$)',
                'data' => $Custo_Mensal_Vista,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(118,0,0)',
                'borderColor' => 'rgb(118,0,0)',
                'type' => 'bar',
            ],
            [
                'label' => 'Lucro Líquido (%)',
                'data' => $Grafico_Lucro_PCT_Vista,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(61,69,81)',
                'borderColor' => 'rgb(61,69,81)',
                'type' => 'line',
            ],
            [
                'label' => 'Taxa Comparativa (%)',
                'data' => $Grafico_Desconto,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(110, 70, 100)',
                'borderColor' => 'rgb(110, 70, 100)',
                'type' => 'line',
            ],
            [
                'label' => 'Custo Acumulado (R$)',
                'data' => $Grafico_Custo_Mensal_Vista,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(155,178,209)',
                'borderColor' => 'rgb(183,204,232)',
                'type' => 'bar',
            ],
            [
                'label' => 'Lucro Líquido (R$)',
                'data' => $Grafico_Lucro_Vista,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(90,109,138)',
                'borderColor' => 'rgb(112,137,170)',
                'type' => 'bar',
            ],

        ],
    ];
    $chartData4 = [
        'labels' => range(0, $prazo), // Eixo X com valores de 1 a $prazo
        'datasets' => [
            [
                'label' => 'Custo Mensal (R$)',
                'data' => $Custo_Mensal_Parcelado,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(118,0,0)',
                'borderColor' => 'rgb(118,0,0)',
                'type' => 'bar',
            ],
            [
                'label' => 'Taxa de Lucro (%)',
                'data' => $Grafico_Lucro_PCT_Parcelado,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(61,69,81)',
                'borderColor' => 'rgb(61,69,81)',
                'type' => 'line',
            ],
            [
                'label' => 'Taxa Comparativa (%)',
                'data' => $Grafico_Desconto,
                'yAxisID' => 'y-axis-2',
                'backgroundColor' => 'rgb(110, 70, 100)',
                'borderColor' => 'rgb(110, 70, 100)',
                'type' => 'line',
            ],
            [
                'label' => 'Custo Acumulado (R$)',
                'data' => $Grafico_Custo_Mensal_Parcelado,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(155,178,209)',
                'borderColor' => 'rgb(183,204,232)',
                'type' => 'bar',
            ],
            [
                'label' => 'Lucro Líquido (R$)',
                'data' => $Grafico_Lucro_Parcelado,
                'yAxisID' => 'y-axis-1',
                'backgroundColor' => 'rgb(90,109,138)',
                'borderColor' => 'rgb(112,137,170)',
                'type' => 'bar',
            ],
        ],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculadora de Viabilidade </title>
    <link rel="stylesheet" href="css/header.css?v=<?php echo filemtime('css/header.css'); ?>">
    <link rel="stylesheet" href="css/style4.css?v=<?php echo filemtime('css/style4.css'); ?>">
    <!-- <link rel="stylesheet" href="css/bootstrap.min.css"> -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link href="https://gitcdn.github.io/bootstrap-toggle/2.2.2/css/bootstrap-toggle.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/header.css?v=<?php echo filemtime('css/header.css'); ?>">
    <link href="img/logo.png" rel="icon">
    <script src="js/header.js?v=<?php echo filemtime('js/header.js'); ?>"></script>
    <script src="https://kit.fontawesome.com/1ad52ad40a.js" crossorigin="anonymous"></script>

</head>
<body>
<div id="main" style="padding-bottom: 90px;">
    <?php define('BIDMAP_HEADER_ASSETS_LOADED', true); ?>
    <?php include_once 'header/header.php' ?>
    <div class="container conteudo-com-espaco">

        <div class="container-logo">
            <h1>Calculadora de Arrematação de Imóveis</h1>
        </div>

        <!-- Premissas -->
        <form method="post" action="#fimDaPagina" id="myForm">

            <div class="retangulo">
                <div class="container content-button">
                    <div class="button-group">
                        <button type="button" class="btn btn-outline-primary leftbutton" id="salvarButton">Salvar</button>
                        <button type="button" class="btn btn-outline-primary leftbutton" id="recuperarButton">Abrir</button>
                        <button type="button" class="btn btn-outline-primary leftbutton" id="imprimirButton">Imprimir</button>
                    </div>
                    <div class="button-group">
                        <button type="button" class="btn btn-outline-primary rightbutton" id="zerarButton">Limpar</button>
                        <button type="submit" class="btn btn-primary rightbutton" id="calcularButton">Calcular</button>
                    </div>
                </div>
            </div>

            <div id="premissas-section">
                <h4>Premissas</h4>
                <div class="form-group">
                    <label for="valor1">Arrematação (R$)</label>
                    <span data-toggle="tooltip" data-html="true" title="Insira o valor que você espera pagar pelo imóvel no leilão.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control campo-conversao" id="valor1" name="valor1" placeholder="Digite o valor em reais" value="<?= $arrematacao ?>" required>
                    <span class="error-message" id="valor1-error"></span>
                </div>

                <div class="form-group">
                    <label for="valor2">Preço de venda do imóvel (R$)</label>
                    <span data-toggle="tooltip" data-html="true" title="Informe o valor pelo qual você planeja vender o imóvel.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control" id="valor2" name="valor2" placeholder="Digite o valor em reais" value="<?= $venda ?>" required>
                    <span class="error-message" id="valor2-error"></span>
                </div>

                <div class="form-group">
                    <label for="parcelas">Prazo de Vendas (meses)</label>
                    <input type="number" class="form-control" id="parcelas2" name="parcelas2" placeholder="Digite o número do prazo" value="<?= $prazo ?>" required>
                    <span class="error-message" id="parcelas2-error"></span>
                </div>

                <div class="form-group">
                    <label for="porcentagem1">Entrada (%)</label>
                    <input type="text" class="form-control" id="porcentagem1" name="porcentagem1" placeholder="Digite a porcentagem" value="<?= $entrada ?>" required>
                    <span class="error-message" id="porcentagem1-error"></span>
                </div>
                <div class="form-group">
                    <label for="parcelas">Número de Parcelas</label>
                    <span data-toggle="tooltip" data-html="true" title="Indique o número de parcelas do pagamento, se for à vista este número deve ser 1">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="number" class="form-control" id="parcelas1" name="parcelas1" placeholder="Digite o número de parcelas" value="<?= $parcela ?>" required>
                    <span class="error-message" id="parcelas1-error"></span>
                </div>
                <div class="form-group">
                    <label for="porcentagem2">Taxa de Juros (% Anual)</label>
                    <span data-toggle="tooltip" data-html="true" title="Adicione a taxa de juros do financiamento ou, em arrematações judiciais, a taxa de correção monetária aplicável.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control" id="porcentagem2" name="porcentagem2" placeholder="Digite a porcentagem" value="<?= $juros_anual ?>" required>
                    <span class="error-message" id="porcentagem2-error"></span>
                </div>
                <div class="form-group">
                    <label for="porcentagem4">Taxa de Mercado para Comparação (% Mensal)</label>
                    <span data-toggle="tooltip" data-html="true" title="Insira uma taxa de referência como CDB ou Selic, para comparar o resultado do investimento.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control" id="porcentagem4" name="porcentagem4" placeholder="Digite a porcentagem" value="<?= $desconto ?>" required>
                    <span class="error-message" id="porcentagem4-error"></span>
                </div>
            </div>

            </br>
            <!-- //custos na aquisicao -->
            <div id="custos-section">

                <div>
                    <h4 class="d-inline-block">Custos na Aquisição</h4>
                    <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Detalhe os custos associados à compra do imóvel, preencha apenas o campo em porcentagem ou se preferir os valores absolutos.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                </div>

                <div class="row g-2">
                    <div class="col-md">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="comissaoEmReais" name="comissaoEmReais" placeholder="R$1000,00" step="0.01" value="<?= $comissao ?>" required>
                            <label for="comissaoEmReais">Comissão Leiloeiro em R$</label>
                            <span class="error-message" id="comissaoEmReais-error"></span>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="comissaoEmPorcentagem" step="0.01" name="comissaoEmPorcentagem" placeholder="5%" value="<?= $comissaoporc ?>">
                            <label for="comissaoEmPorcentagem">Comissão do Leiloeiro em %</label>
                            <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Essa informação normalmente é encontrada nos editais dos leilões, é comum ser 5%.">
                                <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                            </span>
                        </div>
                    </div>
                </div>

                </br>

                <div class="row g-2">
                    <div class="col-md">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="ITBIEmReais" name="ITBI" placeholder="R$1000,00" step="0.01" value="<?= $ITBI ?>" required>
                            <label for=" ITBIEmReais">ITBI em R$</label>
                            <span class="error-message" id="ITBIEmReais-error"></span>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="ITBIEmPorcentagem" step="0.01" name="ITBIporc" placeholder="5%" value="<?= $ITBIporc ?>">
                            <label for="ITBIEmPorcentagem">ITBI em %</label>
                            <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Insira a alíquota do Imposto sobre Transmissão de Bens Imóveis, esta costuma ser variar de 1% a 5% conforme o município.">
                                <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                            </span>
                        </div>
                    </div>
                </div>

                </br>
                <div class="row g-2">
                    <div class="col-md">
                        <div class="form-floating">
                            <input type="text" class="form-control campo-conversao" id="asses1EmReais" step="0.01" name="asses1" placeholder="R$1000,00" value="<?= $asses1 ?>" required>
                            <label for="assoriaEmReais">Assessoria Aquisição em R$</label>
                            <span class="error-message" id="asses1EmReais-error"></span>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="form-floating">
                            <input type="text" step="0.01" class="form-control campo-conversao" id="asses1EmPorcentagem" name="asses1porc" placeholder="5%" value="<?= $asses1porc ?>" max="100">
                            <label for="asses1EmPorcentagem">Assessoria em %</label>
                            <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Se utilizar serviços de assessoria, informe o valor a ser desembolsado no momento da aquisição do imóvel.">
                                <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                            </span>
                        </div>
                    </div>
                </div>

                </br>

                <div class="form-group">
                    <label for="dividaprop">Dívidas Propter Rem*</label>
                    <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="São as dívidas que podem acompanhar o imóvel após a arrematação, normalmente essa informação está no edital. *Esse valor não é descontado no Ganho de Capital.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control" id="dividaprop" name="dividaprop" placeholder="Digite o valor da sua divida" value="<?= $dividaprop ?>" required>
                    <span class="error-message" id="dividaprop-error"></span>
                </div>
                <div class="form-group">
                    <label for="registro">Registro</label>
                    <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Adicione os custos para transferência do imóvel.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control" id="registro" name="registro" placeholder="Digite o valor do registro" value="<?= $registro ?>" required>
                    <span class="error-message" id="registro-error"></span>
                </div>
                <div class="form-group">
                    <label for="reforma">Reforma</label>
                    <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Estime o orçamento necessário para eventuais reformas no imóvel.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control" id="reforma" name="reforma" placeholder="Digite o valor da reforma" value="<?= $reforma ?>" required>
                    <span class="error-message" id="reforma-error"></span>
                </div>
                <div class="form-group">
                    <label for="custos">Outros Custos</label>
                    <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Use este campo para quaisquer outros custos associados à aquisição que não estejam listados acima.">
                        <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                    </span>
                    <input type="text" class="form-control" id="custos" name="custos" placeholder="Digite o valor se possuir outros custos" value="<?= $custos ?>" required>
                    <span class="error-message" id="custos-error"></span>
                </div>
                </br>

            </div>
            <!-- //custos na venda -->
            <div>
                <h4 class="d-inline-block">Custos na Venda</h4>
                <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Declare os custos associados à venda do imóvel, preencha apenas o campo em porcentagem ou se preferir os valores absolutos.">
                    <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                </span>
            </div>
            <div class="row g-2">
                <div class="col-md">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="corretorEmReais" name="corretor" step="0.01" placeholder="R$1000,00" value="<?= $corretor ?>" required>
                        <label for="corretorEmReais">Corretor Imobiliario em R$</label>
                        <span class="error-message" id="corretorEmReais-error"></span>
                    </div>
                </div>
                <div class="col-md">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="corretorEmPorcentagem" step="0.01" name="corretorporc" placeholder="5%" value="<?= $corretorporc ?>">
                        <label for="corretorEmPorcentagem">Corretor Imobiliario em %</label>
                        <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Informe a comissão do corretor para a venda do imóvel, 6% é um número usual.">
                            <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                        </span>
                    </div>
                </div>
            </div>

            </br>

            <div class="row g-2">
                <div class="col-md">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="asses2EmReais" name="asses2" placeholder="R$1000,00" step="0.01" value="<?= $asses2 ?>" required>
                        <label for=" asses2EmReais">Assessoria Venda em R$</label>
                        <span class="error-message" id="asses2EmReais-error"></span>
                    </div>
                </div>
                <div class="col-md">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="asses2EmPorcentagem" step="0.01" name="asses2porc" placeholder="5%" value="<?= $asses2porc ?>">
                        <label for="asses2EmPorcentagem">Assessoria em %</label>
                        <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Caso utilize serviços de assessoria, informe o valor a ser desembolsado no momento da venda do imóvel.">
                            <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                        </span>
                    </div>
                </div>
            </div>

            </br>
            </br>
            <div>
                <h4 class="d-inline-block">Imposto de Renda</h4>
                <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Este campo calcula automaticamente o IR de acordo com a opção de pessoa Física ou Jurídica. Para situações específicas, use o campo Imposto de Renda Manual.">
                    <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                </span>
            </div>
            <div class="container mt-5">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="flexRadioDefault" id="flexRadioDefault1" value="Imposto de Renda PF" onchange="calcularImposto()" checked>
                    <label class="form-check-label" for="flexRadioDefault1">
                        Imposto de Renda PF
                    </label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="flexRadioDefault" id="flexRadioDefault2" value="Imposto de Renda PJ" onchange="calcularImposto()">
                    <label class="form-check-label" for="flexRadioDefault2">
                        Imposto de Renda PJ
                    </label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="flexRadioDefault" id="flexRadioDefault3" value="Imposto de Renda Manual" onchange="calcularImposto()">
                    <label class="form-check-label" for="flexRadioDefault3">
                        Imposto de Renda Manual
                    </label>
                </div>
            </div>
            <br>
            <div class="row g-2">
                <div class="col-md">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="IREmReais" name="IREmReais" placeholder="R$1000,00" value="<?= $IREmReais ?>" readonly>
                        <label for=" IREmReais">Imposto de renda em R$</label>
                    </div>
                </div>
            </div>
            </br>
            </br>
            <!-- //Receita Recorrente-->
            <div>
                <h4 class="d-inline-block">Receita Mensal</h4>
                <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Detalhe as receitas que serão obtidas com o imóvel, como aluguel ou locação por temporada.">
                    <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                </span>
            </div>
            <div class="form-group">
                <label for="parcelas">Prazo para Início de Receita (meses)</label>
                <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Defina o tempo estimado para começar a receber a receita do imóvel.">
                    <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                </span>
                <input type="number" class="form-control" id="inicioReceita" name="inicioReceita" placeholder="Digite o prazo de inicio da receita" value="<?= $inicioReceita ?>" required>
                <span class="error-message" id="inicioReceita-error"></span>
            </div>
            <div class="form-group">
                <label for="parcelas">Aluguel Mensal com Despesas</label>
                <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Informe o valor total do aluguel recebido, inclua o valor de IPTU e condomínio, caso seja pago pelo locatário.">
                    <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                </span>
                <input type="text" class="form-control" id="aluguelLiquido" name="aluguelLiquido" placeholder="Digite o valor do aluguel liquido mensal" value="<?= $aluguelLiquido ?>" required>
                <span class="error-message" id="aluguelLiquido-error"></span>
            </div>
            <div class="form-group">
                <label for="parcelas">Duração do aluguel (meses)</label>
                <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Estime a duração do contrato de aluguel.">
                    <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                </span>
                <input type="number" class="form-control" id="duracaoAluguel" name="duracaoAluguel" placeholder="Digite a duracao do aluguel" value="<?= $duracaoAluguel ?>" required>
                <span class="error-message" id="duracaoAluguel-error"></span>
            </div>

            <br>

            <div class="row g-2">
                <div class="col-md">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="IRRecorrente" name="IRRecorrente" placeholder="R$1000,00" step="0.01" value="<?= $IRRecorrente ?>" readonly>
                        <label for="IRRecorrente">Imposto de Renda Recorrente em R$</label>
                        <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="É o imposto de renda sobre o aluguel. Este campo é calculado conforme a seleção IRPF ou IRPJ. Caso tenha selecionado a opção Manual digite o valor do IR mensal. ">
                            <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                        </span>
                    </div>
                </div>
            </div>
            </br>
            </br>
            <!-- //Custo Recorrentes-->

            <div>
                <h4 class="d-inline-block">Custo Mensal</h4>
                <span class="d-inline-block" data-toggle="tooltip" data-html="true" title="Insira o custo mensal do IPTU e Condomínio do imóvel">
                    <i class="fa-solid fa-circle-info" style="color: #c7c7c7;"></i>
                </span>
            </div>

            <div class="form-group">
                <label for="parcelas">IPTU Mensal</label>
                <input type="text" class="form-control" id="IPTU" name="IPTU" placeholder="Digite o prazo de inicio da receita" value="<?= $IPTU ?>" required>
                <span class="error-message" id="IPTU-error"></span>
            </div>
            <div class="form-group">
                <label for="parcelas">Condomínio</label>
                <input type="text" class="form-control" id="condominio" name="condominio" placeholder="Digite o valor do aluguel liquido mensal" value="<?= $condominio ?>" required>
                <span class="error-message" id="condominio-error"></span>
            </div>

            <div class="modal fade" id="confirmacaoModal" tabindex="-1" role="dialog" aria-labelledby="confirmacaoModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmacaoModalLabel">Confirmação</h5>
                        </div>
                        <div class="modal-body">
                            <p>Tem certeza de que deseja limpar os dados do formulário?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" id="limparDadosButton">Limpar Dados</button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="loadDataModal" tabindex="-1" role="dialog" aria-labelledby="loadDataModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="loadDataModalLabel">Importar Dados</h5>
                        </div>
                        <div class="modal-body remove-padding">
                            <table class="tableCode" id="tableTitle">
                                <tbody id="codigoTableBody">
                                    <!-- Aqui serão exibidos os títulos dos códigos -->
                                </tbody>
                            </table>

                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" id="carregarDadosButton" data-dismiss="modal">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="saveDataModal" tabindex="-1" role="dialog" aria-labelledby="savedDataModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="saveDataModalLabel">Salvar Dados</h5>
                        </div>
                        <div class="modal-body">
                            <p>Adicione um titulo:</p>
                            <input type="text" id="codeTitle"><br><br>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger" id="salvarDadosButton" onclick="calculate()">Salvar</button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="myModalLabel">Erro</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Fechar">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <?php echo $_SESSION['erro_message']; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="videoModal" tabindex="-1" role="dialog" aria-labelledby="videoModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="videoModalLabel">Vídeo do YouTube</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <iframe width="100%" height="315" src="https://www.youtube.com/embed/XoiOOiuH8iI" frameborder="0" allowfullscreen></iframe>
                        </div>
                    </div>
                </div>
            </div>

        </form>

        <?php
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            echo
            '</br>
            <div class="div-choice">
                <div>
                    <h4 class="d-inline-block">Selecione Como Deseja Ver o Resultado</h4>
                </div>
                </br>
                <div class="form-check form-switch form-check-inline">
                    <input class="form-check-input" type="checkbox" role="switch" id="avistaToggle" checked>
                    <label class="form-check-label" for="avistaToggle">À Vista</label>
                </div>
                <div class="form-check form-switch form-check-inline">
                    <input class="form-check-input" type="checkbox" role="switch" id="priceToggle">
                    <label class="form-check-label" for="priceToggle">Price</label>
                </div>
                <div class="form-check form-switch form-check-inline" id="fimDaPagina">
                    <input class="form-check-input" type="checkbox" role="switch" id="sacToggle">
                    <label class="form-check-label" for="sacToggle">SAC</label>
                </div>
                <div class="form-check form-switch form-check-inline">
                    <input class="form-check-input" type="checkbox" role="switch" id="parceladoToggle">
                    <label class="form-check-label" for="parceladoToggle">Parcelado</label>
                </div>
            </div>
            </br>';
        } ?>
        </br>
        </br>
        </br>
        <!-- A vista -->
        <table id="table-avista" class="table" style="display: none;">
            <h3 id="title-vista">Resultado À Vista</h3>
            <tr>
                <td>Lucro <span title="Resultado líquido da operação no prazo analisado, considerando receitas e custos do modelo selecionado.">ⓘ</span></td>
                <td><?= $Lucro_Vista ?></td>
            </tr>
            <tr>
            <td>Capital Necessário <span title="Maior necessidade de caixa ao longo da operação, considerando o saldo do mês. Ex.: No mês de venda a receita supera os custos acumulados, então, os custos desse mês não são considerados aqui.">ⓘ</span></td>
                <td><?= $Desembolso_Total_Vista ?></td>
            </tr>
            <tr>
                <td>Taxa de Lucro <span title="Percentual de lucro da operação em relação ao custo acumulado final.">ⓘ</span></td>
                <td><?= $Taxa_Lucro_Vista ?></td>
            </tr>
            <tr>
                <td>TIR (a.m.) <span title="Taxa Interna de Retorno ao mês, calculada com base no fluxo de caixa da operação.">ⓘ</span></td>
                <td><?= $TIR_AM_Vista ?></td>
            </tr>
            <tr>
                <td>TIR (a.a.) <span title="Taxa Interna de Retorno ao ano, derivada da TIR mensal da operação.">ⓘ</span></td>
                <td><?= $TIR_AA_Vista ?></td>
            </tr>
            <tr>
                <td>VPL <span title="Valor Presente Líquido da operação com base na taxa de desconto informada.">ⓘ</span></td>
                <td><?= $VPL_Vista ?></td>
            </tr>
            <tr>
                <td>Retorno do Aluguel <span title="Rentabilidade mensal do aluguel em relação ao custo acumulado da operação, considerando o aluguel líquido (aluguel menos IPTU, condomínio e IR sobre aluguel). Obs.: No gráfico, o aluguel não aparece como redução de custo, e sim como aumento do lucro.">ⓘ</span></td>                <td><?= $Retorno_Aluguel_Vista ?></td>
            </tr>
        </table>
        <canvas id="meuGrafico3"></canvas>
        </br>
        <!-- Price -->
        <table id="table-price" class="table" style="display: none;">
            <div class="container" id="title-price">
                <div class="row">
                    <div class="col">
                        <h3 class="d-inline-block">Resultado Price</h3>
                        <!-- <p>Assista o vídeo para entender mais sobre o gráfico: <a href="#" id="openModal">Tutorial</a>.</p> -->
                    </div>
                </div>
            </div>
            <tr>
                <td>Lucro <span title="Resultado líquido da operação no prazo analisado, considerando receitas e custos do modelo selecionado.">ⓘ</span></td>
                <td><?= $Lucro_Price ?></td>
            </tr>
            <tr>
            <td>Capital Necessário <span title="Maior necessidade de caixa ao longo da operação, considerando o saldo do mês. Ex.: No mês de venda a receita supera os custos acumulados, então, os custos desse mês não são considerados aqui.">ⓘ</span></td>
                <td><?= $Desembolso_Total_Price ?></td>
            </tr>
            <tr>
                <td>Taxa de Lucro <span title="Percentual de lucro da operação em relação ao custo acumulado final.">ⓘ</span></td>
                <td><?= $Taxa_Lucro_Price ?></td>
            </tr>
            <tr>
                <td>TIR (a.m.) <span title="Taxa Interna de Retorno ao mês, calculada com base no fluxo de caixa da operação.">ⓘ</span></td>
                <td><?= $TIR_AM_Price ?></td>
            </tr>
            <tr>
                <td>TIR (a.a.) <span title="Taxa Interna de Retorno ao ano, derivada da TIR mensal da operação.">ⓘ</span></td>
                <td><?= $TIR_AA_Price ?></td>
            </tr>
            <tr>
                <td>VPL <span title="Valor Presente Líquido da operação com base na taxa de desconto informada.">ⓘ</span></td>
                <td><?= $VPL_Price ?></td>
            </tr>
            <tr>
                <td>Retorno do Aluguel <span title="Rentabilidade mensal do aluguel em relação ao custo acumulado da operação, considerando o aluguel líquido (aluguel menos IPTU, condomínio e IR sobre aluguel). Obs.: No gráfico, o aluguel não aparece como redução de custo, e sim como aumento do lucro.">ⓘ</span></td>
                <td><?= $Retorno_Aluguel_Price ?></td>
            </tr>
        </table>
        <canvas id="meuGrafico2"></canvas>
        </br>
        <!-- SAC -->
        <table id="table-sac" class="table">
            <h3 id="title-sac">Resultado SAC</h3>
            <tr>
                <td>Lucro <span title="Resultado líquido da operação no prazo analisado, considerando receitas e custos do modelo selecionado.">ⓘ</span></td>
                <td><?= $Lucro_SAC ?></td>
            </tr>
            <tr>
            <td>Capital Necessário <span title="Maior necessidade de caixa ao longo da operação, considerando o saldo do mês. Ex.: No mês de venda a receita supera os custos acumulados, então, os custos desse mês não são considerados aqui.">ⓘ</span></td>
                <td><?= $Desembolso_Total_SAC ?></td>
            </tr>
            <tr>
                <td>Taxa de Lucro <span title="Percentual de lucro da operação em relação ao custo acumulado final.">ⓘ</span></td>
                <td><?= $Taxa_Lucro_SAC ?></td>
            </tr>
            <tr>
                <td>TIR (a.m.) <span title="Taxa Interna de Retorno ao mês, calculada com base no fluxo de caixa da operação.">ⓘ</span></td>
                <td><?= $TIR_AM_SAC ?></td>
            </tr>
            <tr>
                <td>TIR (a.a.) <span title="Taxa Interna de Retorno ao ano, derivada da TIR mensal da operação.">ⓘ</span></td>
                <td><?= $TIR_AA_SAC ?></td>
            </tr>
            <tr>
                <td>VPL <span title="Valor Presente Líquido da operação com base na taxa de desconto informada.">ⓘ</span></td>
                <td><?= $VPL_SAC; ?></td>
            </tr>
            <tr>
                <td>Retorno do Aluguel <span title="Rentabilidade mensal do aluguel em relação ao custo acumulado da operação, considerando o aluguel líquido (aluguel menos IPTU, condomínio e IR sobre aluguel). Obs.: No gráfico, o aluguel não aparece como redução de custo, e sim como aumento do lucro.">ⓘ</span></td>
                <td><?= $Retorno_Aluguel_SAC ?></td>
            </tr>
        </table>
        <canvas id="meuGrafico1"></canvas>
        </br>
        <!-- Parcelado -->
        <table id="table-parcelado" class="table">
            <div class="container" id="title-parcelado">
                <div class="row">
                    <div class="col">
                        <h3 class="d-inline-block">Resultado Parcelado </h3>
                    </div>
                </div>
            </div>
            <tr>
                <td>Lucro <span title="Resultado líquido da operação no prazo analisado, considerando receitas e custos do modelo selecionado.">ⓘ</span></td>
                <td><?= $Lucro_Parcelado ?></td>
            </tr>
            <tr>
            <td>Capital Necessário <span title="Maior necessidade de caixa ao longo da operação, considerando o saldo do mês. Ex.: No mês de venda a receita supera os custos acumulados, então, os custos desse mês não são considerados aqui.">ⓘ</span></td>
                <td><?= $Desembolso_Total_Parcelado ?></td>
            </tr>
            <tr>
                <td>Taxa de Lucro <span title="Percentual de lucro da operação em relação ao custo acumulado final.">ⓘ</span></td>
                <td><?= $Taxa_Lucro_Parcelado ?></td>
            </tr>
            <tr>
                <td>TIR (a.m.) <span title="Taxa Interna de Retorno ao mês, calculada com base no fluxo de caixa da operação.">ⓘ</span></td>
                <td><?= $TIR_AM_Parcelado ?></td>
            </tr>
            <tr>
                <td>TIR (a.a.) <span title="Taxa Interna de Retorno ao ano, derivada da TIR mensal da operação.">ⓘ</span></td>
                <td><?= $TIR_AA_Parcelado ?></td>
            </tr>
            <tr>
                <td>VPL <span title="Valor Presente Líquido da operação com base na taxa de desconto informada.">ⓘ</span></td>
                <td><?= $VPL_Parcelado; ?></td>
            </tr>
            <tr>
                <td>Retorno do Aluguel <span title="Rentabilidade mensal do aluguel em relação ao custo acumulado da operação, considerando o aluguel líquido (aluguel menos IPTU, condomínio e IR sobre aluguel). Obs.: No gráfico, o aluguel não aparece como redução de custo, e sim como aumento do lucro.">ⓘ</span></td>
                <td><?= $Retorno_Aluguel_Parcelado ?></td>
            </tr>
        </table>
        <canvas id="meuGrafico4"></canvas>
        </br>
        </br>
        </br>

        <?php
        /* if ($_SERVER["REQUEST_METHOD"] === "POST") {
            echo '<a href="tables.php?dados=' . urlencode($dados_serializados) . '" target="blank" >Ver Cálculo Completo</a>';
        }
 */
        $chartData1 = isset($chartData1) ? $chartData1 : [];
        $chartData2 = isset($chartData2) ? $chartData2 : [];
        $chartData3 = isset($chartData3) ? $chartData3 : [];
        $chartData4 = isset($chartData4) ? $chartData4 : [];

        ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.3/dist/umd/popper.min.js"></script>
<script src="https://gitcdn.github.io/bootstrap-toggle/2.2.2/js/bootstrap-toggle.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.9-1/crypto-js.min.js"></script>

<script>
    var secretKey = "BidMap";

    var emailCliente = "<?php echo $email; ?>";

    function deleteCode(code) {
        fetch('delete_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'codigo=' + code
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSavedCodeTitles();
                } else {
                    alert("Erro ao excluir o código. Por favor, tente novamente mais tarde.");
                }
            })
            .catch(error => {
                alert("Erro ao excluir o código. Verifique sua conexão ou tente novamente mais tarde.");
            });
    }

    function loadSavedCodeTitles() {
        fetch('buscar_titulos.php', {
                method: 'POST',
            })
            .then(response => response.json())
            .then(data => {
                var tableBody = document.getElementById('codigoTableBody');
                tableBody.innerHTML = '';

                if (data.length > 0) {
                    document.getElementById('tableTitle').style.display = 'table';
                    data.forEach(pair => {
                        var row = document.createElement('tr');

                        var titleCell = document.createElement('td');
                        titleCell.textContent = pair.titulo;
                        row.appendChild(titleCell);

                        var containerColumn = document.createElement('td');
                        containerColumn.classList.add('separate-icon');
                        var editIcon = document.createElement('i');
                        editIcon.classList.add('fa', 'fa-eye', 'editIcon');
                        editIcon.setAttribute('data-code', pair.code);
                        containerColumn.appendChild(editIcon);

                        var deleteIcon = document.createElement('i');
                        deleteIcon.classList.add('fa', 'fa-trash', 'deleteIcon');
                        deleteIcon.setAttribute('data-code', pair.code);
                        containerColumn.appendChild(deleteIcon);
                        row.appendChild(containerColumn);

                        var containerCell = document.createElement('td');

                        tableBody.appendChild(row);
                    });
                    document.querySelectorAll('.deleteIcon').forEach(icon => {
                        icon.addEventListener('click', function(event) {
                            event.preventDefault();
                            var code = icon.getAttribute('data-code');
                            var confirmDelete = confirm("Tem certeza de que deseja excluir este código?");
                            if (confirmDelete) {
                                deleteCode(code);
                            }
                        });
                    });
                    document.querySelectorAll('.editIcon').forEach(icon => {
                        icon.addEventListener('click', function(event) {
                            event.preventDefault();
                            var code = icon.getAttribute('data-code');
                            var confirmEdit = confirm("Clique em OK para carregar o seu cálculo. É necessário selecionar novamente a opção de imposto de renda.");
                            if (confirmEdit) {
                                loadFormData(code);
                            }
                        });
                    });
                } else {
                    alert('Você não possui nenhum dado em nosso banco de dados!');
                    document.getElementById('tableTitle').style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert("Erro ao buscar os títulos dos códigos. Verifique sua conexão ou tente novamente mais tarde.");
            });
    }

    function calculate() {
        var formData = document.getElementById('myForm').elements;
        var jsonData = {};

        for (var i = 0; i < formData.length; i++) {
            jsonData[formData[i].name] = formData[i].value;
        }

        var encryptedData = CryptoJS.AES.encrypt(JSON.stringify(jsonData), secretKey).toString();

        var code = generateRandomCode(10);

        var title = document.getElementById('codeTitle').value;

        var xhr = new XMLHttpRequest();
        xhr.open("POST", "save_data.php", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                alert(xhr.responseText);
            }
        };
        xhr.send("code=" + code + "&encryptedData=" + encodeURIComponent(encryptedData) + "&title=" + encodeURIComponent(title) +
            "&email=" + encodeURIComponent(emailCliente));

    }

    function generateRandomCode(length) {
        var result = '';
        var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var charactersLength = characters.length;
        for (var i = 0; i < length; i++) {
            result += characters.charAt(Math.floor(Math.random() * charactersLength));
        }
        return result;
    }

    function loadFormData(code) {

        fetch('search_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'savedCode=' + code
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                } else {

                    var decryptedData = CryptoJS.AES.decrypt(data.encryptedData, secretKey).toString(CryptoJS.enc.Utf8);
                    var jsonData = JSON.parse(decryptedData);

                    document.getElementById('valor1').value = jsonData.valor1;
                    document.getElementById('valor2').value = jsonData.valor2;
                    document.getElementById('parcelas2').value = jsonData.parcelas2;
                    document.getElementById('porcentagem1').value = jsonData.porcentagem1;
                    document.getElementById('parcelas1').value = jsonData.parcelas1;
                    document.getElementById('porcentagem2').value = jsonData.porcentagem2;
                    document.getElementById('porcentagem4').value = jsonData.porcentagem4;
                    document.getElementById('comissaoEmReais').value = jsonData.comissaoEmReais;
                    document.getElementById('comissaoEmPorcentagem').value = jsonData.comissaoEmPorcentagem;
                    document.getElementById('ITBIEmReais').value = jsonData.ITBI;
                    document.getElementById('ITBIEmPorcentagem').value = jsonData.ITBIporc;
                    document.getElementById('asses1EmReais').value = jsonData.asses1;
                    document.getElementById('asses1EmPorcentagem').value = jsonData.asses1porc;
                    document.getElementById('dividaprop').value = jsonData.dividaprop;
                    document.getElementById('registro').value = jsonData.registro;
                    document.getElementById('reforma').value = jsonData.reforma;
                    document.getElementById('custos').value = jsonData.custos;
                    document.getElementById('corretorEmReais').value = jsonData.corretor;
                    document.getElementById('corretorEmPorcentagem').value = jsonData.corretorporc;
                    document.getElementById('asses2EmReais').value = jsonData.asses2;
                    document.getElementById('asses2EmPorcentagem').value = jsonData.asses2porc;

                    document.getElementById('IREmReais').removeAttribute('readonly');
                    document.getElementById('IREmReais').value = jsonData.IREmReais;
                    document.getElementById('IREmReais').setAttribute('readonly', 'true');

                    document.getElementById('inicioReceita').value = jsonData.inicioReceita;
                    document.getElementById('aluguelLiquido').value = jsonData.aluguelLiquido;
                    document.getElementById('duracaoAluguel').value = jsonData.duracaoAluguel;
                    document.getElementById('IRRecorrente').value = jsonData.IRRecorrente;
                    document.getElementById('IPTU').value = jsonData.IPTU;
                    document.getElementById('condominio').value = jsonData.condominio;

                    var selectedOption = jsonData.flexRadioDefault;

                    if (selectedOption === "Imposto de Renda PF") {
                        document.getElementById('flexRadioDefault1').checked = true;
                    } else if (selectedOption === "Imposto de Renda PJ") {
                        document.getElementById('flexRadioDefault2').checked = true;
                    } else if (selectedOption === "Imposto de Renda Manual") {
                        document.getElementById('flexRadioDefault3').checked = true;
                    }

                    console.log(jsonData);

                    calcularImposto();

                    $('#loadDataModal').modal('hide');

                }
            }).catch(error => {
                console.error('Erro:', error);
                alert("Erro ao carregar o formulário. Verifique sua conexão ou tente novamente mais tarde.");
            });
    }


    $(document).ready(function() {
        $('[data-toggle="tooltip"]').tooltip();
    });

    $(document).ready(function() {
        $("#openModal").click(function(event) {
            // Impedir o comportamento padrão de navegação
            event.preventDefault();
            // Abrir o modal
            $("#videoModal").modal();
        });
    });

    $(document).ready(function() {
        // Função para inicializar um gráfico
        function initializeChart(ctx, data) {
            return new Chart(ctx, {
                type: 'bar',
                data: data,

                options: {
                    scales: {
                        'y-axis-1': {
                            position: 'left',
                            beginAtZero: true,
                            ticks: {
                                callback: function(value, index, values) {
                                    return 'R$' + value.toLocaleString('pt-BR'); // Adiciona o símbolo de porcentagem aos valores do eixo
                                }
                            },
                            grid: {
                                display: true,
                            },

                        },
                        'y-axis-2': {
                            position: 'right',
                            beginAtZero: true,
                            ticks: {
                                callback: function(value, index, values) {
                                    return value + '%'; // Adiciona o símbolo de porcentagem aos valores do eixo
                                }
                            },
                            grid: {
                                display: false,
                            },
                        },
                        x: {
                            grid: {
                                display: false,
                            },
                        },
                    },
                },
            });
        }
        // IDs dos elementos canvas
        var chart1Canvas = document.getElementById('meuGrafico1');
        var chart2Canvas = document.getElementById('meuGrafico2');
        var chart3Canvas = document.getElementById('meuGrafico3');
        var chart4Canvas = document.getElementById('meuGrafico4');

        // Dados dos gráficos
        var chartData1 = <?php echo json_encode($chartData1); ?>;
        var chartData2 = <?php echo json_encode($chartData2); ?>;
        var chartData3 = <?php echo json_encode($chartData3); ?>;
        var chartData4 = <?php echo json_encode($chartData4); ?>;

        // Inicializar os gráficos com os dados obtidos
        initializeChart(chart1Canvas.getContext('2d'), chartData1);
        initializeChart(chart2Canvas.getContext('2d'), chartData2);
        initializeChart(chart3Canvas.getContext('2d'), chartData3);
        initializeChart(chart4Canvas.getContext('2d'), chartData4);
    });

    document.addEventListener("DOMContentLoaded", function() {
        var fimDaPaginaElement = document.getElementById('fimDaPagina');

        if (fimDaPaginaElement) {
            fimDaPaginaElement.scrollIntoView({
                behavior: 'smooth'
            });
        }
    });

    // Função para esconder elementos com base no estado do botão
    function toggleElements(element, graph, title, isChecked) {
        if (isChecked) {
            $(element).show();
            $(element + ', ' + graph).show();
            $(element + ', ' + title).show();
        } else {
            $(element).hide();
            $(element + ', ' + graph).hide();
            $(element + ', ' + title).hide();
        }
    }

    $(document).ready(function() {

        // Inicialize o estado dos elementos
        toggleElements('#table-sac', '#meuGrafico1', '#title-sac', $('#sacToggle').prop('checked'));
        toggleElements('#table-price', '#meuGrafico2', '#title-price', $('#priceToggle').prop('checked'));
        toggleElements('#table-avista', '#meuGrafico3', '#title-vista', $('#avistaToggle').prop('checked'));
        toggleElements('#table-parcelado', '#meuGrafico4', '#title-parcelado', $('#parceladoToggle').prop('checked'));

        // Adicione o manipulador de eventos de mudança para cada botão
        $('#sacToggle').change(function() {
            toggleElements('#table-sac', '#meuGrafico1', '#title-sac', $(this).prop('checked'));
        });

        $('#priceToggle').change(function() {
            toggleElements('#table-price', '#meuGrafico2', '#title-price', $(this).prop('checked'));
        });

        $('#avistaToggle').change(function() {
            toggleElements('#table-avista', '#meuGrafico3', '#title-vista', $(this).prop('checked'));
        });

        $('#parceladoToggle').change(function() {
            toggleElements('#table-parcelado', '#meuGrafico4', '#title-parcelado', $(this).prop('checked'));
        });
    });

    function formatarReais(valor) {
        // Substituir vírgula por ponto

        if (isNaN(valor)) {
            return 'R$ 0,00';
        }

        return Number(valor).toLocaleString('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        });
    }

    function formatarPorcentagem(valor) {
        valor = valor.toString().replace(',', '');
        if (isNaN(valor)) {
            return '0,00 %'; // Retorna 0 formatado como porcentagem
        }
        valor = valor.toString().replace('.', ',');
        return valor + '%';
    }

    function removerFormatacao(valor) {

        if (valor.includes('R$')) {
            valor = valor.toString().replace('R$', '').trim();
            valor = valor.replace(/\./g, '');
            valor = valor.replace(',', '.');
            return parseFloat(valor);
        } else if (valor.includes('%')) {
            // Se contém '%', realiza o tratamento para porcentagem
            valor = valor.replace('%', '').trim();
            valor = valor.replace(',', '.'); // Troca a vírgula decimal por ponto
            return parseFloat(valor);
        } else {
            valor = valor.replace(',', '.');
            // Se não contém nem 'R$' nem '%', retorna o valor sem alterações
            return parseFloat(valor);
        }
    }

    function aplicarFormatacaoComBaseNoID(inputElement) {
        if (inputElement.id === 'porcentagem1' || inputElement.id === 'porcentagem2' || inputElement.id === 'porcentagem4' || inputElement.id === 'corretorEmPorcentagem' || inputElement.id === 'asses2EmPorcentagem' || inputElement.id === 'asses1EmPorcentagem' || inputElement.id === 'ITBIEmPorcentagem' || inputElement.id === 'comissaoEmPorcentagem') {
            valor = inputElement.value;
            inputElement.value = formatarPorcentagem(valor);
        } else {
            valor = inputElement.value;
            inputElement.value = formatarReais(valor);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const camposTexto = document.querySelectorAll('input[type="text"]');
        camposTexto.forEach(function(campo) {
            if (campo.id !== 'savedCode' && campo.id !== 'codeTitle') {
                aplicarFormatacaoComBaseNoID(campo);
            }
        });
    });

    document.addEventListener('focus', function(e) {

        if (e.target && e.target.type === 'text' && e.target.id !== 'savedCode' && e.target.id !== 'codeTitle') {
            let valor = e.target.value;
            valor = valor.toString().replace('%', '').trim();
            valor = valor.toString().replace('R$', '').trim();
            valor = valor.replace(/\./g, ''); // Remove os pontos dos milhares

            e.target.value = valor;
        }
    }, true);

    document.addEventListener('blur', function(e) {
        if (e.target && e.target.type === 'text' && e.target.id !== 'savedCode' && e.target.id !== 'codeTitle') {
            if (e.target.id === 'porcentagem1' || e.target.id === 'porcentagem2' || e.target.id === 'porcentagem4' || e.target.id === 'corretorEmPorcentagem' || e.target.id === 'asses2EmPorcentagem' || e.target.id === 'asses1EmPorcentagem' || e.target.id === 'ITBIEmPorcentagem' || e.target.id === 'comissaoEmPorcentagem') {
                let valor = e.target.value;
                valor = valor.replace('%', '').toString().trim();
                valor = valor.replace('.', ',');
                valor = valor.replace(',', '.');
                if (isNaN(valor)) {
                    valor = '0,00 %'; // Retorna 0 formatado como porcentagem
                } else {
                    valor = valor.replace('.', ',');
                    valor = valor + '%';
                }
                e.target.value = valor;
            } else {
                let valor = e.target.value;
                // Se contém 'R$', realiza o tratamento para moeda
                valor = valor.toString().replace('R$', '').trim();
                valor = valor.replace(/\./g, ''); // Remove os pontos dos milhares
                valor = valor.replace(',', '.'); // Troca a vírgula decimal por ponto

                if (isNaN(valor)) {
                    valor = "R$ 0,00";
                } else {
                    valor = Number(valor).toLocaleString('pt-BR', {
                        style: 'currency',
                        currency: 'BRL'
                    });
                }

                e.target.value = valor;
            }
        }
    }, true);

    function aplicarFormatacaoInicial() {
        const camposTexto = document.querySelectorAll('input[type="text"]');
        camposTexto.forEach(function(campo) {
            if (campo.id !== 'savedCode' && campo.id !== 'codeTitle') {
                aplicarFormatacaoComBaseNoID(campo);
            }
        });
    }

    function removerFormatacaoNoSubmit() {
        const camposTexto = document.querySelectorAll('input[type="text"]');
        camposTexto.forEach(function(campo) {
            if (campo.id !== 'savedCode' && campo.id !== 'codeTitle') {
                campo.value = removerFormatacao(campo.value);
            }
        });
    }

    document.querySelector('form').addEventListener('submit', function(e) {
        removerFormatacaoNoSubmit();
    });

    document.getElementById("limparDadosButton").addEventListener("click", function() {
        // Defina os valores iniciais dos campos do formulário
        var valoresIniciais = {
            valor1: "R$ 0,00",
            valor2: "R$ 0,00",
            parcelas2: 12,
            porcentagem1: "100%",
            porcentagem2: "0%",
            porcentagem4: "0%",
            parcelas1: 1,
            comissaoEmReais: "R$ 0,00",
            comissaoEmPorcentagem: "0%",
            ITBIEmReais: "R$ 0,00",
            ITBIEmPorcentagem: "0%",
            asses1EmReais: "R$ 0,00",
            asses1EmPorcentagem: "0%",
            dividaprop: "R$ 0,00",
            registro: "R$ 0,00",
            reforma: "R$ 0,00",
            custos: "R$ 0,00",
            corretorEmReais: "R$ 0,00",
            corretorEmPorcentagem: "0%",
            asses2EmReais: "R$ 0,00",
            asses2EmPorcentagem: "0%",
            IREmReais: "R$ 0,00",
            IRRecorrente: "R$ 0,00",
            inicioReceita: 1,
            aluguelLiquido: "R$ 0,00",
            duracaoAluguel: 0,
            IPTU: "R$ 0,00",
            condominio: "R$ 0,00"
        };

        // Limpe os valores dos campos para os valores iniciais
        for (var campo in valoresIniciais) {
            document.getElementById(campo).value = valoresIniciais[campo];
        }

        toggleElements('#table-sac', '#meuGrafico1', '#title-sac', false);
        toggleElements('#table-price', '#meuGrafico2', '#title-price', false);
        toggleElements('#table-avista', '#meuGrafico3', '#title-vista', false);
        toggleElements('#table-avista', '#meuGrafico4', '#title-parcelado', false);

        $('.div-choice').hide();

        // Feche a modal de confirmação
        $('#confirmacaoModal').modal('hide');
    });

    // Vincule a função de abertura da modal de confirmação ao botão "Zerar"
    document.getElementById("zerarButton").addEventListener("click", function() {
        $('#confirmacaoModal').modal('show');
    });

    document.getElementById("carregarDadosButton").addEventListener("click", function() {
        $('#loadDataModal').modal('hide');
    });

    document.getElementById("salvarDadosButton").addEventListener("click", function() {
        $('#saveDataModal').modal('hide');
    });

    // Vincule a função de abertura da modal de confirmação ao botão "Carregar Dados"
    document.getElementById("recuperarButton").addEventListener("click", function() {
        $('#loadDataModal').modal('show');
        loadSavedCodeTitles();
    });

    document.getElementById("salvarButton").addEventListener("click", function() {
        $('#saveDataModal').modal('show');
    });

    document.getElementById("imprimirButton").addEventListener("click", function() {
        window.print();
    });




    document.querySelector('form').addEventListener('submit', function(e) {

        e.preventDefault();
        const valor1 = parseFloat(document.getElementById('valor1').value);
        const valor2 = parseFloat(document.getElementById('valor2').value);
        const parcelas2 = parseFloat(document.getElementById('parcelas2').value);
        const parcelas1 = parseFloat(document.getElementById('parcelas1').value);
        const porcentagem1 = parseFloat(document.getElementById('porcentagem1').value);
        const porcentagem2 = parseFloat(document.getElementById('porcentagem2').value);
        const porcentagem4 = parseFloat(document.getElementById('porcentagem4').value);
        const comissaoEmReais = parseFloat(document.getElementById('comissaoEmReais').value);
        const ITBIEmReais = parseFloat(document.getElementById('ITBIEmReais').value);
        const asses1EmReais = parseFloat(document.getElementById('asses1EmReais').value);
        const dividaprop = parseFloat(document.getElementById('dividaprop').value);
        const registro = parseFloat(document.getElementById('registro').value);
        const reforma = parseFloat(document.getElementById('reforma').value);
        const custos = parseFloat(document.getElementById('custos').value);
        const corretorEmReais = parseFloat(document.getElementById('corretorEmReais').value);
        const asses2EmReais = parseFloat(document.getElementById('asses2EmReais').value);
        const inicioReceita = parseFloat(document.getElementById('inicioReceita').value);
        const aluguelLiquido = parseFloat(document.getElementById('aluguelLiquido').value);
        const duracaoAluguel = parseFloat(document.getElementById('duracaoAluguel').value);
        const IPTU = parseFloat(document.getElementById('IPTU').value);
        const condominio = parseFloat(document.getElementById('condominio').value);

        let errorFields1 = [];

        function updateErrorMessage(field, message) {
            const errorMessageElement = document.getElementById(`${field}-error`);
            if (message) {
                errorMessageElement.innerHTML = message;
                errorMessageElement.style.display = 'block';

            } else {
                errorMessageElement.style.display = 'none';
            }

        }
        if (isNaN(valor1) || valor1 <= 0) {
            errorFields1.push('Arrematação');
            updateErrorMessage('valor1', '"Arrematação" não pode ser menor ou igual a 0.');
        } else {
            updateErrorMessage('valor1', '');
        }

        if (isNaN(valor2) || valor2 <= 0) {
            errorFields1.push('Venda');
            updateErrorMessage('valor2', '"Venda" não pode ser menor ou igual a 0.');
        } else {
            updateErrorMessage('valor2', '');
        }

        if (isNaN(parcelas2) || parcelas2 <= 0 || parcelas2 > 480) {
            errorFields1.push('Prazo');
            updateErrorMessage('parcelas2', '"Prazo" não pode ser maior que 480 e menor que 0.');
        } else {
            updateErrorMessage('parcelas2', '');
        }

        if (porcentagem1 === 0 && parcelas1 === 0) {
            errorFields1.push('Os campos "Entrada %" e "Número de Parcelas" não podem conter o valor 0 em ambos.');
            updateErrorMessage('porcentagem1', 'Os campos "Entrada %" e "Número de Parcelas" não podem conter o valor 0 em ambos.');
            updateErrorMessage('parcelas1', 'Os campos "Entrada %" e "Número de Parcelas" não podem conter o valor 0 em ambos.');
        } else if (isNaN(porcentagem1) || porcentagem1 < 0 || porcentagem1 > 100) {
            errorFields1.push('Entrada');
            updateErrorMessage('porcentagem1', '"Entrada" não pode ser maior que 100 e menor que 0.');
        } else {
            updateErrorMessage('porcentagem1', '');
            updateErrorMessage('parcelas1', '');
        }

        if (isNaN(porcentagem2) || porcentagem2 < 0 || porcentagem2 > 100) {
            errorFields1.push('Taxa de Juros ou Correção (%)');
            updateErrorMessage('porcentagem2', '"Taxa de Juros ou Correção" não pode ser maior que 100 e menor que 0.');
        } else {
            updateErrorMessage('porcentagem2', '');
        }

        if (isNaN(porcentagem4) || porcentagem4 < 0 || porcentagem4 > 100) {
            errorFields1.push('Taxa de Desconto mensal (VPL) (%)');
            updateErrorMessage('porcentagem4', '"Taxa de Desconto mensal" não pode ser maior que 100 e menor que 0.');
        } else {
            updateErrorMessage('porcentagem4', '');
        }

        if (isNaN(parcelas1) || parcelas1 < 0) {
            errorFields1.push('Número de Parcelas');
            updateErrorMessage('parcelas1', '"Número de Parcelas" não pode ser menor que 0.');
        } else {
            updateErrorMessage('parcelas1', '');
        }
        if (isNaN(comissaoEmReais) || comissaoEmReais < 0) {
            errorFields1.push('Comissão Leiloeiro em R$');
            updateErrorMessage('comissaoEmReais', '"Comissão Leiloeiro em R$" não pode ser menor que 0.');
        } else {
            updateErrorMessage('comissaoEmReais', '');
        }
        if (isNaN(ITBIEmReais) || ITBIEmReais < 0) {
            errorFields1.push('ITBI em R$');
            updateErrorMessage('ITBIEmReais', '"ITBI em R$" não pode ser menor que 0.');
        } else {
            updateErrorMessage('ITBIEmReais', '');
        }
        if (isNaN(asses1EmReais) || asses1EmReais < 0) {
            errorFields1.push('Assessoria Aquisição em R$');
            updateErrorMessage('asses1EmReais', '"Assessoria Aquisição em R$" não pode ser menor que 0.');
        } else {
            updateErrorMessage('asses1EmReais', '');
        }
        if (isNaN(corretorEmReais) || corretorEmReais < 0) {
            errorFields1.push('Corretor Imobiliario em R$');
            updateErrorMessage('corretorEmReais', '"Corretor Imobiliario em R$" não pode ser menor que 0.');
        } else {
            updateErrorMessage('corretorEmReais', '');
        }
        if (isNaN(asses2EmReais) || asses2EmReais < 0) {
            errorFields1.push('Assessoria Venda em R$');
            updateErrorMessage('asses2EmReais', '"Assessoria Venda em R$" não pode ser menor que 0.');
        } else {
            updateErrorMessage('asses2EmReais', '');
        }
        if (isNaN(dividaprop) || dividaprop < 0) {
            errorFields1.push('Dívidas Propter Rem');
            updateErrorMessage('dividaprop', '"Dívidas Propter Rem" não pode ser menor que 0.');
        } else {
            updateErrorMessage('dividaprop', '');
        }
        if (isNaN(reforma) || reforma < 0) {
            errorFields1.push('Reforma');
            updateErrorMessage('reforma', '"Reforma" não pode ser menor que 0.');
        } else {
            updateErrorMessage('reforma', '');
        }
        if (isNaN(custos) || custos < 0) {
            errorFields1.push('Outros Custos');
            updateErrorMessage('custos', '"Outros Custos" não pode ser menor que 0.');
        } else {
            updateErrorMessage('custos', '');
        }
        if (isNaN(inicioReceita) || inicioReceita < 0 || inicioReceita >= parcelas2) {
            errorFields1.push('Inicio Receita');
            updateErrorMessage('inicioReceita', '"Inicio Receita" não pode ser menor que 0 e não pode ser maior ou igual ao prazo de venda.');
        } else {
            updateErrorMessage('inicioReceita', '');
        }
        if (isNaN(registro) || registro < 0) {
            errorFields1.push('Registro');
            updateErrorMessage('registro', '"Registro" não pode ser menor que 0.');
        } else {
            updateErrorMessage('registro', '');
        }
        if (isNaN(aluguelLiquido) || aluguelLiquido < 0) {
            errorFields1.push('Aluguel Liquido');
            updateErrorMessage('aluguelLiquido', '"Aluguel Liquido" não pode ser menor que 0.');
        } else {
            updateErrorMessage('aluguelLiquido', '');
        }

        if (isNaN(duracaoAluguel) || duracaoAluguel < 0) {
            errorFields1.push('Duração Aluguel');
            updateErrorMessage('duracaoAluguel', '"Duração Aluguel" não pode ser menor que 0.');
        } else {
            updateErrorMessage('duracaoAluguel', '');
        }

        if (isNaN(IPTU) || IPTU < 0) {
            errorFields1.push('IPTU');
            updateErrorMessage('IPTU', '"IPTU" não pode ser menor que 0.');
        } else {
            updateErrorMessage('IPTU', '');
        }

        if (isNaN(condominio) || condominio < 0) {
            errorFields1.push('Condominio');
            updateErrorMessage('condominio', '"Condominio" não pode ser menor que 0.');
        } else {
            updateErrorMessage('condominio', '');
        }



        if (errorFields1.length > 0) {
            alert("Possui um ou mais campos com erro!");
            const camposTexto = document.querySelectorAll('input[type="text"]');
            camposTexto.forEach(function(campo) {
                if (campo.id !== 'savedCode' && campo.id !== 'codeTitle') {
                    campo.value = removerFormatacao(campo.value);
                    aplicarFormatacaoComBaseNoID(campo);
                }
            });
        } else {
            this.submit(); // Envie o formulário manualmente
        }


    });

    function atualizarValores(idReais, idPorcentagem, idValor) {
        const valorArrematacao = document.getElementById(idValor);
        const inputComissaoEmReais = document.getElementById(idReais);
        const campoPorcentagem = document.getElementById(idPorcentagem);

        valorArrematacao.addEventListener('input', () => {
            calculaReaisComissao(valorArrematacao, inputComissaoEmReais, campoPorcentagem);
            calculaPorcentagemComissao(valorArrematacao, inputComissaoEmReais, campoPorcentagem);
            calcularImposto();
        });

        inputComissaoEmReais.addEventListener('input', () => {
            calculaPorcentagemComissao(valorArrematacao, inputComissaoEmReais, campoPorcentagem);
            calcularImposto();
        });

        campoPorcentagem.addEventListener('input', () => {
            calculaReaisComissao(valorArrematacao, inputComissaoEmReais, campoPorcentagem);
            calcularImposto();
        });
    }

    function calculaPorcentagemComissao(valorArrematacao, inputComissaoEmReais, campoPorcentagem) {
        let valorTotal = removerFormatacao(valorArrematacao.value) || 0;
        let valorEmReais = removerFormatacao(inputComissaoEmReais.value) || 0;
        let valorEmPorcentagem = 0;

        if (valorEmReais > valorTotal) {
            valorEmReais = valorTotal;
            inputComissaoEmReais.value = formatarReais(valorEmReais);
        }

        if (valorEmReais > 0) {
            valorEmPorcentagem = (valorEmReais / valorTotal) * 100;
        }

        if (valorEmPorcentagem > 100) {
            valorEmPorcentagem = 100;
        } else if (valorEmPorcentagem < 0) {
            valorEmPorcentagem = 0;
        }

        campoPorcentagem.value = formatarPorcentagem(valorEmPorcentagem);
    }

    function calculaReaisComissao(valorArrematacao, inputComissaoEmReais, campoPorcentagem) {
        let valorTotal = removerFormatacao(valorArrematacao.value) || 0;
        let valorEmPorcentagem = removerFormatacao(campoPorcentagem.value) || 0;
        let valorEmReais = 0;

        if (valorEmPorcentagem <= 0) {
            valorEmReais = 0;
        } else if (valorEmPorcentagem > 0) {
            valorEmReais = (valorEmPorcentagem / 100) * valorTotal;
        }

        if (valorEmReais > valorTotal) {
            valorEmReais = valorTotal;
        }
        if (valorEmPorcentagem > 100) {
            valorEmReais = valorTotal;
            valorEmPorcentagem = 100;
            campoPorcentagem.value = formatarPorcentagem(valorEmPorcentagem);
        }

        inputComissaoEmReais.value = formatarReais(valorEmReais);
    }

    atualizarValores('comissaoEmReais', 'comissaoEmPorcentagem', 'valor1')
    atualizarValores('ITBIEmReais', 'ITBIEmPorcentagem', 'valor1');
    atualizarValores('asses1EmReais', 'asses1EmPorcentagem', 'valor1');
    atualizarValores('corretorEmReais', 'corretorEmPorcentagem', 'valor2');
    atualizarValores('asses2EmReais', 'asses2EmPorcentagem', 'valor2');

    //calcular imposto de renda
    document.getElementById('aluguelLiquido').addEventListener('input', calcularImposto);
    document.getElementById('IPTU').addEventListener('input', calcularImposto);
    document.getElementById('condominio').addEventListener('input', calcularImposto);

    function calcularImposto() {
        let IR = 0;
        const comissaoEmReais = removerFormatacao(document.getElementById("comissaoEmReais").value);
        const ITBIEmReais = removerFormatacao(document.getElementById("ITBIEmReais").value);
        const dividaprop = removerFormatacao(document.getElementById("dividaprop").value);
        const valor1 = removerFormatacao(document.getElementById("valor1").value);
        const valor2 = removerFormatacao(document.getElementById("valor2").value);
        const corretor = removerFormatacao(document.getElementById("corretorEmReais").value);
        const asses1 = removerFormatacao(document.getElementById("asses1EmReais").value);
        const asses2 = removerFormatacao(document.getElementById("asses2EmReais").value);
        const registro = removerFormatacao(document.getElementById("registro").value);
        const reforma = removerFormatacao(document.getElementById("reforma").value);
        const custos = removerFormatacao(document.getElementById("custos").value);
        const aluguelLiquido = removerFormatacao(document.getElementById("aluguelLiquido").value);
        const IPTU = removerFormatacao(document.getElementById("IPTU").value);
        const condominio = removerFormatacao(document.getElementById("condominio").value);
        const IREmPorcentagem = 15;

        const opcaoSelecionada = document.querySelector('input[name="flexRadioDefault"]:checked').value;
        if (opcaoSelecionada === "Imposto de Renda PF") {
            document.getElementById("IREmReais").setAttribute("readonly", true);
            document.getElementById("IRRecorrente").setAttribute("readonly", true);
            let custoAquisicao = comissaoEmReais + ITBIEmReais + registro + asses1 + reforma + custos;

            IR = (IREmPorcentagem / 100) * (valor2 - custoAquisicao - corretor - asses2 - valor1);

            if (IR <= 0) {
                IR = 0;
            }

            IR = formatarReais(IR);
            document.getElementById("IREmReais").value = IR;

            let IRRecorrente = 0.275 * (aluguelLiquido - (IPTU + condominio));
            if (IRRecorrente <= 0) {
                IRRecorrente = 0;
            }
            IRRecorrente = formatarReais(IRRecorrente);
            document.getElementById("IRRecorrente").value = IRRecorrente;

        } else if (opcaoSelecionada === "Imposto de Renda PJ") {
            document.getElementById("IREmReais").setAttribute("readonly", true);
            document.getElementById("IRRecorrente").setAttribute("readonly", true);
            let primeiraParte = valor2 * 0.0593;
            let receitaMultiplicada = valor2 * 0.08;
            let segundaParte = receitaMultiplicada > 60000 ? (receitaMultiplicada - 60000) * 0.10 : 0;

            IR = primeiraParte + segundaParte;
            if (IR <= 0) {
                IR = 0;
            }
            IR = formatarReais(IR);

            document.getElementById("IREmReais").value = IR;

            let IRRecorrente = 0.1133 * (aluguelLiquido - (IPTU + condominio));

            if (IRRecorrente <= 0) {
                IRRecorrente = 0;
            }

            IRRecorrente = formatarReais(IRRecorrente);

            document.getElementById("IRRecorrente").value = IRRecorrente;

        } else if (opcaoSelecionada === "Imposto de Renda Manual") {
            const ireEmReaisInput = document.getElementById("IREmReais");
            ireEmReaisInput.removeAttribute("readonly");
            const ireRecorrenteInput = document.getElementById("IRRecorrente");
            ireRecorrenteInput.removeAttribute("readonly");

            ireRecorrenteInput.value = ireRecorrenteInput.value;
            ireEmReaisInput.value = ireEmReaisInput.value;

        }
    }

    //Manter botao selecionado 
    function setRadioState() {
        const radioButtons = document.querySelectorAll('input[type="radio"]');
        const selectedValue = getCookie('selectedRadio');

        if (selectedValue) {
            for (const radioButton of radioButtons) {
                if (radioButton.value === selectedValue) {
                    radioButton.checked = true;
                } else {
                    radioButton.checked = false;
                }
            }
        }
    }

    function saveRadioState() {
        const selectedRadio = document.querySelector('input[type="radio"]:checked');
        if (selectedRadio) {
            setCookie('selectedRadio', selectedRadio.value, 365);
        }
    }

    function setCookie(name, value, days) {
        const expires = new Date();
        expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = `${name}=${value};expires=${expires.toUTCString()};path=/`;
    }

    function getCookie(name) {
        const cookieArray = document.cookie.split(';');
        for (const cookie of cookieArray) {
            const [cookieName, cookieValue] = cookie.trim().split('=');
            if (cookieName === name) {
                return cookieValue;
            }
        }
        return null;
    }
    window.addEventListener('load', setRadioState);
    const radioButtons = document.querySelectorAll('input[type="radio"]');
    for (const radioButton of radioButtons) {
        radioButton.addEventListener('click', saveRadioState);
    }
</script>
</body>

</html>
