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

            const telefoneTemFormatoValido = /^\+?[0-9\s().-]+$/.test(telefone);
            const quantidadeDigitosTelefone = telefone.replace(/\D/g, '').length;

            if (nome === '') {
                erros.push('Informe o nome do aluno.');
            }

            if (
                !telefoneTemFormatoValido ||
                quantidadeDigitosTelefone < 10 ||
                quantidadeDigitosTelefone > 13 ||
                telefone.length > 20
            ) {
                erros.push('Informe um telefone com DDD e 10 a 13 dígitos.');
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
