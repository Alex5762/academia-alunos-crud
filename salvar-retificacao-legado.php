<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once __DIR__ . '/src/Domain/Enrollment/LegacyEnrollmentRectificationService.php';

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Enrollment\LegacyEnrollmentRectificationService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'legacy.rectify');
validarToken();

$reviewId = validarId($_POST['review_id'] ?? '');
$enrollmentId = validarId($_POST['enrollment_id'] ?? '');

if ($reviewId === false || $enrollmentId === false) {
    http_response_code(400);
    exit('Identificadores de revisão ou matrícula inválidos.');
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

$sourceReference = $_POST['fonte_referencia'] ?? '';
$observations = $_POST['observacoes'] ?? '';
$startReceived = $_POST['inicio_em'] ?? '';
$endReceived = $_POST['fim_exclusivo_em'] ?? '';

if (
    !is_string($sourceReference)
    || !is_string($observations)
    || !is_string($startReceived)
    || !is_string($endReceived)
) {
    http_response_code(400);
    exit('Dados de retificação inválidos.');
}

function parseLegacyRectificationDateTime(string $received, string $fieldName): DateTimeImmutable
{
    $received = trim($received);

    if (
        preg_match(
            '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/D',
            $received,
            $parts
        ) !== 1
    ) {
        throw new InvalidArgumentException($fieldName . ' precisa conter uma data e hora local válidas.');
    }

    $normalized = $parts[1] . 'T' . $parts[2] . ':' . ($parts[3] ?? '00');
    $format = '!Y-m-d\TH:i:s';
    $academyTimezone = new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE);
    $dateTime = DateTimeImmutable::createFromFormat($format, $normalized, $academyTimezone);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$dateTime instanceof DateTimeImmutable
        || $dateTime->format('Y-m-d\TH:i:s') !== $normalized
        || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
    ) {
        throw new InvalidArgumentException($fieldName . ' contém uma data ou hora inexistente.');
    }

    return $dateTime;
}

try {
    $sourceReference = trim($sourceReference);
    $observations = trim($observations);

    if ($sourceReference === '') {
        throw new InvalidArgumentException('Informe a referência da nova evidência.');
    }

    if ($observations === '') {
        throw new InvalidArgumentException('Informe a justificativa da correção.');
    }

    $newStartLocal = parseLegacyRectificationDateTime($startReceived, 'O novo início');
    $newEndExclusiveLocal = parseLegacyRectificationDateTime($endReceived, 'O novo fim exclusivo');

    $service = new LegacyEnrollmentRectificationService($pdo);
    $service->rectify(
        (int) $revisao['aluno_id_origem'],
        (int) $revisao['id'],
        (int) $enrollmentId,
        (int) $usuario['id'],
        $sourceReference,
        $observations,
        $newStartLocal,
        $newEndExclusiveLocal
    );

    $_SESSION['mensagem'] = 'Retificação registrada com sucesso. O intervalo anterior e a nova evidência foram preservados no histórico.';
    header(
        'Location: retificar-vigencia-legado.php?review_id=' . (int) $revisao['id'],
        true,
        303
    );
    exit;
} catch (DomainException | InvalidArgumentException $exception) {
    $_SESSION['mensagem'] = $exception->getMessage();
    header(
        'Location: retificar-vigencia-legado.php?review_id=' . (int) $revisao['id'],
        true,
        303
    );
    exit;
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Não foi possível retificar a vigência. Consulte o log do servidor.');
}
