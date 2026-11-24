<?php
declare(strict_types=1);

use FitControl\Domain\Classes\ClassCatalogService;

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Classes/ClassCatalogService.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'classes.manage');

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validarToken();
    $acao = $_POST['acao'] ?? '';
    if (!is_string($acao)) {
        http_response_code(400);
        exit('Ação inválida.');
    }

    $service = new ClassCatalogService($pdo);
    try {
        if ($acao === 'criar_modalidade') {
            $name = $_POST['nome'] ?? '';
            $description = $_POST['descricao'] ?? '';
            if (!is_string($name) || !is_string($description)) {
                throw new InvalidArgumentException('Os dados da modalidade são inválidos.');
            }
            $service->createModality((int) $usuario['id'], $name, $description);
            $_SESSION['mensagem'] = 'Modalidade cadastrada com sucesso.';
        } elseif ($acao === 'editar_modalidade') {
            $id = validarId($_POST['id'] ?? '');
            $name = $_POST['nome'] ?? '';
            $description = $_POST['descricao'] ?? '';
            if ($id === false || !is_string($name) || !is_string($description)) {
                throw new InvalidArgumentException('Os dados da modalidade são inválidos.');
            }
            $service->updateModality((int) $id, (int) $usuario['id'], $name, $description);
            $_SESSION['mensagem'] = 'Modalidade atualizada com sucesso.';
        } elseif ($acao === 'inativar_modalidade') {
            $id = validarId($_POST['id'] ?? '');
            if ($id === false) {
                throw new InvalidArgumentException('Identificador de modalidade inválido.');
            }
            $service->deactivateModality((int) $id, (int) $usuario['id']);
            $_SESSION['mensagem'] = 'Modalidade inativada. O histórico foi preservado.';
        } elseif ($acao === 'criar_instrutor') {
            $name = $_POST['nome'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['telefone'] ?? '';
            $userIdReceived = $_POST['usuario_id'] ?? '';
            if (!is_string($name) || !is_string($email) || !is_string($phone) || !is_string($userIdReceived)) {
                throw new InvalidArgumentException('Os dados do instrutor são inválidos.');
            }
            $userId = $userIdReceived === '' ? null : validarId($userIdReceived);
            if ($userId === false) {
                throw new InvalidArgumentException('Conta de funcionário inválida.');
            }
            $service->createInstructor((int) $usuario['id'], $name, $email, $phone, $userId);
            $_SESSION['mensagem'] = 'Instrutor cadastrado com sucesso.';
        } elseif ($acao === 'editar_instrutor') {
            $id = validarId($_POST['id'] ?? '');
            $name = $_POST['nome'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['telefone'] ?? '';
            $userIdReceived = $_POST['usuario_id'] ?? '';
            if ($id === false || !is_string($name) || !is_string($email) || !is_string($phone) || !is_string($userIdReceived)) {
                throw new InvalidArgumentException('Os dados do instrutor são inválidos.');
            }
            $userId = $userIdReceived === '' ? null : validarId($userIdReceived);
            if ($userId === false) {
                throw new InvalidArgumentException('Conta de funcionário inválida.');
            }
            $service->updateInstructor((int) $id, (int) $usuario['id'], $name, $email, $phone, $userId);
            $_SESSION['mensagem'] = 'Instrutor atualizado com sucesso.';
        } elseif ($acao === 'inativar_instrutor') {
            $id = validarId($_POST['id'] ?? '');
            if ($id === false) {
                throw new InvalidArgumentException('Identificador de instrutor inválido.');
            }
            $service->deactivateInstructor((int) $id, (int) $usuario['id']);
            $_SESSION['mensagem'] = 'Instrutor inativado. O histórico foi preservado.';
        } else {
            http_response_code(400);
            exit('Ação não permitida.');
        }

        header('Location: gestao-aulas.php', true, 303);
        exit;
    } catch (DomainException | InvalidArgumentException $exception) {
        $erro = $exception->getMessage();
        http_response_code(422);
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $erro = 'Não foi possível salvar. Verifique se o nome ou a conta vinculada já estão em uso.';
        http_response_code(422);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $erro = 'Não foi possível concluir a operação. Consulte o log do servidor.';
        http_response_code(500);
    }
}

$modalidades = $pdo->query(
    'SELECT id, nome, descricao, ativo, criado_em, atualizado_em
     FROM modalidades ORDER BY ativo DESC, nome ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$instrutores = $pdo->query(
    'SELECT i.id, i.nome, i.email, i.telefone, i.usuario_id, i.ativo,
            u.nome AS usuario_nome, u.email AS usuario_email
     FROM instrutores i
     LEFT JOIN usuarios u ON u.id = i.usuario_id
     ORDER BY i.ativo DESC, i.nome ASC, i.id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$usuariosAtivos = $pdo->query(
    'SELECT id, nome, email FROM usuarios WHERE ativo = 1 ORDER BY nome ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gerencie modalidades e instrutores da academia.">
    <title>Gestão de aulas | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page class-catalog-page">
    <a class="settings-back" href="aulas.php">← Voltar à grade de aulas</a>
    <span class="eyebrow">ADMINISTRAÇÃO DA ACADEMIA</span>
    <h1>Modalidades e instrutores</h1>
    <p class="settings-intro">Mantenha os catálogos que alimentam a grade semanal. Inativar preserva os registros e a auditoria.</p>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status"><?= escapar($mensagem) ?></div>
    <?php } ?>
    <?php if ($erro !== '') { ?>
        <div class="flash-message flash-error" role="alert"><?= escapar($erro) ?></div>
    <?php } ?>

    <section class="settings-card account-card" aria-labelledby="modality-create-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">＋</span>
            <div>
                <h2 id="modality-create-title">Nova modalidade</h2>
                <p>Exemplos: funcional, spinning, yoga ou dança.</p>
            </div>
        </div>
        <form method="post" class="class-catalog-create-form">
            <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
            <input type="hidden" name="acao" value="criar_modalidade">
            <div>
                <label for="modality-name">Nome</label>
                <input id="modality-name" name="nome" maxlength="100" required>
            </div>
            <div>
                <label for="modality-description">Descrição (opcional)</label>
                <input id="modality-description" name="descricao" maxlength="255">
            </div>
            <button class="button button-primary" type="submit">Cadastrar modalidade</button>
        </form>
    </section>

    <section class="settings-card account-card" aria-labelledby="modality-list-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">▤</span>
            <div>
                <h2 id="modality-list-title">Modalidades cadastradas</h2>
                <p>Edite os dados ou inative modalidades que não são mais oferecidas.</p>
            </div>
        </div>
        <?php if ($modalidades === []) { ?>
            <p class="plan-setup-note">Nenhuma modalidade cadastrada.</p>
        <?php } else { ?>
            <div class="class-catalog-list">
                <?php foreach ($modalidades as $item) { ?>
                    <form method="post" class="class-catalog-row">
                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="acao" value="editar_modalidade">
                        <div class="class-catalog-fields">
                            <div>
                                <label for="modality-name-<?= (int) $item['id'] ?>">Nome</label>
                                <input id="modality-name-<?= (int) $item['id'] ?>" name="nome" maxlength="100" value="<?= escapar($item['nome']) ?>" required>
                            </div>
                            <div>
                                <label for="modality-description-<?= (int) $item['id'] ?>">Descrição</label>
                                <input id="modality-description-<?= (int) $item['id'] ?>" name="descricao" maxlength="255" value="<?= escapar($item['descricao'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="class-catalog-row-actions">
                            <span class="class-catalog-status <?= (int) $item['ativo'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                <?= (int) $item['ativo'] === 1 ? 'Ativa' : 'Inativa' ?>
                            </span>
                            <button class="button button-primary" type="submit">Salvar</button>
                        </div>
                    </form>
                    <?php if ((int) $item['ativo'] === 1) { ?>
                        <form method="post" class="class-catalog-deactivate-form">
                            <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                            <input type="hidden" name="acao" value="inativar_modalidade">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button class="button button-search" type="submit">Inativar modalidade</button>
                        </form>
                    <?php } ?>
                <?php } ?>
            </div>
        <?php } ?>
    </section>

    <section class="settings-card account-card" aria-labelledby="instructor-create-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">＋</span>
            <div>
                <h2 id="instructor-create-title">Novo instrutor</h2>
                <p>O instrutor não precisa ter uma conta no FitControl. O vínculo com funcionário é opcional.</p>
            </div>
        </div>
        <form method="post" class="class-instructor-create-form">
            <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
            <input type="hidden" name="acao" value="criar_instrutor">
            <div>
                <label for="instructor-name">Nome</label>
                <input id="instructor-name" name="nome" maxlength="100" required>
            </div>
            <div>
                <label for="instructor-email">E-mail (opcional)</label>
                <input id="instructor-email" name="email" type="email" maxlength="150">
            </div>
            <div>
                <label for="instructor-phone">Telefone (opcional)</label>
                <input id="instructor-phone" name="telefone" maxlength="20">
            </div>
            <div>
                <label for="instructor-user">Conta de funcionário (opcional)</label>
                <select id="instructor-user" name="usuario_id">
                    <option value="">Sem conta vinculada</option>
                    <?php foreach ($usuariosAtivos as $conta) { ?>
                        <option value="<?= (int) $conta['id'] ?>"><?= escapar($conta['nome'] . ' · ' . $conta['email']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <button class="button button-primary" type="submit">Cadastrar instrutor</button>
        </form>
    </section>

    <section class="settings-card account-card" aria-labelledby="instructor-list-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">▤</span>
            <div>
                <h2 id="instructor-list-title">Instrutores cadastrados</h2>
                <p>O vínculo de conta não concede permissões adicionais.</p>
            </div>
        </div>
        <?php if ($instrutores === []) { ?>
            <p class="plan-setup-note">Nenhum instrutor cadastrado.</p>
        <?php } else { ?>
            <div class="class-catalog-list">
                <?php foreach ($instrutores as $item) { ?>
                    <form method="post" class="class-instructor-row">
                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="acao" value="editar_instrutor">
                        <div class="class-instructor-fields">
                            <div>
                                <label for="instructor-name-<?= (int) $item['id'] ?>">Nome</label>
                                <input id="instructor-name-<?= (int) $item['id'] ?>" name="nome" maxlength="100" value="<?= escapar($item['nome']) ?>" required>
                            </div>
                            <div>
                                <label for="instructor-email-<?= (int) $item['id'] ?>">E-mail</label>
                                <input id="instructor-email-<?= (int) $item['id'] ?>" name="email" type="email" maxlength="150" value="<?= escapar($item['email'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="instructor-phone-<?= (int) $item['id'] ?>">Telefone</label>
                                <input id="instructor-phone-<?= (int) $item['id'] ?>" name="telefone" maxlength="20" value="<?= escapar($item['telefone'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="instructor-user-<?= (int) $item['id'] ?>">Conta vinculada</label>
                                <select id="instructor-user-<?= (int) $item['id'] ?>" name="usuario_id">
                                    <option value="">Sem conta vinculada</option>
                                    <?php foreach ($usuariosAtivos as $conta) { ?>
                                        <option value="<?= (int) $conta['id'] ?>" <?= (int) ($item['usuario_id'] ?? 0) === (int) $conta['id'] ? 'selected' : '' ?>>
                                            <?= escapar($conta['nome'] . ' · ' . $conta['email']) ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="class-catalog-row-actions">
                            <span class="class-catalog-status <?= (int) $item['ativo'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                <?= (int) $item['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                            </span>
                            <button class="button button-primary" type="submit">Salvar</button>
                        </div>
                    </form>
                    <?php if ((int) $item['ativo'] === 1) { ?>
                        <form method="post" class="class-catalog-deactivate-form">
                            <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                            <input type="hidden" name="acao" value="inativar_instrutor">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button class="button button-search" type="submit">Inativar instrutor</button>
                        </form>
                    <?php } ?>
                <?php } ?>
            </div>
        <?php } ?>
    </section>
</main>
</body>
</html>
