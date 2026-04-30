#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

function parseArgs(argv) {
  const args = { minScore: 60, top: 30 };
  for (let i = 2; i < argv.length; i += 1) {
    const current = argv[i];
    if (!current.startsWith('--')) continue;
    const [rawKey, rawValue] = current.split('=');
    const key = rawKey.replace(/^--/, '');
    const next = rawValue != null ? rawValue : argv[i + 1];

    if (rawValue == null && next && !next.startsWith('--')) i += 1;

    if (key === 'input') args.input = next;
    else if (key === 'output') args.output = next;
    else if (key === 'competitor') args.competitor = next;
    else if (key === 'gap-output') args.gapOutput = next;
    else if (key === 'min-score') args.minScore = Number(next || 60);
    else if (key === 'top') args.top = Number(next || 30);
  }
  return args;
}

function normalizeHeader(header) {
  return String(header || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

function splitCsvLine(line, delimiter) {
  const values = [];
  let value = '';
  let inQuotes = false;

  for (let i = 0; i < line.length; i += 1) {
    const ch = line[i];
    if (ch === '"') {
      const next = line[i + 1];
      if (inQuotes && next === '"') {
        value += '"';
        i += 1;
      } else {
        inQuotes = !inQuotes;
      }
    } else if (ch === delimiter && !inQuotes) {
      values.push(value);
      value = '';
    } else {
      value += ch;
    }
  }
  values.push(value);
  return values.map((v) => v.trim());
}

function parseCsv(text) {
  const lines = text.split(/\r?\n/).filter(Boolean);
  if (!lines.length) return [];

  const delimiter = lines[0].includes(';') ? ';' : ',';
  const headers = splitCsvLine(lines[0], delimiter).map(normalizeHeader);

  return lines.slice(1).map((line) => {
    const cols = splitCsvLine(line, delimiter);
    const row = {};
    headers.forEach((h, idx) => {
      row[h] = cols[idx] || '';
    });
    return row;
  });
}

function pickField(row, aliases) {
  for (const key of aliases) {
    if (row[key]) return row[key];
  }
  return '';
}

function getDomain(url) {
  if (!url) return '';
  try {
    return new URL(url).hostname.replace(/^www\./, '').toLowerCase();
  } catch {
    return String(url)
      .replace(/^https?:\/\//i, '')
      .split('/')[0]
      .replace(/^www\./, '')
      .toLowerCase();
  }
}

function scoreBacklink({ domain, anchor, sourceUrl, targetUrl }) {
  let score = 50;

  if (domain && !domain.includes('blogspot') && !domain.includes('wordpress.com')) score += 8;
  if (domain.includes('.org') || domain.includes('.edu') || domain.includes('.gov')) score += 10;

  const anchorNorm = String(anchor || '').toLowerCase();
  if (!anchorNorm || anchorNorm.length < 2) score -= 8;
  if (/clique aqui|saiba mais|aqui/.test(anchorNorm)) score -= 6;
  if (/redutores|configurador|ibr/.test(anchorNorm)) score += 8;

  const sourceNorm = String(sourceUrl || '').toLowerCase();
  if (/forum|comments?|profile|tag\//.test(sourceNorm)) score -= 12;

  const targetNorm = String(targetUrl || '').toLowerCase();
  if (/paginasprincipal|paginasconfiguradores|consulta|configurador/.test(targetNorm)) score += 6;

  return Math.max(0, Math.min(100, score));
}

function classify(score) {
  if (score >= 75) return 'alta';
  if (score >= 55) return 'media';
  return 'baixa';
}

function toCsv(rows, headers) {
  const escape = (value) => {
    const text = String(value ?? '');
    if (/[",;\n]/.test(text)) return `"${text.replace(/"/g, '""')}"`;
    return text;
  };
  const out = [headers.join(';')];
  rows.forEach((row) => {
    out.push(headers.map((h) => escape(row[h])).join(';'));
  });
  return `${out.join('\n')}\n`;
}

function main() {
  const args = parseArgs(process.argv);
  if (!args.input) {
    console.error('Uso: node SEO/AuditarBacklinksBing.js --input arquivo.csv [--output SEO/BacklinksPriorizados.csv] [--competitor concorrente.csv] [--gap-output SEO/BacklinksGapConcorrente.csv] [--min-score 60] [--top 30]');
    process.exit(1);
  }

  const inputPath = path.resolve(args.input);
  if (!fs.existsSync(inputPath)) {
    console.error(`Arquivo não encontrado: ${inputPath}`);
    process.exit(1);
  }

  const csv = fs.readFileSync(inputPath, 'utf8');
  const data = parseCsv(csv);

  const analyzed = data.map((row) => {
    const sourceUrl = pickField(row, ['source page', 'source url', 'pagina de origem', 'url de origem', 'origin page']);
    const targetUrl = pickField(row, ['target page', 'target url', 'pagina de destino', 'url de destino', 'destination page']);
    const anchor = pickField(row, ['anchor text', 'texto ancora', 'anchor']);

    const domain = getDomain(sourceUrl || pickField(row, ['domain', 'dominio']));
    const score = scoreBacklink({ domain, anchor, sourceUrl, targetUrl });

    return {
      dominio: domain,
      origem: sourceUrl,
      destino: targetUrl,
      ancora: anchor,
      score,
      qualidade: classify(score),
      acao: score >= args.minScore
        ? 'manter e tentar nova menção editorial'
        : 'revisar domínio e substituir por oportunidade qualificada'
    };
  });

  const prioritized = analyzed
    .filter((row) => row.score >= args.minScore)
    .sort((a, b) => b.score - a.score)
    .slice(0, args.top);

  const outputPath = path.resolve(args.output || 'SEO/BacklinksPriorizados.csv');
  fs.writeFileSync(
    outputPath,
    toCsv(prioritized, ['dominio', 'origem', 'destino', 'ancora', 'score', 'qualidade', 'acao']),
    'utf8'
  );

  const summary = analyzed.reduce((acc, row) => {
    acc[row.qualidade] = (acc[row.qualidade] || 0) + 1;
    return acc;
  }, { alta: 0, media: 0, baixa: 0 });

  let gapCount = 0;
  if (args.competitor) {
    const competitorPath = path.resolve(args.competitor);
    if (!fs.existsSync(competitorPath)) {
      console.error(`Arquivo de concorrente não encontrado: ${competitorPath}`);
      process.exit(1);
    }

    const competitorCsv = fs.readFileSync(competitorPath, 'utf8');
    const competitorData = parseCsv(competitorCsv);
    const myDomains = new Set(analyzed.map((row) => row.dominio).filter(Boolean));
    const competitorDomains = new Set();

    competitorData.forEach((row) => {
      const sourceUrl = pickField(row, ['source page', 'source url', 'pagina de origem', 'url de origem', 'origin page']);
      const domain = getDomain(sourceUrl || pickField(row, ['domain', 'dominio']));
      if (domain) competitorDomains.add(domain);
    });

    const gaps = Array.from(competitorDomains)
      .filter((domain) => !myDomains.has(domain))
      .sort()
      .map((domain) => ({
        dominio: domain,
        acao: 'prospectar contato editorial e parceria de conteúdo'
      }));

    gapCount = gaps.length;
    const gapOutputPath = path.resolve(args.gapOutput || 'SEO/BacklinksGapConcorrente.csv');
    fs.writeFileSync(gapOutputPath, toCsv(gaps, ['dominio', 'acao']), 'utf8');
    console.log(`Gap de domínios com concorrente gerado em: ${gapOutputPath}`);
  }

  console.log(`Backlinks analisados: ${analyzed.length}`);
  console.log(`Alta: ${summary.alta} | Média: ${summary.media} | Baixa: ${summary.baixa}`);
  console.log(`Lista priorizada gerada em: ${outputPath}`);
  if (args.competitor) console.log(`Domínios de gap identificados: ${gapCount}`);
}

main();
