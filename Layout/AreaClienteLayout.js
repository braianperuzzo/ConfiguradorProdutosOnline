const INITIAL_LOADER_HIDDEN_EVENT = 'initial-loader-hidden';
let areaClienteBootstrapStarted = false;

function runWhenDomReady(callback) {
    if (typeof callback !== 'function') {
        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
}

function bootstrapAreaCliente() {
    const AREA_CLIENTE_BASE_PATH = '/AreaCliente';
    const AREA_CLIENTE_SESSAO_PATH = '/AreaCliente/Sessao';
    const IS_AREA_CLIENTE_PATH = /^\/(Paginas\/)?AreaCliente/.test(
        window.location.pathname
    );

    function normalizarCaminhoAreaCliente(path, { lowercase = true } = {}) {
        if (!path) {
            return '';
        }

        const caminho = path.replace(/\/+$/g, '');
        return lowercase ? caminho.toLowerCase() : caminho;
    }

    function atualizarLinksAreaCliente(destino) {
        const destinoNormalizado = typeof destino === 'string' && destino.length > 0
            ? destino
            : AREA_CLIENTE_BASE_PATH;
        const caminhoDestino = normalizarCaminhoAreaCliente(destinoNormalizado, { lowercase: false });

        document.querySelectorAll('a[href]').forEach((link) => {
            if (!(link instanceof HTMLAnchorElement) || link.hasAttribute('data-area-cliente-static')) {
                return;
            }

            const href = link.getAttribute('href');
            if (!href) {
                return;
            }

            try {
                const url = new URL(href, window.location.origin);
                const caminhoAtual = normalizarCaminhoAreaCliente(url.pathname);

                if (caminhoAtual !== normalizarCaminhoAreaCliente(AREA_CLIENTE_BASE_PATH)) {
                    return;
                }

                url.pathname = caminhoDestino || AREA_CLIENTE_BASE_PATH;
                const finalHref = url.origin === window.location.origin
                    ? url.pathname + url.search + url.hash
                    : url.toString();
                link.setAttribute('href', finalHref);
            } catch { }
        });
    }

    const AREA_CLIENTE_STATE_STORAGE_KEY = 'areaClientePersistedState';
    const AREA_CLIENTE_KEEP_ALIVE_INTERVAL = 5 * 60 * 1000;
    const AREA_CLIENTE_FORCE_SWITCHER_STORAGE_KEY = 'areaClienteSelecionarEmpresaPendente';
    const AREA_CLIENTE_EMPRESA_STORAGE_KEY = 'areaClienteEmpresaSelecionada';
    const AREA_CLIENTE_EMPRESA_SYNC_KEY = 'areaClienteEmpresaSyncTentada';

    let areaClienteKeepAliveTimer = null;

    function extrairPrimeiroNome(valor) {
        if (!valor) {
            return '';
        }
        try {
            return String(valor)
                .trim()
                .split(/\s+/)
                .find((parte) => parte && parte.length > 0) || '';
        } catch {
            return '';
        }
    }

    function storeAreaClienteState(logged, nome = '') {
        const trimmed = extrairPrimeiroNome(nome);
        try {
            if (logged) {
                sessionStorage.setItem('areaClienteLogado', '1');
                if (trimmed) {
                    sessionStorage.setItem('areaClientePrimeiroNome', trimmed);
                } else {
                    sessionStorage.removeItem('areaClientePrimeiroNome');
                }
            } else {
                sessionStorage.setItem('areaClienteLogado', '0');
                sessionStorage.removeItem('areaClientePrimeiroNome');
            }
        } catch { }
        try {
            if (logged) {
                localStorage.setItem(
                    AREA_CLIENTE_STATE_STORAGE_KEY,
                    JSON.stringify({ logged: true, nome: trimmed || '', updatedAt: Date.now() })
                );
            } else {
                localStorage.removeItem(AREA_CLIENTE_STATE_STORAGE_KEY);
            }
        } catch { }
    }

    function obterEmpresaPersistida() {
        if (typeof localStorage === 'undefined') {
            return '';
        }
        try {
            return normalizarDocumento(localStorage.getItem(AREA_CLIENTE_EMPRESA_STORAGE_KEY) || '');
        } catch {
            return '';
        }
    }

    function registrarEmpresaPersistida(documento) {
        if (typeof localStorage === 'undefined') {
            return;
        }
        const doc = normalizarDocumento(documento);
        try {
            if (doc) {
                localStorage.setItem(AREA_CLIENTE_EMPRESA_STORAGE_KEY, doc);
            } else {
                localStorage.removeItem(AREA_CLIENTE_EMPRESA_STORAGE_KEY);
            }
        } catch { }
    }

    function limparEmpresaPersistida() {
        if (typeof localStorage === 'undefined') {
            return;
        }
        try {
            localStorage.removeItem(AREA_CLIENTE_EMPRESA_STORAGE_KEY);
        } catch { }
    }

    function obterTentativaSync() {
        if (typeof sessionStorage === 'undefined') {
            return '';
        }
        try {
            return normalizarDocumento(sessionStorage.getItem(AREA_CLIENTE_EMPRESA_SYNC_KEY) || '');
        } catch {
            return '';
        }
    }

    function marcarTentativaSync(documento) {
        if (typeof sessionStorage === 'undefined') {
            return;
        }
        const doc = normalizarDocumento(documento);
        try {
            if (doc) {
                sessionStorage.setItem(AREA_CLIENTE_EMPRESA_SYNC_KEY, doc);
            } else {
                sessionStorage.removeItem(AREA_CLIENTE_EMPRESA_SYNC_KEY);
            }
        } catch { }
    }

    const empresaSectionCleanups = new WeakMap();
    let areaClienteSessionData = null;

    function cleanupEmpresaSection(container) {
        if (!container) {
            return;
        }
        const cleanup = empresaSectionCleanups.get(container);
        if (typeof cleanup === 'function') {
            try {
                cleanup();
            } catch { }
        }
        empresaSectionCleanups.delete(container);
    }

    function escapeHtml(value) {
        const texto = value == null ? '' : String(value);
        const mapa = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
        return texto.replace(/[&<>"']/g, (match) => mapa[match] || match);
    }

    function normalizarDocumento(valor) {
        return (valor || '')
            .toString()
            .replace(/\D/g, '');
    }

    function formatarDocumento(valor) {
        const doc = normalizarDocumento(valor);
        if (doc.length === 14) {
            return doc.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
        }
        if (doc.length === 11) {
            return doc.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        }
        return doc;
    }

    function normalizarTextoPesquisa(valor) {
        if (valor == null) {
            return '';
        }
        let texto = String(valor).toLowerCase();
        if (typeof texto.normalize === 'function') {
            texto = texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return texto;
    }

    function prepararEmpresasSessao(dados) {
        if (!dados || typeof dados !== 'object') {
            return null;
        }
        const docAtivo = normalizarDocumento(dados.empresaDocumento || dados.cpfcnpj || '');
        const empresasOriginais = Array.isArray(dados.empresas) ? dados.empresas : [];
        const documentosInseridos = new Set();
        const empresasNormalizadas = [];

        empresasOriginais.forEach((item) => {
            if (!item) {
                return;
            }
            const doc = normalizarDocumento(item.documento || item._docLimpo || '');
            if (!doc || documentosInseridos.has(doc)) {
                return;
            }
            documentosInseridos.add(doc);
            empresasNormalizadas.push({
                nome: (item.nome || item.razao || '').toString().trim(),
                papel: (item.papel || item.role || '').toString().trim(),
                codigo: (item.codigo || '').toString().trim(),
                documento: doc,
                _docLimpo: doc
            });
        });

        if (docAtivo && !documentosInseridos.has(docAtivo)) {
            documentosInseridos.add(docAtivo);
            empresasNormalizadas.push({
                nome: (dados.empresaNome || dados.empresa || '').toString().trim(),
                papel: (dados.empresaPapel || '').toString().trim(),
                codigo: (dados.codigo || '').toString().trim(),
                documento: docAtivo,
                _docLimpo: docAtivo
            });
        }

        const empresaAtiva = docAtivo
            ? empresasNormalizadas.find((empresa) => empresa._docLimpo === docAtivo) || null
            : null;

        return {
            cpfcnpj: docAtivo,
            documentoAtivo: docAtivo,
            empresas: empresasNormalizadas,
            empresaAtiva,
            empresaNome: (empresaAtiva?.nome || dados.empresaNome || dados.empresa || '').toString().trim(),
            empresaPapel: (empresaAtiva?.papel || dados.empresaPapel || '').toString().trim(),
            codigo: (dados.codigo || '').toString().trim(),
            isPessoaJuridica: docAtivo.length === 14
        };
    }

    function filtrarOpcoesEmpresas(lista, mensagemVazia, termo) {
        if (!lista) {
            return;
        }
        const filtroTexto = normalizarTextoPesquisa(termo);
        const filtroNumero = normalizarDocumento(termo);
        let visiveis = 0;

        lista.querySelectorAll('li').forEach((item) => {
            const botao = item.querySelector('.empresa-switcher__option');
            if (!botao) {
                return;
            }
            const texto = normalizarTextoPesquisa(botao.textContent || '');
            const doc = normalizarDocumento(botao.dataset?.documento || '');
            const correspondeTexto = filtroTexto ? texto.includes(filtroTexto) : true;
            const correspondeNumero = filtroNumero ? doc.includes(filtroNumero) : false;
            const deveMostrar = filtroTexto
                ? correspondeTexto || correspondeNumero
                : (filtroNumero ? correspondeNumero : true);
            item.hidden = !deveMostrar;
            botao.setAttribute('aria-hidden', deveMostrar ? 'false' : 'true');
            botao.tabIndex = deveMostrar ? 0 : -1;
            if (deveMostrar) {
                visiveis += 1;
            }
        });

        if (mensagemVazia) {
            if (visiveis === 0 && (lista.dataset?.possuiEmpresas || '') !== 'false') {
                mensagemVazia.classList.remove('d-none');
            } else {
                mensagemVazia.classList.add('d-none');
            }
        }
    }

    async function obterTokenCsrf() {
        if (typeof window !== 'undefined' && window.csrfToken) {
            return window.csrfToken;
        }
        try {
            const resposta = await fetch('/CSRFPega.php', { credentials: 'same-origin' });
            const dados = await resposta.json().catch(() => null);
            if (dados && dados.token) {
                window.csrfToken = dados.token;
            }
        } catch { }
        return typeof window !== 'undefined' ? window.csrfToken : '';
    }

    async function trocarEmpresaDocumento(documento, callbacks = {}) {
        const doc = normalizarDocumento(documento);
        const setStatus = typeof callbacks.setStatus === 'function' ? callbacks.setStatus : null;
        const onStart = typeof callbacks.onStart === 'function' ? callbacks.onStart : null;
        const onEnd = typeof callbacks.onEnd === 'function' ? callbacks.onEnd : null;

        if (!doc) {
            if (setStatus) {
                setStatus('⚠️ Documento inválido.');
            }
            return { sucesso: false, erro: '⚠️ Documento inválido.' };
        }

        const atual = normalizarDocumento(areaClienteSessionData?.documentoAtivo || areaClienteSessionData?.cpfcnpj || '');
        if (doc === atual) {
            return { sucesso: true };
        }

        if (onStart) {
            try {
                onStart();
            } catch { }
        }

        try {
            const params = new URLSearchParams({ empresaDocumento: doc });
            const csrfToken = await obterTokenCsrf();
            if (csrfToken) {
                params.set('csrf_token', csrfToken);
            } else {
                const mensagem = '⚠️ Não foi possível validar a solicitação. Atualize a página e tente novamente.';
                if (setStatus) {
                    setStatus(mensagem);
                }
                return { sucesso: false, erro: mensagem };
            }
            const resposta = await fetch('/PaginasAreaClienteAcessoCadastro/ValidarSessao.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString(),
                credentials: 'include'
            });
            let dados = null;
            try {
                dados = await resposta.json();
            } catch { }
            if (resposta.ok && dados?.sucesso) {
                const documentoRetornado = normalizarDocumento(dados?.empresaDocumento || '');
                if (documentoRetornado && documentoRetornado !== doc) {
                    const mensagem = '⚠️ Não foi possível trocar de empresa. Tente novamente.';
                    registrarEmpresaPersistida(documentoRetornado);
                    if (setStatus) {
                        setStatus(mensagem);
                    }
                    return { sucesso: false, erro: mensagem };
                }
                registrarEmpresaPersistida(doc);
                setTimeout(() => {
                    try {
                        window.location.reload();
                    } catch { }
                }, 0);
                return { sucesso: true };
            }
            const mensagem = dados?.erro || dados?.mensagem || '⚠️ Não foi possível trocar de empresa.';
            if (setStatus) {
                setStatus(mensagem);
            }
            return { sucesso: false, erro: mensagem };
        } catch {
            const mensagem = '⚠️ Não foi possível trocar de empresa.';
            if (setStatus) {
                setStatus(mensagem);
            }
            return { sucesso: false, erro: mensagem };
        } finally {
            if (onEnd) {
                try {
                    onEnd();
                } catch { }
            }
        }
    }

    function buildEmpresaSectionMarkup(suffix, options = {}) {
        if (!areaClienteSessionData || areaClienteSessionData.isPessoaJuridica !== true) {
            return '';
        }

        const empresas = Array.isArray(areaClienteSessionData.empresas)
            ? areaClienteSessionData.empresas.filter((empresa) => normalizarDocumento(empresa?._docLimpo || empresa?.documento).length > 0)
            : [];
        const documentoAtivo = normalizarDocumento(areaClienteSessionData.documentoAtivo || areaClienteSessionData.cpfcnpj || '');
        if (!documentoAtivo || documentoAtivo.length !== 14 || empresas.length === 0) {
            return '';
        }

        const activeEmpresa = empresas.find((empresa) => normalizarDocumento(empresa._docLimpo || empresa.documento) === documentoAtivo) || null;
        const nomeAtivo = (activeEmpresa?.nome || areaClienteSessionData.empresaNome || '').trim() || formatarDocumento(documentoAtivo);
        const papelAtivo = (activeEmpresa?.papel || areaClienteSessionData.empresaPapel || '').trim();
        const docFormatado = formatarDocumento(documentoAtivo);
        const multiplas = empresas.length > 1;
        const forceLink = options.forceLink === true;
        const exibirDropdown = multiplas && !forceLink;

        const nomeResumoBase = (nomeAtivo || docFormatado || 'Empresa ativa').trim();
        const nomeResumo = escapeHtml(nomeResumoBase);
        const resumoDocumento = docFormatado
            ? `<span class="area-cliente-empresa__resumo-doc empresa-switcher__doc">${escapeHtml(docFormatado)}</span>`
            : '';
        const resumoPapel = papelAtivo
            ? `<span class="area-cliente-empresa__resumo-papel empresa-switcher__papel">${escapeHtml(papelAtivo)}</span>`
            : '';
        const resumoMeta = (resumoDocumento || resumoPapel)
            ? `<span class="area-cliente-empresa__resumo-meta empresa-switcher__meta">${resumoDocumento}${resumoPapel}</span>`
            : '';

        const resumoMarkup = `
                    <span class="area-cliente-empresa__resumo area-cliente-empresa__content empresa-switcher__content" aria-live="polite">
                        <span class="area-cliente-empresa__resumo-nome empresa-switcher__nome" title="${nomeResumo}">${nomeResumo}</span>
                        ${resumoMeta || ''}
                    </span>`;

        const areaClienteSeletorUrl = '/AreaCliente/Sessao?SelecionarEmpresa';
        const triggerMarkup = `
                ${exibirDropdown
                ? `<button type="button" class="area-cliente-empresa__button empresa-switcher__trigger" data-empresa-role="trigger" aria-haspopup="dialog" aria-expanded="false" aria-label="Selecionar empresa ativa" data-gtag-event="bannercliente-empresa">`
                : `<a href="${areaClienteSeletorUrl}" class="area-cliente-empresa__button empresa-switcher__trigger" data-empresa-role="link" data-gtag-event="bannercliente-empresa">`}
                    <span class="area-cliente-empresa__icon empresa-switcher__icon" aria-hidden="true" data-gtag-event="bannercliente-empresa">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="2" y1="21" x2="22" y2="21" />
                            <rect x="4" y="11" width="6" height="10" rx="1" fill="none" />
                            <rect x="10" y="2" width="10" height="19" rx="1" fill="none" />
                            <line x1="6" y1="16.00415" x2="8" y2="16.00415" />
                            <line x1="14" y1="16.00415" x2="16" y2="16.00415" />
                            <line x1="14" y1="11.50415" x2="16" y2="11.50415" />
                            <line x1="14" y1="7.00415" x2="16" y2="7.00415" />
                        </svg>
                    </span>
                    ${resumoMarkup}
                    ${exibirDropdown
                ? `<span class="empresa-switcher__caret" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                                <path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"></path>
                            </svg>
                        </span>`
                : ''}
                ${exibirDropdown ? '</button>' : '</a>'}
        `;

        let dropdownMarkup = '';
        if (exibirDropdown) {
            const listId = `empresaSwitcherList-${suffix}`;
            const optionsMarkup = empresas.map((empresa) => {
                const doc = normalizarDocumento(empresa._docLimpo || empresa.documento);
                const docFormatadoItem = formatarDocumento(doc);
                const nomeEmpresa = (empresa.nome || docFormatadoItem || '').trim();
                const papelEmpresa = (empresa.papel || '').trim();
                const metaPartes = [
                    `<span class="empresa-switcher__option-doc">${escapeHtml(docFormatadoItem)}</span>`
                ];
                if (papelEmpresa) {
                    metaPartes.push(`<span class="empresa-switcher__option-role">${escapeHtml(papelEmpresa)}</span>`);
                }
                const ativo = doc === documentoAtivo;
                const optionId = `empresa-switcher-option-${suffix}-${doc || 'item'}`;
                return `
                        <li>
                            <button type="button" class="empresa-switcher__option${ativo ? ' active' : ''}" data-documento="${escapeHtml(doc)}"
                                role="option" aria-selected="${ativo ? 'true' : 'false'}" aria-checked="${ativo ? 'true' : 'false'}"
                                aria-current="${ativo ? 'true' : 'false'}" aria-hidden="false" id="${optionId}">
                                <span class="empresa-switcher__option-info">
                                    <span class="empresa-switcher__option-nome">${escapeHtml(nomeEmpresa || docFormatadoItem)}</span>
                                    <span class="empresa-switcher__option-meta">${metaPartes.join('')}</span>
                                </span>
                            </button>
                        </li>`;
            }).join('');

            dropdownMarkup = `
                <div class="empresa-switcher__dropdown" data-empresa-role="dropdown" role="dialog" aria-hidden="true" aria-label="Empresas disponíveis">
                    <div class="empresa-switcher__status text-center small d-none" data-empresa-role="status" role="status" aria-live="polite"></div>
                    <ul class="empresa-switcher__options list-unstyled mb-0 px-0 py-1" data-empresa-role="list" id="${listId}"
                        role="listbox" aria-label="Empresas disponíveis" data-possui-empresas="${empresas.length > 0 ? 'true' : 'false'}">
                        ${optionsMarkup}
                    </ul>
                    <div class="empresa-switcher__empty-message text-center small d-none" data-empresa-role="empty" role="status" aria-live="polite">
                        Nenhuma empresa encontrada
                    </div>
                </div>`;
        }

        return `
            <div class="area-cliente-empresa" data-area-cliente-empresa="${suffix}" data-multiplas="${exibirDropdown ? 'true' : 'false'}">
                ${triggerMarkup}
                ${dropdownMarkup}
            </div>`;
    }

    function buildAreaClienteMenuMarkup(suffix) {
        let markup = '<div class="area-cliente-dropdown">';
        const empresaMarkup = buildEmpresaSectionMarkup(suffix, { forceLink: true });
        if (empresaMarkup) {
            markup += empresaMarkup;
        }
        markup += `
            <a href="/AreaCliente/Sessao" data-gtag-event="bannercliente-dadoscadastrais">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-person text-orange" viewBox="0 0 14 14"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4Zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.831 1.418-.832 1.664h10Z" stroke="currentColor" stroke-width="0.25"/></svg>
                <span>Dados Cadastrais</span>
            </a>
            <a href="/AreaCliente/Produtos" data-gtag-event="bannercliente-produtos">
                <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" width="16" height="16" stroke="currentColor" stroke-width="0.25" class="text-orange area-cliente-icon-flip"><path fill-rule="evenodd" clip-rule="evenodd" d="M12.4856 1.12584C12.1836 0.958052 11.8164 0.958052 11.5144 1.12584L2.51436 6.12584L2.5073 6.13784L2.49287 6.13802C2.18749 6.3177 2 6.64568 2 7V16.9999C2 17.3631 2.19689 17.6977 2.51436 17.874L11.5022 22.8673C11.8059 23.0416 12.1791 23.0445 12.4856 22.8742L21.4856 17.8742C21.8031 17.6978 22 17.3632 22 17V7C22 6.64568 21.8125 6.31781 21.5071 6.13813C21.4996 6.13372 21.4921 6.12942 21.4845 6.12522L12.4856 1.12584ZM5.05923 6.99995L12.0001 10.856L14.4855 9.47519L7.74296 5.50898L5.05923 6.99995ZM16.5142 8.34816L18.9409 7L12 3.14396L9.77162 4.38195L16.5142 8.34816ZM4 16.4115V8.69951L11 12.5884V20.3004L4 16.4115ZM13 20.3005V12.5884L20 8.69951V16.4116L13 20.3005Z"/></svg>
                <span>Meus Produtos</span>
            </a>
            <a href="/AreaCliente/ProdutosFavoritos" data-gtag-event="bannercliente-favoritos">
                <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" width="16" height="16" stroke="currentColor" stroke-width="0.25" class="text-orange"><path d="M20.5 4.609A5.811 5.811 0 0 0 16 2.5a5.75 5.75 0 0 0-4 1.455A5.75 5.75 0 0 0 8 2.5 5.811 5.811 0 0 0 3.5 4.609c-.953 1.156-1.95 3.249-1.289 6.66 1.055 5.447 8.966 9.917 9.3 10.1a1 1 0 0 0 .974 0c.336-.187 8.247-4.657 9.3-10.1.661-3.411-.336-5.504-1.289-6.66Zm-.674 6.28C19.08 14.74 13.658 18.322 12 19.34c-2.336-1.41-7.142-4.95-7.821-8.451-.513-2.646.189-4.183.869-5.007A3.819 3.819 0 0 1 8 4.5a3.493 3.493 0 0 1 3.115 1.469 1.005 1.005 0 0 0 1.76.011A3.489 3.489 0 0 1 16 4.5a3.819 3.819 0 0 1 2.959 1.382c.68.824 1.382 2.361.867 5.007Z"/></svg>
                <span>Meus Favoritos</span>
            </a>
            <a href="/AreaCliente/Carrinhos" data-gtag-event="bannercliente-carrinhos">
                <svg class="text-orange" viewBox="0 0 512 512" width="16" height="16" fill="none" stroke="currentColor" stroke-width="24" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg"><g><g><path d="M432.6,225.191c-3.479-2.868-8.63-2.374-11.502,1.107c-44.517,53.996-104.988,91.167-120.785,100.37c-15.798-9.196-76.206-46.304-120.782-100.373c-2.872-3.482-8.02-3.975-11.503-1.107c-3.481,2.87-3.976,8.02-1.107,11.503c53.44,64.814,126.412,104.882,129.494,106.556c1.216,0.66,2.559,0.991,3.901,0.991c1.342,0,2.685-0.331,3.901-0.991c3.08-1.673,76.053-41.739,129.49-106.554C436.579,233.212,436.083,228.061,432.6,225.191z"></path></g></g><g><g><path d="M380.93,441.191c-19.522,0-35.404,15.882-35.404,35.404c0,19.522,15.882,35.404,35.404,35.404c19.522,0,35.404-15.882,35.404-35.404C416.334,457.073,400.452,441.191,380.93,441.191z M380.93,495.66c-10.511,0-19.064-8.553-19.064-19.064s8.553-19.064,19.064-19.064s19.064,8.553,19.064,19.064S391.441,495.66,380.93,495.66z"></path></g></g><g><g><path d="M163.057,441.191c-19.522,0-35.404,15.882-35.404,35.404c0,19.522,15.882,35.404,35.404,35.404s35.404-15.882,35.404-35.404C198.462,457.073,182.58,441.191,163.057,441.191z M163.057,495.66c-10.511,0-19.064-8.553-19.064-19.064s8.553-19.064,19.064-19.064s19.064,8.553,19.064,19.064S173.569,495.66,163.057,495.66z"></path></g></g><g><g><path d="M492.737,190.638h-27.761c15.443-28.859,23.261-57.922,23.261-86.572C488.237,46.684,441.554,0,384.172,0c-33.327,0-64.393,16.016-83.856,42.534C280.854,16.016,249.788,0,216.462,0c-57.383,0-104.067,46.684-104.067,104.067c0,28.647,7.84,57.718,23.282,86.572h-30.126l-8.11-55.418c-3.174-21.683-23.585-39.324-45.498-39.324H19.262c-4.513,0-8.17,3.657-8.17,8.17c0,4.513,3.657,8.17,8.17,8.17h32.681c13.887,0,27.318,11.608,29.33,25.349l36.288,247.942c3.172,21.683,23.583,39.323,45.497,39.323H380.93c4.513,0,8.17-3.657,8.17-8.17s-3.657-8.17-8.17-8.17H163.057c-13.887,0-27.318-11.608-29.33-25.349l-25.785-176.182h364.449l-18.771,128.251c-2.012,13.74-15.443,25.349-29.33,25.349H157.669c-4.513,0-8.17,3.657-8.17,8.17c0,4.513,3.657,8.17,8.17,8.17h266.621c21.914,0,42.325-17.64,45.498-39.324l19.117-130.617h3.832c4.513,0,8.17-3.657,8.17-8.17S497.251,190.638,492.737,190.638z M154.411,190.638c-17.022-29.02-25.675-58.134-25.675-86.572c0-48.372,39.354-87.726,87.726-87.726c31.834,0,61.227,17.348,76.708,45.275c1.44,2.597,4.176,4.208,7.146,4.208c2.971,0,5.705-1.611,7.146-4.208c15.481-27.927,44.874-45.275,76.708-45.275c48.372,0,87.725,39.354,87.725,87.726c0,28.439-8.649,57.543-25.673,86.572H154.411z"></path></g></g></svg>
                <span>Meus Carrinhos</span>
            </a>
            <a href="/AreaCliente/Desenhos" data-gtag-event="bannercliente-desenhos">
                <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" width="16" height="16" stroke="currentColor" stroke-width="0.25" class="text-orange"><path d="M12.5535 16.5061C12.4114 16.6615 12.2106 16.75 12 16.75C11.7894 16.75 11.5886 16.6615 11.4465 16.5061L7.44648 12.1311C7.16698 11.8254 7.18822 11.351 7.49392 11.0715C7.79963 10.792 8.27402 10.8132 8.55352 11.1189L11.25 14.0682V3C11.25 2.58579 11.5858 2.25 12 2.25C12.4142 2.25 12.75 2.58579 12.75 3V14.0682L15.4465 11.1189C15.726 10.8132 16.2004 10.792 16.5061 11.0715C16.8118 11.351 16.833 11.8254 16.5535 12.1311L12.5535 16.5061Z"/><path d="M3.75 15C3.75 14.5858 3.41422 14.25 3 14.25C2.58579 14.25 2.25 14.5858 2.25 15V15.0549C2.24998 16.4225 2.24996 17.5248 2.36652 18.3918C2.48754 19.2919 2.74643 20.0497 3.34835 20.6516C3.95027 21.2536 4.70814 21.5125 5.60825 21.6335C6.47522 21.75 7.57754 21.75 8.94513 21.75H15.0549C16.4225 21.75 17.5248 21.75 18.3918 21.6335C19.2919 21.5125 20.0497 21.2536 20.6517 20.6516C21.2536 20.0497 21.5125 19.2919 21.6335 18.3918C21.75 17.5248 21.75 16.4225 21.75 15.0549V15C21.75 14.5858 21.4142 14.25 21 14.25C20.5858 14.25 20.25 14.5858 20.25 15C20.25 16.4354 20.2484 17.4365 20.1469 18.1919C20.0482 18.9257 19.8678 19.3142 19.591 19.591C19.3142 19.8678 18.9257 20.0482 18.1919 20.1469C17.4365 20.2484 16.4354 20.25 15 20.25H9C7.56459 20.25 6.56347 20.2484 5.80812 20.1469C5.07435 20.0482 4.68577 19.8678 4.40901 19.591C4.13225 19.3142 3.9518 18.9257 3.85315 18.1919C3.75159 17.4365 3.75 16.4354 3.75 15Z"/></svg>
                <span>Meus Desenhos</span>
            </a>
            <a href="/AreaCliente/Cotacoes" data-gtag-event="bannercliente-cotacoes">
                <svg fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" width="16" height="16" stroke="currentColor" stroke-width="0.25" class="text-orange"><path d="M-12.179,3.178A6.972,6.972,0,0,0-18.022,0,6.985,6.985,0,0,0-25,6.977a6.972,6.972,0,0,0,3.179,5.845A6.972,6.972,0,0,0-15.978,16,6.985,6.985,0,0,0-9,9.023,6.972,6.972,0,0,0-12.179,3.178Zm.133,3.8a5.984,5.984,0,0,1-5.976,5.978A5.985,5.985,0,0,1-24,6.977,5.984,5.984,0,0,1-18.022,1,5.983,5.983,0,0,1-12.046,6.977ZM-15.978,15A5.938,5.938,0,0,1-19.6,13.769a6.983,6.983,0,0,0,1.574.186,6.985,6.985,0,0,0,6.976-6.978A6.967,6.967,0,0,0-11.231,5.4,5.939,5.939,0,0,1-10,9.023,5.984,5.984,0,0,1-15.978,15ZM-18.522,2.5V3H-19a2,2,0,0,0-2,2,2,2,0,0,0,2,2h.478V9.5h-.228A1.252,1.252,0,0,1-20,8.25a.5.5,0,0,0-.5-.5.5.5,0,0,0-.5.5,2.253,2.253,0,0,0,2.25,2.25h.228v1a.5.5,0,0,0,.5.5.5.5,0,0,0,.5-.5v-1h.272A2.253,2.253,0,0,0-15,8.25,2.253,2.253,0,0,0-17.25,6h-.272V4H-17a1,1,0,0,1,1,1v.5a.5.5,0,0,0,.5.5.5.5,0,0,0,.5-.5V5a2,2,0,0,0-2-2h-.522V2.5a.5.5,0,0,0-.5-.5A.5.5,0,0,0-18.522,2.5ZM-16,8.25A1.252,1.252,0,0,1-17.25,9.5h-.272V7h.272A1.252,1.252,0,0,1-16,8.25ZM-18.522,6H-19a1,1,0,0,1-1-1,1,1,0,0,1,1-1h.478Z" transform="translate(25)"/></svg>
                <span>Minhas Cotações</span>
            </a>
            <a href="/AreaCliente/Cadastros" data-gtag-event="bannercliente-cadastros">
                <svg fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" width="16" height="16" stroke="currentColor" stroke-width="0.25" class="text-orange"><path d="m15.66 7-.91-2.68L8.62.85a1.28 1.28 0 0 0-1.24 0L1.25 4.32.34 7a1.24 1.24 0 0 0 .58 1.5l.33.18V11a1.25 1.25 0 0 0 .63 1l5.5 3.11a1.28 1.28 0 0 0 1.24 0l5.5-3.11a1.25 1.25 0 0 0 .63-1V8.68l.33-.18a1.24 1.24 0 0 0 .58-1.5zM10 9.87l-.48-1.28L14 6.13l.44 1.28zM8 1.94 13.46 5 8 8 2.54 5zM1.52 7.41 2 6.13l4.48 2.46L6 9.87zm1 1.95 4.25 2.32.62-1.84v3.87L2.5 11zM13.5 11l-4.88 2.71V9.84l.63 1.84 4.25-2.32z"/></svg>
                <span>Meus Cadastros</span>
            </a>
            <a href="/PaginasAreaClienteSessaoPerfil/Sair.php" data-gtag-event="bannercliente-sair">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-right text-orange" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10 15a1 1 0 0 0 1-1V9h-1v5H3V2h6v5h1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h7z"/><path fill-rule="evenodd" d="M14.5 8a.5.5 0 0 0-.5-.5H8.707l1.147-1.146a.5.5 0 0 0-.708-.708l-2 2a.5.5 0 0 0 0 .708l2 2a.5.5 0 0 0 .708-.708L8.707 8.5H14a.5.5 0 0 0 .5-.5z"/></svg>
                <span>Sair</span>
            </a>
        `;
        markup += '</div>';
        return markup;
    }

    function initializeEmpresaSection(container, suffix) {
        if (!container) {
            cleanupEmpresaSection(container);
            return;
        }

        const wrapper = container.querySelector(`[data-area-cliente-empresa="${suffix}"]`);
        if (!wrapper) {
            cleanupEmpresaSection(container);
            return;
        }

        const toggleButton = wrapper.querySelector('[data-empresa-role="toggle"]');
        const triggerButton = wrapper.querySelector('[data-empresa-role="trigger"]');
        const linkSelecionarEmpresa = wrapper.querySelector('[data-empresa-role="link"]');
        const dropdown = wrapper.querySelector('[data-empresa-role="dropdown"]');
        const switcherContainer = wrapper.querySelector('[data-empresa-role="switcher"]');
        const lista = wrapper.querySelector('[data-empresa-role="list"]');
        const mensagemVazia = wrapper.querySelector('[data-empresa-role="empty"]');
        const statusElement = wrapper.querySelector('[data-empresa-role="status"]');
        const searchInput = wrapper.querySelector('[data-empresa-role="search"]');
        const closeButton = wrapper.querySelector('[data-empresa-role="close"]');
        const resumoContainer = wrapper.querySelector('.area-cliente-empresa__resumo');
        const resumoDocumento = wrapper.querySelector('.area-cliente-empresa__resumo-doc');
        const pageBody = document.body || document.documentElement;
        const multiplas = wrapper.dataset?.multiplas === 'true';
        const triggerInicialmenteDesabilitado = triggerButton ? triggerButton.disabled : false;

        if (switcherContainer) {
            switcherContainer.style.display = 'none';
            switcherContainer.setAttribute('aria-hidden', 'true');
        }

        if (dropdown) {
            dropdown.style.display = 'none';
            dropdown.setAttribute('aria-hidden', 'true');
        }

        if (lista) {
            lista.querySelectorAll('.empresa-switcher__option').forEach((botao) => {
                if (!botao) {
                    return;
                }
                if (!botao.id) {
                    const doc = normalizarDocumento(botao.dataset?.documento || '');
                    if (doc) {
                        botao.id = `${lista.id || 'empresa-switcher-option'}-${doc}`;
                    }
                }
                if (!botao.hasAttribute('tabindex')) {
                    botao.tabIndex = 0;
                }
            });
            filtrarOpcoesEmpresas(lista, mensagemVazia, '');
        }

        const setStatus = (texto) => {
            if (!statusElement) {
                return;
            }
            const mensagem = (texto || '').toString().trim();
            if (mensagem) {
                statusElement.textContent = mensagem;
                statusElement.classList.remove('d-none');
            } else {
                statusElement.textContent = '';
                statusElement.classList.add('d-none');
            }
        };

        let aberto = false;

        const outsideClickHandler = (event) => {
            if (!wrapper.contains(event.target)) {
                setDropdownOpen(false);
            }
        };

        const keydownHandler = (event) => {
            if (event.key === 'Escape') {
                if (wrapper.contains(event.target)) {
                    event.stopPropagation();
                    setDropdownOpen(false);
                    const focusTarget = triggerButton || toggleButton;
                    if (focusTarget) {
                        try {
                            focusTarget.focus({ preventScroll: true });
                        } catch {
                            focusTarget.focus();
                        }
                    }
                }
            }
        };
        const setDropdownOpen = (open) => {
            if (!dropdown) {
                return;
            }
            if (open) {
                if (aberto) {
                    return;
                }
                aberto = true;
                wrapper.classList.add('area-cliente-empresa--open');
                if (pageBody) {
                    pageBody.classList.add('empresa-switcher-modal-open');
                }
                if (switcherContainer) {
                    switcherContainer.style.display = 'flex';
                    switcherContainer.setAttribute('aria-hidden', 'false');
                }
                dropdown.style.display = 'flex';
                dropdown.setAttribute('aria-hidden', 'false');
                if (triggerButton) {
                    triggerButton.setAttribute('aria-expanded', 'true');
                }
                if (toggleButton) {
                    toggleButton.setAttribute('aria-expanded', 'true');
                }
                document.addEventListener('click', outsideClickHandler);
                document.addEventListener('keydown', keydownHandler);
                if (lista) {
                    lista.scrollTop = 0;
                }
                if (searchInput && !searchInput.disabled) {
                    setTimeout(() => {
                        try {
                            searchInput.focus({ preventScroll: true });
                        } catch {
                            searchInput.focus();
                        }
                    }, 0);
                }
                return;
            }
            if (!aberto) {
                return;
            }
            aberto = false;
            wrapper.classList.remove('area-cliente-empresa--open');
            if (switcherContainer) {
                switcherContainer.style.display = 'none';
                switcherContainer.setAttribute('aria-hidden', 'true');
            }
            dropdown.style.display = 'none';
            dropdown.setAttribute('aria-hidden', 'true');
            if (triggerButton) {
                triggerButton.setAttribute('aria-expanded', 'false');
            }
            if (toggleButton) {
                toggleButton.setAttribute('aria-expanded', 'false');
            }
            if (pageBody) {
                pageBody.classList.remove('empresa-switcher-modal-open');
            }
            document.removeEventListener('click', outsideClickHandler);
            document.removeEventListener('keydown', keydownHandler);
            if (searchInput) {
                searchInput.value = '';
            }
            if (lista) {
                filtrarOpcoesEmpresas(lista, mensagemVazia, '');
            }
            setStatus('');
        };
        const toggleDropdown = (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (!dropdown) {
                return;
            }
            setStatus('');
            setDropdownOpen(!aberto);
        };

        const removers = [];

        if (linkSelecionarEmpresa) {
            const obterDestinoAtual = () =>
                window.location.pathname +
                window.location.search +
                window.location.hash;

            const atualizarLinkComRetorno = (destino) => {
                try {
                    const originalHref = linkSelecionarEmpresa.getAttribute('href');
                    if (!originalHref) {
                        return null;
                    }

                    const url = new URL(originalHref, window.location.origin);
                    if (url.origin !== window.location.origin) {
                        const absoluto = url.toString();
                        linkSelecionarEmpresa.setAttribute('href', absoluto);
                        return absoluto;
                    }

                    if (destino && typeof destino === 'string' && destino.trim()) {
                        url.searchParams.set('retorno', destino);
                        url.search = url.search.replace(/%2F/gi, '/');
                    } else {
                        url.searchParams.delete('retorno');
                    }

                    const finalHref = url.pathname + url.search + url.hash;
                    linkSelecionarEmpresa.setAttribute('href', finalHref);
                    return finalHref;
                } catch {
                    return null;
                }
            };

            const registrarRetornoAtual = (event) => {
                const atual = obterDestinoAtual();
                let finalHref = null;

                if (!IS_AREA_CLIENTE_PATH) {
                    try {
                        if (typeof sessionStorage !== 'undefined') {
                            sessionStorage.setItem(AREA_CLIENTE_FORCE_SWITCHER_STORAGE_KEY, '1');
                            sessionStorage.setItem('retornoPosLogin', atual);
                            sessionStorage.setItem('areaClienteRetornoSelecionarEmpresa', atual);
                        }
                    } catch { }

                    finalHref = atualizarLinkComRetorno(atual);
                } else {
                    finalHref = atualizarLinkComRetorno('');
                }

                if (!finalHref && linkSelecionarEmpresa) {
                    finalHref = linkSelecionarEmpresa.getAttribute('href');
                }

                if (
                    finalHref &&
                    event &&
                    event.button === 0 &&
                    !event.ctrlKey &&
                    !event.metaKey &&
                    !event.shiftKey &&
                    !event.altKey
                ) {
                    event.preventDefault();
                    event.stopPropagation();
                    window.location.href = finalHref;
                }
            };

            if (!IS_AREA_CLIENTE_PATH) {
                atualizarLinkComRetorno(obterDestinoAtual());
            }

            linkSelecionarEmpresa.addEventListener('click', registrarRetornoAtual);
            removers.push(() => linkSelecionarEmpresa.removeEventListener('click', registrarRetornoAtual));
        }

        const resumoTargets = [];
        if (resumoDocumento) {
            resumoTargets.push(resumoDocumento);
        }
        if (resumoContainer && resumoContainer !== resumoDocumento) {
            resumoTargets.push(resumoContainer);
        }

        if (switcherContainer) {
            const overlayHandler = (event) => {
                if (event.target === switcherContainer) {
                    setDropdownOpen(false);
                }
            };
            switcherContainer.addEventListener('click', overlayHandler);
            removers.push(() => switcherContainer.removeEventListener('click', overlayHandler));
        }

        if (closeButton) {
            const closeHandler = (event) => {
                event.preventDefault();
                event.stopPropagation();
                setDropdownOpen(false);
            };
            closeButton.addEventListener('click', closeHandler);
            removers.push(() => closeButton.removeEventListener('click', closeHandler));
        }

        if (toggleButton) {
            toggleButton.addEventListener('click', toggleDropdown);
            removers.push(() => toggleButton.removeEventListener('click', toggleDropdown));
        }

        if (triggerButton && !triggerButton.disabled) {
            const triggerHandler = (event) => {
                event.preventDefault();
                event.stopPropagation();
                setStatus('');
                setDropdownOpen(!aberto);
            };
            triggerButton.addEventListener('click', triggerHandler);
            removers.push(() => triggerButton.removeEventListener('click', triggerHandler));
        }

        if (multiplas && dropdown && resumoTargets.length > 0) {
            const resumoClickHandler = (event) => {
                if (triggerButton && triggerButton.disabled) {
                    return;
                }
                toggleDropdown(event);
            };
            const resumoKeydownHandler = (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                if (triggerButton && triggerButton.disabled) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                setStatus('');
                setDropdownOpen(!aberto);
            };
            resumoTargets.forEach((elemento) => {
                if (!elemento) {
                    return;
                }
                if (!elemento.hasAttribute('tabindex')) {
                    elemento.tabIndex = 0;
                }
                elemento.setAttribute('role', 'button');
                elemento.setAttribute('aria-haspopup', 'dialog');
                elemento.addEventListener('click', resumoClickHandler);
                elemento.addEventListener('keydown', resumoKeydownHandler);
                removers.push(() => {
                    elemento.removeEventListener('click', resumoClickHandler);
                    elemento.removeEventListener('keydown', resumoKeydownHandler);
                });
            });
        }

        if (searchInput) {
            const inputHandler = (event) => {
                filtrarOpcoesEmpresas(lista, mensagemVazia, event.target.value || '');
                if (lista) {
                    lista.scrollTop = 0;
                }
            };
            const searchKeydown = (event) => {
                if (event.key === 'Escape') {
                    event.stopPropagation();
                    setDropdownOpen(false);
                }
            };
            searchInput.addEventListener('input', inputHandler);
            searchInput.addEventListener('keydown', searchKeydown);
            removers.push(() => {
                searchInput.removeEventListener('input', inputHandler);
                searchInput.removeEventListener('keydown', searchKeydown);
            });
        }

        if (lista) {
            const clickHandler = (event) => {
                const botao = event.target instanceof Element
                    ? event.target.closest('.empresa-switcher__option')
                    : null;
                if (!botao) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                const documentoSelecionado = botao.dataset?.documento || '';
                const documentoNormalizado = normalizarDocumento(documentoSelecionado);
                if (!documentoNormalizado) {
                    setDropdownOpen(false);
                    return;
                }
                const documentoAtual = normalizarDocumento(areaClienteSessionData?.documentoAtivo || areaClienteSessionData?.cpfcnpj || '');
                if (documentoNormalizado === documentoAtual) {
                    setDropdownOpen(false);
                    return;
                }
                const ativoAnterior = lista.querySelector('.empresa-switcher__option.active');
                const idAnterior = ativoAnterior ? ativoAnterior.id : '';
                if (ativoAnterior === botao) {
                    setDropdownOpen(false);
                    return;
                }
                if (ativoAnterior) {
                    ativoAnterior.classList.remove('active');
                    ativoAnterior.setAttribute('aria-current', 'false');
                    ativoAnterior.setAttribute('aria-selected', 'false');
                    ativoAnterior.setAttribute('aria-checked', 'false');
                }
                botao.classList.add('active');
                botao.setAttribute('aria-current', 'true');
                botao.setAttribute('aria-selected', 'true');
                botao.setAttribute('aria-checked', 'true');
                if (triggerButton) {
                    triggerButton.setAttribute('aria-activedescendant', botao.id || '');
                }

                const restaurarSelecao = () => {
                    botao.classList.remove('active');
                    botao.setAttribute('aria-current', 'false');
                    botao.setAttribute('aria-selected', 'false');
                    botao.setAttribute('aria-checked', 'false');
                    if (ativoAnterior) {
                        ativoAnterior.classList.add('active');
                        ativoAnterior.setAttribute('aria-current', 'true');
                        ativoAnterior.setAttribute('aria-selected', 'true');
                        ativoAnterior.setAttribute('aria-checked', 'true');
                    }
                    if (triggerButton) {
                        if (idAnterior) {
                            triggerButton.setAttribute('aria-activedescendant', idAnterior);
                        } else {
                            triggerButton.removeAttribute('aria-activedescendant');
                        }
                    }
                };

                const startLoading = () => {
                    if (triggerButton) {
                        triggerButton.setAttribute('aria-busy', 'true');
                        triggerButton.classList.add('disabled');
                        triggerButton.disabled = true;
                    }
                    setStatus('');
                };

                const endLoading = () => {
                    if (triggerButton) {
                        triggerButton.removeAttribute('aria-busy');
                        triggerButton.classList.remove('disabled');
                        triggerButton.disabled = triggerInicialmenteDesabilitado;
                    }
                };

                trocarEmpresaDocumento(documentoNormalizado, {
                    setStatus,
                    onStart: startLoading,
                    onEnd: endLoading
                }).then((resultado) => {
                    if (!resultado || resultado.sucesso) {
                        setDropdownOpen(false);
                        return;
                    }
                    restaurarSelecao();
                });
            };
            lista.addEventListener('click', clickHandler);
            removers.push(() => lista.removeEventListener('click', clickHandler));
        }

        const cleanup = () => {
            removers.forEach((remover) => {
                try {
                    remover();
                } catch { }
            });
            document.removeEventListener('click', outsideClickHandler);
            document.removeEventListener('keydown', keydownHandler);
            if (dropdown) {
                dropdown.style.display = 'none';
                dropdown.setAttribute('aria-hidden', 'true');
            }
            if (switcherContainer) {
                switcherContainer.style.display = 'none';
                switcherContainer.setAttribute('aria-hidden', 'true');
            }
            if (toggleButton) {
                toggleButton.setAttribute('aria-expanded', 'false');
            }
            if (triggerButton) {
                triggerButton.setAttribute('aria-expanded', 'false');
                triggerButton.removeAttribute('aria-busy');
                triggerButton.classList.remove('disabled');
                triggerButton.disabled = triggerInicialmenteDesabilitado;
            }
            if (pageBody) {
                pageBody.classList.remove('empresa-switcher-modal-open');
            }
            setStatus('');
            aberto = false;
        };

        empresaSectionCleanups.set(container, cleanup);
    }


    window.__areaClienteLogado = false;

    runWhenDomReady(() => {
        const desktop = document.getElementById('areaClienteDesktop');
        const mobile = document.getElementById('areaClienteMobile');

        let currentAreaClienteState = { logged: null, nome: '' };
        let dropdownCloseHandler = null;

        function resolverNomeSaudacao(nomePreferencial, sessionData) {
            const candidatosBrutos = [];
            const adicionarCandidato = (valor) => {
                if (!valor && valor !== 0) {
                    return;
                }
                try {
                    const texto = String(valor).trim();
                    if (texto) {
                        candidatosBrutos.push(texto);
                    }
                } catch { }
            };

            adicionarCandidato(nomePreferencial);

            if (sessionData && typeof sessionData === 'object') {
                const chavesPossiveis = [
                    'primeiroNome',
                    'primeiro_nome',
                    'nome',
                    'Nome',
                    'NOME',
                    'nomeCompleto',
                    'nome_completo',
                    'nomeUsuario',
                    'usuarioNome',
                    'displayName',
                    'display_name'
                ];
                chavesPossiveis.forEach((chave) => adicionarCandidato(sessionData[chave]));

                if (sessionData.usuario && typeof sessionData.usuario === 'object') {
                    adicionarCandidato(sessionData.usuario.nome);
                    adicionarCandidato(sessionData.usuario.Nome);
                    adicionarCandidato(sessionData.usuario.displayName);
                }
            }

            adicionarCandidato(currentAreaClienteState?.nome);

            const candidatosPrimeiroNome = candidatosBrutos
                .map((valor) => {
                    try {
                        return extrairPrimeiroNome(valor);
                    } catch {
                        return '';
                    }
                })
                .filter((parte) => parte && parte.length > 0);

            if (candidatosPrimeiroNome.length > 0) {
                return candidatosPrimeiroNome[0];
            }

            if (candidatosBrutos.length > 0) {
                return candidatosBrutos[0];
            }

            const email = sessionData && (sessionData.email ?? sessionData.Email ?? sessionData.EMAIL);
            if (email && typeof email === 'string') {
                const parteLocal = email.split('@')[0] || '';
                const nomeEmail = extrairPrimeiroNome(parteLocal.replace(/[._-]+/g, ' '));
                if (nomeEmail) {
                    return nomeEmail;
                }
            }

            return '';
        }

        const getStoredAreaClienteState = () => {
            try {
                const rawLogged = sessionStorage.getItem('areaClienteLogado');
                const rawNome = sessionStorage.getItem('areaClientePrimeiroNome');
                if (rawLogged !== null || rawNome !== null) {
                    const logged = rawLogged === '1';
                    const nome = extrairPrimeiroNome(rawNome);
                    return { logged, nome };
                }
            } catch { }

            try {
                const persistedRaw = localStorage.getItem(AREA_CLIENTE_STATE_STORAGE_KEY);
                if (!persistedRaw) {
                    return { logged: false, nome: '' };
                }
                const persisted = JSON.parse(persistedRaw);
                if (!persisted || typeof persisted !== 'object') {
                    return { logged: false, nome: '' };
                }
                const logged = persisted.logged === true;
                const nome = extrairPrimeiroNome(persisted.nome);
                try {
                    if (logged) {
                        sessionStorage.setItem('areaClienteLogado', '1');
                        if (nome) {
                            sessionStorage.setItem('areaClientePrimeiroNome', nome);
                        } else {
                            sessionStorage.removeItem('areaClientePrimeiroNome');
                        }
                    } else {
                        sessionStorage.setItem('areaClienteLogado', '0');
                        sessionStorage.removeItem('areaClientePrimeiroNome');
                    }
                } catch { }
                return { logged, nome };
            } catch {
                return { logged: false, nome: '' };
            }
        };

        function renderLoggedOut() {
            stopAreaClienteKeepAlive();
            if (currentAreaClienteState.logged === false) {
                if (desktop) {
                    desktop.classList.remove('area-cliente-loading');
                }
                if (mobile) {
                    mobile.classList.remove('area-cliente-loading');
                }
                atualizarLinksAreaCliente(AREA_CLIENTE_BASE_PATH);
                return;
            }

            currentAreaClienteState = { logged: false, nome: '' };
            areaClienteSessionData = null;
            try {
                window.__areaClienteEmpresas = null;
            } catch { }

            if (dropdownCloseHandler) {
                document.removeEventListener('click', dropdownCloseHandler);
                dropdownCloseHandler = null;
            }
            const loggedOutMarkup = `
                <a href="/AreaCliente" class="area-cliente-trigger text-decoration-none" aria-label="Área do Cliente - Entre ou Cadastre-se">
                    <span class="area-cliente-title">Área do Cliente</span>
                    <span class="area-cliente-status">Entre | Cadastre-se</span>
                </a>
            `;

            if (desktop) {
                cleanupEmpresaSection(desktop);
                desktop.innerHTML = loggedOutMarkup;
                desktop.classList.remove('area-cliente-loading');
            }
            if (mobile) {
                cleanupEmpresaSection(mobile);
                mobile.innerHTML = loggedOutMarkup;
                mobile.classList.remove('area-cliente-loading');
            }
            atualizarLinksAreaCliente(AREA_CLIENTE_BASE_PATH);

        }
        function renderLoggedIn(nome, sessionData) {
            startAreaClienteKeepAlive();
            const sessionNome = sessionData && (sessionData.nome ?? sessionData.Nome ?? sessionData.nomeCompleto ?? sessionData.NOME);
            const resolvedName = resolverNomeSaudacao(nome ?? sessionNome, sessionData);
            const hasNewSessionData = sessionData && typeof sessionData === 'object';
            if (hasNewSessionData) {
                const preparado = prepararEmpresasSessao(sessionData);
                if (preparado) {
                    areaClienteSessionData = preparado;
                } else {
                    areaClienteSessionData = null;
                }
            }
            if (typeof window !== 'undefined') {
                try {
                    window.__areaClienteEmpresas = areaClienteSessionData;
                } catch { }
            }

            if (hasNewSessionData) {
                if (!areaClienteSessionData?.isPessoaJuridica) {
                    limparEmpresaPersistida();
                } else {
                    const documentoAtivo = normalizarDocumento(areaClienteSessionData.documentoAtivo || areaClienteSessionData.cpfcnpj || '');
                    const empresas = Array.isArray(areaClienteSessionData.empresas)
                        ? areaClienteSessionData.empresas
                        : [];
                    const documentoPersistido = obterEmpresaPersistida();
                    const persistidoValido = documentoPersistido
                        ? empresas.some((empresa) => normalizarDocumento(empresa?._docLimpo || empresa?.documento) === documentoPersistido)
                        : false;

                    if (documentoPersistido && !persistidoValido) {
                        registrarEmpresaPersistida(documentoAtivo);
                        marcarTentativaSync('');
                    } else if (documentoPersistido && documentoAtivo && documentoPersistido !== documentoAtivo) {
                        const tentativaAtual = obterTentativaSync();
                        if (tentativaAtual !== documentoPersistido) {
                            marcarTentativaSync(documentoPersistido);
                            trocarEmpresaDocumento(documentoPersistido).then((resultado) => {
                                if (resultado && resultado.sucesso) {
                                    return;
                                }
                                marcarTentativaSync('');
                            });
                        }
                    } else if (documentoAtivo) {
                        if (!documentoPersistido) {
                            registrarEmpresaPersistida(documentoAtivo);
                        }
                        marcarTentativaSync('');
                    }
                }
            }

            if (currentAreaClienteState.logged === true && currentAreaClienteState.nome === resolvedName && !hasNewSessionData) {
                atualizarLinksAreaCliente(AREA_CLIENTE_SESSAO_PATH);
                return;
            }

            if (hasNewSessionData && resolvedName) {
                storeAreaClienteState(true, resolvedName);
            }

            currentAreaClienteState = { logged: true, nome: resolvedName };

            const displayName = resolvedName || 'Cliente';
            const safeDisplayName = escapeHtml(displayName);
            const menuDesktop = buildAreaClienteMenuMarkup('desktop');
            const menuMobile = buildAreaClienteMenuMarkup('mobile');

            if (desktop) {
                cleanupEmpresaSection(desktop);
                desktop.innerHTML = `<button type="button" class="area-cliente-user area-cliente-trigger" id="areaClienteBtnDesktop" aria-haspopup="true" aria-expanded="false" aria-label="Área do Cliente" data-gtag-event="bannercliente-abrir">Olá, ${safeDisplayName}<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/></svg></button>${menuDesktop}`;
                desktop.classList.remove('area-cliente-loading');
            }
            if (mobile) {
                cleanupEmpresaSection(mobile);
                mobile.innerHTML = `<button type="button" class="area-cliente-user area-cliente-trigger" id="areaClienteBtnMobile" aria-haspopup="true" aria-expanded="false" aria-label="Área do Cliente" data-gtag-event="bannercliente-abrir">Olá, ${safeDisplayName}<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/></svg></button>${menuMobile}`;
                mobile.classList.remove('area-cliente-loading');
            }

            if (window.anexarRetorno) {
                window.anexarRetorno(
                    'a[href="/PaginasAreaClienteSessaoPerfil/Sair.php"], a[href="/AreaCliente/Sair"]',
                    false
                );
            }

            atualizarLinksAreaCliente(AREA_CLIENTE_SESSAO_PATH);

            function bind(btnId, container) {
                const btn = document.getElementById(btnId);
                const dropdown = container ? container.querySelector('.area-cliente-dropdown') : null;
                const arrow = btn ? btn.querySelector('svg') : null;
                if (!btn || !dropdown) return;
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const shown = dropdown.style.display === 'block';
                    const shouldOpen = !shown;
                    dropdown.style.display = shouldOpen ? 'block' : 'none';
                    btn.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
                    container?.classList.toggle('area-cliente-nav-open', shouldOpen);
                    if (arrow) arrow.classList.toggle('invertido', shouldOpen);
                });

            }

            bind('areaClienteBtnDesktop', desktop);
            bind('areaClienteBtnMobile', mobile);

            initializeEmpresaSection(desktop, 'desktop');
            initializeEmpresaSection(mobile, 'mobile');

            if (dropdownCloseHandler) {
                document.removeEventListener('click', dropdownCloseHandler);
            }

            dropdownCloseHandler = (e) => {
                [desktop, mobile].forEach((cont) => {
                    if (!cont) return;
                    const btn = cont.querySelector('.area-cliente-user');
                    const dd = cont.querySelector('.area-cliente-dropdown');
                    const arrow = btn ? btn.querySelector('svg') : null;
                    if (dd && btn && arrow && !cont.contains(e.target)) {
                        dd.style.display = 'none';
                        dd.style.left = '';
                        dd.style.top = '';
                        btn.setAttribute('aria-expanded', 'false');
                        cont.classList.remove('area-cliente-nav-open');
                        arrow.classList.remove('invertido');
                    }
                });
            };

            document.addEventListener('click', dropdownCloseHandler);
        }

        const atualizarEmpresaSessaoPeloEvento = (detalhe) => {
            if (!detalhe || typeof detalhe !== 'object') {
                return;
            }
            const documentoAtivo = normalizarDocumento(detalhe.documento || '');
            const empresasEvento = Array.isArray(detalhe.empresas) ? detalhe.empresas : [];
            if (!documentoAtivo || empresasEvento.length === 0) {
                return;
            }

            const empresasNormalizadas = empresasEvento
                .map((empresa) => {
                    if (!empresa) {
                        return null;
                    }
                    const documento = normalizarDocumento(empresa.documento || empresa._docLimpo || '');
                    if (!documento) {
                        return null;
                    }
                    return {
                        documento,
                        _docLimpo: documento,
                        nome: (empresa.nome || '').toString().trim(),
                        papel: (empresa.papel || '').toString().trim()
                    };
                })
                .filter(Boolean);

            if (empresasNormalizadas.length === 0) {
                return;
            }

            const empresaAtiva = empresasNormalizadas.find((empresa) => empresa.documento === documentoAtivo)
                || empresasNormalizadas[0];

            const payload = {
                empresaDocumento: documentoAtivo,
                cpfcnpj: documentoAtivo,
                empresaNome: empresaAtiva?.nome || '',
                empresaPapel: empresaAtiva?.papel || '',
                empresas: empresasNormalizadas
            };

            renderLoggedIn(currentAreaClienteState.nome || '', payload);
        };

        document.addEventListener('ibr:empresaSessaoAtivaAlterada', (event) => {
            if (currentAreaClienteState.logged !== true) {
                return;
            }
            atualizarEmpresaSessaoPeloEvento(event?.detail);
        });


        const storedState = getStoredAreaClienteState();
        if (storedState.logged) {
            window.__areaClienteLogado = true;
            renderLoggedIn(storedState.nome);
        } else {
            window.__areaClienteLogado = false;
            renderLoggedOut();
        }
        const handleStoredStateFallback = () => {
            const { logged, nome } = getStoredAreaClienteState();
            if (logged) {
                window.__areaClienteLogado = true;
                renderLoggedIn(nome);
            } else {
                window.__areaClienteLogado = false;
                renderLoggedOut();
            }
        };

        const sessaoValida = (dados) => {
            if (!dados || typeof dados !== 'object') {
                return false;
            }

            if (dados.sucesso === true || dados.sucesso === 1 || dados.sucesso === '1') {
                return true;
            }

            if (typeof dados.sucesso === 'string' && dados.sucesso.toLowerCase() === 'true') {
                return true;
            }

            return Boolean(dados.email && !dados.erro);
        };

        function stopAreaClienteKeepAlive() {
            if (areaClienteKeepAliveTimer) {
                clearInterval(areaClienteKeepAliveTimer);
                areaClienteKeepAliveTimer = null;
            }
        }

        async function runAreaClienteKeepAlive() {
            try {
                const resposta = await fetch('/PaginasAreaClienteAcessoCadastro/ValidarSessao.php', {
                    credentials: 'include',
                    cache: 'no-store',
                    headers: {
                        'Cache-Control': 'no-cache',
                        Pragma: 'no-cache'
                    }
                });

                if (!resposta.ok) {
                    stopAreaClienteKeepAlive();
                    window.__areaClienteLogado = false;
                    storeAreaClienteState(false);
                    renderLoggedOut();
                    return;
                }

                try {
                    const dados = await resposta.json();
                    if (sessaoValida(dados)) {
                        const nomeCompleto = dados.nome ?? dados.Nome ?? dados.nomeCompleto ?? dados.NOME ?? '';
                        const primeiroNome = extrairPrimeiroNome(nomeCompleto);
                        storeAreaClienteState(true, primeiroNome || nomeCompleto);
                    }
                } catch { }
            } catch { }
        }

        function startAreaClienteKeepAlive() {
            stopAreaClienteKeepAlive();
            try {
                areaClienteKeepAliveTimer = setInterval(runAreaClienteKeepAlive, AREA_CLIENTE_KEEP_ALIVE_INTERVAL);
            } catch {
                areaClienteKeepAliveTimer = null;
            }
        }

        fetch('/PaginasAreaClienteAcessoCadastro/ValidarSessao.php', {
            credentials: 'include',
            cache: 'no-store',
            headers: {
                'Cache-Control': 'no-cache',
                Pragma: 'no-cache'
            }
        })
            .then((r) => r.json())
            .then((d) => {
                if (sessaoValida(d)) {
                    window.__areaClienteLogado = true;
                    const nomeCompleto = d && (d.nome ?? d.Nome ?? d.nomeCompleto ?? d.NOME ?? '');
                    const primeiroNome = extrairPrimeiroNome(nomeCompleto);
                    storeAreaClienteState(true, primeiroNome || nomeCompleto);
                    renderLoggedIn(nomeCompleto, d);
                } else {
                    window.__areaClienteLogado = false;
                    storeAreaClienteState(false);
                    renderLoggedOut();
                }
            })
            .catch(() => {
                handleStoredStateFallback();

            });
    });

    runWhenDomReady(() => {
        if (!/Android/i.test(navigator.userAgent)) return;

        document
            .querySelectorAll('a[href*="play.google.com/store/apps/details?id="]')
            .forEach((link) => {
                link.addEventListener('click', (e) => {
                    const appId = new URL(link.href).searchParams.get('id');
                    if (!appId) return;
                    e.preventDefault();
                    const marketUrl = `market://details?id=${appId}`;
                    // se o esquema falhar, voltar para o link padrão
                    setTimeout(() => {
                        window.location.href = link.href;
                    }, 1000);
                    window.location.href = marketUrl;
                });
            });
    });

    const CONFIGURADOR_STORAGE_KEY = 'ultimoConfiguradorCompleto';
    const LEGACY_CONFIGURADOR_STORAGE_KEY = 'ultimoConfigurador';

    function normalizarDestinoConfigurador(valor) {
        if (!valor || typeof valor !== 'string') {
            return null;
        }

        const texto = valor.trim();
        if (!texto) {
            return null;
        }

        try {
            const url = new URL(texto, window.location.origin);
            if (url.origin !== window.location.origin) {
                return null;
            }

            if (!ehPaginaConfigurador(url.pathname, url.search)) {
                return null;
            }

            return `${url.pathname}${url.search}${url.hash}`;
        } catch {
            if (!texto.startsWith('/')) {
                return null;
            }

            const interrogarPos = texto.indexOf('?');
            const hashPos = texto.indexOf('#');
            const end = (pos) => (pos >= 0 ? pos : texto.length);
            const stop = Math.min(end(interrogarPos), end(hashPos));
            const pathname = texto.slice(0, stop) || texto;
            const search = interrogarPos >= 0
                ? texto.slice(interrogarPos, hashPos >= 0 ? hashPos : undefined)
                : '';

            if (!ehPaginaConfigurador(pathname, search)) {
                return null;
            }

            return texto;
        }
    }

    function normalizarRetornoParaQuery(valor) {
        if (!valor || typeof valor !== 'string') {
            return valor;
        }
        if (!valor.includes('%')) {
            return valor;
        }
        try {
            return decodeURIComponent(valor);
        } catch {
            return valor;
        }
    }

    function filtrarRetornoAreaCliente(valor) {
        if (!valor || typeof valor !== 'string') {
            return valor;
        }
        const ehArquivoEstatico = (pathname) => {
            if (!pathname) {
                return false;
            }
            if (/^\/service-worker(\.min)?\.js$/i.test(pathname)) {
                return true;
            }
            return /\.(?:js|json|css|map|png|jpe?g|gif|webp|svg|ico|txt|xml)$/i.test(
                pathname
            );
        };
        try {
            const url = new URL(valor, window.location.origin);
            const caminho = url.pathname.replace(/\/+$/g, '') || '/';
            if (caminho === '/' || caminho === '/RedutoresIBR.html') {
                return null;
            }
            if (ehArquivoEstatico(caminho)) {
                return null;
            }
            if (ehPaginaAreaCliente(url.pathname)) {
                return null;
            }
        } catch {
            const texto = valor.trim().toLowerCase();
            if (texto === '/' || texto === '/redutoresibr.html') {
                return null;
            }
            if (
                texto === '/service-worker.js' ||
                texto === '/service-worker.min.js' ||
                /\.(?:js|json|css|map|png|jpe?g|gif|webp|svg|ico|txt|xml)$/i.test(texto)
            ) {
                return null;
            }
            if (texto.startsWith('/areacliente')) {
                return null;
            }
        }
        return valor;
    }


    function persistirConfiguradorDestino(caminho) {
        const normalizado = normalizarDestinoConfigurador(caminho);
        if (!normalizado) {
            return;
        }

        try {
            sessionStorage.setItem(CONFIGURADOR_STORAGE_KEY, normalizado);
        } catch { }

        try {
            localStorage.setItem(CONFIGURADOR_STORAGE_KEY, normalizado);
        } catch { }
    }

    function ehPaginaAreaCliente(pathname = '') {
        if (typeof pathname !== 'string') {

            return false;
        }

        const caminho = pathname.trim().toLowerCase();

        return (
            caminho.startsWith('/areacliente') ||
            caminho.startsWith('/paginasareacliente')
        );
    }

    function ehPaginaSiteForaAreaCliente(pathname = '') {
        if (!pathname || typeof pathname !== 'string') {
            return false;
        }

        const texto = pathname.trim();
        if (!texto) {
            return false;
        }

        if (!texto.startsWith('/')) {
            return false;
        }

        if (ehPaginaAreaCliente(texto)) {
            return false;
        }

        return true;
    }

    function ehPaginaConfigurador(pathname = '', search = '') {
        if (!ehPaginaSiteForaAreaCliente(pathname)) {
            return false;
        }

        const possuiIndicadorConfigurador = /Configurador/i.test(pathname);
        const possuiParametroConfigurador =
            typeof search === 'string' && search.includes('QULN=');

        if (possuiIndicadorConfigurador || possuiParametroConfigurador) {
            return true;
        }

        // Caso não seja uma URL explicitamente do configurador, ainda assim tratamos
        // qualquer página do site (fora da área do cliente) como destino válido.
        return true;
    }

    function tentarObterConfiguradorDoReferrer() {
        try {
            const ref = document.referrer;
            if (!ref) {
                return null;
            }

            const caminho = normalizarDestinoConfigurador(ref);

            if (caminho) {
                persistirConfiguradorDestino(caminho);
            }

            return caminho;
        } catch {
            return null;
        }
    }

    function obterUltimoConfiguradorDestino() {
        try {
            const candidatos = [
                sessionStorage.getItem(CONFIGURADOR_STORAGE_KEY),
                localStorage.getItem(CONFIGURADOR_STORAGE_KEY),
                sessionStorage.getItem(LEGACY_CONFIGURADOR_STORAGE_KEY),
                localStorage.getItem(LEGACY_CONFIGURADOR_STORAGE_KEY),
                sessionStorage.getItem('retornoPosLogin')
            ];

            for (let i = 0; i < candidatos.length; i += 1) {
                const candidato = normalizarDestinoConfigurador(candidatos[i]);
                if (candidato) {
                    persistirConfiguradorDestino(candidato);
                    return candidato;
                }
            }

            const doReferrer = tentarObterConfiguradorDoReferrer();
            if (doReferrer) {
                return doReferrer;
            }

            return '/';
        } catch {
            return '/';
        }
    }

    function obterDadosUrlParaConfigurador(targetUrl) {
        function adicionarOrigin(origens, origin) {
            if (!origin || typeof origin !== 'string') {
                return;
            }
            if (origens.indexOf(origin) === -1) {
                origens.push(origin);
            }
        }

        function obterOriginsPermitidos() {
            const origens = [];
            try {
                if (window.location && window.location.origin) {
                    adicionarOrigin(origens, window.location.origin);
                }
            } catch { }

            try {
                if (window.location && window.location.protocol && window.location.host) {
                    adicionarOrigin(origens, window.location.protocol + '//' + window.location.host);
                }
            } catch { }

            try {
                adicionarOrigin(origens, new URL(CANONICAL_ORIGIN).origin);
            } catch { }

            return origens;
        }

        function origemEhPermitida(origin, permitidas) {
            if (!origin) {
                return true;
            }
            return permitidas.indexOf(origin) !== -1;
        }

        function dadosDaLocalizacaoAtual() {
            try {
                const atual = window.location || {};
                const pathname = typeof atual.pathname === 'string' ? atual.pathname : '';
                if (!pathname) {
                    return null;
                }
                return {
                    pathname: pathname,
                    search: typeof atual.search === 'string' ? atual.search : '',
                    hash: typeof atual.hash === 'string' ? atual.hash : ''
                };
            } catch {
                return null;
            }
        }

        const permitidas = obterOriginsPermitidos();
        const baseOrigin = permitidas.length ? permitidas[0] : undefined;

        if (!targetUrl) {
            return dadosDaLocalizacaoAtual();
        }

        if (typeof targetUrl === 'string') {
            try {
                const url = new URL(targetUrl, baseOrigin);
                if (!origemEhPermitida(url.origin, permitidas)) {
                    return dadosDaLocalizacaoAtual();
                }
                return {
                    pathname: url.pathname,
                    search: url.search,
                    hash: url.hash
                };
            } catch {
                return dadosDaLocalizacaoAtual();
            }
        }

        if (typeof targetUrl === 'object' && targetUrl !== null) {
            const caminho = typeof targetUrl.pathname === 'string' ? targetUrl.pathname : '';
            if (caminho) {
                return {
                    pathname: caminho,
                    search: typeof targetUrl.search === 'string' ? targetUrl.search : '',
                    hash: typeof targetUrl.hash === 'string' ? targetUrl.hash : ''
                };
            }
            if (typeof targetUrl.href === 'string') {
                return obterDadosUrlParaConfigurador(targetUrl.href);
            }
        }

        return dadosDaLocalizacaoAtual();
    }

    function sincronizarRetornoPosLogin(destino) {
        if (!destino) {
            return;
        }
        try {
            sessionStorage.setItem('retornoPosLogin', destino);
        } catch { }
    }

    function salvarConfiguradorAtualSeNecessario(targetUrl) {
        try {
            const dados = obterDadosUrlParaConfigurador(targetUrl);
            if (!dados || !dados.pathname) {
                return;
            }

            const pathname = dados.pathname;
            const search = typeof dados.search === 'string' ? dados.search : '';
            const hash = typeof dados.hash === 'string' ? dados.hash : '';
            if (!pathname || ehPaginaAreaCliente(pathname)) {
                return;
            }

            if (!ehPaginaConfigurador(pathname, search)) {
                return;
            }

            const atual = pathname + search + hash;
            persistirConfiguradorDestino(atual);
            sincronizarRetornoPosLogin(atual);
        } catch {
            // Ignora erros de acesso ao sessionStorage
        }
    }

    function registrarConfiguradorPorRetornoQuery() {
        try {
            const params = new URLSearchParams(window.location.search || '');
            const retorno = params.get('retorno');
            if (!retorno) {
                return;
            }

            const caminho = normalizarDestinoConfigurador(retorno);
            if (!caminho) {
                return;
            }

            persistirConfiguradorDestino(caminho);
        } catch {
            // Ignora erros de parsing
        }
    }


    // Salva a página atual para retorno após login ou logout
    runWhenDomReady(() => {
        const foraAreaCliente = !/^\/(Paginas\/)?AreaCliente/.test(
            window.location.pathname
        );
        if (foraAreaCliente) {
            try {
                const atual =
                    window.location.pathname +
                    window.location.search +
                    window.location.hash;
                const caminhoAtual = window.location.pathname.replace(/\/+$/g, '') || '/';
                if (caminhoAtual !== '/' && caminhoAtual !== '/RedutoresIBR.html') {
                    sessionStorage.setItem('retornoPosLogin', atual);
                }
            } catch { }
        }
        salvarConfiguradorAtualSeNecessario();
        try {
            if (ehPaginaAreaCliente(window.location.pathname)) {
                tentarObterConfiguradorDoReferrer();
            }
        } catch {
            // Ignora erros ao acessar window.location
        }
        registrarConfiguradorPorRetornoQuery();
        const normalizarCaminho = (path) => {
            if (!path || path === '/') {
                return '/';
            }
            return path.replace(/\/+$/g, '');
        };

        const anexarRetorno = (selectorOrElements, overwrite = true, useCurrent = false) => {
            const elementos =
                typeof selectorOrElements === 'string'
                    ? Array.from(document.querySelectorAll(selectorOrElements))
                    : Array.from(selectorOrElements || []);

            elementos.forEach((link) => {
                if (!(link instanceof HTMLAnchorElement)) {
                    return;
                }
                const obterTokenCsrf = async () => {
                    if (window.csrfToken) {
                        return window.csrfToken;
                    }
                    try {
                        const resposta = await fetch('/CSRFPega.php', { credentials: 'same-origin' });
                        const dados = await resposta.json().catch(() => null);
                        if (dados && dados.token) {
                            window.csrfToken = dados.token;
                        }
                    } catch { }
                    return window.csrfToken;
                };
                const enviarLogoutPost = async (url) => {
                    const token = await obterTokenCsrf();
                    if (!token) {
                        return;
                    }

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = url.pathname;
                    form.style.display = 'none';

                    const params = url.searchParams;
                    params.forEach((value, key) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = value;
                        form.appendChild(input);
                    });

                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = 'csrf_token';
                    csrfInput.value = token;
                    form.appendChild(csrfInput);

                    document.body.appendChild(form);
                    form.submit();
                };
                const atualizarHref = () => {
                    try {
                        let destino = sessionStorage.getItem('retornoPosLogin');
                        const atual =
                            window.location.pathname +
                            window.location.search +
                            window.location.hash;
                        const configurador = obterUltimoConfiguradorDestino();

                        if (useCurrent) {
                            destino = atual;
                            sessionStorage.setItem('retornoPosLogin', destino);
                        } else if (overwrite || !destino) {
                            destino = configurador;
                            sessionStorage.setItem('retornoPosLogin', destino);
                        }

                        destino = filtrarRetornoAreaCliente(destino);

                        const originalHref = link.getAttribute('href');
                        if (!originalHref) {
                            return null;
                        }

                        const url = new URL(originalHref, window.location.origin);
                        if (url.origin !== window.location.origin) {
                            return url;
                        }

                        if (destino) {
                            const retornoNormalizado = normalizarRetornoParaQuery(destino);
                            url.searchParams.set('retorno', retornoNormalizado);
                            url.search = url.search.replace(/%2F/gi, '/');
                        } else {
                            url.searchParams.delete('retorno');
                        }

                        const isAbsolute = /^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(originalHref);
                        const finalHref = isAbsolute
                            ? url.toString()
                            : url.pathname + url.search + url.hash;

                        link.setAttribute('href', finalHref);

                        return url;
                    } catch {
                        return null;
                    }
                };


                link.addEventListener('click', (e) => {
                    const url = atualizarHref();
                    if (!url) return;

                    const caminhoNormalizado = normalizarCaminhoAreaCliente(url.pathname);
                    const deveIrParaSessao =
                        window.__areaClienteLogado === true &&
                        caminhoNormalizado === normalizarCaminhoAreaCliente(AREA_CLIENTE_BASE_PATH);


                    if (deveIrParaSessao) {
                        url.pathname = AREA_CLIENTE_SESSAO_PATH;
                        const finalHref = url.origin === window.location.origin
                            ? url.pathname + url.search + url.hash
                            : url.toString();
                        link.setAttribute('href', finalHref);
                    }

                    const caminhoNormalizadoLogout = normalizarCaminho(url.pathname);
                    const ehLogout =
                        caminhoNormalizadoLogout === '/PaginasAreaClienteSessaoPerfil/Sair.php' ||
                        caminhoNormalizadoLogout === '/AreaCliente/Sair';
                    if (ehLogout) {
                        storeAreaClienteState(false);
                    }

                    if (
                        e.button === 0 &&
                        !e.ctrlKey &&
                        !e.metaKey &&
                        !e.shiftKey &&
                        !e.altKey
                    ) {
                        e.preventDefault();
                        if (ehLogout) {
                            enviarLogoutPost(url);
                            return;
                        }
                        window.location.href = url.toString();
                    }
                });

                // Garante que o href já contenha o parâmetro de retorno
                atualizarHref();
            });
        };
        window.anexarRetorno = anexarRetorno;
        const selecionarLinksPorCaminho = (paths) => {
            const normalizados = paths.map(normalizarCaminho);
            return Array.from(document.querySelectorAll('a[href]')).filter((link) => {
                const href = link.getAttribute('href');
                if (!href) {
                    return false;
                }
                try {
                    const url = new URL(href, window.location.origin);
                    if (url.origin !== window.location.origin) {
                        return false;
                    }
                    const caminho = normalizarCaminho(url.pathname);
                    return normalizados.includes(caminho);
                } catch {
                    return false;
                }
            });
        };

        anexarRetorno(
            selecionarLinksPorCaminho(['/AreaCliente']),
            foraAreaCliente,
            true
        );
        anexarRetorno(
            selecionarLinksPorCaminho([
                '/PaginasAreaClienteSessaoPerfil/Sair.php',
                '/AreaCliente/Sair'
            ]),
            false
        );
        anexarRetorno('#linkConfigurador', false, true);
        const configuradorLink = document.getElementById('linkConfigurador');
        if (configuradorLink) {
            configuradorLink.addEventListener('click', () => {
                try {
                    const atual =
                        window.location.pathname +
                        window.location.search +
                        window.location.hash;
                    sessionStorage.setItem('retornoPosLogin', atual);
                } catch { }
            });
        }
    });

    if (typeof window.voltarParaConfiguradorOuHome !== 'function') {
        window.voltarParaConfiguradorOuHome = () => {
            const destino = obterUltimoConfiguradorDestino();
            window.location.href = destino;
        };
    }

    runWhenDomReady(() => {
        document.querySelectorAll('.link-voltar-configurador').forEach((link) => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                if (window.voltarParaConfiguradorOuHome) {
                    window.voltarParaConfiguradorOuHome();
                } else {
                    const destino = obterUltimoConfiguradorDestino();
                    window.location.href = destino;
                }
            });
        });
    });
}

