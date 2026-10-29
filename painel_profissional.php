<?php
// Inicia a sessão para reconhecer a profissional autenticada.
session_start();

// Cria um token de segurança para validar os formulários.
if (empty($_SESSION["csrf_agendamentos"])) {
    $_SESSION["csrf_agendamentos"] = bin2hex(random_bytes(32));
}

// Inclui a conexão com o banco de dados.
require_once("conexao.php");

/* ============================================================
   VERIFICAÇÃO DE ACESSO
   ============================================================ */

// Permite acesso somente para usuários autenticados.
if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit();
}

// Permite acesso exclusivamente para profissionais.
if (!isset($_SESSION["usuario_tipo"]) || $_SESSION["usuario_tipo"] !== "profissional") {
    header("Location: login.php");
    exit();
}

/* ============================================================
   DADOS DA PROFISSIONAL
   ============================================================ */

// Recupera os dados básicos da sessão.
$id_profissional = (int) $_SESSION["usuario_id"];
$nome_profissional = $_SESSION["usuario_nome"] ?? "Profissional";
$especialidade_profissional = $_SESSION["especialidade"] ?? "Não informada";

// Busca os dados mais recentes da profissional no banco.
try {
    $sql = "SELECT id, nome, email, telefone, tipo_usuario, especialidade
            FROM usuarios
            WHERE id = :id
            LIMIT 1";

    $consulta = $conexao->prepare($sql);
    $consulta->bindValue(":id", $id_profissional, PDO::PARAM_INT);
    $consulta->execute();

    $profissional = $consulta->fetch(PDO::FETCH_ASSOC);

    // Se a conta não existir, encerra a sessão.
    if (!$profissional) {
        session_destroy();
        header("Location: login.php");
        exit();
    }

    // Atualiza os dados utilizados na página.
    $nome_profissional = $profissional["nome"];
    $email_profissional = $profissional["email"];
    $telefone_profissional = $profissional["telefone"];
    $especialidade_profissional = $profissional["especialidade"] ?? $especialidade_profissional;

    // Mantém os dados atualizados nas variáveis da sessão.
    $_SESSION["usuario_nome"] = $nome_profissional;
    $_SESSION["especialidade"] = $especialidade_profissional;

} catch (PDOException $erro) {
    // Registra o problema sem exibir informações técnicas na página.
    error_log("Erro dados profissional: " . $erro->getMessage());
}

/* ============================================================
   DATA ATUAL
   ============================================================ */

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// Prepara as datas para consultas e exibição.
$data_atual_sql = date("Y-m-d");
$data_br = date("d/m/Y");

// Traduz o dia da semana para português.
$dias_semana = [
    "Sunday" => "Domingo",
    "Monday" => "Segunda-feira",
    "Tuesday" => "Terça-feira",
    "Wednesday" => "Quarta-feira",
    "Thursday" => "Quinta-feira",
    "Friday" => "Sexta-feira",
    "Saturday" => "Sábado"
];

$dia_atual = $dias_semana[date("l")] ?? date("l");

/* ============================================================
   RESUMO DOS AGENDAMENTOS DE HOJE
   ============================================================ */

// Inicializa os indicadores com valores padrão.
$agendamentos_hoje = 0;
$agendamentos_pendentes = 0;
$atendimentos_concluidos = 0;
$valor_dia = 0;
$proximo_atendimento = null;

