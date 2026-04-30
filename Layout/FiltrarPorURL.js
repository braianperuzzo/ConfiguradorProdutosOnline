(function () {
    function applyFilter(value) {
        value = (value || '').trim().toUpperCase();
        document.querySelectorAll('.galeria-produto[data-visible]').forEach(el => {
            const visible = (el.getAttribute('data-visible') || '').trim().toUpperCase();
            el.style.display = !value || visible === value ? '' : 'none';
        });
    }

    function init() {
        const params = new URLSearchParams(window.location.search);
        let param = [...params.keys()].find(key => key.toUpperCase().endsWith('LN'));

        if (!param) {
            const selectEl = document.querySelector('select[id$="LN"]');
            if (selectEl) param = selectEl.id;
        }

        if (!param) param = 'QULN';

        const urlValue = params.get(param) || '';
        applyFilter(urlValue);

        const select = document.getElementById(param);
        if (select) {
            if (urlValue) select.value = urlValue.toUpperCase();
            select.addEventListener('change', () => applyFilter(select.value));
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init());
    } else {
        init();
    }

    function getParamValue(params, key) {
        if (!params.has(key)) {
            return '';
        }
        return params.get(key) || '';
    }

    function normalizeValue(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toUpperCase();
    }

    function optionMatches(option, desiredNormalized) {
        const optionValue = normalizeValue(option?.value);
        const optionText = normalizeValue(option?.textContent);
        const dataValue = normalizeValue(option?.dataset?.value);
        return optionValue === desiredNormalized || optionText === desiredNormalized || dataValue === desiredNormalized;
    }

    function findOptionValue(select, desiredValue) {
        if (!select || !desiredValue) {
            return '';
        }
        const desiredNormalized = normalizeValue(desiredValue);
        const options = Array.from(select.options || []);
        const match = options.find(option => optionMatches(option, desiredNormalized));
        return match ? match.value : '';
    }

    function focusSelect(select) {
        if (!select) return;
        try {
            select.focus({ preventScroll: true });
            select.click();
        } catch (error) {
            select.focus();
        }
    }

    function highlightSelect(select) {
        const section = select?.closest('section') || select?.parentElement;
        if (select) {
            select.classList.add('campo-invalido');
        }
        if (section?.scrollIntoView) {
            section.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        focusSelect(select);
    }

    function finalizeLoading() {
        const paramCount = typeof window !== 'undefined' && Number.isFinite(Number(window._paramCount))
            ? Number(window._paramCount)
            : Array.from(new URLSearchParams(window.location.search).keys()).length;
        const aguardandoGeracaoProduto = paramCount > 1 && !Boolean(window.produtoGerado);

        if (aguardandoGeracaoProduto) {
            return;
        }

        if (typeof window.finishLoading === 'function') {
            window.finishLoading();
            return;
        }

        if (typeof window !== 'undefined') {
            window.keepLoading = false;
        }
        if (typeof window.hideLoadingScreen === 'function') {
            window.hideLoadingScreen(true);
        } else if (typeof window.requestLoaderHide === 'function') {
            window.requestLoaderHide();
        }
    }

    let incompleteMessageShown = false;

    function isModoPreenchimentoIntercambialidade(params) {
        return params.get('intercambialidadeModoPreenchimento') === '1';
    }

    function obterPrimeiroCampoVazio() {
        const selects = Array.from(document.querySelectorAll('select[id]'));
        return selects.find(select => {
            if (!select || select.disabled) return false;
            if (select.offsetParent === null) return false;
            const valorAtual = String(select.value || '').trim();
            if (valorAtual) return false;
            const opcoesValidas = Array.from(select.options || []).filter(option => String(option.value || '').trim());
            return opcoesValidas.length > 0;
        }) || null;
    }

    function showIncompleteMessage(params) {
        if (incompleteMessageShown) return;
        incompleteMessageShown = true;

        const mensagem = isModoPreenchimentoIntercambialidade(params)
            ? '⚠️ Por favor, preencha todos os campos antes de gerar o produto.'
            : '⚠️ Oops 😕 parece que algumas opções que você tentou acessar não estão mais disponíveis. Para seguir com a troca, complete as informações que faltam a partir do campo destacado ✅ e depois crie um novo produto para concluir 📦';

        if (typeof window.exibirAlerta === 'function') {
            window.exibirAlerta(mensagem);
            return;
        }
        if (typeof window.alert === 'function') {
            window.alert(mensagem);
        }
    }

    function countMatchingUrlParams(params, selects) {
        const selectIds = new Set((selects || []).map(select => String(select?.id || '').trim()).filter(Boolean));
        if (!selectIds.size) {
            return 0;
        }

        let count = 0;
        params.forEach((value, key) => {
            if (!selectIds.has(key)) {
                return;
            }
            if (!String(value || '').trim()) {
                return;
            }
            count += 1;
        });
        return count;
    }

    function waitForSelectReady(select, timeoutMs = 10000) {
        return new Promise(resolve => {
            const start = Date.now();
            const tick = () => {
                const enabled = select && !select.disabled;
                if (select && enabled) {
                    resolve(true);
                    return;
                }
                if (Date.now() - start >= timeoutMs) {
                    resolve(false);
                    return;
                }
                setTimeout(tick, 120);
            };
            tick();
        });
    }

    function waitForOptionAvailability(select, desiredValue, timeoutMs = 8000) {
        return new Promise(resolve => {
            const start = Date.now();
            const desiredNormalized = normalizeValue(desiredValue);
            const tick = () => {
                const options = Array.from(select?.options || []);
                const available = options.some(option => optionMatches(option, desiredNormalized));
                if (available) {
                    resolve(true);
                    return;
                }
                if (Date.now() - start >= timeoutMs) {
                    resolve(false);
                    return;
                }
                setTimeout(tick, 120);
            };
            tick();
        });
    }

    async function preencherConfiguradorPorURL() {
        const params = new URLSearchParams(window.location.search);
        const selects = Array.from(document.querySelectorAll('select[id]'));
        const matchingParamCount = countMatchingUrlParams(params, selects);

        if (!selects.length) {
            return;
        }

        const pendencias = [];

        for (const select of selects) {
            const ready = await waitForSelectReady(select);
            const desiredValue = getParamValue(params, select.id);
            if (!desiredValue) {
                continue;
            }

            if (!ready) {
                pendencias.push({ select, desiredValue });
                continue;
            }

            const optionReady = await waitForOptionAvailability(select, desiredValue);
            if (!optionReady) {
                pendencias.push({ select, desiredValue });
                continue;
            }

            const optionValue = findOptionValue(select, desiredValue);
            if (!optionValue) {
                pendencias.push({ select, desiredValue });
                continue;
            }

            if (select.value !== optionValue) {
                select.value = optionValue;
                select.dispatchEvent(new Event('change'));
            }
        }

        if (pendencias.length) {
            const start = Date.now();
            let restantes = pendencias;
            while (Date.now() - start < 15000 && restantes.length) {
                const proximaRodada = [];
                for (const { select, desiredValue } of restantes) {
                    const ready = await waitForSelectReady(select, 1200);
                    if (!ready) {
                        proximaRodada.push({ select, desiredValue });
                        continue;
                    }
                    const optionReady = await waitForOptionAvailability(select, desiredValue, 1200);
                    if (!optionReady) {
                        proximaRodada.push({ select, desiredValue });
                        continue;
                    }
                    const optionValue = findOptionValue(select, desiredValue);
                    if (!optionValue) {
                        proximaRodada.push({ select, desiredValue });
                        continue;
                    }
                    if (select.value !== optionValue) {
                        select.value = optionValue;
                        select.dispatchEvent(new Event('change'));
                    }
                }

                if (!proximaRodada.length) {
                    restantes = [];
                    break;
                }

                restantes = proximaRodada;
                await new Promise(resolve => setTimeout(resolve, 250));
            }

            if (restantes.length) {
                const shouldWarnUser = isModoPreenchimentoIntercambialidade(params) || matchingParamCount > 1;
                if (shouldWarnUser) {
                    const { select } = restantes[0];
                    const campoVazio = obterPrimeiroCampoVazio();
                    highlightSelect(campoVazio || select);
                    showIncompleteMessage(params);
                }
            }
        }

        finalizeLoading();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => preencherConfiguradorPorURL());
    } else {
        preencherConfiguradorPorURL();
    }
})();
