
<?php
// ============================================================
// INICIAR SESSÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão para identificar a cliente autenticada.
session_start();

// Permite o acesso somente para clientes logadas.
if (
    !isset($_SESSION["usuario_id"]) ||
    !isset($_SESSION["usuario_tipo"]) ||
    $_SESSION["usuario_tipo"] !== "cliente"
) {
    header("Location: login.php");
    exit();
}

// Carrega a conexão com o banco de dados.
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// ============================================================
// IDENTIFICAR A PROFISSIONAL
// ============================================================

// Recebe o ID da profissional enviado pela URL.
$profissional_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

// Impede a consulta caso o ID não seja válido.
if (!$profissional_id || $profissional_id <= 0) {
    header("Location: servicos.php");
    exit();
}

// ============================================================
// BUSCAR OS DADOS DO PERFIL
// ============================================================

// Busca somente profissionais ativos.
$sql_profissional = "
    SELECT
        id,
        nome,
        especialidade,
        descricao,
        redes_sociais,
        chave_pix,
        tipo_chave_pix
    FROM usuarios
    WHERE id = :id
      AND tipo_usuario = 'profissional'
      AND status = 'ativo'
    LIMIT 1
";

$stmt_profissional = $conexao->prepare($sql_profissional);

$stmt_profissional->execute([
    ":id" => $profissional_id
]);

$profissional = $stmt_profissional->fetch(PDO::FETCH_ASSOC);

// Retorna à página de serviços se o perfil não existir.
if (!$profissional) {
    header("Location: servicos.php");
    exit();
}

// ============================================================
// TRATAR OS DADOS PARA EXIBIÇÃO
// ============================================================

// Converte o código da especialidade para um nome amigável.
$especialidades = [
    "nail" => "Unhas",
    "lash" => "Cílios",
    "eyebrow" => "Sobrancelhas",
    "cliente" => "Não informada"
];

$especialidade = $especialidades[
    $profissional["especialidade"] ?? ""
] ?? "Não informada";

// Guarda as informações opcionais do perfil.
$descricao = trim($profissional["descricao"] ?? "");
$redes_sociais = trim($profissional["redes_sociais"] ?? "");
$chave_pix = trim($profissional["chave_pix"] ?? "");
$tipo_chave_pix = trim($profissional["tipo_chave_pix"] ?? "");

// ============================================================
// BUSCAR OS SERVIÇOS DA PROFISSIONAL
// ============================================================

// Busca os serviços ativos vinculados à profissional selecionada.
$sql_servicos = "
    SELECT DISTINCT
        s.id,
        s.nome_servico,
        s.tipo_servico,
        s.descricao,
        s.preco,
        s.duracao
    FROM servicos s
    INNER JOIN vinculacao v
        ON v.servico_id = s.id
       AND v.profissional_id = :profissional_id
    WHERE s.profissional_id = :profissional_id_dono
      AND s.status = 'ativo'
    ORDER BY s.nome_servico ASC
";

$stmt_servicos = $conexao->prepare($sql_servicos);

$stmt_servicos->execute([
    ":profissional_id" => $profissional_id,
    ":profissional_id_dono" => $profissional_id
]);

$servicos = $stmt_servicos->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// FUNÇÃO PARA EXIBIR TEXTO COM SEGURANÇA
// ============================================================