// Busca os agendamentos do dia da profissional autenticada.
try {
    
$sql_ag = "SELECT a.horario, a.status, a.servico_id,
c.nome AS cliente_nome,
c.telefone AS cliente_telefone,
c.email AS cliente_email,
s.nome_servico,
a.preco_final AS preco
FROM agendamentos a
LEFT JOIN usuarios c ON a.cliente_id = c.id
LEFT JOIN servicos s ON a.servico_id = s.id
WHERE a.profissional_id = :profissional_id
AND DATE(a.data_agendamento) = :data_hoje
AND LOWER(TRIM(a.status)) <> 'cancelado'
ORDER BY a.horario ASC";


    $stmt_ag = $conexao->prepare($sql_ag);
    $stmt_ag->bindValue(":profissional_id", $id_profissional, PDO::PARAM_INT);
    $stmt_ag->bindValue(":data_hoje", $data_atual_sql);
    $stmt_ag->execute();

    $agendamentos = $stmt_ag->fetchAll(PDO::FETCH_ASSOC);

    // Conta os atendimentos e separa os pendentes dos concluídos.
    foreach ($agendamentos as $ag) {
        $agendamentos_hoje++;

        if ($ag["status"] === "pendente") {
            $agendamentos_pendentes++;

            if (!$proximo_atendimento) {
                $proximo_atendimento = $ag;
            }
        }

        if ($ag["status"] === "concluido") {
            $atendimentos_concluidos++;
            $valor_dia += (float) $ag["preco"];
        }
    }

} catch (PDOException $e) {
    error_log("Erro agendamentos: " . $e->getMessage());
}

/* ============================================================
   PRÓXIMO AGENDAMENTO FUTURO
   ============================================================ */

// Recupera o horário atual para não mostrar agendamentos passados.
$data_hora_atual = date("H:i:s");

try {
    // Localiza o agendamento pendente mais próximo.
    $sql_proximo = "SELECT a.id AS agendamento_id,
                           DATE(a.data_agendamento) AS data_agendamento,
                           a.horario,
                           a.status,
                           a.servico_id,
                           c.nome AS cliente_nome,
                           c.telefone AS cliente_telefone,
                           c.email AS cliente_email,
                           s.nome_servico,
                           a.preco_final AS preco
                    FROM agendamentos a
                    LEFT JOIN usuarios c ON a.cliente_id = c.id
                    LEFT JOIN servicos s ON a.servico_id = s.id
                    WHERE a.profissional_id = :profissional_id
                      AND a.status = 'pendente'
                      AND DATE(a.data_agendamento) >= :data_hoje
                      AND (
                          DATE(a.data_agendamento) > :data_hoje_futura
                          OR a.horario >= :hora_atual
                      )
                    ORDER BY DATE(a.data_agendamento) ASC, a.horario ASC
                    LIMIT 1";

    $stmt_proximo = $conexao->prepare($sql_proximo);
    $stmt_proximo->bindValue(":profissional_id", $id_profissional, PDO::PARAM_INT);
    $stmt_proximo->bindValue(":data_hoje", $data_atual_sql);
    $stmt_proximo->bindValue(":data_hoje_futura", $data_atual_sql);
    $stmt_proximo->bindValue(":hora_atual", $data_hora_atual);
    $stmt_proximo->execute();

    // Guarda o atendimento mais próximo ou mantém nulo se não houver.
    $proximo_atendimento = $stmt_proximo->fetch(PDO::FETCH_ASSOC) ?: null;

} catch (PDOException $e) {
    error_log("Erro ao buscar próximo agendamento: " . $e->getMessage());
    $proximo_atendimento = null;
}

/* ============================================================
   HORÁRIOS DE TRABALHO
   ============================================================ */

// Define horários padrão caso ainda não existam configurações salvas.
$inicio_padrao = "08:00";
$ultimo_inicio = "17:00";
$fim_expediente = "19:00";
$inicio_almoco = "12:00";
$fim_almoco = "13:00";

try {
    // Busca os horários cadastrados pela profissional.
    $sql_hr = "SELECT horario_inicio, ultimo_horario,
                      inicio_almoco, fim_almoco
               FROM horarios
               WHERE profissional_id = :pid
               LIMIT 1";

    $stmt_hr = $conexao->prepare($sql_hr);
    $stmt_hr->bindValue(":pid", $id_profissional, PDO::PARAM_INT);
    $stmt_hr->execute();

    $hr = $stmt_hr->fetch(PDO::FETCH_ASSOC);

    if ($hr) {
        $inicio_padrao = $hr["horario_inicio"] ?? $inicio_padrao;
        $ultimo_inicio = $hr["ultimo_horario"] ?? $ultimo_inicio;

        // Mantém o encerramento padrão quando não há valor salvo.
        $inicio_almoco = $hr["inicio_almoco"] ?? $inicio_almoco;
        $fim_almoco = $hr["fim_almoco"] ?? $fim_almoco;
    }

} catch (PDOException $e) {
    error_log("Erro horários: " . $e->getMessage());
}

