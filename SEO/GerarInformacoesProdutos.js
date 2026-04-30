#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const selectorsDir = path.join(repoRoot, 'PaginasConfiguradoresSeletores');
const produtosPaginaPath = path.join(repoRoot, 'PaginasPrincipal', 'PaginaProdutos.html');
const fontePath = path.join(repoRoot, 'PaginasConfiguradores', 'InformacoesProdutosFonte.json');
const outputPath = path.join(repoRoot, 'PaginasConfiguradores', 'InformacoesProdutos.json');

const readFile = (filePath) => fs.readFileSync(filePath, 'utf8');

const normalizarTexto = (valor) => {
  if (!valor) return '';
  return String(valor).replace(/\s+/g, ' ').trim();
};

const extrairAtributo = (tag, atributo) => {
  const regex = new RegExp(`${atributo}\\s*=\\s*(["'])(.*?)\\1`, 'i');
  const match = tag.match(regex);
  return match ? match[2] : '';
};

const carregarMetasDosSeletores = () => {
  const arquivos = fs.readdirSync(selectorsDir)
    .filter((file) => /^SeletoresConfigurador.*\.html$/i.test(file));

  const metas = {};

  arquivos.forEach((arquivo) => {
    const conteudo = readFile(path.join(selectorsDir, arquivo));
    const tags = conteudo.match(/<div\b(?:(?:"[^"]*"|'[^']*'|[^'">])*)>/gi) || [];
    tags.forEach((tag) => {
      if (!/class\s*=\s*(["'])[^"']*\bgaleria-produto\b[^"']*\1/i.test(tag)) {
        return;
      }
      const linha = extrairAtributo(tag, 'data-visible');
      if (!linha) return;
      const title = normalizarTexto(extrairAtributo(tag, 'data-title'));
      const description = normalizarTexto(extrairAtributo(tag, 'data-description'));
      metas[linha] = {
        title,
        description,
      };
    });
  });

  return metas;
};

const carregarLinhasPaginaProdutos = () => {
  const conteudo = readFile(produtosPaginaPath);
  const regex = /href=["'][^"']*[?&][^=]*LN=([^"'&]+)["']/gi;
  const linhas = new Set();
  let match;
  while ((match = regex.exec(conteudo)) !== null) {
    const linha = decodeURIComponent(match[1]);
    if (linha) {
      linhas.add(linha);
    }
  }
  return Array.from(linhas);
};

const carregarFonteCategorias = () => {
  if (!fs.existsSync(fontePath)) {
    throw new Error(`Arquivo de fonte não encontrado: ${fontePath}`);
  }
  const json = JSON.parse(readFile(fontePath));
  return json && typeof json === 'object' ? json : {};
};

const validarEntrada = (linhasEsperadas, fonteCategorias) => {
  const linhasFonte = Object.keys(fonteCategorias);

  const faltantes = linhasEsperadas.filter((linha) => !linhasFonte.includes(linha));
  if (faltantes.length) {
    throw new Error(`Linhas sem definição em InformacoesProdutosFonte.json: ${faltantes.join(', ')}`);
  }

  const extras = linhasFonte.filter((linha) => !linhasEsperadas.includes(linha));
  if (extras.length) {
    throw new Error(`Linhas em InformacoesProdutosFonte.json que não estão em PaginaProdutos.html: ${extras.join(', ')}`);
  }
};

const validarSaida = (metadata) => {
  const erros = [];
  Object.entries(metadata).forEach(([linha, info]) => {
    if (!info || typeof info !== 'object') {
      erros.push(`${linha}: metadata inválida`);
      return;
    }
    if (typeof info.title !== 'string') {
      erros.push(`${linha}: title inválido`);
    }
    if (typeof info.description !== 'string') {
      erros.push(`${linha}: description inválida`);
    }
    if (!Array.isArray(info.categories)) {
      erros.push(`${linha}: categories inválido`);
    }
    if (!Array.isArray(info.groups)) {
      erros.push(`${linha}: groups inválido`);
    }
  });

  if (erros.length) {
    throw new Error(`Falha na validação das chaves esperadas:\n- ${erros.join('\n- ')}`);
  }
};

const gerar = () => {
  const metasSeletores = carregarMetasDosSeletores();
  const linhasEsperadas = carregarLinhasPaginaProdutos();
  const fonteCategorias = carregarFonteCategorias();

  validarEntrada(linhasEsperadas, fonteCategorias);

  const metadata = {};

  linhasEsperadas
    .sort((a, b) => a.localeCompare(b, 'pt-BR'))
    .forEach((linha) => {
      const infoFonte = fonteCategorias[linha] || {};
      const infoSelector = metasSeletores[linha] || {};

      metadata[linha] = {
        title: infoSelector.title || linha,
        description: infoSelector.description || '',
        categories: Array.isArray(infoFonte.categories) ? infoFonte.categories : [],
        groups: Array.isArray(infoFonte.groups) ? infoFonte.groups : [],
      };
    });

  validarSaida(metadata);

  fs.writeFileSync(outputPath, `${JSON.stringify(metadata, null, 2)}\n`, 'utf8');
  return metadata;
};

try {
  gerar();
  console.log('InformacoesProdutos.json gerado com sucesso.');
} catch (error) {
  console.error(error.message || error);
  process.exitCode = 1;
}
