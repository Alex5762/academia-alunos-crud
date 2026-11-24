<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once __DIR__ . '/src/Domain/Enrollment/LegacyReviewDecisionService.php';

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Enrollment\LegacyReviewDecisionService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'legacy.review');
validarToken();

$reviewIdRecebido = $_POST['review_id'] ?? '';
$reviewId = validarId($reviewIdRecebido);
if ($reviewId === false) {
    http_response_code(400);
    exit('Identificador de revisão inválido.');
}

$consultaRevisao = $pdo->prepare(
    'SELECT id, aluno_id_origem
     FROM revisoes_legado
     WHERE id = :review_id
     LIMIT 1'
);
$consultaRevisao->execute(['review_id' => $reviewId]);
$revisao = $consultaRevisao->fetch(PDO::FETCH_ASSOC);

if (!$revisao) {
    http_response_code(404);
    exit('Registro de revisão não encontrado.');
}

$resultado = $_POST['resultado'] ?? '';
$fonte = $_POST['fonte_referencia'] ?? '';
$observacoes = $_POST['observacoes'] ?? '';

if (!is_string($resultado) || !is_string($fonte) || !is_string($observacoes)) {
    http_response_code(400);
    exit('Dados de revisão inválidos.');
}

try {
    if (!in_array($resultado, ['VINCULO_CONFIRMADO', 'LEGADO_SEM_VIGENCIA', 'PENDENTE_REVISAO'], true)) {
        throw new InvalidArgumentException('Selecione um resultado válido.');
    }

    $fonte = trim($fonte);
    $observacoes = trim($observacoes);
    $inicioLocal = null;
    $fimExclusivoLocal = null;
    $precoComprovado = null;
    $duracaoComprovada = null;
    $unidadeComprovada = null;
    $diaReferencia = null;

    $camposComprovacao = [
        'inicio_em' => $_POST['inicio_em'] ?? '',
        'fim_exclusivo_em' => $_POST['fim_exclusivo_em'] ?? '',
        'preco_comprovado' => $_POST['preco_comprovado'] ?? '',
        'duracao_comprovada' => $_POST['duracao_comprovada'] ?? '',
        'unidade_comprovada' => $_POST['unidade_comprovada'] ?? '',
        'dia_referencia_calendario' => $_POST['dia_referencia_calendario'] ?? '',
    ];

    foreach ($camposComprovacao as $valor) {
        if (!is_string($valor)) {
            throw new InvalidArgumentException('Os campos de comprovação precisam ser textos válidos.');
        }
    }

    if ($resultado === 'VINCULO_CONFIRMADO') {
        $fusoAcademia = new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE);

        foreach (['inicio_em', 'fim_exclusivo_em'] as $campoData) {
            $valorData = trim($camposComprovacao[$campoData]);
            if ($valorData === '' || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $valorData) !== 1) {
                throw new InvalidArgumentException('Informe o início e o fim exclusivo comprovados.');
            }

            $dataLocal = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                $valorData,
                $fusoAcademia
            );
            $errosData = DateTimeImmutable::getLastErrors();

            if (
                !$dataLocal instanceof DateTimeImmutable
                || $dataLocal->format('Y-m-d\TH:i') !== $valorData
                || (is_array($errosData) && ($errosData['warning_count'] > 0 || $errosData['error_count'] > 0))
            ) {
                throw new InvalidArgumentException('Informe datas e horários locais válidos.');
            }

            if ($campoData === 'inicio_em') {
                $inicioLocal = $dataLocal;
            } else {
                $fimExclusivoLocal = $dataLocal;
            }
        }

        $precoRecebido = trim($camposComprovacao['preco_comprovado']);
        if ($precoRecebido !== '') {
            if (preg_match('/^(\d{1,8})(?:[,.](\d{2}))?$/D', $precoRecebido, $partesPreco) !== 1) {
                throw new InvalidArgumentException('Use o preço comprovado no formato 149,90 ou 149.90.');
            }

            $precoComprovado = $partesPreco[1] . '.' . ($partesPreco[2] ?? '00');
        }

        $duracaoRecebida = trim($camposComprovacao['duracao_comprovada']);
        $unidadeComprovada = trim($camposComprovacao['unidade_comprovada']);

        if ($duracaoRecebida !== '' || $unidadeComprovada !== '') {
            if ($duracaoRecebida === '' || $unidadeComprovada === '') {
                throw new InvalidArgumentException('Informe duração e unidade juntas, somente se ambas estiverem comprovadas.');
            }

            $duracaoValidada = filter_var(
                $duracaoRecebida,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 65535]]
            );
            if ($duracaoValidada === false) {
                throw new InvalidArgumentException('A duração comprovada precisa ser um inteiro positivo.');
            }
            $duracaoComprovada = (int) $duracaoValidada;
        }

        $diaRecebido = trim($camposComprovacao['dia_referencia_calendario']);
        if ($diaRecebido !== '') {
            $diaValidado = filter_var(
                $diaRecebido,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 31]]
            );
            if ($diaValidado === false) {
                throw new InvalidArgumentException('O dia de referência precisa estar entre 1 e 31.');
            }
            $diaReferencia = (int) $diaValidado;
        }
    } elseif (
        $camposComprovacao['inicio_em'] !== ''
        || $camposComprovacao['fim_exclusivo_em'] !== ''
        || $camposComprovacao['preco_comprovado'] !== ''
        || $camposComprovacao['duracao_comprovada'] !== ''
        || $camposComprovacao['unidade_comprovada'] !== ''
        || $camposComprovacao['dia_referencia_calendario'] !== ''
    ) {
        throw new InvalidArgumentException(
            'Datas, preço e duração só podem acompanhar uma confirmação de vínculo comprovado.'
        );
    }

    $service = new LegacyReviewDecisionService(
        $pdo,
        static fn (): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
        )
    );

    $enrollmentId = $service->decide(
        (int) $revisao['aluno_id_origem'],
        (int) $revisao['id'],
        (int) $usuario['id'],
        $resultado,
        $fonte === '' ? null : $fonte,
        $observacoes === '' ? null : $observacoes,
        $inicioLocal,
        $fimExclusivoLocal,
        $precoComprovado,
        $duracaoComprovada,
        $unidadeComprovada === '' ? null : $unidadeComprovada,
        $diaReferencia
    );

    $_SESSION['mensagem'] = match ($resultado) {
        'VINCULO_CONFIRMADO' => 'Vínculo confirmado. O intervalo comprovado foi preservado no histórico.',
        'LEGADO_SEM_VIGENCIA' => 'Ausência de vigência registrada com evidência. Novas matrículas estão liberadas.',
        default => 'Revisão mantida como pendente. Novas matrículas continuam bloqueadas.',
    };

    header('Location: revisoes-legado.php?filtro=todos&review_id=' . (int) $revisao['id'], true, 303);
    exit;
} catch (DomainException | InvalidArgumentException $exception) {
    $_SESSION['mensagem'] = $exception->getMessage();
    header('Location: revisoes-legado.php?filtro=todos&review_id=' . (int) $revisao['id'], true, 303);
    exit;
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Não foi possível registrar a revisão. Consulte o log do servidor.');
}
