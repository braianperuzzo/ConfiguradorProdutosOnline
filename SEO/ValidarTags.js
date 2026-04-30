const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const manualPath = path.join(rootDir, 'SEO', 'ManualTags.md');

const FILE_EXTENSIONS = new Set(['.html', '.js', '.php']);
const IGNORE_DIRS = new Set(['node_modules', '.git']);

const BUSINESS_EVENT_REQUIRED_PARAMS = {
  view_item: ['item_id', 'item_name', 'item_category', 'line_code'],
  add_to_cart: ['item_id', 'item_name', 'item_category', 'line_code', 'value', 'currency'],
  begin_checkout: ['quote_id', 'value', 'currency'],
  generate_lead: ['quote_id', 'value', 'currency'],
  purchase: ['quote_id', 'value', 'currency'],
  page_view: ['page_location', 'page_referrer', 'page_title']
};

function extractInlinePayloadKeys(payloadSource) {
  if (!payloadSource || typeof payloadSource !== 'string') return new Set();
  const keys = new Set();
  const keyRegex = /([a-zA-Z_][a-zA-Z0-9_]*)\s*:/g;
  let match;
  while ((match = keyRegex.exec(payloadSource)) !== null) {
    keys.add(match[1]);
  }
  return keys;
}

function extractBusinessContractViolations(content, filePath) {
  const violations = [];
  const callRegex = /(dispatchOrQueueEvent|sendEvent|gtag)\s*\(\s*["'`]([^"'`]+)["'`]\s*,\s*["'`]([^"'`]+)["'`]\s*,\s*(\{[\s\S]*?\})\s*\)|(?:dispatchOrQueueEvent|sendEvent)\s*\(\s*["'`]([^"'`]+)["'`]\s*,\s*(\{[\s\S]*?\})\s*\)/g;
  let match;
  while ((match = callRegex.exec(content)) !== null) {
    const gtagEvent = match[1] === 'gtag' && match[2] === 'event' ? (match[3] || '').trim().toLowerCase() : '';
    const eventName = (gtagEvent || match[5] || '').trim().toLowerCase();
    const payloadSource = match[4] || match[6] || '';
    if (!eventName || !payloadSource) continue;
    const required = BUSINESS_EVENT_REQUIRED_PARAMS[eventName];
    if (!required || !required.length) continue;
    const keys = extractInlinePayloadKeys(payloadSource);
    const missing = required.filter((key) => !keys.has(key));
    if (!missing.length) continue;
    violations.push({ file: filePath, event: eventName, missing });
  }
  return violations;
}

function walk(dir, files = []) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    if (IGNORE_DIRS.has(entry.name)) continue;
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(fullPath, files);
      continue;
    }
    if (!FILE_EXTENSIONS.has(path.extname(entry.name))) continue;
    if (fullPath === manualPath) continue;
    files.push(fullPath);
  }
  return files;
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&');
}

function isValidEventName(value) {
  return /^[a-z0-9][a-z0-9_-]*$/.test(value);
}