// Evita que textos cadastrados sejam interpretados como HTML.
function escapar($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Perfil da Profissional | TCC Estética</title>

    <!-- Carrega a folha de estilos externa do projeto. -->
    <link rel="stylesheet" href="style.css">
</head>

<body class="pagina-perfil-profissional">

    <!-- ======================================================
         MENU SUPERIOR
         ====================================================== -->

    <header class="cabecalho-perfil">
        <div class="conteudo-cabecalho-perfil">

            <a href="painel_cliente.php" class="logo-perfil">
                TCC Estética
            </a>

            <nav class="menu-perfil" aria-label="Menu principal">
                <a href="painel_cliente.php">Início</a>
                <a href="servicos.php">Serviços</a>
                <a href="meus_agendamentos.php">Meus agendamentos</a>
                <a href="perfil_cliente.php">Meu perfil</a>
                <a href="logout.php">Sair</a>
            </nav>

        </div>
    </header>

    <main class="conteudo-perfil-profissional">

        <!-- ==================================================
             CABEÇALHO DO PERFIL
             ================================================== -->

        <section class="cartao-perfil-profissional">

            <a href="servicos.php" class="link-voltar-perfil">
                &larr; Voltar aos serviços
            </a>

            <div class="identificacao-profissional">

                <div class="iniciais-profissional" aria-hidden="true">
                    <?= escapar(
                        mb_strtoupper(
                            mb_substr($profissional["nome"], 0, 1, "UTF-8"),
                            "UTF-8"
                        )
                    ) ?>
                </div>

                <div class="informacoes-profissional">

                    <p class="texto-identificacao">
                        PERFIL PROFISSIONAL
                    </p>

                    <h1>
                        <?= escapar($profissional["nome"]) ?>
                    </h1>

                    <span class="especialidade-profissional">
                        <?= escapar($especialidade) ?>
                    </span>

                </div>

            </div>

            <!-- Exibe a descrição somente se estiver preenchida. -->
            <?php if ($descricao !== ""): ?>
                <div class="secao-descricao-profissional">

                    <h2>Sobre a profissional</h2>

                    <p><?= nl2br(escapar($descricao)) ?></p>

                </div>
            <?php else: ?>
                <div class="secao-descricao-profissional">
                    <h2>Sobre a profissional</h2>
                    <p>Esta profissional ainda não cadastrou uma descrição.</p>
                </div>
            <?php endif; ?>

            <!-- Exibe as redes sociais cadastradas. -->
            <div class="secao-contato-profissional">

                <h2>Redes sociais</h2>

                <?php if ($redes_sociais !== ""): ?>

                    <p class="texto-redes-sociais">
                        <?= nl2br(escapar($redes_sociais)) ?>
                    </p>

                <?php else: ?>

                    <p>
                        Nenhuma rede social informada no momento.
                    </p>

                <?php endif; ?>

            </div>

            <!-- Exibe a chave Pix apenas para consulta. -->
            <div class="secao-pix-profissional">

                <h2>Informações de pagamento</h2>

                <?php if ($chave_pix !== ""): ?>

                    <?php if ($tipo_chave_pix !== ""): ?>
                        <p>
                            <strong>Tipo de chave:</strong>
                            <?= escapar($tipo_chave_pix) ?>
                        </p>
                    <?php endif; ?>

                    <p>
                        <strong>Chave Pix:</strong>
                        <span class="chave-pix">
                            <?= escapar($chave_pix) ?>
                        </span>
                    </p>

                    <p class="aviso-pix">
                        Confira os dados antes de realizar qualquer pagamento.
                        A confirmação do pagamento é feita pela profissional.
                    </p>

                <?php else: ?>

                    <p>
                        A profissional ainda não cadastrou uma chave Pix.
                    </p>

                <?php endif; ?>

            </div>

        </section>

        <!-- ==================================================
             LISTAGEM DE SERVIÇOS
             ================================================== -->

        <section class="secao-servicos-profissional">

            <div class="titulo-secao-perfil">
                <div>
                    <p class="texto-identificacao">ATENDIMENTOS</p>
                    <h2>Serviços oferecidos</h2>
                </div>
            </div>

            <?php if (count($servicos) > 0): ?>

                <div class="lista-servicos-profissional">

                    <?php foreach ($servicos as $servico): ?>

                        <article class="cartao-servico-perfil">

                            <div class="informacoes-servico-perfil">

                                <h3>
                                    <?= escapar($servico["nome_servico"]) ?>
                                </h3>

                                <?php if (!empty($servico["tipo_servico"])): ?>
                                    <span class="tipo-servico-perfil">
                                        <?= escapar($servico["tipo_servico"]) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($servico["descricao"])): ?>
                                    <p>
                                        <?= nl2br(escapar($servico["descricao"])) ?>
                                    </p>
                                <?php endif; ?>

                            </div>

                            <div class="detalhes-servico-perfil">

                                <p>
                                    <strong>Duração:</strong>
                                    <?= escapar($servico["duracao"] ?: "A combinar") ?>
                                </p>

                                <p>
                                    <strong>Preço:</strong>
                                    <?php if ($servico["preco"] !== null): ?>
                                        R$ <?= number_format(
                                            (float) $servico["preco"],
                                            2,
                                            ",",
                                            "."
                                        ) ?>
                                    <?php else: ?>
                                        A consultar
                                    <?php endif; ?>
                                </p>

                            </div>

                            <!-- Encaminha para a agenda da profissional. -->
                            <a
                                class="botao-agenda-perfil"
                                href="agenda.php?servico_id=<?= (int) $servico["id"] ?>&profissional_id=<?= (int) $profissional["id"] ?>"
                            >
                                Ver agenda
                            </a>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="mensagem-servicos-vazios">
                    <p>
                        Esta profissional ainda não possui serviços
                        ativos disponíveis para exibição.
                    </p>
                </div>

            <?php endif; ?>

        </section>

    </main>

</body>
</html>