/* ============================================================
   PREPARAÇÃO DA SAUDAÇÃO
   ============================================================ */

// Utiliza somente o primeiro nome no cabeçalho.
$primeiro_nome = explode(" ", trim($nome_profissional))[0];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Painel Profissional | TCC Estética</title>

    <!-- Carrega o CSS principal do sistema. -->
    <link rel="stylesheet" href="style.css">

    <style>
        /* Estiliza o botão para concluir o atendimento. */
        .btn-concluir-agendamento {
            border: 0;
            border-radius: 9px;
            padding: 11px 17px;
            background: #704B42;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-concluir-agendamento:hover {
            background: #5A3434;
        }

        /* Estiliza o link para consultar todos os atendimentos. */
        .btn-ver-atendimentos {
            color: #8F4054;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-ver-atendimentos:hover {
            text-decoration: underline;
        }

        /* Transforma o novo card em um atalho clicável. */
        .card-atalho-aviso {
            display: block;
            box-sizing: border-box;
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        /* Destaca o atalho quando a profissional passa o mouse. */
        .card-atalho-aviso:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(90, 52, 52, 0.12);
        }

        /* Indica visualmente que o card pode ser acessado pelo teclado. */
        .card-atalho-aviso:focus-visible {
            outline: 3px solid #A85F70;
            outline-offset: 3px;
        }

        /* Mantém o título e a descrição legíveis no atalho. */
        .card-atalho-aviso .titulo,
        .card-atalho-aviso .numero,
        .card-atalho-aviso .descricao {
            color: inherit;
        }
    </style>
</head>

<body>

<!-- ============================================================
     MENU LATERAL
     ============================================================ -->
<aside class="sidebar">

    <div class="logo">
        <h1>Estética</h1>

        <!-- Exibe a especialidade da profissional. -->
        <?php $especialidade = htmlspecialchars($_SESSION["especialidade"] ?? "Profissional"); ?>
        <p><?= $especialidade ?></p>
    </div>

    <!-- Links de navegação entre as páginas profissionais. -->
    <nav class="menu">
        <a href="painel_profissional.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'painel_profissional.php' ? 'ativo' : '' ?>">
            Início
        </a>

        <a href="atendimentos.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'atendimentos.php' ? 'ativo' : '' ?>">
            Atendimentos
        </a>

        <a href="minha_agenda.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'minha_agenda.php' ? 'ativo' : '' ?>">
            Minha Agenda
        </a>

        <a href="meus_servicos.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'meus_servicos.php' ? 'ativo' : '' ?>">
            Meus Serviços
        </a>

        <div class="separador"></div>

        <a href="meu_perfil.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'meu_perfil.php' ? 'ativo' : '' ?>">
            Meu Perfil
        </a>
    </nav>

    <!-- Encerra a sessão da profissional. -->
    <a href="logout.php" class="btn-sair">Sair</a>

</aside>

<!-- ============================================================
     CONTEÚDO PRINCIPAL
     ============================================================ -->
