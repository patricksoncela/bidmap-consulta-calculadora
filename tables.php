<?php

if (isset($_GET['dados'])) {
    $dados_serializados = $_GET['dados'];

    // Desserializa os dados JSON para arrays
    $dados = json_decode(urldecode($dados_serializados), true);

    // Agora, você tem acesso às suas arrays na página "tabelas.php"
    $prazo = $dados['prazo'];

    $Custo_Negocio = $dados['Custo_Negocio'];
    $Receita_Recorrente = $dados['Receita_Recorrente'];
    $Custo_Recorrente = $dados['Custo_Recorrente'];
    $Venda = $dados['Venda'];

    $Desembolso_SAC = $dados['Desembolso_SAC'];
    $Saldo_Devedor_SAC = $dados['Saldo_Devedor_SAC'];
    $Juros_SAC = $dados['Juros_SAC'];
    $Amortizacao_SAC = $dados['Amortizacao_SAC'];
    $Fluxo_Caixa_SAC = $dados['Fluxo_Caixa_SAC'];
    $Fluxo_Acumulado_SAC = $dados['Fluxo_Acumulado_SAC'];
    $Custo_Acumulado_SAC = $dados['Custo_Acumulado_SAC'];
    $Receita_Acumulada_SAC = $dados['Receita_Acumulada_SAC'];

    $Desembolso_Parcelado = $dados['Desembolso_Parcelado'];
    $Saldo_Devedor_Parcelado = $dados['Saldo_Devedor_Parcelado'];
    $Juros_Parcelado = $dados['Juros_Parcelado'];
    $Amortizacao_Parcelado = $dados['Amortizacao_Parcelado'];
    $Fluxo_Caixa_Parcelado = $dados['Fluxo_Caixa_Parcelado'];
    $Fluxo_Acumulado_Parcelado = $dados['Fluxo_Acumulado_Parcelado'];
    $Custo_Acumulado_Parcelado = $dados['Custo_Acumulado_Parcelado'];
    $Receita_Acumulada_Parcelado = $dados['Receita_Acumulada_Parcelado'];


    $Desembolso_Price = $dados['Desembolso_Price'];
    $Saldo_Devedor_Price = $dados['Saldo_Devedor_Price'];
    $Juros_Price = $dados['Juros_Price'];
    $Amortizacao_Price = $dados['Amortizacao_Price'];
    $Fluxo_Caixa_Price = $dados['Fluxo_Caixa_Price'];
    $Fluxo_Acumulado_Price = $dados['Fluxo_Acumulado_Price'];
    $Custo_Acumulado_Price = $dados['Custo_Acumulado_Price'];
    $Receita_Acumulada_Price = $dados['Receita_Acumulada_Price'];

    $Desembolso_Vista = $dados['Desembolso_Vista'];
    $Saldo_Devedor_Vista = $dados['Saldo_Devedor_Vista'];
    $Juros_Vista = $dados['Juros_Vista'];
    $Amortizacao_Vista = $dados['Amortizacao_Vista'];
    $Fluxo_Caixa_Vista = $dados['Fluxo_Caixa_Vista'];
    $Fluxo_Acumulado_Vista = $dados['Fluxo_Acumulado_Vista'];
    $Custo_Acumulado_Vista = $dados['Custo_Acumulado_Vista'];
    $Receita_Acumulada_Vista = $dados['Receita_Acumulada_Vista'];

    echo "<table class='table'>
    <tr>
    <th>SAC E JUDICIAL</th>
        <th>Ultimo Mês</th>";

    for ($mes = 0; $mes <= $prazo; $mes++) {
        echo "<th>Mês $mes</th>";
    }

    echo "</tr>
    <tr>
        <td>Arrematação</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Desembolso_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Desembolso_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
    <td>Saldo Devedor</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Saldo_Devedor_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Saldo_Devedor_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Juros</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Juros_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Juros_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Amortização</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Amortizacao_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Amortizacao_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Custos do Negócio</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Negocio[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Negocio[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Receita Recorrente</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Custos Recorrentes</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Venda</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Venda[$prazo]}</td>";
        } else {
            echo "<td>{$Venda[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Fluxo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Custo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Acumulado_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Acumulado_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Receita_Acumulada</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Acumulada_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Acumulada_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Lucro Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Fluxo_Caixa</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Caixa_SAC[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Caixa_SAC[$mes]}</td>";
        }
    }
    echo "</tr>
