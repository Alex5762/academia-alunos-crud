// Conceitos usados: querySelector, addEventListener, trim, if e textContent.
document.addEventListener('DOMContentLoaded', function () {
    const formulario = document.querySelector('#form-aluno');

    if (formulario) {
        formulario.addEventListener('submit', function (evento) {
            const nome = document.querySelector('#nome').value.trim();
            const telefone = document.querySelector('#telefone').value.trim();
            const email = document.querySelector('#email');
            const plano = document.querySelector('#plano').value;
            const mensagem = document.querySelector('#mensagem-validacao');
            const erros = [];

            if (nome === '') {
                erros.push('Informe o nome do aluno.');
            }

            if (telefone === '') {
                erros.push('Informe o telefone.');
            }

            if (email.value.trim() === '' || !email.validity.valid) {
                erros.push('Informe um e-mail válido.');
            }

            if (plano === '') {
                erros.push('Selecione um plano.');
            }

            if (erros.length > 0) {
                evento.preventDefault();
                mensagem.textContent = erros.join(' ');
            } else {
                mensagem.textContent = '';
            }
        });
    }
});