<main class="main">

    <!-- Cabeçalho do painel. -->
    <section class="topo">
        <div>
            <h2>Olá, <?php echo htmlspecialchars($primeiro_nome); ?>!</h2>
            <p>Aqui você pode acompanhar e administrar sua rotina profissional.</p>
        </div>

        <div class="data">
            <?php echo htmlspecialchars($dia_atual); ?>,
            <?php echo htmlspecialchars($data_br); ?>
        </div>
    </section>

    <!-- ========================================================
         INDICADORES E ATALHO PARA PUBLICAR AVISOS
         ======================================================== -->
    <section class="cards">

        <!-- Mostra o total de agendamentos previstos para hoje. -->
        <div class="card">
            <div class="titulo">Agendamentos de hoje</div>
            <div class="numero"><?php echo $agendamentos_hoje; ?></div>
            <div class="descricao">atendimentos previstos</div>
        </div>

        <!-- Mostra quantos atendimentos estão pendentes hoje. -->
        <div class="card">
            <div class="titulo">Pendentes</div>
            <div class="numero"><?php echo $agendamentos_pendentes; ?></div>
            <div class="descricao">aguardando atendimento</div>
        </div>

        <!-- Mostra quantos atendimentos foram concluídos hoje. -->
        <div class="card">
            <div class="titulo">Atendimentos concluídos</div>
            <div class="numero"><?php echo $atendimentos_concluidos; ?></div>
            <div class="descricao">realizados hoje</div>
        </div>

        <!--
            Substitui o card de valor do dia por um atalho.
            Ao clicar, a profissional acessa a área de avisos do perfil.
        -->
        <a href="meu_perfil.php#avisos" class="card card-atalho-aviso"
           aria-label="Ir para Meu Perfil e publicar um aviso para as clientes">

            <div class="titulo">Publicar aviso</div>
            <div class="numero">Escrever mensagem</div>
            <div class="descricao">Compartilhe informações com suas clientes.</div>

        </a>

    </section>

    <!-- ========================================================
         PRÓXIMO ATENDIMENTO E ROTINA
         ======================================================== -->
    <section class="grid">

        <!-- Exibe os dados do próximo atendimento pendente. -->
        <div class="box">
            <h3>Próximo atendimento</h3>

            <div class="proximo">

                <?php if ($proximo_atendimento): ?>

                    <!-- Data do próximo atendimento. -->
                    <div class="informacao">
                        Data:
                        <?php echo date("d/m/Y", strtotime($proximo_atendimento["data_agendamento"])); ?>
                    </div>

                    <!-- Horário agendado. -->
                    <div class="horario">
                        <?php echo htmlspecialchars($proximo_atendimento["horario"]); ?>
                    </div>

                    <!-- Nome e contato da cliente. -->
                    <div class="cliente">
                        <?php echo htmlspecialchars($proximo_atendimento["cliente_nome"] ?? "Cliente não identificada"); ?>
                    </div>

                    <div class="informacao">
                        Serviço:
                        <?php echo htmlspecialchars($proximo_atendimento["nome_servico"] ?? "Não informado"); ?>
                    </div>

                    <div class="informacao">
                        Telefone:
                        <?php echo htmlspecialchars($proximo_atendimento["cliente_telefone"] ?? "--"); ?>
                    </div>

                    <div class="informacao">
                        E-mail:
                        <?php echo htmlspecialchars($proximo_atendimento["cliente_email"] ?? "--"); ?>
                    </div>

                    <div>
                        <span class="status">Próximo atendimento</span>
                    </div>

                    <!-- Formulário para concluir o atendimento. -->
                    <form action="atualizar_status_agendamento.php"
                          method="POST"
                          onsubmit="return confirm('Confirma que este atendimento foi realizado?');"
                          style="margin-top: 16px;">

                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($_SESSION['csrf_agendamentos']); ?>">

                        <input type="hidden" name="agendamento_id"
                               value="<?php echo (int) $proximo_atendimento['agendamento_id']; ?>">

                        <input type="hidden" name="novo_status" value="concluido">
                        <input type="hidden" name="origem" value="painel_profissional.php">

                        <button type="submit" class="btn-concluir-agendamento">
                            Concluir atendimento
                        </button>

                    </form>

                    <!-- Atalho para consultar todos os atendimentos. -->
                    <a href="atendimentos.php"
                       class="btn-ver-atendimentos"
                       style="display:inline-block; margin-top:10px;">
                        Ver todos os atendimentos
                    </a>

                <?php else: ?>

                    <!-- Mensagem apresentada quando não existem atendimentos futuros. -->
                    <div class="horario">--</div>
                    <div class="cliente">Nenhum atendimento agendado</div>

                    <div class="informacao">
                        Quando houver um agendamento, os dados da cliente aparecerão aqui.
                    </div>

                    <div class="informacao">Serviço: --</div>
                    <div class="informacao">Telefone: --</div>
                    <div class="informacao">E-mail: --</div>

                    <div>
                        <span class="status">Sem atendimento</span>
                    </div>

                <?php endif; ?>

            </div>
        </div>

        <!-- Exibe os horários de trabalho cadastrados. -->
        <div class="box">
            <h3>Minha rotina</h3>

            <div class="horario-item">
                <strong>Início</strong>
                <span><?php echo htmlspecialchars($inicio_padrao); ?></span>
            </div>

            <div class="horario-item">
                <strong>Último início</strong>
                <span><?php echo htmlspecialchars($ultimo_inicio); ?></span>
            </div>

            <div class="horario-item">
                <strong>Encerramento</strong>
                <span><?php echo htmlspecialchars($fim_expediente); ?></span>
            </div>

            <div class="horario-item">
                <strong>Almoço</strong>
                <span>
                    <?php echo htmlspecialchars($inicio_almoco); ?>
                    -
                    <?php echo htmlspecialchars($fim_almoco); ?>
                </span>
            </div>

            <div class="horario-item">
                <strong>Folgas</strong>
                <span>Definidas em Minha Agenda</span>
            </div>
        </div>

    </section>

    <!-- ========================================================
         ATALHOS PARA AS PRINCIPAIS FUNCIONALIDADES
         ======================================================== -->
    <section class="box atalhos-box">

        <h3>Gerenciar minha rotina</h3>

        <div class="atalhos">

            <!-- Atalho para gerenciar serviços. -->
            <a href="meus_servicos.php" class="atalho">
                <div class="icone"></div>
                <strong>Meus Serviços</strong>
                <span>Altere preços, duração e disponibilidade.</span>
            </a>

            <!-- Atalho para configurar os horários de trabalho. -->
            <a href="minha_agenda.php?aba=horarios" class="atalho">
                <div class="icone"></div>
                <strong>Meus Horários</strong>
                <span>Configure dias e horários de trabalho.</span>
            </a>

            <!-- Atalho para cadastrar folgas e bloqueios. -->
            <a href="minha_agenda.php?aba=bloqueios" class="atalho">
                <div class="icone"></div>
                <strong>Folgas e Bloqueios</strong>
                <span>Bloqueie datas e períodos.</span>
            </a>

            <!-- Atalho para abrir a agenda. -->
            <a href="minha_agenda.php" class="atalho">
                <div class="icone"></div>
                <strong>Minha Agenda</strong>
                <span>Visualize agendamentos, horários e folgas.</span>
            </a>

            <!-- Atalho para consultar o histórico de atendimentos. -->
            <a href="atendimentos.php" class="atalho">
                <div class="icone"></div>
                <strong>Histórico</strong>
                <span>Consulte atendimentos realizados.</span>
            </a>

            <!-- Atalho para editar os dados e publicar avisos. -->
            <a href="meu_perfil.php#avisos" class="atalho">
                <div class="icone"></div>
                <strong>Meu Perfil</strong>
                <span>Atualize seus dados e publique avisos para as clientes.</span>
            </a>

        </div>
    </section>

    <!-- Informa onde os horários podem ser configurados. -->
    <div class="aviso">
        <strong>Configuração de horários:</strong>
        Os horários são carregados da tabela <em>horarios</em> quando disponíveis.
        Caso contrário, são utilizados os valores padrão.<br>
        <em>Altere seus horários e folgas diretamente em Minha Agenda.</em>
    </div>

</main>

</body>
</html>
