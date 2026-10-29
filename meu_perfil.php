
<?php
// ============================================================
// 1. INICIAR SESSÃO, CONEXÃO E PROTEÇÃO CSRF
// ============================================================

session_start();
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// Permite acesso somente à profissional autenticada.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "profissional"
) {
    header("Location: login.php");
    exit();
}

// Guarda o identificador da profissional autenticada.
$profissional_id = (int) $_SESSION["usuario_id"];

// Cria um token de segurança para proteger os formulários.
if (empty($_SESSION["csrf_perfil"])) {
    $_SESSION["csrf_perfil"] = bin2hex(random_bytes(32));
}

// Inicializa as mensagens e os dados dos formulários.
$mensagem_sucesso = "";
$mensagem_erro = "";
$erroConsulta = false;
$modoEdicao = ($_GET["editar"] ?? "") === "1";

$chave_pix_formulario = "";
$descricao_formulario = "";
$redes_sociais_formulario = "";

$titulo_aviso_formulario = "";
$mensagem_aviso_formulario = "";

$avisos_profissional = [];
$usuario = [];

// Recupera mensagens temporárias deixadas após um redirecionamento.
if (isset($_SESSION["perfil_flash_sucesso"])) {
    $mensagem_sucesso = $_SESSION["perfil_flash_sucesso"];
    unset($_SESSION["perfil_flash_sucesso"]);
}

if (isset($_SESSION["perfil_flash_erro"])) {
    $mensagem_erro = $_SESSION["perfil_flash_erro"];
    unset($_SESSION["perfil_flash_erro"]);
}


// ============================================================
// 2. FUNÇÕES AUXILIARES
// ============================================================

