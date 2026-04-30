function validarCPF(cpf) {
    cpf = String(cpf).replace(/\D/g, '');
    if (cpf.length !== 11 || /(\d)\1{10}/.test(cpf)) return false;
    let soma = 0;
    for (let i = 0; i < 9; i++) soma += parseInt(cpf.charAt(i)) * (10 - i);
    let resto = soma % 11;
    let digito = resto < 2 ? 0 : 11 - resto;
    if (parseInt(cpf.charAt(9)) !== digito) return false;
    soma = 0;
    for (let i = 0; i < 10; i++) soma += parseInt(cpf.charAt(i)) * (11 - i);
    resto = soma % 11;
    digito = resto < 2 ? 0 : 11 - resto;
    return parseInt(cpf.charAt(10)) === digito;
}

function valorCaracterCNPJ(caractere) {
    if (/\d/.test(caractere)) return parseInt(caractere, 10);
    return caractere.charCodeAt(0) - 48;
}

function validarCNPJ(cnpj) {
    cnpj = String(cnpj).toUpperCase().replace(/[^0-9A-Z]/g, '');
    if (cnpj.length !== 14 || /^(.)\1{13}$/.test(cnpj)) return false;

    const pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    let soma = 0;
    for (let i = 0; i < 12; i++) soma += valorCaracterCNPJ(cnpj.charAt(i)) * pesos1[i];

    let resto = soma % 11;
    const digito1 = resto < 2 ? 0 : 11 - resto;
    if (parseInt(cnpj.charAt(12), 10) !== digito1) return false;

    const pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    soma = 0;
    for (let i = 0; i < 13; i++) soma += valorCaracterCNPJ(cnpj.charAt(i)) * pesos2[i];

    resto = soma % 11;
    const digito2 = resto < 2 ? 0 : 11 - resto;
    return parseInt(cnpj.charAt(13), 10) === digito2;
}

function validarDocumento(valor) {
    const documento = String(valor).toUpperCase().replace(/[^0-9A-Z]/g, '');
    const numeros = documento.replace(/\D/g, '');
    const ehCPF = documento.length === 11 && documento === numeros;
    return ehCPF ? validarCPF(numeros) : validarCNPJ(documento);
}
if (typeof module !== 'undefined') module.exports = { validarCPF, validarCNPJ, validarDocumento };
