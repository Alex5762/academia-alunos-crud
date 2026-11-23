<?php
declare(strict_types=1);

use FitControl\Domain\Classes\ClassScheduleQuery;
use FitControl\Domain\Classes\ClassScheduleService;

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Classes/ClassScheduleQuery.php';
require_once __DIR__ . '/src/Domain/Classes/ClassScheduleService.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'classes.manage');

$dias = [
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado',
    7 => 'Domingo',
];

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
$erro = '';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validarToken();

    $acao = $_POST['acao'] ?? '';
    if (!is_string($acao)) {
        http_response_code(400);
        exit('Ação inválida.');
    }

    try {
        $service = new ClassScheduleService($pdo);

        if ($acao === 'criar_horario' || $acao === 'editar_horario') {
            $modalityId = validarId($_POST['modalidade_id'] ?? '');
            $instructorReceived = $_POST['instrutor_id'] ?? '';
            $instructorId = $instructorReceived === '' ? null : validarId($instructorReceived);
            $weekdayReceived = $_POST['dia_semana'] ?? '';
            $startTime = $_POST['hora_inicio'] ?? '';
            $endTime = $_POST['hora_fim'] ?? '';

            if ($modalityId === false) {
                throw new InvalidArgumentException('Selecione uma modalidade válida.');
            }
            if ($instructorId === false) {
                throw new InvalidArgumentException('Selecione um instrutor válido.');
            }
            if (!is_string($weekdayReceived) || !in_array($weekdayReceived, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                throw new InvalidArgumentException('Selecione um dia da semana válido.');
            }
            if (!is_string($startTime) || !is_string($endTime)) {
                throw new InvalidArgumentException('Informe os horários de início e término.');
            }

            $weekday = (int) $weekdayReceived;
            if ($acao === 'criar_horario') {
                $service->create(
                    (int) $usuario['id'],
                    (int) $modalityId,
                    $instructorId === null ? null : (int) $instructorId,
                    $weekday,
                    $startTime,
                    $endTime
                );
                $_SESSION['mensagem'] = 'Horário cadastrado com sucesso.';
            } else {
                $scheduleId = validarId($_POST['id'] ?? '');
                if ($scheduleId === false) {
                    throw new InvalidArgumentException('Identificador de horário inválido.');
                }
                $service->update(
                    (int) $scheduleId,
                    (int) $usuario['id'],
                    (int) $modalityId,
                    $instructorId === null ? null : (int) $instructorId,
                    $weekday,
                    $startTime,
                    $endTime
                );
                $_SESSION['mensagem'] = 'Horário atualizado com sucesso.';
            }
        } elseif ($acao === 'inativar_horario') {
            $scheduleId = validarId($_POST['id'] ?? '');
            if ($scheduleId === false) {
                throw new InvalidArgumentException('Identificador de horário inválido.');
            }
            $service->deactivate((int) $scheduleId, (int) $usuario['id']);
            $_SESSION['mensagem'] = 'Horário inativado. O registro e a auditoria foram preservados.';
        } else {
            http_response_code(400);
            exit('Ação não permitida.');
        }

        header('Location: gerenciar-horarios.php', true, 303);
        exit;
    } catch (DomainException | InvalidArgumentException $exception) {
        $erro = $exception->getMessage();
        http_response_code(422);
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $erro = $exception->getCode() === '23000'
            ? 'Já existe um horário desta modalidade para esse dia e horário de início.'
            : 'Não foi possível salvar o horário. Consulte o log do servidor.';
        http_response_code(422);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $erro = 'Não foi possível concluir a operação. Consulte o log do servidor.';
        http_response_code(500);
    }
}