</table>
</br>
</br>";

    echo "<table class='table'>
    <tr>
    <th>Parcelado</th>
        <th>Ultimo Mês</th>";

    for ($mes = 0; $mes <= $prazo; $mes++) {
        echo "<th>Mês $mes</th>";
    }

    echo "</tr>
    <tr>
        <td>Arrematação</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Desembolso_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Desembolso_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
    <td>Saldo Devedor</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Saldo_Devedor_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Saldo_Devedor_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Juros</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Juros_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Juros_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Amortização</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Amortizacao_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Amortizacao_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Custos do Negócio</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Negocio[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Negocio[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Receita Recorrente</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Custos Recorrentes</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Venda</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Venda[$prazo]}</td>";
        } else {
            echo "<td>{$Venda[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Fluxo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Custo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Acumulado_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Acumulado_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Receita_Acumulada</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Acumulada_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Acumulada_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Lucro Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
        <td>Fluxo_Caixa</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Caixa_Parcelado[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Caixa_Parcelado[$mes]}</td>";
        }
    }
    echo "</tr>
</table>
</br>
</br>";

    echo "<table class='table'>
<tr>
    <th>PRICE</th>
    <th>Ultimo Mês</th>";

    for ($mes = 0; $mes <= $prazo; $mes++) {
        echo "<th>Mês $mes</th>";
    }
    echo "</tr>
    <tr>
    <td>Arrematação</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Desembolso_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Desembolso_Price[$mes]}</td>";
        }
    }
    echo "</tr>
    <tr>
    <td>Saldo Devedor</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Saldo_Devedor_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Saldo_Devedor_Price[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Juros</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Juros_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Juros_Price[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Amortização</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Amortizacao_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Amortizacao_Price[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Custos do Negócio</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Negocio[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Negocio[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Receita Recorrente</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Custos Recorrentes</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Venda</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Venda[$prazo]}</td>";
        } else {
            echo "<td>{$Venda[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Fluxo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_Price[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Custo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Acumulado_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Acumulado_Price[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Receita_Acumulada</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Acumulada_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Acumulada_Price[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Lucro Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_Price[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Fluxo_Caixa</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Caixa_Price[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Caixa_Price[$mes]}</td>";
        }
    }
    echo "</tr>
</table>
</br>
</br>";

    echo "<table class='table'>
<tr>
    <th>A VISTA</th>
    <th>Ultimo Mês</th>";

    for ($mes = 0; $mes <= $prazo; $mes++) {
        echo "<th>Mês $mes</th>";
    }
    echo "</tr>
    <tr>
    <td>Arrematação</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Desembolso_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Desembolso_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Saldo Devedor</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Saldo_Devedor_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Saldo_Devedor_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Juros</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Juros_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Juros_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Amortização</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Amortizacao_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Amortizacao_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Custos do Negócio</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Negocio[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Negocio[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Receita Recorrente</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Custos Recorrentes</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Recorrente[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Recorrente[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Venda</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Venda[$prazo]}</td>";
        } else {
            echo "<td>{$Venda[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Fluxo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Custo_Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Custo_Acumulado_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Custo_Acumulado_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Receita_Acumulada</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Receita_Acumulada_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Receita_Acumulada_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Lucro Acumulado</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Acumulado_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Acumulado_Vista[$mes]}</td>";
        }
    }
    echo "</tr>
<tr>
    <td>Fluxo_Caixa</td>";
    for ($mes = -1; $mes <= $prazo; $mes++) {
        if ($mes == -1) {
            echo "<td>{$Fluxo_Caixa_Vista[$prazo]}</td>";
        } else {
            echo "<td>{$Fluxo_Caixa_Vista[$mes]}</td>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cálculo Completo</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link href="https://gitcdn.github.io/bootstrap-toggle/2.2.2/css/bootstrap-toggle.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>

<body>
</body>
<style>
    .table-container {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th,
    td {
        text-align: left;
        padding: 8px;
    }

    th {
        background-color: #f2f2f2;
    }

    tr:nth-child(even) {
        background-color: #f2f2f2;
    }
</style>

</html>