<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Attendance/AttendanceCheckinService.php';

use FitControl\Domain\Attendance\AttendanceCheckinService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'attendance.register');
validarToken();

$studentId = validarId($_POST['aluno_id'] ?? '');
if ($studentId === false) {
    http_response_code(400);
    exit('Identificador de aluno inválido.');
}

try {
    $service = new AttendanceCheckinService(
        $pdo,
        static fn (): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Sao_Paulo')
        )
    );
    $checkinId = $service->register((int) $studentId, (int) $usuario['id']);
    $_SESSION['mensagem'] = 'Check-in #' . $checkinId . ' registrado com sucesso.';
} catch (DomainException | InvalidArgumentException $exception) {
    $_SESSION['mensagem'] = $exception->getMessage();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Não foi possível registrar o check-in. Consulte o log do servidor.');
}

header('Location: checkins.php', true, 303);
exit;
