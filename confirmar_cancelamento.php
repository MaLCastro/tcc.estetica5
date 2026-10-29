
<?php
// ============================================================
// 1. INICIAR SESSÃO, CONEXÃO E VALIDAR ACESSO
// ============================================================

session_start();
require_once("conexao.php");

// Utiliza o fuso horário padrão do projeto.
date_default_timezone_set("America/Sao_Paulo");

// Permite o acesso somente para clientes autenticadas.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    header("Location: login.php");
    exit();
}

$cliente_id = (int) $_SESSION["usuario_id"];
$fuso_horario = new DateTimeZone("America/Sao_Paulo");

// Protege os textos exibidos no HTML.
function escaparCancelamento($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

// Formata a data para o padrão brasileiro.
function formatarDataCancelamento($data)
{
    $data = (string) $data;
    $data_objeto = DateTime::createFromFormat("!Y-m-d", $data);

    if (
        !$data_objeto ||
        $data_objeto->format("Y-m-d") !== $data
    ) {
        return "Data não informada";
    }

    return $data_objeto->format("d/m/Y");
}

// ============================================================
// 2. VALIDAR O ID E PREPARAR O TOKEN DE SEGURANÇA
// ============================================================

// Obtém o ID enviado pela listagem de agendamentos.
$agendamento_id = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

// Também permite recuperar o ID enviado pelo formulário.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $agendamento_id = filter_input(
        INPUT_POST,
        "agendamento_id",
        FILTER_VALIDATE_INT
    );
}

// Interrompe o fluxo se o identificador for inválido.
if (!$agendamento_id || $agendamento_id < 1) {
    $_SESSION["mensagem_agendamento"] = [
        "tipo" => "erro",
        "texto" => "O agendamento informado é inválido."
    ];

    header("Location: meus_agendamentos.php");
    exit();
}

// Cria um token para proteger o envio do formulário.
if (empty($_SESSION["token_confirmar_cancelamento"])) {
    $_SESSION["token_confirmar_cancelamento"] = bin2hex(random_bytes(32));
}

// Inicializa as mensagens da página.
$erro = "";
$agendamento = null;

// ============================================================
// 3. BUSCAR E VALIDAR O AGENDAMENTO
// ============================================================

try {
    // Busca apenas um agendamento pertencente à cliente autenticada.
    $sql = "
        SELECT
            a.id,
            a.cliente_id,
            a.data_agendamento,
            a.horario,
            a.status,
            COALESCE(a.preco_final, s.preco) AS preco,
            s.nome_servico AS servico,
            u.nome AS profissional
        FROM agendamentos AS a
        LEFT JOIN servicos AS s
            ON s.id = a.servico_id
        LEFT JOIN usuarios AS u
            ON u.id = a.profissional_id
        WHERE a.id = :id
          AND a.cliente_id = :cliente_id
        LIMIT 1
    ";

    $consulta = $conexao->prepare($sql);

    $consulta->execute([
        ":id" => $agendamento_id,
        ":cliente_id" => $cliente_id
    ]);

    $agendamento = $consulta->fetch(PDO::FETCH_ASSOC);

    // Impede consultar ou cancelar agendamentos de outra pessoa.
    if (!$agendamento) {
        $_SESSION["mensagem_agendamento"] = [
            "tipo" => "erro",
            "texto" => "Agendamento não encontrado."
        ];

        header("Location: meus_agendamentos.php");
        exit();
    }

} catch (PDOException $e) {
    // Registra detalhes técnicos somente no log do servidor.
    error_log(
        "Erro ao consultar agendamento para cancelamento: " .
        $e->getMessage()
    );

    $erro = "Não foi possível carregar os dados do agendamento.";
}

