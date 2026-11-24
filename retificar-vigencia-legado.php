<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'legacy.rectify');
$reviewId = validarId($_GET['review_id'] ?? null);

if ($reviewId === false) {
    http_response_code(400);
    exit('Identificador de revisão inválido.');
}

function retificacaoLegadoUtcParaLocal(string $valor): DateTimeImmutable
{
    $utc = new DateTimeZone('UTC');
    $academia = new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE);
    $instante = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $valor, $utc);

    if (!$instante instanceof DateTimeImmutable) {
        throw new UnexpectedValueException('Foi encontrado um instante inválido no histórico legado.');
    }

    return $instante->setTimezone($academia);
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);

$estruturaDisponivel = true;
$colunasObrigatorias = [
    'matriculas' => ['id', 'aluno_id', 'inicio_em', 'fim_exclusivo_em', 'origem'],
    'revisoes_legado' => ['id', 'aluno_id_origem', 'resultado_atual', 'fonte_referencia'],
    'revisoes_legado_eventos' => [
        'id', 'revisao_legado_id', 'tipo_evento', 'resultado_novo',
        'matricula_id', 'inicio_novo', 'fim_exclusivo_novo',
        'fonte_referencia', 'observacoes',
    ],
];

foreach ($colunasObrigatorias as $tabela => $colunas) {
    $placeholders = [];
    $parametros = ['tabela' => $tabela];

    foreach ($colunas as $indice => $coluna) {
        $placeholder = 'coluna_' . $indice;
        $placeholders[] = ':' . $placeholder;
        $parametros[$placeholder] = $coluna;
    }

    $consultaEstrutura = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND COLUMN_NAME IN (' . implode(', ', $placeholders) . ')'
    );
    $consultaEstrutura->execute($parametros);

    if ((int) $consultaEstrutura->fetchColumn() !== count($colunas)) {
        $estruturaDisponivel = false;
        break;
    }
}

$erroPagina = null;
$revisao = null;
$matricula = null;
$eventoAtual = null;
$inicioLocal = null;
$fimExclusivoLocal = null;