function parseManualTags(content) {
  const documented = new Set();
  const patterns = [];
  const patternLabels = [];
  const placeholderPatterns = new Map([
    ['<linha>', '[a-z0-9.]+'],
    ['<provedor>', '[a-z0-9-]+']
  ]);
  const ignoredTokens = new Set([
    'event_label',
    'event_category',
    'event_name',
    'flow_context',
    'data-gtag-event',
    'data-gtag-label',
    'item_id',
    'item_name',
    'item_category',
    'line_code',
    'quote_id',
    'value',
    'currency',
    'search_term',
    'method',
    'page_location',
    'page_referrer',
    'page_title'
  ]);
  const patternLabelSet = new Set();
  const lines = content.split(/\r?\n/);

  for (const line of lines) {
    const isTableLine = line.includes('|');
    const tagRegex = /`([^`]+)`/g;
    const candidates = [];

    if (isTableLine) {
      const columns = line.split('|').map((column) => column.trim());
      const usableColumns = columns.slice(1, columns.length - 1);
      candidates.push(...usableColumns);
    } else {
      candidates.push(line);
    }

    for (const candidate of candidates) {
      let match;
      while ((match = tagRegex.exec(candidate)) !== null) {
        const token = match[1].trim();
        if (!token) continue;
        const lower = token.toLowerCase();
        const placeholders = lower.match(/<[^>]+>/g) || [];
        const hasAllowedPlaceholder = placeholders.some((value) => placeholderPatterns.has(value));
        const hasOtherPlaceholder = placeholders.some((value) => !placeholderPatterns.has(value));

        if (!isTableLine && !hasAllowedPlaceholder) continue;
        if (hasOtherPlaceholder) continue;
        if (hasAllowedPlaceholder && lower.includes('<acao>')) continue;
        if (ignoredTokens.has(lower)) continue;

        if (hasAllowedPlaceholder) {
          let regexSource = escapeRegex(lower);
          placeholderPatterns.forEach((pattern, placeholder) => {
            regexSource = regexSource.replace(new RegExp(escapeRegex(placeholder), 'g'), pattern);
          });
          const regex = new RegExp(`^${regexSource}$`, 'i');
          if (!patternLabelSet.has(lower)) {
            patterns.push(regex);
            patternLabels.push(lower);
            patternLabelSet.add(lower);
          }
          continue;
        }

        if (!isValidEventName(lower)) continue;
        documented.add(lower);
      }
    }
  }

  return { documented, patterns, patternLabels };
}

function extractEvents(content) {
  const events = [];
  const patternLabels = [];

  const dataAttrRegex = /data-gtag-event\s*=\s*(["'])([^"']+)\1/g;
  let match;
  while ((match = dataAttrRegex.exec(content)) !== null) {
    const value = match[2].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const sendEventRegex = /sendEvent\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = sendEventRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const dispatchEventRegex = /dispatchOrQueueEvent\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = dispatchEventRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const dispararTagRegex = /dispararTagGtag\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = dispararTagRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const dispararBuscaRegex = /dispararTagBusca\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = dispararBuscaRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const trackFlowRegex = /trackFlowEvent\s*\(\s*["'`]([^"'`]+)["'`]\s*,\s*["'`]([^"'`]+)["'`]/g;
  while ((match = trackFlowRegex.exec(content)) !== null) {
    const flow = match[1].trim();
    const step = match[2].trim();
    if (!isValidEventName(flow) || !isValidEventName(step)) continue;
    events.push(`${flow.toLowerCase()}_${step.toLowerCase()}`);
  }

  const gtagEventRegex = /gtag\s*\(\s*['"]event['"]\s*,\s*['"]([^'"]+)['"]/g;
  while ((match = gtagEventRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const gtagEventTernaryRegex = /gtag\s*\(\s*['"]event['"]\s*,\s*[^?]+?\?\s*["'`]([^"'`]+)["'`]\s*:\s*["'`]([^"'`]+)["'`]/g;
  while ((match = gtagEventTernaryRegex.exec(content)) !== null) {
    const first = match[1].trim();
    const second = match[2].trim();
    [first, second].forEach((value) => {
      if (!isValidEventName(value)) return;
      events.push(value.toLowerCase());
    });
  }

  const gtagEventTemplateRegex = /gtag\s*\(\s*['"]event['"]\s*,\s*`([^`]+)`/g;
  while ((match = gtagEventTemplateRegex.exec(content)) !== null) {
    const template = match[1];
    if (!template.includes('${')) continue;
    const normalized = template.replace(/\$\{[^}]+\}/g, '<provedor>');
    if (!isValidEventName(normalized.replace(/<[^>]+>/g, ''))) continue;
    patternLabels.push(normalized.toLowerCase());
  }

  const enviarEventoRegex = /enviarEventoGtag\s*\(([^)]*)\)/g;
  while ((match = enviarEventoRegex.exec(content)) !== null) {
    const args = match[1];
    const literalRegex = /["'`]([^"'`]+)["'`]/g;
    let literalMatch;
    while ((literalMatch = literalRegex.exec(args)) !== null) {
      const value = literalMatch[1].trim();
      if (!isValidEventName(value)) continue;
      events.push(value.toLowerCase());
    }
  }

  const registrarEventoBuscaRegex = /registrarEventoBusca\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = registrarEventoBuscaRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const tagEventRegex = /tagEvent\s*:\s*["'`]([^"'`]+)["'`]/g;
  while ((match = tagEventRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const eventNameAssignmentRegex = /\b\w*(?:EventName|Evento)\b\s*=\s*([^;]+)/g;
  while ((match = eventNameAssignmentRegex.exec(content)) !== null) {
    const assignment = match[1];
    const literalRegex = /["'`]([^"'`]+)["'`]/g;
    let literalMatch;
    while ((literalMatch = literalRegex.exec(assignment)) !== null) {
      const value = literalMatch[1].trim().toLowerCase();
      if (!isValidEventName(value)) continue;
      if (value === 'produto-site' || value === 'produto-catalogo') {
        patternLabels.push(`seletor-<linha>-${value}`);
        continue;
      }
      events.push(value);
    }
  }

  const eventoTernaryRegex = /\bevento\b\s*=\s*[^;]*\?\s*["'`]([^"'`]+)["'`]\s*:\s*["'`]([^"'`]+)["'`]/g;
  while ((match = eventoTernaryRegex.exec(content)) !== null) {
    const first = match[1].trim();
    const second = match[2].trim();
    [first, second].forEach((value) => {
      if (!isValidEventName(value)) return;
      events.push(value.toLowerCase());
    });
  }

  const dataTagRegex = /data-tag-(?:event|show|hide)\s*=\s*(["'`])([^"'`]+)\1/g;
  while ((match = dataTagRegex.exec(content)) !== null) {
    const value = match[2].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const selectorEventRegex = /sendSelectorEvent\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = selectorEventRegex.exec(content)) !== null) {
    const suffix = match[1].trim().toLowerCase();
    if (!isValidEventName(suffix)) continue;
    patternLabels.push(`seletor-<linha>-${suffix}`);
  }

  const dispararTagSeletorConcorrenteRegex = /dispararTagSeletorConcorrente\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = dispararTagSeletorConcorrenteRegex.exec(content)) !== null) {
    const suffix = match[1].trim().toLowerCase();
    if (!isValidEventName(suffix)) continue;
    patternLabels.push(`seletor-<linha>-${suffix}`);
  }

  const dispatchIntercambialidadeTagRegex = /dispatchIntercambialidadeTag\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = dispatchIntercambialidadeTagRegex.exec(content)) !== null) {
    const suffix = match[1].trim().toLowerCase();
    if (!isValidEventName(suffix)) continue;
    patternLabels.push(`seletor-<linha>-${suffix}`);
  }

  const configuradorEventRegex = /sendConfiguradorEvent\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = configuradorEventRegex.exec(content)) !== null) {
    const action = match[1].trim().toLowerCase();
    if (!isValidEventName(action)) continue;
    patternLabels.push(`${action}-configurador-<linha>`);
  }

  const registrarTagSeletorRegex = /registrarTagSeletor\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = registrarTagSeletorRegex.exec(content)) !== null) {
    const suffix = match[1].trim().toLowerCase();
    if (!isValidEventName(suffix)) continue;
    patternLabels.push(`seletor-<linha>-${suffix}`);
    patternLabels.push(`${suffix}-configurador-<linha>`);
  }

  const registrarTagSeletorTernaryRegex = /registrarTagSeletor\s*\(\s*[^?]+?\?\s*["'`]([^"'`]+)["'`]\s*:\s*["'`]([^"'`]+)["'`]/g;
  while ((match = registrarTagSeletorTernaryRegex.exec(content)) !== null) {
    const first = match[1].trim().toLowerCase();
    const second = match[2].trim().toLowerCase();
    [first, second].forEach((suffix) => {
      if (!isValidEventName(suffix)) return;
      patternLabels.push(`seletor-<linha>-${suffix}`);
      patternLabels.push(`${suffix}-configurador-<linha>`);
    });
  }

  const selectorPrefixRegex = /selectorTagPrefix\s*\+\s*["'`]([^"'`]+)["'`]/g;
  while ((match = selectorPrefixRegex.exec(content)) !== null) {
    const suffix = match[1].trim().toLowerCase();
    if (!isValidEventName(suffix)) continue;
    patternLabels.push(`seletor-<linha>-${suffix}`);
  }

  const eventoBaseRegex = /eventoBase\s*=\s*([^;]+)/g;
  while ((match = eventoBaseRegex.exec(content)) !== null) {
    const assignment = match[1];
    const literalRegex = /["'`]([^"'`]+)["'`]/g;
    let literalMatch;
    while ((literalMatch = literalRegex.exec(assignment)) !== null) {
      const suffix = literalMatch[1].trim().toLowerCase();
      if (!isValidEventName(suffix)) continue;
      patternLabels.push(`seletor-<linha>-${suffix}`);
      patternLabels.push(`${suffix}-configurador-<linha>`);
    }
  }

  if (content.includes('carrinho-acessorios-') && content.includes('acaoTag')) {
    const acaoTagRegex = /acaoTag\s*=\s*[^;]+/g;
    while ((match = acaoTagRegex.exec(content)) !== null) {
      const assignment = match[0];
      const literalRegex = /["'`]([^"'`]+)["'`]/g;
      let literalMatch;
      while ((literalMatch = literalRegex.exec(assignment)) !== null) {
        const value = literalMatch[1].trim().toLowerCase();
        if (!isValidEventName(value)) continue;
        patternLabels.push(`carrinho-acessorios-${value}-<linha>`);
      }
    }
  }

  const fluxoStepsRegex = /steps\s*:\s*{([\s\S]*?)}/g;
  while ((match = fluxoStepsRegex.exec(content)) !== null) {
    const block = match[1];
    const literalRegex = /["'`]([^"'`]+)["'`]/g;
    let literalMatch;
    while ((literalMatch = literalRegex.exec(block)) !== null) {
      const value = literalMatch[1].trim();
      if (!isValidEventName(value)) continue;
      events.push(value.toLowerCase());
    }
  }

  const dispararEventoRegex = /dispararEventoGtag\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = dispararEventoRegex.exec(content)) !== null) {
    const value = match[1].trim();
    if (!isValidEventName(value)) continue;
    events.push(value.toLowerCase());
  }

  const buildGaleriaEventRegex = /buildGaleriaEventName\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = buildGaleriaEventRegex.exec(content)) !== null) {
    const suffix = match[1].trim().toLowerCase();
    if (!isValidEventName(suffix)) continue;
    patternLabels.push(`seletor-<linha>-${suffix}`);
    patternLabels.push(`${suffix}-configurador-<linha>`);
  }

  const trackCompartilharRegex = /trackCompartilharTipo\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = trackCompartilharRegex.exec(content)) !== null) {
    const suffix = match[1].trim().toLowerCase();
    if (!isValidEventName(suffix)) continue;
    patternLabels.push(`compartilhar-${suffix}-configurador-<linha>`);
  }
  const carrinhoLinhaRegex = /dispararTagCarrinhoLinha\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = carrinhoLinhaRegex.exec(content)) !== null) {
    const prefix = match[1].trim().toLowerCase();
    if (!isValidEventName(prefix)) continue;
    patternLabels.push(`${prefix}-<linha>`);
  }

  const carrinhoLinhaValorRegex = /dispararTagCarrinhoLinhaValor\s*\(\s*["'`]([^"'`]+)["'`]/g;
  while ((match = carrinhoLinhaValorRegex.exec(content)) !== null) {
    const prefix = match[1].trim().toLowerCase();
    if (!isValidEventName(prefix)) continue;
    patternLabels.push(`${prefix}-<linha>`);
  }

  const templateLinhaRegex = /`(home-galeria|acesso-configurador)-\$\{[^}]+\}`/gi;
  while ((match = templateLinhaRegex.exec(content)) !== null) {
    const prefix = match[1].trim().toLowerCase();
    patternLabels.push(`${prefix}-<linha>`);
  }

  return { events, patternLabels };
}

function buildReport() {
  if (!fs.existsSync(manualPath)) {
    throw new Error(`ManualTags.md não encontrado em ${manualPath}`);
  }

  const manualContent = fs.readFileSync(manualPath, 'utf8');
  const { documented, patterns, patternLabels } = parseManualTags(manualContent);

  const files = walk(rootDir);
  const foundEvents = new Set();
  const foundPatternLabels = new Set();
  const businessContractViolations = [];

  for (const file of files) {
    const content = fs.readFileSync(file, 'utf8');
    const { events, patternLabels: extractedPatterns } = extractEvents(content);
    for (const event of events) {
      foundEvents.add(event);
    }
    for (const label of extractedPatterns) {
      foundPatternLabels.add(label);
    }
    businessContractViolations.push(...extractBusinessContractViolations(content, file));
  }

  const undocumented = [];
  for (const event of Array.from(foundEvents).sort()) {
    if (documented.has(event)) continue;
    if (patterns.some((pattern) => pattern.test(event))) continue;
    undocumented.push(event);
  }

  const documentedMissing = [];
  for (const tag of Array.from(documented).sort()) {
    if (!foundEvents.has(tag)) {
      documentedMissing.push(tag);
    }
  }

  patternLabels.forEach((label, index) => {
    const pattern = patterns[index];
    const hasMatch = foundPatternLabels.has(label)
      || Array.from(foundEvents).some((event) => pattern.test(event));
    if (!hasMatch) {
      documentedMissing.push(label);
    }
  });

  documentedMissing.sort();

  return { undocumented, documentedMissing, totalFound: foundEvents.size, businessContractViolations };
}

try {
  const { undocumented, documentedMissing, totalFound, businessContractViolations } = buildReport();

  console.log(`Total de eventos encontrados: ${totalFound}`);

  if (undocumented.length) {
    console.log('\nEventos não documentados:');
    undocumented.forEach((event) => console.log(`- ${event}`));
  } else {
    console.log('\nNenhum evento não documentado encontrado.');
  }

  if (businessContractViolations.length) {
    console.log('\nEventos fora do contrato canônico de negócio:');
    businessContractViolations.forEach((violation) => {
      console.log(`- ${path.relative(rootDir, violation.file)} :: ${violation.event} (faltando: ${violation.missing.join(', ')})`);
    });
  } else {
    console.log('\nNenhuma violação de contrato canônico encontrada.');
  }

  if (documentedMissing.length) {
    console.log('\nEventos documentados não encontrados:');
    documentedMissing.forEach((event) => console.log(`- ${event}`));
  } else {
    console.log('\nNenhum evento documentado sem uso encontrado.');
  }

  if (undocumented.length || businessContractViolations.length) {
    process.exitCode = 1;
  }
} catch (error) {
  console.error(`[ValidarTags] Erro: ${error.message}`);
  process.exitCode = 1;
}