export function initAreaCliente() {
    if (areaClienteBootstrapStarted) {
        return;
    }

    const startBootstrap = () => {
        if (areaClienteBootstrapStarted) {
            return;
        }
        areaClienteBootstrapStarted = true;
        bootstrapAreaCliente();
    };

    const root = document.documentElement;
    const shouldWaitForLoader =
        root &&
        root.classList &&
        root.classList.contains('is-initial-loading');

    if (shouldWaitForLoader) {
        const eventTarget = typeof window !== 'undefined' ? window : document;
        const handleLoaderHidden = () => {
            eventTarget.removeEventListener(INITIAL_LOADER_HIDDEN_EVENT, handleLoaderHidden);
            startBootstrap();
        };
        eventTarget.addEventListener(INITIAL_LOADER_HIDDEN_EVENT, handleLoaderHidden, { once: true });
    } else {
        startBootstrap();
    }
}

runWhenDomReady(() => {
    const AREA_CLIENTE_NAV_LOADER_STORAGE_KEY = 'areaClienteNavLoaderStartedAt';

    document.addEventListener('click', (event) => {
        const target = event && event.target;
        if (!target || typeof target.closest !== 'function') {
            return;
        }

        const areaClienteLink = target.closest('.area-cliente-nav a[href]');
        if (!areaClienteLink) {
            return;
        }

        try {
            sessionStorage.setItem(AREA_CLIENTE_NAV_LOADER_STORAGE_KEY, String(Date.now()));
        } catch { }
    }, true);
});