$query = new ClassScheduleQuery($pdo);
$modalidades = $query->activeModalities();
$instrutores = $query->activeInstructors();
$horarios = $query->allSchedulesForManagement();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gerencie os horários recorrentes da academia.">
    <title>Gerenciar horários | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page class-schedule-management-page">
    <a class="settings-back" href="aulas.php">← Voltar à grade de aulas</a>
    <span class="eyebrow">OPERAÇÃO DA ACADEMIA</span>
    <h1>Gerenciar horários</h1>
    <p class="settings-intro">Cadastre e mantenha a programação semanal. As alterações são auditadas e os registros inativados permanecem no histórico.</p>

    <div class="class-schedule-manage-link">
        <a class="button button-search" href="gestao-aulas.php">Gerenciar modalidades e instrutores</a>
    </div>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status"><?= escapar($mensagem) ?></div>
    <?php } ?>
    <?php if ($erro !== '') { ?>
        <div class="flash-message flash-error" role="alert"><?= escapar($erro) ?></div>
    <?php } ?>

    <section class="settings-card account-card" aria-labelledby="schedule-create-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">＋</span>
            <div>
                <h2 id="schedule-create-title">Novo horário</h2>
                <p>Escolha uma modalidade, o dia, o intervalo e um instrutor opcional.</p>
            </div>
        </div>

        <?php if ($modalidades === []) { ?>
            <p class="plan-setup-note">Cadastre uma modalidade antes de criar horários.</p>
        <?php } else { ?>
            <form method="post" class="class-schedule-create-form">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                <input type="hidden" name="acao" value="criar_horario">
                <div class="class-schedule-fields">
                    <div>
                        <label for="schedule-create-modality">Modalidade</label>
                        <select id="schedule-create-modality" name="modalidade_id" required>
                            <option value="">Selecione uma modalidade</option>
                            <?php foreach ($modalidades as $modalidade) { ?>
                                <option value="<?= (int) $modalidade['id'] ?>"><?= escapar($modalidade['nome']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div>
                        <label for="schedule-create-day">Dia da semana</label>
                        <select id="schedule-create-day" name="dia_semana" required>
                            <option value="">Selecione um dia</option>
                            <?php foreach ($dias as $dayNumber => $dayName) { ?>
                                <option value="<?= $dayNumber ?>"><?= escapar($dayName) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div>
                        <label for="schedule-create-start">Início</label>
                        <input id="schedule-create-start" name="hora_inicio" type="time" required>
                    </div>
                    <div>
                        <label for="schedule-create-end">Término</label>
                        <input id="schedule-create-end" name="hora_fim" type="time" required>
                    </div>
                    <div>
                        <label for="schedule-create-instructor">Instrutor (opcional)</label>
                        <select id="schedule-create-instructor" name="instrutor_id">
                            <option value="">Sem instrutor definido</option>
                            <?php foreach ($instrutores as $instrutor) { ?>
                                <option value="<?= (int) $instrutor['id'] ?>"><?= escapar($instrutor['nome']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="class-schedule-form-actions">
                    <button class="button button-primary" type="submit">Cadastrar horário</button>
                </div>
            </form>
        <?php } ?>
    </section>

    <section class="settings-card account-card" aria-labelledby="schedule-list-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">▤</span>
            <div>
                <h2 id="schedule-list-title">Horários cadastrados</h2>
                <p>Edite horários ativos ou inative os que não são mais oferecidos.</p>
            </div>
        </div>

        <?php if ($horarios === []) { ?>
            <p class="plan-setup-note">Nenhum horário cadastrado.</p>
        <?php } else { ?>
            <div class="class-schedule-management-list">
                <?php foreach ($horarios as $horario) {
                    $scheduleId = (int) $horario['id'];
                    $weekday = (int) $horario['dia_semana'];
                    $isActive = (int) $horario['ativo'] === 1;
                    $instructorLabel = $horario['instrutor_nome'] ?? 'Sem instrutor definido';
                    if ($horario['instrutor_nome'] !== null && (int) $horario['instrutor_ativo'] !== 1) {
                        $instructorLabel .= ' (inativo)';
                    }
                ?>
                    <article class="class-schedule-management-item">
                        <header class="class-schedule-management-heading">
                            <div>
                                <h3><?= escapar($horario['modalidade_nome']) ?></h3>
                                <p>
                                    <?= escapar($dias[$weekday] ?? 'Dia inválido') ?> ·
                                    <?= escapar(substr((string) $horario['hora_inicio'], 0, 5)) ?>–<?= escapar(substr((string) $horario['hora_fim'], 0, 5)) ?> ·
                                    <?= escapar($instructorLabel) ?>
                                </p>
                            </div>
                            <span class="class-catalog-status <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                                <?= $isActive ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </header>

                        <?php if ($isActive) { ?>
                            <form method="post" class="class-schedule-edit-form" id="schedule-edit-<?= $scheduleId ?>">
                                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                <input type="hidden" name="acao" value="editar_horario">
                                <input type="hidden" name="id" value="<?= $scheduleId ?>">

                                <div class="class-schedule-fields">
                                    <div>
                                        <label for="schedule-<?= $scheduleId ?>-modality">Modalidade</label>
                                        <select id="schedule-<?= $scheduleId ?>-modality" name="modalidade_id" required>
                                            <?php foreach ($modalidades as $modalidade) { ?>
                                                <option value="<?= (int) $modalidade['id'] ?>" <?= (int) $horario['modalidade_id'] === (int) $modalidade['id'] ? 'selected' : '' ?>>
                                                    <?= escapar($modalidade['nome']) ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="schedule-<?= $scheduleId ?>-day">Dia</label>
                                        <select id="schedule-<?= $scheduleId ?>-day" name="dia_semana" required>
                                            <?php foreach ($dias as $dayNumber => $dayName) { ?>
                                                <option value="<?= $dayNumber ?>" <?= $weekday === $dayNumber ? 'selected' : '' ?>>
                                                    <?= escapar($dayName) ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="schedule-<?= $scheduleId ?>-start">Início</label>
                                        <input id="schedule-<?= $scheduleId ?>-start" name="hora_inicio" type="time" value="<?= escapar(substr((string) $horario['hora_inicio'], 0, 5)) ?>" required>
                                    </div>
                                    <div>
                                        <label for="schedule-<?= $scheduleId ?>-end">Término</label>
                                        <input id="schedule-<?= $scheduleId ?>-end" name="hora_fim" type="time" value="<?= escapar(substr((string) $horario['hora_fim'], 0, 5)) ?>" required>
                                    </div>
                                    <div>
                                        <label for="schedule-<?= $scheduleId ?>-instructor">Instrutor (opcional)</label>
                                        <select id="schedule-<?= $scheduleId ?>-instructor" name="instrutor_id">
                                            <option value="">Sem instrutor definido</option>
                                            <?php foreach ($instrutores as $instrutor) { ?>
                                                <option value="<?= (int) $instrutor['id'] ?>" <?= (int) ($horario['instrutor_id'] ?? 0) === (int) $instrutor['id'] ? 'selected' : '' ?>>
                                                    <?= escapar($instrutor['nome']) ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="class-schedule-form-actions">
                                    <button class="button button-primary" type="submit">Salvar alterações</button>
                                </div>
                            </form>

                            <form method="post" class="class-schedule-deactivate-form">
                                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                <input type="hidden" name="acao" value="inativar_horario">
                                <input type="hidden" name="id" value="<?= $scheduleId ?>">
                                <button class="button button-search" type="submit">Inativar horário</button>
                            </form>
                        <?php } else { ?>
                            <p class="class-schedule-inactive-note">Registro preservado para histórico; não aparece na grade ativa.</p>
                        <?php } ?>
                    </article>
                <?php } ?>
            </div>
        <?php } ?>
    </section>
</main>
</body>
</html>
