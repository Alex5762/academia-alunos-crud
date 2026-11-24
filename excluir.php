<?php
// A exclusão física foi aposentada no Bloco 02.
// O cadastro deve ser inativado para preservar histórico e auditoria.
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
exit('A exclusão física de alunos foi desativada. Use a ação de inativar no painel.');