// ============================================================
// 4. PROCESSAR A CONFIRMAÇÃO DO CANCELAMENTO
// ============================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["confirmar_cancelamento"]) &&
    $agendamento
) {
    // Confere o token de segurança do formulário.
    $token_recebido = $_POST["token_cancelamento"] ?? "";
    $token_sessao = $_SESSION["token_confirmar_cancelamento"] ?? "";

    if (
        !is_string($token_recebido) ||
        !is_string($token_sessao) ||
        $token_sessao === "" ||
        !hash_equals($token_sessao, $token_recebido)
    ) {
        $erro = "Não foi possível validar sua solicitação. Atualize a página e tente novamente.";

    } else {
        // Verifica se o status ainda permite cancelamento.
        $status_atual = strtolower(
            trim((string) $agendamento["status"])
        );

        if (!in_array(
            $status_atual,
            ["pendente", "confirmado", "alterado"],
            true
        )) {
            $erro = "Este agendamento não pode mais ser cancelado.";

        } else {
            // Valida a data e o horário antes de cancelar.
            $data_banco = (string) $agendamento["data_agendamento"];
            $horario_banco = substr(
                trim((string) $agendamento["horario"]),
                0,
                5
            );

            $data_hora = DateTime::createFromFormat(
                "!Y-m-d H:i",
                $data_banco . " " . $horario_banco,
                $fuso_horario
            );

            $data_valida = (
                $data_hora !== false &&
                $data_hora->format("Y-m-d H:i") ===
                    $data_banco . " " . $horario_banco
            );

            $agora = new DateTime("now", $fuso_horario);

            if (!$data_valida || $data_hora <= $agora) {
                $erro = "Não é possível cancelar um atendimento cujo horário já passou ou é inválido.";

            } else {
                try {
                    // O motivo é opcional, mas ainda não é persistido.
                    $motivo = trim(
                        (string) ($_POST["motivo"] ?? "")
                    );

                    // Atualiza o status somente se o registro continuar cancelável.
                    // A condição evita substituir um status alterado por outra operação.
                    $sql_cancelar = "
                        UPDATE agendamentos
                        SET status = 'cancelado'
                        WHERE id = :id
                          AND cliente_id = :cliente_id
                          AND status IN ('pendente', 'confirmado', 'alterado')
                    ";

                    $consulta_cancelar = $conexao->prepare($sql_cancelar);

                    $consulta_cancelar->execute([
                        ":id" => $agendamento_id,
                        ":cliente_id" => $cliente_id
                    ]);

                    // Só informa sucesso se o banco alterar o registro.
                    if ($consulta_cancelar->rowCount() > 0) {
                        // Guarda a mensagem para a próxima página.
                        $_SESSION["mensagem_agendamento"] = [
                            "tipo" => "sucesso",
                            "texto" => "Agendamento cancelado com sucesso."
                        ];

                        // Invalida o token usado após a confirmação.
                        unset($_SESSION["token_confirmar_cancelamento"]);

                        header("Location: meus_agendamentos.php");
                        exit();

                    } else {
                        $erro = "O agendamento não foi alterado. Atualize a página e confira o status.";
                    }

                } catch (PDOException $e) {
                    // Evita exibir informações internas do banco.
                    error_log(
                        "Erro ao cancelar agendamento: " .
                        $e->getMessage()
                    );

                    $erro = "Ocorreu um erro ao cancelar o agendamento. Tente novamente.";
                }
            }
        }
    }
}

// ============================================================
// 5. PREPARAR OS DADOS PARA EXIBIÇÃO
// ============================================================

$nome_servico = $agendamento["servico"] ?? "Serviço";
$nome_profissional = $agendamento["profissional"] ?? "Não informada";
$data_formatada = formatarDataCancelamento(
    $agendamento["data_agendamento"] ?? ""
);
$horario_formatado = $agendamento["horario"] ?? "--:--";

$preco = $agendamento["preco"] ?? null;

$status_atual = strtolower(
    trim((string) ($agendamento["status"] ?? ""))
);

$pode_cancelar = in_array(
    $status_atual,
    ["pendente", "confirmado", "alterado"],
    true
);