if (!$estruturaDisponivel) {
    $erroPagina = 'A retificação ficará disponível depois da instalação e validação das migrations 005 e 006. Nenhum dado foi alterado.';
} else {
    $consultaRevisao = $pdo->prepare(
        'SELECT
            r.id,
            r.aluno_id_origem,
            r.plano_texto_original,
            r.resultado_atual,
            r.fonte_referencia,
            a.nome AS aluno_nome,
            a.email AS aluno_email
         FROM revisoes_legado r
         INNER JOIN alunos a ON a.id = r.aluno_id_origem
         WHERE r.id = :review_id
         LIMIT 1'
    );
    $consultaRevisao->execute(['review_id' => $reviewId]);
    $revisao = $consultaRevisao->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($revisao === null) {
        http_response_code(404);
        exit('Registro de revisão não encontrado.');
    }

    if ($revisao['resultado_atual'] !== 'VINCULO_CONFIRMADO') {
        $erroPagina = 'Somente vínculos legados confirmados podem receber retificação. Reabra a revisão se uma nova evidência colocar a vigência em dúvida.';
    } else {
        $consultaEvento = $pdo->prepare(
            'SELECT
                id,
                tipo_evento,
                resultado_novo,
                matricula_id,
                fonte_referencia,
                observacoes
             FROM revisoes_legado_eventos
             WHERE revisao_legado_id = :review_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $consultaEvento->execute(['review_id' => $reviewId]);
        $eventoAtual = $consultaEvento->fetch(PDO::FETCH_ASSOC) ?: null;

        if (
            $eventoAtual === null
            || $eventoAtual['resultado_novo'] !== 'VINCULO_CONFIRMADO'
            || (int) ($eventoAtual['matricula_id'] ?? 0) < 1
        ) {
            $erroPagina = 'A decisão mais recente não identifica uma matrícula legada retificável. Revise o histórico antes de continuar.';
        } else {
            $consultaMatricula = $pdo->prepare(
                "SELECT id, inicio_em, fim_exclusivo_em
                 FROM matriculas
                 WHERE id = :enrollment_id
                   AND aluno_id = :student_id
                   AND origem = 'LEGADO'
                 LIMIT 1"
            );
            $consultaMatricula->execute([
                'enrollment_id' => $eventoAtual['matricula_id'],
                'student_id' => $revisao['aluno_id_origem'],
            ]);
            $matricula = $consultaMatricula->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($matricula === null) {
                $erroPagina = 'A matrícula legada associada à decisão não foi encontrada. Nenhuma alteração foi realizada.';
            } else {
                $inicioLocal = retificacaoLegadoUtcParaLocal((string) $matricula['inicio_em']);
                $fimExclusivoLocal = retificacaoLegadoUtcParaLocal((string) $matricula['fim_exclusivo_em']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Corrija uma vigência legada com evidência e histórico auditável.">
    <title>Retificar vigência | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page users-page legacy-rectification-page">
    <a class="settings-back" href="revisoes-legado.php?filtro=todos&amp;review_id=<?= (int) $reviewId ?>">← Voltar à revisão</a>
    <span class="eyebrow">REGULARIZAÇÃO DE CONTRATOS</span>
    <h1>Retificar vigência legada</h1>
    <p class="settings-intro">
        Corrija somente as datas comprovadas de um vínculo legado. O intervalo anterior e a nova evidência
        permanecerão registrados no histórico, sem apagar decisões anteriores.
    </p>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status">
            <span class="flash-check" aria-hidden="true">✓</span>
            <?= escapar($mensagem) ?>
        </div>
    <?php } ?>

    <?php if ($erroPagina !== null) { ?>
        <section class="settings-card account-card">
            <h2>Retificação indisponível</h2>
            <p class="plan-setup-note"><?= escapar($erroPagina) ?></p>
            <a class="button button-search" href="revisoes-legado.php?filtro=todos&amp;review_id=<?= (int) $reviewId ?>">Voltar à revisão</a>
        </section>
    <?php } else { ?>
        <section class="settings-card account-card" aria-labelledby="legacy-rectification-context-title">
            <div class="settings-card-heading">
                <span class="settings-icon" aria-hidden="true">↺</span>
                <div>
                    <h2 id="legacy-rectification-context-title">Vínculo confirmado</h2>
                    <p>Confira a identidade do aluno e o intervalo que está sendo corrigido.</p>
                </div>
            </div>

            <div class="legacy-review-student">
                <div>
                    <strong><?= escapar($revisao['aluno_nome']) ?></strong>
                    <span><?= escapar($revisao['aluno_email']) ?> · Aluno #<?= (int) $revisao['aluno_id_origem'] ?></span>
                </div>
                <span class="account-status is-active">Vínculo confirmado</span>
            </div>

            <dl class="legacy-review-original">
                <div>
                    <dt>Texto original em alunos.plano</dt>
                    <dd><?= escapar($revisao['plano_texto_original'] !== '' ? $revisao['plano_texto_original'] : 'Sem texto original') ?></dd>
                </div>
                <div>
                    <dt>Fonte atualmente registrada</dt>
                    <dd><?= escapar($revisao['fonte_referencia'] ?: 'Nenhuma') ?></dd>
                </div>
                <div>
                    <dt>Matrícula legada</dt>
                    <dd>#<?= (int) $matricula['id'] ?></dd>
                </div>
            </dl>

            <div class="legacy-rectification-current-period">
                <strong>Intervalo atualmente registrado</strong>
                <dl>
                    <div>
                        <dt>Início</dt>
                        <dd><?= escapar($inicioLocal->format('d/m/Y H:i:s')) ?></dd>
                    </div>
                    <div>
                        <dt>Fim exclusivo</dt>
                        <dd><?= escapar($fimExclusivoLocal->format('d/m/Y H:i:s')) ?></dd>
                    </div>
                </dl>
                <small>Horário local da academia: <?= escapar(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE) ?>.</small>
            </div>
        </section>

        <section class="settings-card account-card" aria-labelledby="legacy-rectification-form-title">
            <div class="settings-card-heading">
                <div>
                    <h2 id="legacy-rectification-form-title">Nova vigência comprovada</h2>
                    <p>Informe o intervalo exato sustentado pela nova evidência.</p>
                </div>
            </div>

            <p class="legacy-review-warning">
                Esta operação altera somente início e fim exclusivo. Preço, duração, plano contratado,
                cancelamento e demais dados históricos não serão inferidos nem modificados.
                O fim exclusivo é o instante em que a vigência deixa de valer.
            </p>

            <form action="salvar-retificacao-legado.php" method="post" class="account-create-form legacy-review-form legacy-rectification-form">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                <input type="hidden" name="review_id" value="<?= (int) $revisao['id'] ?>">
                <input type="hidden" name="enrollment_id" value="<?= (int) $matricula['id'] ?>">

                <fieldset class="legacy-review-period">
                    <legend>Período corrigido</legend>
                    <div>
                        <label for="inicio-retificado">Novo início local</label>
                        <input type="datetime-local" id="inicio-retificado" name="inicio_em"
                               step="1" required value="<?= escapar($inicioLocal->format('Y-m-d\TH:i:s')) ?>">
                    </div>
                    <div>
                        <label for="fim-retificado">Novo fim exclusivo local</label>
                        <input type="datetime-local" id="fim-retificado" name="fim_exclusivo_em"
                               step="1" required value="<?= escapar($fimExclusivoLocal->format('Y-m-d\TH:i:s')) ?>">
                    </div>
                    <small class="field-hint">
                        As datas são interpretadas em <?= escapar(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE) ?>
                        e persistidas em UTC. O fim exclusivo precisa ser posterior ao início.
                    </small>
                </fieldset>

                <div class="legacy-review-wide">
                    <label for="fonte-retificacao">Referência da nova evidência</label>
                    <input type="text" id="fonte-retificacao" name="fonte_referencia"
                           maxlength="255" required
                           value="<?= escapar($eventoAtual['fonte_referencia'] ?? $revisao['fonte_referencia'] ?? '') ?>"
                           placeholder="Ex.: contrato assinado, pasta 2025, documento 18">
                </div>

                <div class="legacy-review-wide">
                    <label for="observacoes-retificacao">Justificativa da correção</label>
                    <textarea id="observacoes-retificacao" name="observacoes" rows="4"
                              maxlength="65535" required
                              placeholder="Explique qual informação estava incorreta e como a evidência sustenta as novas datas."></textarea>
                </div>

                <div class="legacy-review-form-actions">
                    <button class="button button-primary" type="submit">Registrar retificação auditada</button>
                    <a class="button button-search" href="detalhes-aluno.php?id=<?= (int) $revisao['aluno_id_origem'] ?>">Abrir perfil do aluno</a>
                    <a class="button button-search" href="revisoes-legado.php?filtro=todos&amp;review_id=<?= (int) $revisao['id'] ?>">Cancelar</a>
                </div>
            </form>
        </section>
    <?php } ?>
</main>
</body>
</html>
