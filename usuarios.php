<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Security/AccessControl.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'users.manage');
$usuarios = $pdo->query(
    'SELECT id, nome, email, ativo, perfil, criado_em
     FROM usuarios
     ORDER BY ativo DESC, nome ASC'
)->fetchAll();

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);

$senhaTemporaria = $_SESSION['senha_temporaria_criada'] ?? '';
unset($_SESSION['senha_temporaria_criada']);

$totalAtivos = 0;
foreach ($usuarios as $conta) {
    $totalAtivos += (int) $conta['ativo'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gerencie as contas individuais dos funcionários do FitControl.">
    <title>Funcionários | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page users-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">GESTÃO DE ACESSO</span>
    <h1>Funcionários</h1>
    <p class="settings-intro">Crie contas individuais e controle quem pode acessar o FitControl.</p>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status">
            <span class="flash-check" aria-hidden="true">✓</span>
            <?= escapar($mensagem) ?>
        </div>
    <?php } ?>

    <?php if ($senhaTemporaria !== '') { ?>
        <section class="temporary-password" aria-labelledby="temporary-password-title">
            <strong id="temporary-password-title">Senha temporária — copie agora</strong>
            <p>Entregue esta senha ao funcionário por um canal seguro. Ela não será exibida novamente e deverá ser trocada no primeiro acesso.</p>
            <code id="temporary-password"><?= escapar($senhaTemporaria) ?></code>
        </section>
    <?php } ?>

    <section class="settings-card account-card" aria-labelledby="create-account-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">＋</span>
            <div>
                <h2 id="create-account-title">Adicionar funcionário</h2>
                <p>A senha temporária será gerada automaticamente.</p>
            </div>
        </div>

        <form action="salvar-usuario.php" method="post" class="account-create-form">
            <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">

            <div>
                <label for="nome-funcionario">Nome completo</label>
                <input type="text" id="nome-funcionario" name="nome" maxlength="100"
                       autocomplete="name" required>
            </div>

            <div>
                <label for="email-funcionario">E-mail</label>
                <input type="email" id="email-funcionario" name="email" maxlength="150"
                       autocomplete="email" inputmode="email" required>
            </div>

            <button class="button button-primary" type="submit">Criar conta</button>
        </form>
    </section>

    <section class="settings-card account-card" aria-labelledby="accounts-list-title">
        <div class="settings-card-heading account-list-heading">
            <div>
                <h2 id="accounts-list-title">Contas cadastradas</h2>
                <p><?= count($usuarios) ?> conta(s) no total · <?= $totalAtivos ?> ativa(s)</p>
            </div>
        </div>

        <div class="table-scroll" tabindex="0" aria-label="Tabela de funcionários; role horizontalmente para ver todas as colunas">
            <table class="students-table accounts-table">
                <thead>
                    <tr>
                        <th scope="col">FUNCIONÁRIO</th>
                        <th scope="col">E-MAIL</th>
                        <th scope="col">CADASTRADO EM</th>
                        <th scope="col">PERFIL</th>
                        <th scope="col">STATUS</th>
                        <th scope="col">AÇÃO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $conta) {
                        $ativo = (int) $conta['ativo'] === 1;
                        $contaAtual = (int) $conta['id'] === (int) $usuario['id'];
                    ?>
                        <tr>
                            <td>
                                <div class="account-person">
                                    <strong><?= escapar($conta['nome']) ?></strong>
                                    <?php if ($contaAtual) { ?><small>Você</small><?php } ?>
                                </div>
                            </td>
                            <td><?= escapar($conta['email']) ?></td>
                            <td><?= escapar(date('d/m/Y H:i', strtotime($conta['criado_em']))) ?></td>
                            <td>
                                <?php if ($contaAtual) { ?>
                                    <span class="account-current"><?= escapar(\FitControl\Security\AccessControl::label($conta['perfil'])) ?></span>
                                <?php } else { ?>
                                    <form action="alterar-perfil-usuario.php" method="post" class="account-status-form">
                                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $conta['id'] ?>">
                                        <select name="perfil" aria-label="Perfil de <?= escapar($conta['nome']) ?>">
                                            <?php foreach (\FitControl\Security\AccessControl::roles() as $perfilDisponivel) { ?>
                                                <option value="<?= escapar($perfilDisponivel) ?>"
                                                    <?= $conta['perfil'] === $perfilDisponivel ? 'selected' : '' ?>>
                                                    <?= escapar(\FitControl\Security\AccessControl::label($perfilDisponivel)) ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                        <button class="button button-search" type="submit">Salvar perfil</button>
                                    </form>
                                <?php } ?>
                            </td>
                            <td>
                                <span class="account-status <?= $ativo ? 'is-active' : 'is-inactive' ?>">
                                    <?= $ativo ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($contaAtual) { ?>
                                    <span class="account-current">Conta atual</span>
                                <?php } else { ?>
                                    <form action="alterar-status-usuario.php" method="post" class="account-status-form">
                                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $conta['id'] ?>">
                                        <input type="hidden" name="acao" value="<?= $ativo ? 'desativar' : 'reativar' ?>">
                                        <button class="button <?= $ativo ? 'button-search' : 'button-primary' ?>"
                                                type="submit">
                                            <?= $ativo ? 'Desativar' : 'Reativar' ?>
                                        </button>
                                    </form>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="account-policy">
        Nesta versão, todas as contas ativas têm as mesmas permissões administrativas.
        Contas não são apagadas: desative o acesso para preservar o histórico de auditoria.
        Não é permitido desativar a própria conta nem a última conta ativa.
    </p>
</main>
</body>
</html>
