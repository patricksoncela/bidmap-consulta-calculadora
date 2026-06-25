<?php
if (file_exists(__DIR__ . '/.env')) {
    $_ENV = array_merge($_ENV, parse_ini_file(__DIR__ . '/.env'));
}
/* if (session_status() == PHP_SESSION_NONE) {
    session_start();
} */
$liberado = $_SESSION['cliente']['liberado'] ?? false;

if (!defined('BIDMAP_HEADER_ASSETS_LOADED')) {
    define('BIDMAP_HEADER_ASSETS_LOADED', true);
    $headerCssVersion = file_exists(__DIR__ . '/../css/header.css') ? filemtime(__DIR__ . '/../css/header.css') : time();
    $headerJsVersion = file_exists(__DIR__ . '/../js/header.js') ? filemtime(__DIR__ . '/../js/header.js') : time();
    echo '<link rel="stylesheet" href="css/header.css?v=' . $headerCssVersion . '">' . PHP_EOL;
    echo '<script src="js/header.js?v=' . $headerJsVersion . '"></script>' . PHP_EOL;
}
?><header class="header">
    <span onclick="openNav()" class="botao-menu">
        <img src="img/menu.png" alt="menu" width="30" height="20">
    </span>
    <a href="consultar_processos.php">
        <img src="img/bidmap-logo-branco.png" alt="logo" width="126" height="28">
    </a>
</header>
<div id="sidebar">
    <div class="btn-container">
        <a href="javascript:void(0)" class="closebtn" onclick="openNav()"><img src="img/x.png" width="15" height="15"></a>
    </div>
    <ul class="nav flex-column">
        <li class="nav-item">
            <span class="nav-link bold">Ferramentas</span>
            <ul class="sub-nav">
                <li class="nav-item">
                    <a class="nav-link menu-consulta-link" href="consultar_processos.php">
                        Consulta de Dados Pessoais
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link menu-consulta-link" href="consultar_processos.php">
                        Consulta de Processos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="calculadora.php">
                        Calculadora
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="historico_consultas.php">
                        Extrato de Cr&eacute;ditos
                    </a>
                </li>
            </ul>
        </li>
    </ul>
</div>
