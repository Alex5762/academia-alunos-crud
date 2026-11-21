<?php
// Endpoint aposentado no Bloco 02: nenhum método pode excluir fisicamente alunos.
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
exit('A exclusão física de alunos foi desativada. Use a ação de inativar no painel.');