// Confere se o atendimento ainda está no futuro.
if ($agendamento && $pode_cancelar) {
    $data_banco = (string) $agendamento["data_agendamento"];
    $horario_banco = substr(
        trim((string) $agendamento["horario"]),
        0,
        5
    );

    $data_hora = DateTime::createFromFormat(
        "!Y-m-d H:i",
        $data_banco . " " . $horario_banco,
        $fuso_horario
    );

    $agora = new DateTime("now", $fuso_horario);

    if (
        !$data_hora ||
        $data_hora->format("Y-m-d H:i") !==
            $data_banco . " " . $horario_banco ||
        $data_hora <= $agora
    ) {
        $pode_cancelar = false;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <!-- Configura a página e a adaptação para dispositivos móveis. -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Confirmar Cancelamento | TCC Estética</title>

    <!-- Reaproveita o CSS global do projeto. -->
    <link rel="stylesheet" href="style.css">

</head>

<body class="pagina-cliente pagina-confirmar-cancelamento">

    <!-- Cabeçalho padronizado da área da cliente. -->
    <header class="cabecalho-cliente">
        <div class="logo-cliente">
            <h1>Estética</h1>
        </div>

        <nav class="menu-cliente" aria-label="Navegação principal">
            <a href="painel_cliente.php">Início</a>
            <a href="servicos.php">Serviços</a>
            <a href="meus_agendamentos.php" class="ativo">Meus Agendamentos</a>
            <a href="perfil_cliente.php">Meu Perfil</a>
            <a href="logout.php" class="menu-sair">Sair</a>
        </nav>
    </header>

    <main class="cancelamento-container">
        <section class="cancelamento-cartao">

            <div class="cancelamento-cabecalho">
                <div class="cancelamento-icone" aria-hidden="true">!</div>

                <span class="cancelamento-etiqueta">
                    CONFIRMAÇÃO DE CANCELAMENTO
                </span>

                <h2>Cancelar agendamento?</h2>

                <p>
                    Tem certeza de que deseja cancelar este atendimento?
                    Confira os dados antes de continuar.
                </p>
            </div>

            <!-- Exibe os dados somente quando o registro foi carregado. -->
            <?php if ($agendamento): ?>

                <div class="cancelamento-detalhes">
                    <div class="cancelamento-dado">
                        <span>Serviço</span>
                        <strong>
                            <?= escaparCancelamento($nome_servico) ?>
                        </strong>
                    </div>

                    <div class="cancelamento-dado">
                        <span>Profissional</span>
                        <strong>
                            <?= escaparCancelamento($nome_profissional) ?>
                        </strong>
                    </div>

                    <div class="cancelamento-dado">
                        <span>Data</span>
                        <strong>
                            <?= escaparCancelamento($data_formatada) ?>
                        </strong>
                    </div>

                    <div class="cancelamento-dado">
                        <span>Horário</span>
                        <strong>
                            <?= escaparCancelamento($horario_formatado) ?>
                        </strong>
                    </div>

                    <div class="cancelamento-dado">
                        <span>Valor</span>
                        <strong>
                            <?php if ($preco !== null): ?>
                                R$ <?= number_format(
                                    (float) $preco,
                                    2,
                                    ",",
                                    "."
                                ) ?>
                            <?php else: ?>
                                A confirmar
                            <?php endif; ?>
                        </strong>
                    </div>
                </div>

                <?php if ($erro !== ""): ?>
                    <!-- Informa o motivo pelo qual a operação não prosseguiu. -->
                    <div class="cancelamento-mensagem cancelamento-erro" role="alert">
                        <?= escaparCancelamento($erro) ?>
                    </div>
                <?php endif; ?>

                <?php if ($pode_cancelar): ?>

                    <!-- Envia a decisão por POST, protegida por token. -->
                    <form method="POST" action="confirmar_cancelamento.php">
                        <input
                            type="hidden"
                            name="agendamento_id"
                            value="<?= (int) $agendamento["id"] ?>"
                        >

                        <input
                            type="hidden"
                            name="token_cancelamento"
                            value="<?= escaparCancelamento(
                                $_SESSION["token_confirmar_cancelamento"]
                            ) ?>"
                        >

                        <p class="cancelamento-pergunta">
                            Deseja informar o motivo do cancelamento?
                        </p>

                        <div class="cancelamento-opcoes">
                            <label class="cancelamento-opcao">
                                <input
                                    type="radio"
                                    name="informar_motivo"
                                    value="sim"
                                    id="motivo-sim"
                                >
                                Sim
                            </label>

                            <label class="cancelamento-opcao">
                                <input
                                    type="radio"
                                    name="informar_motivo"
                                    value="nao"
                                    id="motivo-nao"
                                    checked
                                >
                                Não, prefiro não informar
                            </label>
                        </div>

                        <!-- O campo aparece somente quando a cliente escolhe Sim. -->
                        <div
                            class="cancelamento-campo"
                            id="campo-motivo"
                            hidden
                        >
                            <label for="motivo">
                                Motivo do cancelamento (opcional)
                            </label>

                            <textarea
                                name="motivo"
                                id="motivo"
                                maxlength="500"
                                placeholder="Se desejar, conte brevemente o motivo..."
                            ></textarea>

                            <p class="cancelamento-observacao">
                                O motivo é opcional. Nesta versão, ele não será
                                armazenado no banco de dados.
                            </p>
                        </div>

                        <div class="cancelamento-acoes">
                            <button
                                type="submit"
                                name="confirmar_cancelamento"
                                value="1"
                                class="cancelamento-botao cancelamento-botao-confirmar"
                            >
                                Sim, confirmar cancelamento
                            </button>

                            <a
                                href="meus_agendamentos.php"
                                class="cancelamento-botao cancelamento-botao-voltar"
                            >
                                Não, voltar
                            </a>
                        </div>
                    </form>

                <?php else: ?>

                    <!-- Impede a confirmação de um registro não cancelável. -->
                    <div class="cancelamento-indisponivel" role="status">
                        Este agendamento não pode ser cancelado pela tela
                        de confirmação. Ele pode já ter sido cancelado,
                        concluído ou ter passado do horário.
                    </div>

                    <div class="cancelamento-acoes">
                        <a
                            href="meus_agendamentos.php"
                            class="cancelamento-botao cancelamento-botao-voltar"
                        >
                            Voltar aos meus agendamentos
                        </a>
                    </div>

                <?php endif; ?>

            <?php else: ?>

                <!-- Apresenta erro quando não foi possível carregar o registro. -->
                <div class="cancelamento-mensagem cancelamento-erro" role="alert">
                    <?= escaparCancelamento(
                        $erro !== "" ? $erro : "Não foi possível localizar este agendamento."
                    ) ?>
                </div>

                <div class="cancelamento-acoes">
                    <a
                        href="meus_agendamentos.php"
                        class="cancelamento-botao cancelamento-botao-voltar"
                    >
                        Voltar aos meus agendamentos
                    </a>
                </div>

            <?php endif; ?>

        </section>
    </main>

    <!-- Controla a exibição do campo de motivo. -->
    <script>
        // Recupera os controles da escolha do motivo.
        const opcaoSim = document.getElementById("motivo-sim");
        const opcaoNao = document.getElementById("motivo-nao");
        const campoMotivo = document.getElementById("campo-motivo");

        // Exibe ou oculta o campo conforme a escolha da cliente.
        function atualizarCampoMotivo() {
            if (!opcaoSim || !opcaoNao || !campoMotivo) {
                return;
            }

            const informarMotivo = opcaoSim.checked;

            campoMotivo.hidden = !informarMotivo;
        }

        // Atualiza a tela quando a cliente altera a opção.
        if (opcaoSim && opcaoNao && campoMotivo) {
            opcaoSim.addEventListener("change", atualizarCampoMotivo);
            opcaoNao.addEventListener("change", atualizarCampoMotivo);

            atualizarCampoMotivo();
        }
    </script>

</body>
</html>
