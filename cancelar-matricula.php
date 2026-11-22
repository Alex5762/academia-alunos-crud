<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentStatus.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentCancellationService.php';

use FitControl\Domain\Enrollment\EnrollmentCancellationService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$usuario = exigirAutenticacao($pdo);
validarToken();

$studentId = validarId($_POST['student_id'] ?? '');
$enrollmentId = validarId($_POST['enrollment_id'] ?? '');
if ($studentId === false || $enrollmentId === false) {
    http_response_code(400);
    exit('Identificadores de aluno ou matrícula inválidos.');
}

$reasonReceived = $_POST['motivo_cancelamento'] ?? '';
if (!is_string($reasonReceived)) {
    http_response_code(400);
    exit('Motivo de cancelamento inválido.');
}

$reason = trim($reasonReceived);
$reasonLength = preg_match_all('/./us', $reason);
if ($reasonLength === false || $reasonLength > 255) {
    $_SESSION['mensagem'] = 'O motivo do cancelamento deve conter no máximo 255 caracteres válidos.';
    header('Location: detalhes-aluno.php?id=' . (int) $studentId, true, 303);
    exit;
}

try {
    $service = new EnrollmentCancellationService(
        $pdo,
        static fn (): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
        )
    );

    $service->cancel(
        (int) $studentId,
        (int) $enrollmentId,
        (int) $usuario['id'],
        $reason === '' ? null : $reason
    );

    $_SESSION['mensagem'] = 'Matrícula cancelada com sucesso. O histórico original foi preservado.';
    header('Location: detalhes-aluno.php?id=' . (int) $studentId, true, 303);
    exit;
} catch (DomainException | InvalidArgumentException $exception) {
    $_SESSION['mensagem'] = $exception->getMessage();
    header('Location: detalhes-aluno.php?id=' . (int) $studentId, true, 303);
    exit;
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Não foi possível cancelar a matrícula. Consulte o log do servidor.');
}