// Escapa textos antes de exibi-los no HTML.
function escapar($valor)
{
    return htmlspecialchars(
        (string) ($valor ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

// Conta caracteres respeitando acentos quando possível.
function contarCaracteres($texto)
{
    return function_exists("mb_strlen")
        ? mb_strlen($texto, "UTF-8")
        : strlen($texto);
}

// Valida o token de segurança enviado pelos formulários.
function validarTokenCSRF()
{
    return isset($_POST["csrf_token"], $_SESSION["csrf_perfil"])
        && is_string($_POST["csrf_token"])
        && hash_equals(
            $_SESSION["csrf_perfil"],
            $_POST["csrf_token"]
        );
}


// ============================================================
// 3. CARREGAR DADOS DO PERFIL
// ============================================================

try {
    // Busca os dados pessoais e as informações públicas.
    $consulta = $conexao->prepare("
        SELECT
            id,
            nome,
            email,
            telefone,
            especialidade,
            chave_pix,
            descricao,
            redes_sociais
        FROM usuarios
        WHERE id = :id
          AND tipo_usuario = 'profissional'
        LIMIT 1
    ");

    $consulta->execute([
        ":id" => $profissional_id
    ]);

    $usuario = $consulta->fetch(PDO::FETCH_ASSOC);

    // Encerra o acesso se a conta não existir mais.
    if (!$usuario) {
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $parametros = session_get_cookie_params();

            setcookie(
                session_name(),
                "",
                time() - 42000,
                $parametros["path"],
                $parametros["domain"],
                $parametros["secure"],
                $parametros["httponly"]
            );
        }

        session_destroy();
        header("Location: login.php");
        exit();
    }

    // Carrega os dados atuais para preencher os formulários.
    $chave_pix_formulario = $usuario["chave_pix"] ?? "";
    $descricao_formulario = $usuario["descricao"] ?? "";
    $redes_sociais_formulario = $usuario["redes_sociais"] ?? "";

    // Atualiza os dados usados nas outras páginas do sistema.
    $_SESSION["usuario_nome"] = $usuario["nome"];
    $_SESSION["especialidade"] = $usuario["especialidade"] ?? "";

} catch (PDOException $e) {
    error_log("Erro ao carregar perfil: " . $e->getMessage());

    $mensagem_erro = "Não foi possível carregar os dados do perfil.";
    $erroConsulta = true;
}


// ============================================================
// 4. PROCESSAR FORMULÁRIOS COM PROTEÇÃO CSRF
// ============================================================

if (
    !$erroConsulta &&
    $_SERVER["REQUEST_METHOD"] === "POST"
) {
    // Nenhuma alteração é processada sem um token válido.
    if (!validarTokenCSRF()) {
        http_response_code(403);

        $mensagem_erro =
            "Sua solicitação expirou ou não pôde ser validada. Atualize a página e tente novamente.";

    } else {
        $acao = $_POST["acao"] ?? "";

        // ----------------------------------------------------
        // 4.1. SALVAR INFORMAÇÕES PESSOAIS E PÚBLICAS
        // ----------------------------------------------------
        if ($acao === "salvar") {
            $modoEdicao = true;

            $nome = trim($_POST["nome"] ?? "");
            $telefone = trim($_POST["telefone"] ?? "");
            $especialidade = trim($_POST["especialidade"] ?? "");
            $chave_pix_formulario = trim($_POST["chave_pix"] ?? "");

            // Recebe a descrição e as redes sociais públicas.
            $descricao_formulario = trim($_POST["descricao"] ?? "");
            $redes_sociais_formulario = trim($_POST["redes_sociais"] ?? "");

            $especialidadesPermitidas = [
                "lash",
                "nail",
                "eyebrow"
            ];

            try {
                // Valida os campos obrigatórios.
                if ($nome === "" || $especialidade === "") {
                    throw new Exception(
                        "Preencha o nome e selecione uma especialidade."
                    );
                }

                // Aceita somente especialidades previstas no sistema.
                if (!in_array(
                    $especialidade,
                    $especialidadesPermitidas,
                    true
                )) {
                    throw new Exception(
                        "Selecione uma especialidade válida."
                    );
                }

                // Valida os limites dos campos.
                if (
                    contarCaracteres($nome) > 150 ||
                    contarCaracteres($telefone) > 20
                ) {
                    throw new Exception(
                        "Verifique o tamanho dos dados informados."
                    );
                }

                if (contarCaracteres($chave_pix_formulario) > 255) {
                    throw new Exception(
                        "A chave Pix deve ter até 255 caracteres."
                    );
                }

                // Limita o tamanho das informações públicas.
                if (contarCaracteres($descricao_formulario) > 2000) {
                    throw new Exception(
                        "A descrição deve ter até 2000 caracteres."
                    );
                }

                if (contarCaracteres($redes_sociais_formulario) > 500) {
                    throw new Exception(
                        "As redes sociais devem ter até 500 caracteres."
                    );
                }

                // Atualiza os dados pessoais e públicos da profissional.
                $atualizacao = $conexao->prepare("
                    UPDATE usuarios
                    SET nome = :nome,
                        telefone = :telefone,
                        especialidade = :especialidade,
                        chave_pix = :chave_pix,
                        descricao = :descricao,
                        redes_sociais = :redes_sociais
                    WHERE id = :id
                      AND tipo_usuario = 'profissional'
                ");

                $atualizacao->execute([
                    ":nome" => $nome,
                    ":telefone" => $telefone,
                    ":especialidade" => $especialidade,

                    ":chave_pix" => $chave_pix_formulario !== ""
                        ? $chave_pix_formulario
                        : null,

                    ":descricao" => $descricao_formulario !== ""
                        ? $descricao_formulario
                        : null,

                    ":redes_sociais" => $redes_sociais_formulario !== ""
                        ? $redes_sociais_formulario
                        : null,

                    ":id" => $profissional_id
                ]);

                // Atualiza os dados da sessão.
                $_SESSION["usuario_nome"] = $nome;
                $_SESSION["especialidade"] = $especialidade;

                // Confirma a atualização após redirecionar.
                $_SESSION["perfil_flash_sucesso"] =
                    "Informações atualizadas com sucesso!";

                // Impede o reenvio do formulário ao atualizar a página.
                header("Location: meu_perfil.php");
                exit();

            } catch (Exception $e) {
                error_log(
                    "Erro ao atualizar perfil: " . $e->getMessage()
                );

                // Exibe mensagens de validação conhecidas.
                $mensagensPermitidas = [
                    "Preencha o nome e selecione uma especialidade.",
                    "Selecione uma especialidade válida.",
                    "Verifique o tamanho dos dados informados.",
                    "A chave Pix deve ter até 255 caracteres.",
                    "A descrição deve ter até 2000 caracteres.",
                    "As redes sociais devem ter até 500 caracteres."
                ];

                $mensagem_erro = in_array(
                    $e->getMessage(),
                    $mensagensPermitidas,
                    true
                )
                    ? $e->getMessage()
                    : "Não foi possível salvar as alterações.";

                // Preserva os dados digitados para correção.
                $usuario["nome"] = $nome;
                $usuario["telefone"] = $telefone;
                $usuario["especialidade"] = $especialidade;
                $usuario["chave_pix"] = $chave_pix_formulario;
                $usuario["descricao"] = $descricao_formulario;
                $usuario["redes_sociais"] = $redes_sociais_formulario;
            }
        }

        // ----------------------------------------------------
        // 4.2. PUBLICAR AVISO
        // ----------------------------------------------------
        elseif ($acao === "publicar_aviso") {
            $titulo_aviso_formulario = trim(
                $_POST["titulo"] ?? ""
            );

            $mensagem_aviso_formulario = trim(
                $_POST["mensagem"] ?? ""
            );

            try {
                // A mensagem é obrigatória; o título é opcional.
                if ($mensagem_aviso_formulario === "") {
                    throw new Exception(
                        "Escreva uma mensagem antes de publicar o aviso."
                    );
                }

                if (
                    contarCaracteres($titulo_aviso_formulario) > 150
                ) {
                    throw new Exception(
                        "O título deve ter até 150 caracteres."
                    );
                }

                if (
                    contarCaracteres($mensagem_aviso_formulario) > 5000
                ) {
                    throw new Exception(
                        "A mensagem deve ter até 5000 caracteres."
                    );
                }

                // Armazena NULL quando o título não foi preenchido.
                $tituloBanco = $titulo_aviso_formulario !== ""
                    ? $titulo_aviso_formulario
                    : null;

                // Vincula o aviso à profissional autenticada.
                $inserirAviso = $conexao->prepare("
                    INSERT INTO avisos_profissionais
                        (profissional_id, titulo, mensagem, ativo)
                    VALUES
                        (:profissional_id, :titulo, :mensagem, 1)
                ");

                $inserirAviso->execute([
                    ":profissional_id" => $profissional_id,
                    ":titulo" => $tituloBanco,
                    ":mensagem" => $mensagem_aviso_formulario
                ]);

                // Confirma a publicação após redirecionar.
                $_SESSION["perfil_flash_sucesso"] =
                    "Aviso publicado com sucesso!";

                header("Location: meu_perfil.php#mural-avisos");
                exit();

            } catch (Exception $e) {
                error_log(
                    "Erro ao publicar aviso: " . $e->getMessage()
                );

                $mensagensPermitidas = [
                    "Escreva uma mensagem antes de publicar o aviso.",
                    "O título deve ter até 150 caracteres.",
                    "A mensagem deve ter até 5000 caracteres."
                ];

                $mensagem_erro = in_array(
                    $e->getMessage(),
                    $mensagensPermitidas,
                    true
                )
                    ? $e->getMessage()
                    : "Não foi possível publicar o aviso. Tente novamente.";
            }
        }

        // ----------------------------------------------------
        // 4.3. DESATIVAR AVISO
        // ----------------------------------------------------
        elseif ($acao === "desativar_aviso") {
            $aviso_id = filter_var(
                $_POST["aviso_id"] ?? null,
                FILTER_VALIDATE_INT
            );

            try {
                if (!$aviso_id || $aviso_id < 1) {
                    throw new Exception("Aviso inválido.");
                }

                // Impede alterar avisos de outra profissional.
                // O registro permanece no banco para preservar o histórico.
                $desativar = $conexao->prepare("
                    UPDATE avisos_profissionais
                    SET ativo = 0
                    WHERE id = :id
                      AND profissional_id = :profissional_id
                      AND ativo = 1
                ");

                $desativar->execute([
                    ":id" => $aviso_id,
                    ":profissional_id" => $profissional_id
                ]);

                if ($desativar->rowCount() > 0) {
                    $_SESSION["perfil_flash_sucesso"] =
                        "Aviso removido do mural das clientes.";
                } else {
                    $_SESSION["perfil_flash_erro"] =
                        "O aviso não foi encontrado ou já estava desativado.";
                }

                header("Location: meu_perfil.php#mural-avisos");
                exit();

            } catch (Exception $e) {
                error_log(
                    "Erro ao desativar aviso: " . $e->getMessage()
                );

                $mensagem_erro = $e->getMessage() === "Aviso inválido."
                    ? "Aviso inválido."
                    : "Não foi possível remover o aviso.";
            }
        }
    }
}


// ============================================================
// 5. CARREGAR AVISOS DA PROFISSIONAL
// ============================================================

if (!$erroConsulta) {
    try {
        // Mostra somente os avisos pertencentes à profissional logada.
        $consultaAvisos = $conexao->prepare("
            SELECT id, titulo, mensagem, criado_em, ativo
            FROM avisos_profissionais
            WHERE profissional_id = :profissional_id
            ORDER BY criado_em DESC, id DESC
        ");

        $consultaAvisos->execute([
            ":profissional_id" => $profissional_id
        ]);

        $avisos_profissional = $consultaAvisos->fetchAll(
            PDO::FETCH_ASSOC
        );

    } catch (PDOException $e) {
        error_log(
            "Erro ao carregar mural de avisos: " . $e->getMessage()
        );

        if ($mensagem_erro === "") {
            $mensagem_erro =
                "Não foi possível carregar os avisos.";
        }
    }
}


// ============================================================
// 6. PREPARAR DADOS PARA EXIBIÇÃO
// ============================================================

// Traduz os códigos internos das especialidades.
$nomesEspecialidades = [
    "lash" => "Cílios",
    "nail" => "Unhas",
    "eyebrow" => "Sobrancelhas"
];

$especialidadeExibida = $nomesEspecialidades[
    $usuario["especialidade"] ?? ""
] ?? "Não informada";
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Meu Perfil | Estética</title>

    <!-- Mantém o CSS principal do projeto. -->
    <link rel="stylesheet" href="style.css">

</head>

<body>

<!-- ============================================================
     7. MENU LATERAL
     ============================================================ -->

<aside class="sidebar">
    <div class="logo">
        <h1>Estética</h1>

        <p>
            <?= escapar(
                $_SESSION["especialidade"] ?? "Profissional"
            ) ?>
        </p>
    </div>

    <nav class="menu">
        <a href="painel_profissional.php">Início</a>
        <a href="atendimentos.php">Atendimentos</a>
        <a href="minha_agenda.php">Minha Agenda</a>
        <a href="meus_servicos.php">Meus Serviços</a>

        <div class="separador"></div>

        <a href="meu_perfil.php" class="ativo">Meu Perfil</a>
    </nav>

    <a href="logout.php" class="btn-sair">Sair</a>
</aside>


<!-- ============================================================
     8. CONTEÚDO PRINCIPAL
     ============================================================ -->

<main class="main">
    <div class="perfil-pagina">

        <!-- Cabeçalho da página e acesso à edição. -->
        <section class="perfil-cabecalho">
            <div>
                <h2>Meu Perfil</h2>
                <p>
                    Gerencie seus dados pessoais, informações públicas
                    e dados para pagamento.
                </p>
            </div>

            <?php if (!$erroConsulta && !$modoEdicao): ?>
                <a href="meu_perfil.php?editar=1" class="perfil-botao">
                    Alterar informações pessoais
                </a>
            <?php endif; ?>
        </section>


        <!-- Mensagens de confirmação e erro. -->
        <?php if ($mensagem_sucesso !== ""): ?>
            <div class="alerta alerta-sucesso perfil-alerta">
                <?= escapar($mensagem_sucesso) ?>
            </div>
        <?php endif; ?>

        <?php if ($mensagem_erro !== ""): ?>
            <div class="alerta alerta-erro perfil-alerta">
                <?= escapar($mensagem_erro) ?>
            </div>
        <?php endif; ?>


        <?php if (!$erroConsulta): ?>

            <?php if (!$modoEdicao): ?>

                <!-- ============================================
                     VISUALIZAÇÃO DOS DADOS DO PERFIL
                     ============================================ -->

                <div class="perfil-layout">

                    <section class="perfil-cartao">
                        <h3>Informações pessoais</h3>

                        <div class="perfil-dados">
                            <div class="perfil-dado">
                                <small>Nome completo</small>
                                <p><?= escapar($usuario["nome"] ?? "") ?></p>
                            </div>

                            <div class="perfil-dado">
                                <small>E-mail cadastrado</small>
                                <p><?= escapar($usuario["email"] ?? "") ?></p>
                            </div>

                            <div class="perfil-dado">
                                <small>Telefone cadastrado</small>
                                <p>
                                    <?= trim($usuario["telefone"] ?? "") !== ""
                                        ? escapar($usuario["telefone"])
                                        : "Não informado" ?>
                                </p>
                            </div>

                            <div class="perfil-dado">
                                <small>Especialidade</small>
                                <p><?= escapar($especialidadeExibida) ?></p>
                            </div>
                        </div>

                        <div class="perfil-acoes">
                            <a href="meu_perfil.php?editar=1" class="perfil-botao">
                                Alterar informações pessoais
                            </a>
                        </div>
                    </section>


                    <!-- Informações que poderão ser vistas pelas clientes. -->
                    <section class="perfil-cartao">
                        <h3>Informações públicas do perfil</h3>

                        <div class="perfil-campo-publico">
                            <strong>Sobre mim e meu trabalho</strong>

                            <?php if (trim($usuario["descricao"] ?? "") !== ""): ?>
                                <p class="perfil-texto-publico"><?= escapar($usuario["descricao"]) ?></p>
                            <?php else: ?>
                                <p class="perfil-texto-publico">
                                    Você ainda não cadastrou uma descrição profissional.
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="perfil-campo-publico">
                            <strong>Redes sociais</strong>

                            <?php if (trim($usuario["redes_sociais"] ?? "") !== ""): ?>
                                <p class="perfil-redes-publicas"><?= escapar($usuario["redes_sociais"]) ?></p>
                            <?php else: ?>
                                <p class="perfil-texto-publico">
                                    Você ainda não cadastrou suas redes sociais.
                                </p>
                            <?php endif; ?>
                        </div>

                        <a href="meu_perfil.php?editar=1" class="perfil-botao">
                            Editar informações públicas
                        </a>
                    </section>


                    <!-- Exibe a chave Pix atualmente cadastrada. -->
                    <section class="perfil-cartao">
                        <div class="perfil-pix">
                            <h3>Dados para pagamento</h3>

                            <?php if (!empty($usuario["chave_pix"])): ?>
                                <p><strong>Chave Pix cadastrada</strong></p>

                                <p class="pix-valor">
                                    <?= escapar($usuario["chave_pix"]) ?>
                                </p>

                                <small>
                                    Esta é a chave salva atualmente no seu perfil.
                                </small>
                            <?php else: ?>
                                <p><strong>Nenhuma chave Pix cadastrada.</strong></p>

                                <small>
                                    Para cadastrar uma chave, clique em
                                    Alterar informações pessoais.
                                </small>
                            <?php endif; ?>
                        </div>
                    </section>

                </div>

            <?php else: ?>

                <!-- ============================================
                     FORMULÁRIO DE EDIÇÃO DO PERFIL
                     ============================================ -->

                <section class="perfil-cartao perfil-formulario">
                    <h3>Alterar informações pessoais</h3>

                    <p style="margin-top:-10px; margin-bottom:25px;">
                        Edite os campos desejados e salve suas alterações.
                        Seu e-mail permanece inalterado.
                    </p>

                    <form method="POST" action="meu_perfil.php?editar=1">

                        <!-- Token obrigatório para validar a solicitação. -->
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escapar($_SESSION["csrf_perfil"]) ?>"
                        >

                        <input type="hidden" name="acao" value="salvar">

                        <div class="form-grid">

                            <div class="form-grupo">
                                <label for="nome">Nome completo *</label>

                                <input
                                    type="text"
                                    id="nome"
                                    name="nome"
                                    maxlength="150"
                                    required
                                    value="<?= escapar($usuario["nome"] ?? "") ?>"
                                >
                            </div>

                            <div class="form-grupo">
                                <label for="email">E-mail cadastrado</label>

                                <input
                                    type="email"
                                    id="email"
                                    value="<?= escapar($usuario["email"] ?? "") ?>"
                                    readonly
                                    style="background:var(--cor-clara, #F8F0F1); opacity:0.85;"
                                >

                                <small>
                                    O e-mail não pode ser alterado nesta versão.
                                </small>
                            </div>

                            <div class="form-grupo">
                                <label for="telefone">Telefone</label>

                                <input
                                    type="tel"
                                    id="telefone"
                                    name="telefone"
                                    maxlength="20"
                                    value="<?= escapar($usuario["telefone"] ?? "") ?>"
                                    placeholder="(00) 00000-0000"
                                >
                            </div>

                            <div class="form-grupo">
                                <label for="especialidade">Especialidade *</label>

                                <select
                                    id="especialidade"
                                    name="especialidade"
                                    required
                                >
                                    <option value="">Selecione...</option>

                                    <option
                                        value="lash"
                                        <?= ($usuario["especialidade"] ?? "") === "lash"
                                            ? "selected"
                                            : "" ?>
                                    >
                                        Cílios
                                    </option>

                                    <option
                                        value="nail"
                                        <?= ($usuario["especialidade"] ?? "") === "nail"
                                            ? "selected"
                                            : "" ?>
                                    >
                                        Unhas
                                    </option>

                                    <option
                                        value="eyebrow"
                                        <?= ($usuario["especialidade"] ?? "") === "eyebrow"
                                            ? "selected"
                                            : "" ?>
                                    >
                                        Sobrancelhas
                                    </option>
                                </select>
                            </div>


                            <!-- Dados para pagamento. -->
                            <div class="form-grupo linha-completa">
                                <hr class="perfil-separador">

                                <h3 style="margin:18px 0 8px;">
                                    Dados para pagamento
                                </h3>

                                <label for="chave_pix">Chave Pix</label>

                                <input
                                    type="text"
                                    id="chave_pix"
                                    name="chave_pix"
                                    maxlength="255"
                                    autocomplete="off"
                                    value="<?= escapar($chave_pix_formulario) ?>"
                                    placeholder="CPF, e-mail, telefone ou chave aleatória"
                                >

                                <small>
                                    Para remover a chave cadastrada, deixe o campo
                                    vazio e salve as alterações.
                                </small>
                            </div>


                            <!-- Descrição pública da profissional. -->
                            <div class="form-grupo linha-completa">
                                <hr class="perfil-separador">

                                <h3 style="margin:18px 0 8px;">
                                    Informações públicas do perfil
                                </h3>

                                <p>
                                    Apresente seu trabalho e ajude as clientes
                                    a conhecerem sua experiência e seus serviços.
                                </p>

                                <label for="descricao">
                                    Sobre mim e meu trabalho
                                </label>

                                <textarea
                                    id="descricao"
                                    name="descricao"
                                    maxlength="2000"
                                    rows="5"
                                    placeholder="Conte sobre sua experiência, seus serviços e sua forma de trabalhar..."
                                    style="width:100%; box-sizing:border-box; padding:12px; border:1px solid #DCC7CC; border-radius:9px; font:inherit; resize:vertical;"
                                ><?= escapar($descricao_formulario) ?></textarea>

                                <small>
                                    Máximo de 2000 caracteres. Essa descrição
                                    poderá ser vista pelas clientes no seu perfil.
                                </small>
                            </div>


                            <!-- Redes sociais públicas da profissional. -->
                            <div class="form-grupo linha-completa">
                                <label for="redes_sociais">
                                    Redes sociais
                                </label>

                                <textarea
                                    id="redes_sociais"
                                    name="redes_sociais"
                                    maxlength="500"
                                    rows="3"
                                    placeholder="Ex.: Instagram: https://instagram.com/seuperfil"
                                    style="width:100%; box-sizing:border-box; padding:12px; border:1px solid #DCC7CC; border-radius:9px; font:inherit; resize:vertical;"
                                ><?= escapar($redes_sociais_formulario) ?></textarea>

                                <small>
                                    Informe os links ou nomes dos seus perfis públicos.
                                    Máximo de 500 caracteres.
                                </small>
                            </div>

                        </div>

                        <div class="perfil-acoes">
                            <button type="submit" class="perfil-botao">
                                Salvar alterações
                            </button>

                            <a
                                href="meu_perfil.php"
                                class="perfil-botao perfil-botao-secundario"
                            >
                                Cancelar
                            </a>
                        </div>
                    </form>
                </section>

            <?php endif; ?>


            <!-- ================================================
                 9. MURAL DE AVISOS DA PROFISSIONAL
                 ================================================ -->

            <section
                class="perfil-cartao"
                style="margin-top:28px;"
                id="mural-avisos"
            >
                <h3>Meu mural de avisos</h3>

                <p>
                    Publique recados que aparecerão no painel das clientes
                    junto ao seu nome. O título é opcional.
                </p>

                <!-- Formulário para publicar um novo aviso. -->
                <form
                    method="POST"
                    action="meu_perfil.php#mural-avisos"
                    class="perfil-mural-form"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escapar($_SESSION["csrf_perfil"]) ?>"
                    >

                    <input
                        type="hidden"
                        name="acao"
                        value="publicar_aviso"
                    >

                    <div>
                        <label for="titulo_aviso">Título (opcional)</label>

                        <input
                            type="text"
                            id="titulo_aviso"
                            name="titulo"
                            maxlength="150"
                            value="<?= escapar($titulo_aviso_formulario) ?>"
                            placeholder="Ex.: Aviso sobre horários"
                        >
                    </div>

                    <div>
                        <label for="mensagem_aviso">Mensagem *</label>

                        <textarea
                            id="mensagem_aviso"
                            name="mensagem"
                            maxlength="5000"
                            required
                            placeholder="Escreva um recado para suas clientes..."
                        ><?= escapar($mensagem_aviso_formulario) ?></textarea>
                    </div>

                    <div class="perfil-acoes">
                        <button type="submit" class="perfil-botao">
                            Publicar aviso
                        </button>
                    </div>
                </form>

                <hr class="perfil-separador">

                <h3>Avisos publicados</h3>

                <?php if (empty($avisos_profissional)): ?>
                    <p>Você ainda não publicou avisos para suas clientes.</p>
                <?php else: ?>

                    <div class="perfil-lista-avisos">

                        <?php foreach ($avisos_profissional as $aviso): ?>
                            <article class="perfil-aviso">

                                <!-- O título só aparece quando foi preenchido. -->
                                <?php if (trim($aviso["titulo"] ?? "") !== ""): ?>
                                    <h4><?= escapar($aviso["titulo"]) ?></h4>
                                <?php endif; ?>

                                <p class="perfil-aviso-texto"><?= escapar($aviso["mensagem"]) ?></p>

                                <div class="perfil-aviso-rodape">
                                    <span>
                                        <?php
                                        // Formata a data de publicação.
                                        $dataAviso = strtotime(
                                            $aviso["criado_em"] ?? ""
                                        );
                                        ?>

                                        Publicado em
                                        <?= $dataAviso
                                            ? date("d/m/Y H:i", $dataAviso)
                                            : "data não disponível" ?>
                                    </span>

                                    <span class="perfil-aviso-status <?= (int) $aviso["ativo"] === 1
                                        ? ""
                                        : "inativo" ?>">
                                        <?= (int) $aviso["ativo"] === 1
                                            ? "Visível para clientes"
                                            : "Inativo" ?>
                                    </span>

                                    <!-- Só permite desativar avisos ainda ativos. -->
                                    <?php if ((int) $aviso["ativo"] === 1): ?>
                                        <form
                                            method="POST"
                                            action="meu_perfil.php#mural-avisos"
                                            onsubmit="return confirm('Deseja remover este aviso do mural das clientes?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= escapar($_SESSION["csrf_perfil"]) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="acao"
                                                value="desativar_aviso"
                                            >

                                            <input
                                                type="hidden"
                                                name="aviso_id"
                                                value="<?= (int) $aviso["id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="perfil-aviso-remover"
                                            >
                                                Remover do mural
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>

                    </div>
                <?php endif; ?>
            </section>

        <?php endif; ?>

    </div>
</main>

</body>
</html>
