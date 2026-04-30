var forceLoadVisibleLazyImages = () => { };
if (typeof window !== 'undefined') {
    window.forceLoadVisibleLazyImages = (...args) => forceLoadVisibleLazyImages(...args);
}

const preloadedImageManifest = typeof window !== 'undefined' ? window.__IMAGENS_PRODUTOS_MANIFEST__ : null;
const resolvedAppVersion = (() => {
    if (typeof APP_VERSION === 'string' && APP_VERSION.trim()) {
        return APP_VERSION.trim();
    }
    if (typeof window !== 'undefined') {
        const versionFromWindow = window.APP_VERSION;
        if (typeof versionFromWindow === 'string' && versionFromWindow.trim()) {
            return versionFromWindow.trim();
        }
    }
    return null;
})();
const manifestUrl = resolvedAppVersion
    ? `/ImagensProdutos/manifest.json?v=${encodeURIComponent(resolvedAppVersion)}`
    : '/ImagensProdutos/manifest.json';
const metadataUrl = resolvedAppVersion
    ? `/PaginasConfiguradores/InformacoesProdutos.json?v=${encodeURIComponent(resolvedAppVersion)}`
    : '/PaginasConfiguradores/InformacoesProdutos.json';
const manifestFetch = resolvedAppVersion
    ? fetch(manifestUrl, { cache: 'force-cache' })
    : fetch(manifestUrl);
const imageManifestPromise = preloadedImageManifest
    ? Promise.resolve(preloadedImageManifest)
    : manifestFetch
        .then(response => response.ok ? response.json() : null)
        .catch(() => null);

const enviarEventoGtag = (eventName, payload = {}) => {
    if (typeof window.gtag !== 'function') return;
    window.gtag('event', eventName, payload);
};

const descricaoPorLinha = {};
let configuradorMetadata = null;
let metadataPromise = null;
let metadataAplicada = false;


const linhasFiltroPorCodigo = {
    '1.C': ['Alto Rendimento'],
    '1.H': ['Alto Rendimento'],
    '1.M': ['Alto Rendimento'],
    '1.P': ['Alto Rendimento'],
    '1.R': ['Alto Rendimento'],
    '1.X': ['Alto Rendimento'],
    '1.FFA': ['Alto Rendimento'],
    '1.FKA': ['Alto Rendimento'],
    '1.FR': ['Alto Rendimento']
};

const obterLinhasFiltroFallback = linha => {
    if (!linha) return [];
    return Array.isArray(linhasFiltroPorCodigo[linha]) ? linhasFiltroPorCodigo[linha] : [];
};

const inicializarPopoverAjudaProduto = () => {
    if (!window.LayoutAjudaSeletores || typeof window.LayoutAjudaSeletores.initHelpButtons !== 'function') {
        return;
    }

    window.LayoutAjudaSeletores.initHelpButtons(document);
};

const atualizarConteudoPopoverAjudaProduto = helpButton => {
    if (!helpButton || !window.bootstrap || !window.bootstrap.Popover) {
        return;
    }

    const popover = window.bootstrap.Popover.getInstance(helpButton);
    if (!popover || typeof popover.setContent !== 'function') {
        return;
    }

    popover.setContent({
        '.popover-header': helpButton.dataset.helpTitle || '',
        '.popover-body': helpButton.dataset.helpText || ''
    });
};

const alternarPopoverAjudaProduto = helpButton => {
    if (!helpButton || !window.bootstrap || !window.bootstrap.Popover) {
        return;
    }

    const popover = window.bootstrap.Popover.getInstance(helpButton);
    if (!popover || typeof popover.toggle !== 'function') {
        return;
    }

    document.querySelectorAll('.pagina-produtos__help-icon').forEach(otherButton => {
        if (otherButton === helpButton) return;
        const otherPopover = window.bootstrap.Popover.getInstance(otherButton);
        if (otherPopover && typeof otherPopover.hide === 'function') {
            otherPopover.hide();
        }
    });

    popover.toggle();
};

const normalizarDescricaoPopover = texto => {
    if (typeof texto !== 'string') return '';
    return texto
        .trim()
        .replace(/([.!?])(?=[A-ZÀ-Ý])/g, '$1 ')
        .replace(/\s{2,}/g, ' ');
};

const obterDescricaoProduto = (anchor, linha) => {
    const descricaoAnchor = normalizarDescricaoPopover(anchor.dataset.descricao || '');
    if (descricaoAnchor) return descricaoAnchor;

    if (linha && configuradorMetadata && configuradorMetadata[linha] && typeof configuradorMetadata[linha].description === 'string') {
        return normalizarDescricaoPopover(configuradorMetadata[linha].description);
    }

    if (linha && typeof descricaoPorLinha[linha] === 'string') {
        return normalizarDescricaoPopover(descricaoPorLinha[linha]);
    }

    return '';
};

const obterConteudoAjudaProduto = anchor => {
    const nomeTituloBotao = (anchor.querySelector('.botao-titulo')?.textContent || anchor.getAttribute('title') || 'Produto').trim();
    const linha = obterLinhaConfigurador(anchor);
    const nomeLinha = (anchor.dataset.tituloPagina || '').trim() || nomeTituloBotao;
    const possuiDesenho = anchor.dataset.desenho === '1' || !!anchor.querySelector('.badge-novo');
    const descricao = obterDescricaoProduto(anchor, linha);
    const categorias = (anchor.dataset.categorias || '')
        .split('|')
        .map(item => item.trim())
        .filter(Boolean);
    const grupos = (anchor.dataset.linhasGrupo || '')
        .split('|')
        .map(item => item.trim())
        .filter(Boolean);
    const linhasFiltro = (anchor.dataset.linhasFiltro || '')
        .split('|')
        .map(item => item.trim())
        .filter(Boolean);
    const linhasResumo = linhasFiltro.length
        ? linhasFiltro
        : (grupos.length ? grupos : obterLinhasFiltroFallback(linha));

    const detalhesTecnicos = [
        `<li><strong>Produtos:</strong> ${categorias.length ? categorias.join(', ') : 'Não informado'}</li>`,
        `<li><strong>Linhas:</strong> ${linhasResumo.length ? linhasResumo.join(', ') : 'Não informado'}</li>`,
        `<li><strong>Desenhos Disponíveis:</strong> ${possuiDesenho ? 'Sim' : 'Não'}</li>`
    ].join('');

    return {
        titulo: nomeLinha,
        html: [
            `<p class="mb-2">${descricao || `A linha ${nomeLinha} não possui descrição técnica cadastrada.`}</p>`,
            '<p class="mb-1"><strong>Resumo:</strong></p>',
            `<ul class="mb-2 ps-3">${detalhesTecnicos}</ul>`
        ].join('')
    };
};

const aplicarConteudoAjudaNoBotao = (anchor, helpButton) => {
    if (!anchor || !helpButton) return;
    const { titulo, html } = obterConteudoAjudaProduto(anchor);
    helpButton.setAttribute('data-help-title', titulo);
    helpButton.setAttribute('data-help-text', html);
    helpButton.setAttribute('data-bs-title', titulo);
    helpButton.setAttribute('data-bs-content', html);
    atualizarConteudoPopoverAjudaProduto(helpButton);
};

const inicializarHelpIconsProdutos = () => {
    const anchors = document.querySelectorAll('.pagina-produtos__home-botoes .botao-home');
    if (!anchors.length) return;

    anchors.forEach(anchor => {
        let helpButton = anchor.querySelector('.pagina-produtos__help-icon');
        if (!helpButton) {
            helpButton = document.createElement('button');
            helpButton.type = 'button';
            helpButton.className = 'help-icon pagina-produtos__help-icon';
            helpButton.setAttribute('aria-label', 'Ajuda deste produto');
            helpButton.setAttribute('data-help-class', 'perm-popover');
            helpButton.setAttribute('data-gtag-event', 'home-produto-ajuda-popover');
            helpButton.setAttribute('data-gtag-category', 'Home');

            helpButton.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === 'function') {
                    event.stopImmediatePropagation();
                }
                aplicarConteudoAjudaNoBotao(anchor, helpButton);

                if (!anchor.dataset.descricao && typeof carregarMetadataConfiguradores === 'function') {
                    carregarMetadataConfiguradores().then(() => {
                        aplicarConteudoAjudaNoBotao(anchor, helpButton);
                    });
                }

                const linha = obterLinhaConfigurador(anchor) || 'desconhecida';
                const nomeProduto = (anchor.querySelector('.botao-titulo')?.textContent || anchor.getAttribute('title') || linha).trim();
                enviarEventoGtag('home-produto-ajuda-popover', {
                    event_category: 'Home',
                    event_label: linha,
                    product_name: nomeProduto,
                    help_origin: 'card_produto'
                });

                alternarPopoverAjudaProduto(helpButton);
            });

            helpButton.addEventListener('pointerdown', event => {
                event.stopPropagation();
            });

            helpButton.addEventListener('pointerup', event => {
                event.stopPropagation();
            });

            anchor.appendChild(helpButton);
        }

        aplicarConteudoAjudaNoBotao(anchor, helpButton);
    });

    inicializarPopoverAjudaProduto();
};

const obterLinhaConfigurador = anchor => {
    if (!anchor) return '';
    const linhaData = anchor.dataset && anchor.dataset.linha ? anchor.dataset.linha : '';
    if (linhaData && linhaData.trim()) return linhaData.trim();
    const href = anchor.getAttribute ? anchor.getAttribute('href') : '';
    if (!href) return '';
    const queryIndex = href.indexOf('?');
    const query = queryIndex >= 0 ? href.slice(queryIndex) : '';
    const match = query.match(/([?&][^=]*LN)=([^&]+)/i);
    const linha = match ? decodeURIComponent(match[2]) : '';
    return linha.trim();
};

document.querySelectorAll('.botao-linha').forEach((el, i) => {
    el.style.animationDelay = `${i * 0.1}s`;
});
document.querySelectorAll('.botao-home').forEach(anchor => {
    const img = anchor.querySelector('img');
    let path = img.dataset.path;
    const currentSrcAttr = img.dataset.src || img.getAttribute('src') || '';

    if (!path) {
        const folderFromSrc = currentSrcAttr.match(/^(.*\/)\d+[^/]*\.webp$/);
        if (folderFromSrc) {
            path = folderFromSrc[1];
        } else {
            const slashIndex = currentSrcAttr.lastIndexOf('/');
            if (slashIndex !== -1) {
                path = currentSrcAttr.slice(0, slashIndex + 1);
            }
        }
        if (path) {
            img.dataset.path = path;
        }
    }

    if (path && path.endsWith('/') && (!currentSrcAttr || currentSrcAttr.startsWith('data:'))) {
        const parts = path.split('/').filter(Boolean);
        const folder = (parts[parts.length - 1] || '').replace(/\W+/g, '');
        if (!img.dataset.src) {
            img.dataset.src = `${path}0${folder}.webp`;
        }
    }
    const resolvedSrc = img.dataset.src || img.src;
    const m = resolvedSrc.match(/^(.*\/)(\d+)([^/]+)\.webp$/);
    if (!m) return;
    const base = m[1];
    const name = m[3];
    const urls = [resolvedSrc];
    let idx = 0;
    const startIndex = parseInt(m[2], 10);
    const folderFromPath = (img.dataset.path || '')
        .split('/')
        .filter(Boolean)
        .pop() || '';
    const manifestLookupKeys = Array.from(new Set([
        folderFromPath,
        name
    ].filter(Boolean)));

    const toAbsoluteUrl = (fileName, fallbackBase) => {
        if (!fileName) return null;
        if (typeof fileName === 'object') {
            fileName = fileName.url || fileName.path || fileName.file || '';
        }
        if (!fileName) return null;
        if (/^https?:\/\//i.test(fileName)) return fileName;
        if (fileName.startsWith('/')) return fileName;
        return `${fallbackBase}${fileName.replace(/^\/+/, '')}`;
    };

    const applyManifestEntry = entry => {
        if (!entry || !Array.isArray(entry.files) || !entry.files.length) return false;
        const manifestBase = entry.basePath || base;
        const resolved = entry.files
            .map(file => toAbsoluteUrl(file, manifestBase))
            .filter(Boolean);
        if (!resolved.length) return false;
        const unique = Array.from(new Set(resolved));
        urls.splice(0, urls.length, ...unique);
        idx = Math.min(idx, urls.length - 1);
        if (urls[idx]) {
            img.dataset.src = urls[idx];
        }
        return true;
    };

    const limitedFallbackLoad = (maxAdditional = 2) => {
        if (!Number.isFinite(startIndex)) return;
        let attempts = 0;
        const tryNext = () => {
            if (attempts >= maxAdditional) return;
            const nextIndex = startIndex + 1 + attempts;
            const candidate = `${base}${nextIndex}${name}.webp`;
            const probe = new Image();
            probe.addEventListener('load', () => {
                urls.push(candidate);
                attempts++;
                tryNext();
            }, { once: true });
            probe.addEventListener('error', () => {
                attempts = maxAdditional;
            }, { once: true });
            probe.src = candidate;
        };
        tryNext();
    };

    let fallbackRequested = false;
    let fallbackStarted = false;
    let userInteracted = false;
    const ensureFallbackLoad = () => {
        if (!fallbackRequested || fallbackStarted || !userInteracted) return;
        fallbackStarted = true;
        limitedFallbackLoad();
    };
    const requestFallbackLoad = () => {
        if (!fallbackRequested) {
            fallbackRequested = true;
        }
        ensureFallbackLoad();
    };
    const markInteraction = () => {
        if (!userInteracted) {
            userInteracted = true;
        }
        ensureFallbackLoad();
    };

    imageManifestPromise
        .then(manifest => {
            if (!manifest) {
                requestFallbackLoad();
                return;
            }
            const entries = manifest.products || manifest.folders || {};
            let applied = false;
            for (const key of manifestLookupKeys) {
                if (entries[key]) {
                    applied = applyManifestEntry(entries[key]);
                    if (applied) break;
                }
            }
            if (!applied) {
                requestFallbackLoad();
            }
        })
        .catch(() => { requestFallbackLoad(); });
    const show = dir => {
        if (!urls.length) return;
        idx = (idx + dir + urls.length) % urls.length;
        img.dataset.src = urls[idx];
        img.src = urls[idx];
    };

    const handleError = () => {
        if (urls.length > 1) {
            urls.splice(idx, 1);
            if (idx >= urls.length) idx = 0;
            show(0);
        } else {
            img.removeEventListener('error', handleError);
        }
    };
    img.addEventListener('error', handleError);

    const resolveLinhaAnchor = () => obterLinhaConfigurador(anchor);
    const getGalleryLabel = () => {
        const linha = anchor.dataset.linha || '';
        if (linha) return linha;
        const titulo = anchor.querySelector('.botao-titulo');
        if (titulo && titulo.textContent) {
            return titulo.textContent.trim();
        }
        return (anchor.getAttribute('title') || '').trim();
    };
    const trackGalleryMove = (method, direction) => {
        const linha = resolveLinhaAnchor();
        const eventName = `home-galeria-${linha || 'desconhecida'}`;
        enviarEventoGtag(eventName, {
            event_category: 'Home',
            event_label: linha || getGalleryLabel() || 'galeria_produtos',
            gallery_method: method,
            gallery_direction: direction
        });
    };
    const handleArrowAction = (event, direction) => {
        event.preventDefault();
        event.stopPropagation();
        trackGalleryMove('botao', direction > 0 ? 'proxima' : 'anterior');
        show(direction);
    };
    const handleArrowKeydown = (event, direction) => {
        if (event.key === 'Enter' || event.key === ' ') {
            handleArrowAction(event, direction);
        }
    };
    const prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'galeria-arrow galeria-prev';
    prev.setAttribute('aria-label', 'Imagem anterior');
    prev.setAttribute('tabindex', '0');
    prev.addEventListener('click', event => handleArrowAction(event, -1));
    prev.addEventListener('keydown', event => handleArrowKeydown(event, -1));
    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'galeria-arrow galeria-next';
    next.setAttribute('aria-label', 'Próxima imagem');
    next.setAttribute('tabindex', '0');
    next.addEventListener('click', event => handleArrowAction(event, 1));
    next.addEventListener('keydown', event => handleArrowKeydown(event, 1));
    anchor.appendChild(prev);
    anchor.appendChild(next);

    let startX = 0;
    let dragging = false;
    let moved = false;
    anchor.addEventListener('pointerdown', e => {
        if (e.target && e.target.closest && e.target.closest('.pagina-produtos__help-icon')) {
            return;
        }
        startX = e.clientX;
        dragging = true;
        moved = false;
    });
    anchor.addEventListener('pointermove', e => {
        if (e.target && e.target.closest && e.target.closest('.pagina-produtos__help-icon')) {
            return;
        }
        if (!dragging) return;
        const diff = e.clientX - startX;
        if (Math.abs(diff) > 40) {
            const direction = diff > 0 ? 'anterior' : 'proxima';
            trackGalleryMove('arrastar', direction);
            show(diff > 0 ? -1 : 1);
            dragging = false;
            moved = true;
        }
    });
    anchor.addEventListener('pointerup', () => { dragging = false; });
    anchor.addEventListener('pointercancel', () => { dragging = false; });
    anchor.addEventListener('click', e => {
        if (e.target && e.target.closest && e.target.closest('.pagina-produtos__help-icon')) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        if (moved) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
            moved = false;
            return;
        }
        moved = false;
    });
    anchor.addEventListener('dragstart', e => {
        e.preventDefault();
    });
    img.setAttribute('draggable', 'false');
});

inicializarHelpIconsProdutos();

forceLoadVisibleLazyImages = () => { };
(function initHomePageInteractions() {
    let initialized = false;
    const run = () => {
        if (initialized) {
            return;
        }
        initialized = true;

        const allProductImages = Array.from(document.querySelectorAll('.lazy-product-image'));
        if (!allProductImages.length) {
            return;
        }
        const lcpHeroImage = allProductImages.find(img => img.dataset && img.dataset.lcpHero === 'true') || null;
        const allLazyImages = allProductImages.filter(img => img !== lcpHeroImage);

        const markImageAsLoaded = img => {
            if (!img) return;
            img.dataset.loaded = 'true';
            delete img.dataset.loading;
            img.dispatchEvent(new Event('lazyloaded'));
        };
        const applyResponsiveAttributes = img => {
            if (!img) return;
            const dataSrcset = img.dataset ? img.dataset.srcset : null;
            const dataSizes = img.dataset ? img.dataset.sizes : null;
            if (dataSrcset && !img.getAttribute('srcset')) {
                img.setAttribute('srcset', dataSrcset);
            }
            if (dataSizes && !img.getAttribute('sizes')) {
                img.setAttribute('sizes', dataSizes);
            } else if (!img.getAttribute('sizes')) {
                img.setAttribute('sizes', '(max-width: 599px) 100vw, (max-width: 1199px) 50vw, 33vw');
            }
        };
        const setFetchPriorityAttributes = (img, priority) => {
            if (!img) return;
            const normalized = priority === 'high' ? 'high' : 'low';
            img.setAttribute('fetchpriority', normalized);
            if (!(img.dataset && img.dataset.lcpHero === 'true')) {
                img.setAttribute('loading', normalized === 'high' ? 'eager' : 'lazy');
            }
            if ('fetchPriority' in img) {
                try {
                    img.fetchPriority = normalized;
                } catch (e) { }
            }
        };

        if (lcpHeroImage) {
            applyResponsiveAttributes(lcpHeroImage);
            setFetchPriorityAttributes(lcpHeroImage, 'high');
            const finalizeHero = () => markImageAsLoaded(lcpHeroImage);
            if (lcpHeroImage.complete) {
                finalizeHero();
            } else {
                lcpHeroImage.addEventListener('load', finalizeHero, { once: true });
                lcpHeroImage.addEventListener('error', finalizeHero, { once: true });
            }
        }

        if (!allLazyImages.length) {
            return;
        }
        const getMaxPriorityImages = () => {
            if (navigator.connection) {
                const conn = navigator.connection;
                if (conn.saveData || /(^|-)2g$/i.test(conn.effectiveType || '')) {
                    return 1;
                }
            }
            if (window.matchMedia) {
                if (window.matchMedia('(min-width: 1200px)').matches) {
                    return 3;
                }
                if (window.matchMedia('(min-width: 768px)').matches) {
                    return 2;
                }
            }
            return 1;
        };
        const maxEagerImages = getMaxPriorityImages();
        const eagerPriorityImages = [];
        const lazyImages = [];
        allLazyImages.forEach((img, index) => {
            const hasDataSrc = img.dataset && img.dataset.src;
            const markedEager = img.dataset && img.dataset.eager === 'true';
            if (!hasDataSrc) {
                eagerPriorityImages.push(img);
                return;
            }
            if ((markedEager || index < maxEagerImages) && eagerPriorityImages.length < maxEagerImages) {
                eagerPriorityImages.push(img);
                return;
            }
            lazyImages.push(img);
        });
        eagerPriorityImages.forEach(img => {
            const eagerSrc = img.dataset ? img.dataset.src : null;
            if (eagerSrc && img.src !== eagerSrc) {
                img.src = eagerSrc;
            }
            applyResponsiveAttributes(img);
            setFetchPriorityAttributes(img, 'high');
            const finalize = () => markImageAsLoaded(img);
            if (img.complete) {
                finalize();
            } else {
                img.addEventListener('load', finalize, { once: true });
                img.addEventListener('error', finalize, { once: true });
            }
        });
        if (!lazyImages.length) {
            return;
        }
        const loadLazyImage = img => {

            if (!img || img.dataset.loaded === 'true') return;
            const actualSrc = img.dataset.src;
            if (!actualSrc || img.dataset.loading === 'true') {
                if (!actualSrc) img.dataset.loaded = img.complete ? 'true' : 'false';
                return;
            }
            img.dataset.loading = 'true';
            const finalize = () => markImageAsLoaded(img);
            img.addEventListener('load', finalize, { once: true });
            img.addEventListener('error', finalize, { once: true });
            applyResponsiveAttributes(img);
            setFetchPriorityAttributes(img, 'low');
            requestAnimationFrame(() => {
                img.src = actualSrc;
            });
        };
        const detectInitialViewportImages = images => {
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
            if (!viewportHeight || !viewportWidth) return [];
            return images.filter(img => {
                const rect = img.getBoundingClientRect();
                if (!rect) return false;
                const verticallyVisible = rect.top < viewportHeight && rect.bottom > 0;
                const horizontallyVisible = rect.left < viewportWidth && rect.right > 0;
                return verticallyVisible && horizontallyVisible;
            });
        };
        const initialViewportImages = detectInitialViewportImages(lazyImages);
        const initialViewportSet = new Set(initialViewportImages);
        const MAX_PRIORITY_IMAGES = Math.max(getMaxPriorityImages(), initialViewportImages.length);
        const priorityImages = [];
        const priorityImageSet = new Set();
        const deferredImages = [];
        const includeAsPriority = img => {
            if (!img) return false;
            if (priorityImageSet.has(img)) return true;
            if (priorityImages.length >= MAX_PRIORITY_IMAGES) return false;
            priorityImages.push(img);
            priorityImageSet.add(img);
            return true;
        };

        initialViewportImages.forEach(includeAsPriority);
        lazyImages.forEach(img => {
            if (priorityImages.length < MAX_PRIORITY_IMAGES && img.dataset.priority === 'true' && !priorityImageSet.has(img)) {
                includeAsPriority(img);
            }
        });
        lazyImages.forEach(img => {
            if (priorityImageSet.has(img)) {
                return;
            }
            if (img.dataset && img.dataset.priority === 'true') {
                delete img.dataset.priority;
            }
            setFetchPriorityAttributes(img, 'low');
            deferredImages.push(img);
        });
        const ensurePreload = img => {
            if (!img || !document.head) return;
            const actualSrc = img.dataset.src || img.currentSrc || img.src;
            if (!actualSrc) return;
            const selector = `link[rel="preload"][as="image"][href="${actualSrc}"]`;
            if (document.head.querySelector(selector)) {
                return;
            }
            const link = document.createElement('link');
            link.rel = 'preload';
            link.as = 'image';
            link.href = actualSrc;
            link.setAttribute('fetchpriority', 'high');
            const srcset = img.getAttribute('srcset');
            if (srcset) link.setAttribute('imagesrcset', srcset);
            const sizes = img.getAttribute('sizes');
            if (sizes) link.setAttribute('imagesizes', sizes);
            document.head.appendChild(link);
        };

        priorityImages.forEach(img => {
            const isInitialViewport = initialViewportSet.has(img);
            if (isInitialViewport) {
                ensurePreload(img);
                setFetchPriorityAttributes(img, 'high');
            } else {
                setFetchPriorityAttributes(img, 'low');
            }
            applyResponsiveAttributes(img);
            const actualSrc = img.dataset.src;
            if (actualSrc && img.src !== actualSrc) {
                img.src = actualSrc;
            }
            if (!img.complete) {
                const finalize = () => markImageAsLoaded(img);
                img.addEventListener('load', finalize, { once: true });
                img.addEventListener('error', finalize, { once: true });
            } else {
                markImageAsLoaded(img);
            }
        });

        let lazyObserver = null;
        if ('IntersectionObserver' in window && deferredImages.length) {
            lazyObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        loadLazyImage(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '200px 0px' });
            deferredImages.forEach(img => {
                if (img.dataset.src && img.dataset.loaded !== 'true') {
                    lazyObserver.observe(img);
                } else if (!img.dataset.src) {
                    img.dataset.loaded = img.complete ? 'true' : 'false';
                }
            });
        } else if (deferredImages.length) {
            let scrollFallbackRaf = null;
            let scrollFallbackTimeout = null;
            let lastFallbackRun = 0;
            const fallbackThrottleMs = 150;
            const pruneDeferredImages = () => {
                for (let i = deferredImages.length - 1; i >= 0; i -= 1) {
                    const img = deferredImages[i];
                    if (!img || img.dataset.loaded === 'true') {
                        deferredImages.splice(i, 1);
                    }
                }
            };
            const runScrollFallback = () => {
                scrollFallbackRaf = null;
                lastFallbackRun = Date.now();
                pruneDeferredImages();
                if (!deferredImages.length) return;
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
                deferredImages.forEach(img => {
                    const rect = img.getBoundingClientRect();
                    if (rect.bottom >= -200 && rect.top <= viewportHeight + 200) {
                        loadLazyImage(img);
                    }
                });
            };
            const scheduleScrollFallback = () => {
                const now = Date.now();
                const elapsed = now - lastFallbackRun;
                if (elapsed >= fallbackThrottleMs) {
                    if (!scrollFallbackRaf) {
                        scrollFallbackRaf = requestAnimationFrame(runScrollFallback);
                    }
                    return;
                }
                if (scrollFallbackTimeout) return;
                scrollFallbackTimeout = window.setTimeout(() => {
                    scrollFallbackTimeout = null;
                    if (!scrollFallbackRaf) {
                        scrollFallbackRaf = requestAnimationFrame(runScrollFallback);
                    }
                }, fallbackThrottleMs - elapsed);
            };
            const onScrollFallback = () => {
                scheduleScrollFallback();
            };
            ['scroll', 'resize', 'orientationchange'].forEach(evt => {
                window.addEventListener(evt, onScrollFallback, { passive: true });
            });
            onScrollFallback();
        }

        forceLoadVisibleLazyImages = () => {
            if (!deferredImages.length) return;
            for (let i = deferredImages.length - 1; i >= 0; i -= 1) {
                const img = deferredImages[i];
                if (!img || img.dataset.loaded === 'true') {
                    deferredImages.splice(i, 1);
                }
            }
            if (!deferredImages.length) return;
            deferredImages.forEach(img => {
                const rect = img.getBoundingClientRect();
                if (rect.bottom >= -200 && rect.top <= window.innerHeight + 200) {
                    if (lazyObserver) lazyObserver.unobserve(img);
                    loadLazyImage(img);
                }
            });
        };
        forceLoadVisibleLazyImages();

        priorityImages.forEach(img => {
            if (img.dataset.loaded === 'true') return;
            if (img.complete) {
                markImageAsLoaded(img);
            }
        });

    };

    if (document.readyState === 'loading') {
        if (document.body) {
            requestAnimationFrame(run);
        } else {
            document.addEventListener('DOMContentLoaded', run, { once: true });
        }
    } else {
        run();
    }
})();

const aplicarMetadataNosAnchors = () => {
    if (!configuradorMetadata || metadataAplicada) return;
    const anchors = Array.from(document.querySelectorAll('.botao-home'));
    anchors.forEach(anchor => {
        const match = anchor.href.match(/([?&][^=]*LN)=([^&]+)/i);
        const linha = match ? match[2] : '';
        anchor.dataset.linha = linha;
        const info = configuradorMetadata[linha] || {};
        if (info.description) descricaoPorLinha[linha] = info.description;
        const categorias = normalizarListaTexto(info.categories);
        const grupos = normalizarListaTexto(info.groups);
        const linhasFiltro = normalizarListaTexto(info.lines || info.linhas);
        anchor.dataset.descricao = info.description || '';
        anchor.dataset.tituloPagina = info.title || '';
        anchor.dataset.conteudoPagina = info.description || '';
        anchor.dataset.categorias = categorias.length ? categorias.join('|') : '';
        anchor.dataset.linhasGrupo = grupos.length ? grupos.join('|') : '';
        anchor.dataset.linhasFiltro = linhasFiltro.length ? linhasFiltro.join('|') : '';
        const possuiBadge = anchor.querySelector('.badge-novo');
        const possuiDesenho = typeof info.hasDrawing === 'boolean' ? info.hasDrawing : Boolean(possuiBadge);
        anchor.dataset.desenho = possuiDesenho ? '1' : '0';
    });
    metadataAplicada = true;
    inicializarHelpIconsProdutos();
};

const carregarMetadataConfiguradores = () => {
    if (configuradorMetadata) {
        aplicarMetadataNosAnchors();
        return Promise.resolve(configuradorMetadata);
    }
    if (!metadataPromise) {
        metadataPromise = fetch(metadataUrl, { cache: 'no-store' })
            .then(r => {
                if (!r.ok) throw new Error('Falha ao carregar metadata dos configuradores');
                return r.json();
            })
            .then(json => {
                configuradorMetadata = json || {};
                Object.entries(configuradorMetadata).forEach(([ln, info]) => {
                    if (info && typeof info.description === 'string') {
                        descricaoPorLinha[ln] = info.description;
                    }
                });
                metadataAplicada = false;
                aplicarMetadataNosAnchors();
                return configuradorMetadata;
            })
            .catch(err => {
                console.error(err);
                metadataPromise = null;
                configuradorMetadata = {};
                metadataAplicada = true;
                return configuradorMetadata;
            });
    }
    return metadataPromise.then(() => {
        aplicarMetadataNosAnchors();
        return configuradorMetadata;
    });
};

const botoesSection = document.querySelector('.home-botoes');
const ordemPadrao = Array.from(botoesSection.querySelectorAll('.botao-linha'));
let usarOrdemAlfa = false;
const ordenarIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-down-up" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M11.5 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L11 2.707V14.5a.5.5 0 0 0 .5.5zm-7-14a.5.5 0 0 1 .5.5v11.793l3.146-3.147a.5.5 0 0 1 .708.708l-4 4a.5.5 0 0 1-.708 0l-4-4a.5.5 0 0 1 .708-.708L4 13.293V1.5a.5.5 0 0 1 .5-.5z"/></svg>';
const STORAGE_KEY = 'filtros_produtos';
const ROOT_FILTERS_ATTR = 'data-home-filtros-open';
const rootPrefersFiltrosAbertos = document.documentElement.getAttribute(ROOT_FILTERS_ATTR) === 'true';
const updateRootFiltersState = opened => {
    if (typeof window !== 'undefined' && typeof window.__setHomeFiltrosRootState === 'function') {
        window.__setHomeFiltrosRootState(opened);
    } else if (opened) {
        document.documentElement.setAttribute(ROOT_FILTERS_ATTR, 'true');
    } else {
        document.documentElement.removeAttribute(ROOT_FILTERS_ATTR);
    }
};

const obterCategoriasSelecionadas = () =>
    Array.from(document.querySelectorAll('#dropdownCategorias input[type="checkbox"]:checked')).map(cb => cb.value);

const obterLinhasSelecionadas = () =>
    Array.from(document.querySelectorAll('#dropdownLinhas input[type="checkbox"]:checked')).map(cb => cb.value);
const obterDesenhosSelecionados = () =>
    Array.from(document.querySelectorAll('#dropdownDesenhos input[type="checkbox"]:checked')).map(cb => cb.value);

const atualizarBotaoCategorias = () => {
    const selecionadas = obterCategoriasSelecionadas();
    const botao = document.getElementById('botaoCategorias');
    if (botao) botao.textContent = selecionadas.length ? selecionadas.join(', ') : 'Selecione...';
};

const atualizarBotaoLinhas = () => {
    const selecionadas = obterLinhasSelecionadas();
    const botao = document.getElementById('botaoLinhas');
    if (botao) botao.textContent = selecionadas.length ? selecionadas.join(', ') : 'Selecione...';
};

const atualizarBotaoDesenhos = () => {
    const selecionadas = obterDesenhosSelecionados();
    const botao = document.getElementById('botaoDesenhos');
    if (botao) botao.textContent = selecionadas.length ? selecionadas.join(', ') : 'Selecione...';
};

const loadState = () => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
    } catch {
        return {};
    }
};
const parseListaUrl = valor =>
    (valor || '')
        .split('|')
        .map(item => item.trim())
        .filter(Boolean);
const parseBooleanUrl = valor => {
    if (valor === null) return null;
    const normalizado = String(valor).trim().toLowerCase();
    if (!normalizado) return true;
    if (['0', 'false', 'nao', 'não', 'no'].includes(normalizado)) return false;
    return true;
};
const obterFiltrosUrl = () => {
    const params = new URLSearchParams(window.location.search);
    const termoKey = params.has('termo') ? 'termo' : (params.has('busca') ? 'busca' : null);
    return {
        hasTermo: Boolean(termoKey),
        termo: termoKey ? parseListaUrl(params.get(termoKey)) : [],
        hasCategorias: params.has('categorias'),
        categorias: params.has('categorias') ? parseListaUrl(params.get('categorias')) : [],
        hasLinhas: params.has('linhas'),
        linhas: params.has('linhas') ? parseListaUrl(params.get('linhas')) : [],
        hasDesenhos: params.has('desenhos'),
        desenhos: params.has('desenhos') ? parseListaUrl(params.get('desenhos')) : [],
        hasOrdemAlfa: params.has('ordemAlfa'),
        ordemAlfa: params.has('ordemAlfa') ? parseBooleanUrl(params.get('ordemAlfa')) : null
    };
};
const atualizarUrlFiltros = () => {
    if (typeof window === 'undefined' || !window.history || typeof window.history.replaceState !== 'function') {
        return;
    }
    const params = new URLSearchParams(window.location.search);
    const categorias = obterCategoriasSelecionadas();
    const linhas = obterLinhasSelecionadas();
    const desenhos = obterDesenhosSelecionados();

    if (termosBuscaAtivos.length) {
        params.set('termo', termosBuscaAtivos.join('|'));
    } else {
        params.delete('termo');
    }
    params.delete('busca');

    if (categorias.length) {
        params.set('categorias', categorias.join('|'));
    } else {
        params.delete('categorias');
    }

    if (linhas.length) {
        params.set('linhas', linhas.join('|'));
    } else {
        params.delete('linhas');
    }

    if (desenhos.length) {
        params.set('desenhos', desenhos.join('|'));
    } else {
        params.delete('desenhos');
    }

    if (usarOrdemAlfa) {
        params.set('ordemAlfa', '1');
    } else {
        params.delete('ordemAlfa');
    }

    const query = params.toString();
    const newUrl = `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash || ''}`;
    window.history.replaceState(null, '', newUrl);
};
const criarToastFeedback = (mensagem, tipo = 'sucesso') => {
    if (typeof document === 'undefined') return null;
    const toast = document.createElement('div');
    toast.textContent = mensagem;
    toast.style.position = 'fixed';
    toast.style.left = '50%';
    toast.style.bottom = '20px';
    toast.style.transform = 'translateX(-50%)';
    toast.style.background = tipo === 'erro' ? '#c0392b' : '#333';
    toast.style.color = '#fff';
    toast.style.padding = '10px 20px';
    toast.style.borderRadius = '6px';
    toast.style.fontSize = '14px';
    toast.style.zIndex = '10000';
    toast.style.boxShadow = '0 8px 20px rgba(0,0,0,0.25)';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2800);
    return toast;
};
const criarSelecaoManualLink = url => {
    if (typeof document === 'undefined') return null;
    const wrapper = document.createElement('div');
    wrapper.style.position = 'fixed';
    wrapper.style.left = '50%';
    wrapper.style.bottom = '70px';
    wrapper.style.transform = 'translateX(-50%)';
    wrapper.style.background = '#fff';
    wrapper.style.border = '1px solid #ddd';
    wrapper.style.borderRadius = '6px';
    wrapper.style.padding = '8px 12px';
    wrapper.style.zIndex = '10000';
    wrapper.style.boxShadow = '0 6px 16px rgba(0,0,0,0.2)';
    const input = document.createElement('input');
    input.type = 'text';
    input.value = url;
    input.readOnly = true;
    input.style.width = '320px';
    input.style.maxWidth = '70vw';
    input.style.border = '1px solid #ccc';
    input.style.borderRadius = '4px';
    input.style.padding = '6px 8px';
    wrapper.appendChild(input);
    document.body.appendChild(wrapper);
    input.focus();
    input.select();
    input.setSelectionRange(0, input.value.length);
    return { wrapper, input };
};
const saveState = () => {
    try {
        const state = {
            termos: termosBuscaAtivos.slice(),
            categorias: obterCategoriasSelecionadas(),
            linhas: obterLinhasSelecionadas(),
            ordemAlfa: usarOrdemAlfa,
            desenhos: obterDesenhosSelecionados(),
            filtrosAbertos: areaFiltros ? areaFiltros.classList.contains('show') : true
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch { }
};
const stored = loadState();
const loading = document.getElementById('loadingFiltros');
const mostrarLoading = () => {
    if (!loading) return;
    loading.style.display = 'block';
};
const esconderLoading = () => {
    if (!loading) return;
    const visiveis = Array.from(document.querySelectorAll('.botao-linha'))
        .filter(div => div.style.display !== 'none')
        .map(div => div.querySelector('img.lazy-product-image, img'))
        .filter(Boolean);
    const pendentes = visiveis.filter(img => {
        if (img.dataset && img.dataset.src) {
            return img.dataset.loaded !== 'true';
        }
        return !img.complete;
    });
    if (!pendentes.length) {
        loading.style.display = 'none';
        return;
    }
    let carregadas = 0;
    const finalizar = () => {
        carregadas++;
        if (carregadas >= pendentes.length) {
            loading.style.display = 'none';
        }
    };
    pendentes.forEach(img => {
        const usaLazy = img.dataset && img.dataset.src;
        const evento = usaLazy ? 'lazyloaded' : 'load';
        const pronta = usaLazy ? img.dataset.loaded === 'true' : img.complete;
        if (pronta) {
            finalizar();
        } else {
            img.addEventListener(evento, finalizar, { once: true });
        }
    });
    setTimeout(() => { loading.style.display = 'none'; }, 5000);
};
const noResults = document.getElementById('noResults');
const contadorProdutos = document.getElementById('produtosEncontrados');
const chipsFiltros = document.getElementById('filtrosAtivosChips');
const termosBuscaAtivos = [];
const atualizarContadorProdutos = quantidade => {
    if (!contadorProdutos) return;
    const texto = quantidade === 1
        ? '1 produto encontrado'
        : `${quantidade} Produtos Encontrados`;
    contadorProdutos.textContent = texto;
};
const enviarEventoBusca = () => {
    if (typeof window.gtag !== 'function') return;
    const eventName = 'search';
    let payload = {};
    if (typeof window.getGtagExtraPayload === 'function') {
        const extra = window.getGtagExtraPayload(null, eventName);
        if (extra && typeof extra === 'object') {
            payload = Object.assign(payload, extra);
        }
    }
    window.gtag('event', eventName, payload);
};
const renderizarChipsFiltros = () => {
    if (!chipsFiltros) return;
    chipsFiltros.innerHTML = '';
    const chips = [];
    termosBuscaAtivos.forEach(termo => {
        chips.push({
            label: `Busca: ${termo}`,
            tipo: 'busca',
            termo
        });
    });
    document.querySelectorAll('#dropdownCategorias input[type="checkbox"]:checked').forEach(cb => {
        chips.push({
            label: cb.value,
            tipo: 'categoria',
            checkbox: cb
        });
    });
    document.querySelectorAll('#dropdownLinhas input[type="checkbox"]:checked').forEach(cb => {
        chips.push({
            label: cb.value,
            tipo: 'linha',
            checkbox: cb
        });
    });
    document.querySelectorAll('#dropdownDesenhos input[type="checkbox"]:checked').forEach(cb => {
        chips.push({
            label: cb.value,
            tipo: 'desenho',
            checkbox: cb
        });
    });

    chips.forEach(chip => {
        const chipEl = document.createElement('span');
        chipEl.className = 'pagina-produtos__chip';

        const textEl = document.createElement('span');
        textEl.className = 'pagina-produtos__chip-text';
        textEl.textContent = chip.label;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'pagina-produtos__chip-remove';
        removeBtn.setAttribute('aria-label', `Remover filtro ${chip.label}`);
        removeBtn.innerHTML = '&times;';
        removeBtn.addEventListener('click', () => {
            if (chip.tipo === 'busca') {
                removerTermoBusca(chip.termo);
                if (campoBuscaGlobal) campoBuscaGlobal.value = '';
                if (resultadoBusca) resultadoBusca.classList.remove('show');
            } else if (chip.checkbox) {
                chip.checkbox.checked = false;
            }

            if (chip.tipo === 'categoria') atualizarBotaoCategorias();
            if (chip.tipo === 'linha') atualizarBotaoLinhas();
            if (chip.tipo === 'desenho') atualizarBotaoDesenhos();

            filtrar();
            saveState();
            atualizarUrlFiltros();
        });

        chipEl.appendChild(textEl);
        chipEl.appendChild(removeBtn);
        chipsFiltros.appendChild(chipEl);
    });
};
const ordenar = () => {
    const itens = Array.from(botoesSection.querySelectorAll('.botao-linha'));
    const ordenados = usarOrdemAlfa
        ? itens.sort((a, b) =>
            a
                .querySelector('.botao-titulo')
                .textContent.localeCompare(b.querySelector('.botao-titulo').textContent)
        )
        : ordemPadrao.slice();
    ordenados.forEach(el => botoesSection.appendChild(el));
};
const normalizarTexto = str =>
    (str === null || str === undefined ? '' : String(str))
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/-/g, ' ')
        .replace(/[^\w\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

const SEMANTIC_ENDPOINT = '/PaginasConsultaProdutos/BuscaSemantica.php';
const SEMANTIC_TIMEOUT = 1500;
const semanticCache = new Map();

const buscarSemantico = termo => {
    const consulta = (termo || '').trim();
    if (!consulta) return Promise.resolve(null);
    const chave = normalizarTexto(consulta);
    if (semanticCache.has(chave)) return Promise.resolve(semanticCache.get(chave));
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), SEMANTIC_TIMEOUT);
    return fetch(`${SEMANTIC_ENDPOINT}?termo=${encodeURIComponent(consulta)}`, { signal: controller.signal })
        .then(r => (r.ok ? r.json() : null))
        .then(data => {
            if (data) semanticCache.set(chave, data);
            return data;
        })
        .catch(() => null)
        .finally(() => clearTimeout(timeoutId));
};

const prepararSemanticoBusca = termos => {
    if (!Array.isArray(termos) || !termos.length) return Promise.resolve(null);
    const consulta = termos.join(' ');
    return buscarSemantico(consulta).then(data => {
        if (!data || !Array.isArray(data.resultados)) return null;
        const mapa = new Map();
        data.resultados.forEach(item => {
            if (!item) return;
            const idNormalizado = item.id ? normalizarTexto(item.id) : '';
            if (idNormalizado) mapa.set(idNormalizado, item);
            if (item.url) mapa.set(item.url, item);
            if (item.titulo) mapa.set(normalizarTexto(item.titulo), item);
        });
        const expansoes = Array.isArray(data.expansoes)
            ? data.expansoes.map(item => normalizarTexto(item)).filter(Boolean)
            : [];
        return { mapa, expansoes, resultados: data.resultados };
    });
};

const normalizarListaTexto = valor =>
    Array.isArray(valor)
        ? valor
            .map(item => (item === null || item === undefined ? '' : String(item)).trim())
            .filter(Boolean)
        : [];

const normalizarTermoBusca = termo => normalizarTexto(termo || '');
const adicionarTermoBusca = termo => {
    const limpo = (termo || '').trim();
    if (!limpo) return false;
    const normalizado = normalizarTermoBusca(limpo);
    const existe = termosBuscaAtivos.some(item => normalizarTermoBusca(item) === normalizado);
    if (existe) return false;
    termosBuscaAtivos.push(limpo);
    return true;
};
const removerTermoBusca = termo => {
    const normalizado = normalizarTermoBusca(termo);
    const indice = termosBuscaAtivos.findIndex(item => normalizarTermoBusca(item) === normalizado);
    if (indice >= 0) termosBuscaAtivos.splice(indice, 1);
};
const aplicarTermoBusca = termo => {
    const adicionou = adicionarTermoBusca(termo);
    if (!adicionou) return false;
    renderizarChipsFiltros();
    atualizarUrlFiltros();
    return true;
};

const contemPalavras = (texto, termo) => {
    if (texto.includes(termo)) return true;
    const palavras = termo.split(' ').filter(Boolean);
    return palavras.every(p => {
        if (p.length <= 2) {
            const regex = new RegExp(`\\b${p}\\b`);
            return regex.test(texto);
        }
        if (texto.includes(p)) return true;
        if (p.length > 4 && texto.includes(p.slice(0, -1))) return true;
        return false;
    });
};

const filtrar = (skipLoading = false) => {
    const executarFiltro = semantico => {
        const start = performance.now();
        if (!skipLoading) mostrarLoading();
        const termosBusca = termosBuscaAtivos.map(termo => normalizarTermoBusca(termo)).filter(Boolean);
        const mapaSemantico = semantico && semantico.mapa ? semantico.mapa : new Map();
        const expansoesSemanticas = semantico && semantico.expansoes ? semantico.expansoes : [];
        const possuiExpansoes = expansoesSemanticas.length > 0;
        const scope = 'tudo';
        const scopeProdutoOpcoes = Array.from(document.querySelectorAll('#dropdownScopeProduto input[type="checkbox"]:checked')).map(cb => cb.value);
        const selecao = obterCategoriasSelecionadas();
        const linhasSel = obterLinhasSelecionadas();
        const desenhosSel = obterDesenhosSelecionados();

        requestAnimationFrame(() => {
            let algumVisivel = false;
            let produtosVisiveis = 0;
            document.querySelectorAll('.botao-linha').forEach(div => {
                const anchor = div.querySelector('.botao-home');
                const titulo = normalizarTexto(anchor.textContent);
                const linha = normalizarTexto(anchor.dataset.linha || '');
                const descricao = normalizarTexto(anchor.dataset.descricao || '');
                const paginaTitulo = normalizarTexto(anchor.dataset.tituloPagina || '');
                const paginaTexto = normalizarTexto(anchor.dataset.conteudoPagina || '');
                const cats = anchor.dataset.categorias ? anchor.dataset.categorias.split('|') : [];
                const grupos = anchor.dataset.linhasGrupo ? anchor.dataset.linhasGrupo.split('|') : [];
                const catsNormalizadas = cats.map(normalizarTexto).filter(Boolean);
                const gruposNormalizados = grupos.map(normalizarTexto).filter(Boolean);
                const textoCategorias = normalizarTexto(cats.join(' '));
                const textoGrupos = normalizarTexto(grupos.join(' '));

                const textoCompleto = [
                    titulo,
                    linha,
                    descricao,
                    paginaTitulo,
                    paginaTexto,
                    textoCategorias,
                    textoGrupos,
                ].join(' ');
                const linhaSemantica = normalizarTexto(anchor.dataset.linha || '');
                const matchSemantico = mapaSemantico.has(linhaSemantica) || (anchor.href && mapaSemantico.has(anchor.href));
                const matchExpansao = possuiExpansoes && expansoesSemanticas.some(termo => termo && contemPalavras(textoCompleto, termo));

                const atendeBuscaLiteral = !termosBusca.length || termosBusca.some(termo => {
                    if (!termo) return false;
                    if (scope === 'produto') {
                        const verifica = opt => scopeProdutoOpcoes.length === 0 || scopeProdutoOpcoes.includes(opt);
                        const emTitulo = verifica('titulo') && contemPalavras(titulo, termo);
                        const emLinha = verifica('linha') && contemPalavras(linha, termo);
                        const emCat = verifica('categoria') && contemPalavras(textoCategorias, termo);
                        const emGrupo = verifica('grupo') && contemPalavras(textoGrupos, termo);
                        return emTitulo || emLinha || emCat || emGrupo;
                    }
                    if (scope === 'informacoes') {
                        return contemPalavras((descricao + ' ' + paginaTexto).trim(), termo);
                    }
                    return (
                        contemPalavras(titulo, termo) ||
                        contemPalavras(linha, termo) ||
                        contemPalavras(descricao, termo) ||
                        contemPalavras(paginaTitulo, termo) ||
                        contemPalavras(paginaTexto, termo) ||
                        contemPalavras(textoCategorias, termo) ||
                        contemPalavras(textoGrupos, termo)
                    );
                });
                const atendeBusca = atendeBuscaLiteral || matchSemantico || matchExpansao;

                const atendeCat = !selecao.length || selecao.some(s => catsNormalizadas.includes(normalizarTexto(s)));
                const atendeLinha = !linhasSel.length || linhasSel.some(s => gruposNormalizados.includes(normalizarTexto(s)));

                const possuiDesenho = anchor.dataset.desenho === '1';
                const atendeDesenho = desenhosSel.length === 0 ||
                    (desenhosSel.includes('Sim') && possuiDesenho) ||
                    (desenhosSel.includes('Não') && !possuiDesenho);
                const mostrar = atendeBusca && atendeCat && atendeLinha && atendeDesenho;

                div.style.display = mostrar ? '' : 'none';
                if (mostrar) {
                    algumVisivel = true;
                    produtosVisiveis++;
                }
            });

            requestAnimationFrame(() => {
                atualizarContadorProdutos(produtosVisiveis);
                renderizarChipsFiltros();
                enviarEventoBusca();

                const delay = Math.max(200 - (performance.now() - start), 0);
                setTimeout(() => {
                    esconderLoading();
                    if (noResults) {
                        noResults.classList.toggle('pagina-produtos__hidden', algumVisivel);
                    }
                }, delay);

                requestAnimationFrame(() => {
                    forceLoadVisibleLazyImages();
                });
            });
        });
    };

    return Promise.all([
        carregarMetadataConfiguradores(),
        prepararSemanticoBusca(termosBuscaAtivos),
    ])
        .then(([, semantico]) => executarFiltro(semantico))
        .catch(() => executarFiltro(null));
};

const areaFiltros = document.getElementById('areaFiltros');
const toggleFiltros = document.getElementById('toggleFiltros');
const toggleArrow = document.getElementById('toggleArrow');
const updateToggleArrow = opened => {
    if (toggleArrow) toggleArrow.textContent = opened ? '\u25B2' : '\u25BC';
};
const filtrosAbertosPadrao = stored.filtrosAbertos === true || rootPrefersFiltrosAbertos;
updateRootFiltersState(filtrosAbertosPadrao);
if (toggleFiltros && areaFiltros) {
    if (filtrosAbertosPadrao) {
        areaFiltros.classList.add('show', 'open');
        toggleFiltros.classList.add('open');
    } else {
        areaFiltros.classList.remove('show', 'open');
        toggleFiltros.classList.remove('open');
    }
    updateToggleArrow(filtrosAbertosPadrao);
    toggleFiltros.addEventListener('click', () => {
        const opened = areaFiltros.classList.toggle('show');
        toggleFiltros.classList.toggle('open', opened);
        areaFiltros.classList.toggle('open', opened);
        updateRootFiltersState(opened);
        updateToggleArrow(opened);
        enviarEventoGtag(opened ? 'home-filtro-produtos-abrir' : 'home-filtro-produtos-fechar', {
            event_category: 'Filtro'
        });
        if (opened) carregarMetadataConfiguradores();
        saveState();
    });
}

const limparFiltros = document.getElementById('limparFiltros');
if (limparFiltros && areaFiltros) {
    limparFiltros.addEventListener('click', () => {
        document.getElementById('filtroBusca').value = '';
        termosBuscaAtivos.length = 0;
        document.querySelectorAll('#dropdownCategorias input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('#dropdownLinhas input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('#dropdownDesenhos input[type="checkbox"]').forEach(cb => cb.checked = false);
        atualizarBotaoCategorias();
        atualizarBotaoLinhas();
        atualizarBotaoDesenhos();

        usarOrdemAlfa = false;
        document.getElementById('ordenarAlfa').innerHTML = ordenarIcon + ' Ordenar de A-Z';
        try { localStorage.removeItem(STORAGE_KEY); } catch { }
        ordenar();
        atualizarUrlFiltros();
        filtrar();
    });
}

const ordenarAlfaBtn = document.getElementById('ordenarAlfa');
if (ordenarAlfaBtn) ordenarAlfaBtn.addEventListener('click', () => {
    mostrarLoading();
    if (loading) loading.style.display = 'block';
    usarOrdemAlfa = !usarOrdemAlfa;
    ordenar();
    filtrar();
    document.getElementById('ordenarAlfa').innerHTML = ordenarIcon + (usarOrdemAlfa ? ' Ordem Padrão' : ' Ordenar de A-Z');
    enviarEventoGtag(usarOrdemAlfa ? 'home-filtro-ordenar-az' : 'home-filtro-ordenar-padrao', {
        event_category: 'Filtro'
    });
    saveState();
});
const campoBuscaGlobal = document.getElementById('filtroBusca');
const resultadoBusca = document.getElementById('resultadoBuscaFiltro');
let sugestoesCatalogo = null;
let sugestoesCatalogoPromise = null;
let sugestaoSelecionadaIndex = -1;
let sugestoesVisiveis = [];
let ultimoTermoBusca = '';

const extrairLinhaAnchor = anchor => {
    return obterLinhaConfigurador(anchor);
};

const gerarResumoSugestao = (texto, maxSentencas = 2, maxChars = 220, contexto = null) => {
    const limpo = (texto || '').replace(/\s+/g, ' ').trim();
    if (!limpo) return '';
    const sentencas = limpo.split(/(?<=[.!?])\s+/).filter(Boolean);
    let resumo = sentencas.slice(0, maxSentencas).join(' ').trim();
    if (!resumo) resumo = limpo.slice(0, maxChars);
    if (resumo.length > maxChars) resumo = `${resumo.slice(0, maxChars - 1)}…`;
    const seoService = typeof window !== 'undefined' ? window.SEODescricaoService : null;
    if (!seoService || typeof seoService.obterDescricao !== 'function') {
        return resumo;
    }
    return seoService.obterDescricao({
        key: 'resumo-sugestao',
        base: limpo,
        contexto: contexto || {},
        maxChars,
        minChars: 80,
        allowShort: true,
        fallback: resumo
    }) || resumo;
};

const construirCatalogoSugestoes = () => {
    const itens = new Map();
    if (configuradorMetadata) {
        Object.entries(configuradorMetadata).forEach(([linha, info]) => {
            if (!linha) return;
            const categorias = normalizarListaTexto(info && info.categories);
            const grupos = normalizarListaTexto(info && info.groups);
            itens.set(linha, {
                linha,
                titulo: info && info.title ? info.title : linha,
                descricao: info && info.description ? info.description : '',
                resumo: gerarResumoSugestao(info && info.description ? info.description : '', 2, 220, info),
                categorias,
                grupos,
                href: '',
                tipo: 'produto'
            });
        });
    }
    document.querySelectorAll('.botao-home').forEach(anchor => {
        const linha = extrairLinhaAnchor(anchor);
        const tituloAnchor = anchor.querySelector('.botao-titulo')
            ? anchor.querySelector('.botao-titulo').textContent.trim()
            : anchor.textContent.trim();
        const descricaoAnchor = anchor.dataset.descricao || '';
        const categoriasAnchor = normalizarListaTexto(anchor.dataset.categorias ? anchor.dataset.categorias.split('|') : []);
        const gruposAnchor = normalizarListaTexto(anchor.dataset.linhasGrupo ? anchor.dataset.linhasGrupo.split('|') : []);
        if (linha) {
            const existente = itens.get(linha) || {
                linha,
                titulo: '',
                descricao: '',
                resumo: '',
                categorias: [],
                grupos: [],
                href: '',
                tipo: 'produto'
            };
            existente.titulo = existente.titulo || tituloAnchor || linha;
            existente.descricao = existente.descricao || descricaoAnchor;
            existente.resumo = existente.resumo || gerarResumoSugestao(existente.descricao, 2, 220, existente);
            existente.categorias = existente.categorias.length ? existente.categorias : categoriasAnchor;
            existente.grupos = existente.grupos.length ? existente.grupos : gruposAnchor;
            existente.href = existente.href || anchor.href;
            itens.set(linha, existente);
        } else {
            const chave = anchor.href || tituloAnchor;
            if (!itens.has(chave)) {
                itens.set(chave, {
                    linha: '',
                    titulo: tituloAnchor || chave,
                    descricao: descricaoAnchor,
                    resumo: gerarResumoSugestao(descricaoAnchor, 2, 220, { titulo: tituloAnchor, descricao: descricaoAnchor }),
                    categorias: categoriasAnchor,
                    grupos: gruposAnchor,
                    href: anchor.href,
                    tipo: 'produto'
                });
            }
        }
    });

    sugestoesCatalogo = Array.from(itens.values()).map(item => {
        const itemSeguro = item && typeof item === 'object' ? item : {};
        const titulo = itemSeguro.titulo || itemSeguro.linha || '';
        const textoBusca = normalizarTexto([
            titulo,
            itemSeguro.linha,
            itemSeguro.descricao,
            Array.isArray(itemSeguro.categorias) ? itemSeguro.categorias.join(' ') : '',
            Array.isArray(itemSeguro.grupos) ? itemSeguro.grupos.join(' ') : ''
        ].join(' '));
        const sugestao = Object.assign({}, itemSeguro);
        sugestao.titulo = titulo;
        sugestao.valor = titulo || itemSeguro.linha || '';
        sugestao.textoBusca = textoBusca;
        return sugestao;
    });
    return sugestoesCatalogo;
};

const carregarCatalogoSugestoes = () => {
    const itensPagina = document.querySelectorAll('.botao-home').length;
    if (sugestoesCatalogo && sugestoesCatalogo.length) return Promise.resolve(sugestoesCatalogo);
    if (sugestoesCatalogo && !sugestoesCatalogo.length && itensPagina > 0) {
        sugestoesCatalogo = null;
    }
    if (!sugestoesCatalogoPromise) {
        sugestoesCatalogoPromise = carregarMetadataConfiguradores()
            .catch(() => null)
            .then(() => {
                sugestoesCatalogo = construirCatalogoSugestoes();
                return sugestoesCatalogo;
            })
            .finally(() => {
                sugestoesCatalogoPromise = null;
            });
    }
    return sugestoesCatalogoPromise;
};

const limparSugestoes = () => {
    sugestoesVisiveis = [];
    sugestaoSelecionadaIndex = -1;
    ultimoTermoBusca = '';
    if (resultadoBusca) {
        resultadoBusca.innerHTML = '';
        resultadoBusca.classList.remove('show');
        resultadoBusca.setAttribute('aria-expanded', 'false');
    }
    if (campoBuscaGlobal) {
        campoBuscaGlobal.removeAttribute('aria-activedescendant');
        campoBuscaGlobal.setAttribute('aria-expanded', 'false');
    }
};

const atualizarEstadoSugestao = () => {
    if (!resultadoBusca) return;
    const opcoes = Array.from(resultadoBusca.querySelectorAll('[role="option"]'));
    opcoes.forEach((opcao, index) => {
        const ativo = index === sugestaoSelecionadaIndex;
        opcao.setAttribute('aria-selected', ativo ? 'true' : 'false');
        const link = opcao.querySelector('a');
        if (link) link.classList.toggle('active', ativo);
    });
    if (campoBuscaGlobal && sugestaoSelecionadaIndex >= 0 && opcoes[sugestaoSelecionadaIndex]) {
        campoBuscaGlobal.setAttribute('aria-activedescendant', opcoes[sugestaoSelecionadaIndex].id);
    } else if (campoBuscaGlobal) {
        campoBuscaGlobal.removeAttribute('aria-activedescendant');
    }
};

const selecionarSugestao = index => {
    const item = sugestoesVisiveis[index];
    if (!item) return;
    if (item.href) {
        window.location.href = item.href;
        return;
    }
    if (!campoBuscaGlobal) return;
    campoBuscaGlobal.value = '';
    aplicarTermoBusca(item.valor);
    limparSugestoes();
    filtrar();
    saveState();
};

const renderizarSugestoes = itens => {
    if (!resultadoBusca) return;
    resultadoBusca.innerHTML = '';
    sugestoesVisiveis = itens;
    sugestaoSelecionadaIndex = -1;

    if (!itens.length) {
        resultadoBusca.classList.remove('show');
        resultadoBusca.setAttribute('aria-expanded', 'false');
        if (campoBuscaGlobal) campoBuscaGlobal.setAttribute('aria-expanded', 'false');
        return;
    }

    const ul = document.createElement('ul');
    itens.forEach((item, index) => {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.id = `sugestao-busca-${index}`;

        const link = document.createElement('a');
        link.href = item.href || '#';
        const linhaItem = item.linha === null || item.linha === undefined ? '' : String(item.linha);
        const tituloItem = item.titulo === null || item.titulo === undefined ? '' : String(item.titulo);
        const label = linhaItem && tituloItem && !tituloItem.includes(linhaItem)
            ? `${tituloItem} - ${linhaItem}`
            : tituloItem || linhaItem;
        const header = document.createElement('div');
        header.className = 'resultado-busca-item__header';
        const titulo = document.createElement('span');
        titulo.className = 'resultado-busca-item__title';
        titulo.textContent = label;
        header.appendChild(titulo);
        if (item.tipo) {
            const tag = document.createElement('span');
            tag.className = 'resultado-busca-tag';
            tag.textContent = item.tipo === 'documentacao' ? 'Documentação' : 'Produto';
            header.appendChild(tag);
        }
        link.appendChild(header);
        const resumoTexto = item.resumo || gerarResumoSugestao(item.descricao || '', 2, 220, item);
        if (resumoTexto) {
            const resumo = document.createElement('small');
            resumo.className = 'resultado-busca-resumo';
            resumo.textContent = resumoTexto;
            link.appendChild(resumo);
        }
        link.addEventListener('click', e => {
            e.preventDefault();
            selecionarSugestao(index);
        });
        link.addEventListener('mouseenter', () => {
            sugestaoSelecionadaIndex = index;
            atualizarEstadoSugestao();
        });
        li.appendChild(link);
        ul.appendChild(li);
    });

    resultadoBusca.appendChild(ul);
    resultadoBusca.classList.add('show');
    resultadoBusca.setAttribute('aria-expanded', 'true');
    if (campoBuscaGlobal) campoBuscaGlobal.setAttribute('aria-expanded', 'true');
};

const obterChaveSugestao = item => item.linha || item.href || item.titulo || item.valor;

const combinarSugestoesSemanticas = (catalogo, filtrados, semantico) => {
    const vistos = new Set();
    const combinados = [];
    if (semantico && Array.isArray(semantico.resultados)) {
        const mapaCatalogo = new Map();
        catalogo.forEach(item => {
            if (item.linha) mapaCatalogo.set(normalizarTexto(item.linha), item);
            if (item.href) mapaCatalogo.set(item.href, item);
            if (item.titulo) mapaCatalogo.set(normalizarTexto(item.titulo), item);
        });
        const ordenados = semantico.resultados.slice().sort((a, b) => (b.score || 0) - (a.score || 0));
        ordenados.forEach(resultado => {
            if (!resultado) return;
            if (resultado.tipo === 'documentacao') return;
            const chaveLinha = resultado.id ? normalizarTexto(resultado.id) : '';
            const itemCatalogo = (chaveLinha && mapaCatalogo.get(chaveLinha))
                || (resultado.url ? mapaCatalogo.get(resultado.url) : null)
                || (resultado.titulo ? mapaCatalogo.get(normalizarTexto(resultado.titulo)) : null);
            const item = Object.assign({}, itemCatalogo || {});
            item.linha = item.linha || (resultado.id || '');
            item.titulo = item.titulo || resultado.titulo || resultado.id || '';
            item.descricao = item.descricao || '';
            item.resumo = resultado.resumo || item.resumo || gerarResumoSugestao(item.descricao, 2, 220, item);
            item.href = item.href || resultado.url || '';
            item.tipo = resultado.tipo || item.tipo || 'produto';
            item.valor = item.valor || item.titulo || item.linha;
            const chave = obterChaveSugestao(item);
            if (chave && !vistos.has(chave)) {
                vistos.add(chave);
                combinados.push(item);
            }
        });
    }
    filtrados.forEach(item => {
        const chave = obterChaveSugestao(item);
        if (chave && !vistos.has(chave)) {
            vistos.add(chave);
            combinados.push(item);
        }
    });
    return combinados;
};

const atualizarSugestoesBusca = () => {
    if (!campoBuscaGlobal || !resultadoBusca) return;
    const termoDigitado = campoBuscaGlobal.value || '';
    const termo = normalizarTexto(termoDigitado);
    if (!termo) {
        limparSugestoes();
        return;
    }
    ultimoTermoBusca = termo;
    Promise.all([carregarCatalogoSugestoes(), buscarSemantico(termoDigitado)]).then(([catalogo, semantico]) => {
        if (ultimoTermoBusca !== termo) return;
        const filtrados = catalogo.filter(item => item.textoBusca && contemPalavras(item.textoBusca, termo));
        const combinados = combinarSugestoesSemanticas(catalogo, filtrados, semantico);
        renderizarSugestoes(combinados.slice(0, 8));
    });
};

const urlFiltros = obterFiltrosUrl();
const termosIniciais = urlFiltros.hasTermo
    ? urlFiltros.termo
    : (Array.isArray(stored.termos) ? stored.termos : (stored.termo ? [stored.termo] : []));
if (Array.isArray(termosIniciais)) {
    termosIniciais.forEach(termo => adicionarTermoBusca(termo));
}
const categoriasIniciais = urlFiltros.hasCategorias ? urlFiltros.categorias : stored.categorias;
if (Array.isArray(categoriasIniciais)) {
    document.querySelectorAll('#dropdownCategorias input[type="checkbox"]').forEach(cb => {
        cb.checked = categoriasIniciais.includes(cb.value);
    });
    atualizarBotaoCategorias();
}
const linhasIniciais = urlFiltros.hasLinhas ? urlFiltros.linhas : stored.linhas;
if (Array.isArray(linhasIniciais)) {
    document.querySelectorAll('#dropdownLinhas input[type="checkbox"]').forEach(cb => {
        cb.checked = linhasIniciais.includes(cb.value);
    });
    atualizarBotaoLinhas();
}
const desenhosIniciais = urlFiltros.hasDesenhos ? urlFiltros.desenhos : stored.desenhos;
if (Array.isArray(desenhosIniciais)) {
    document.querySelectorAll('#dropdownDesenhos input[type="checkbox"]').forEach(cb => {
        cb.checked = desenhosIniciais.includes(cb.value);
    });
    atualizarBotaoDesenhos();
}
if (urlFiltros.hasOrdemAlfa ? urlFiltros.ordemAlfa : stored.ordemAlfa) {
    usarOrdemAlfa = true;
    document.getElementById('ordenarAlfa').innerHTML = ordenarIcon + ' Ordem padrão';
    ordenar();
}
renderizarChipsFiltros();
const shouldAutoFiltrar = termosBuscaAtivos.length > 0 ||
    (Array.isArray(categoriasIniciais) && categoriasIniciais.length) ||
    (Array.isArray(linhasIniciais) && linhasIniciais.length) ||
    (Array.isArray(desenhosIniciais) && desenhosIniciais.length) ||
    usarOrdemAlfa;
if (shouldAutoFiltrar) {
    filtrar(true);
} else {
    const totalProdutos = document.querySelectorAll('.botao-linha').length;
    atualizarContadorProdutos(totalProdutos);
}

const aplicarFiltros = document.getElementById('aplicarFiltros');
if (aplicarFiltros) aplicarFiltros.addEventListener('click', () => {
    if (campoBuscaGlobal) {
        const termoDigitado = campoBuscaGlobal.value.trim();
        if (termoDigitado) {
            enviarEventoGtag('home-filtro-busca', {
                event_category: 'Filtro',
                search_term: termoDigitado
            });
        }
        if (aplicarTermoBusca(termoDigitado)) {
            campoBuscaGlobal.value = '';
        }
    }
    filtrar();
    saveState();
    atualizarUrlFiltros();
});
const copiarLinkFiltros = document.getElementById('copiarLinkFiltros');
if (copiarLinkFiltros) {
    copiarLinkFiltros.addEventListener('click', async () => {
        atualizarUrlFiltros();
        const urlAtual = window.location.href;
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(urlAtual);
                criarToastFeedback('🔗 Link Copiado!');
                return;
            } catch (err) {
                console.warn('Falha ao copiar com clipboard:', err);
            }
        }
        const selecao = criarSelecaoManualLink(urlAtual);
        let copiado = false;
        if (document.queryCommandSupported && document.queryCommandSupported('copy')) {
            try {
                copiado = document.execCommand('copy');
            } catch (err) {
                console.warn('Falha ao copiar com execCommand:', err);
            }
        }
        if (copiado) {
            if (selecao && selecao.wrapper) selecao.wrapper.remove();
            criarToastFeedback('🔗 Link Copiado!');
            return;
        }
        criarToastFeedback('Link selecionado. Copie manualmente com Ctrl+C.', 'erro');
        if (selecao && selecao.wrapper) {
            setTimeout(() => selecao.wrapper.remove(), 6000);
        }
    });
}

document.querySelectorAll('#dropdownCategorias input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', () => {
        atualizarBotaoCategorias();
        renderizarChipsFiltros();
        saveState();
        enviarEventoGtag('home-filtro-campo-produtos', { event_category: 'Filtro' });
    });
});

document.querySelectorAll('#dropdownLinhas input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', () => {
        atualizarBotaoLinhas();
        renderizarChipsFiltros();
        saveState();
        enviarEventoGtag('home-filtro-campo-linhas', { event_category: 'Filtro' });
    });
});

document.querySelectorAll('#dropdownDesenhos input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', () => {
        atualizarBotaoDesenhos();
        renderizarChipsFiltros();
        saveState();
        enviarEventoGtag('home-filtro-campo-desenhos', { event_category: 'Filtro' });
    });
});
const btnLimparBusca = document.getElementById('limparBusca');
if (btnLimparBusca) {
    btnLimparBusca.addEventListener('click', () => {
        campoBuscaGlobal.value = '';
        limparSugestoes();
        saveState();
    });
}

if (campoBuscaGlobal) {
    campoBuscaGlobal.setAttribute('aria-autocomplete', 'list');
    campoBuscaGlobal.setAttribute('aria-controls', 'resultadoBuscaFiltro');
    campoBuscaGlobal.setAttribute('aria-expanded', 'false');
    campoBuscaGlobal.addEventListener('input', () => {
        atualizarSugestoesBusca();
        saveState();
    });
    campoBuscaGlobal.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!resultadoBusca || !resultadoBusca.classList.contains('show')) {
                atualizarSugestoesBusca();
            }
            if (!sugestoesVisiveis.length) return;
            e.preventDefault();
            const delta = e.key === 'ArrowDown' ? 1 : -1;
            sugestaoSelecionadaIndex += delta;
            if (sugestaoSelecionadaIndex >= sugestoesVisiveis.length) {
                sugestaoSelecionadaIndex = 0;
            }
            if (sugestaoSelecionadaIndex < 0) {
                sugestaoSelecionadaIndex = sugestoesVisiveis.length - 1;
            }
            atualizarEstadoSugestao();
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (resultadoBusca && resultadoBusca.classList.contains('show') && sugestaoSelecionadaIndex >= 0) {
                selecionarSugestao(sugestaoSelecionadaIndex);
                return;
            }
            limparSugestoes();
            if (campoBuscaGlobal) {
                const termoDigitado = campoBuscaGlobal.value.trim();
                if (termoDigitado) {
                    enviarEventoGtag('home-filtro-busca', {
                        event_category: 'Filtro',
                        search_term: termoDigitado
                    });
                }
                if (aplicarTermoBusca(termoDigitado)) {
                    campoBuscaGlobal.value = '';
                }
            }
            filtrar();
            saveState();
            return;
        }
        if (e.key === 'Escape') {
            limparSugestoes();
        }
    });
}

document.addEventListener('click', e => {
    if (resultadoBusca && !resultadoBusca.contains(e.target) && e.target !== campoBuscaGlobal) {
        limparSugestoes();
    }
    const dd = document.getElementById('dropdownCategorias');
    if (dd && !dd.contains(e.target)) {
        const list = dd.querySelector('.dropdown-list');
        if (list) list.classList.remove('open');
        const btn = dd.querySelector('.dropdown-button');
        if (btn) {
            btn.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
    }
    const dl = document.getElementById('dropdownLinhas');
    if (dl && !dl.contains(e.target)) {
        const list = dl.querySelector('.dropdown-list');
        if (list) list.classList.remove('open');
        const btn = dl.querySelector('.dropdown-button');
        if (btn) {
            btn.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
    }

    const ddw = document.getElementById('dropdownDesenhos');
    if (ddw && !ddw.contains(e.target)) {
        const list = ddw.querySelector('.dropdown-list');
        if (list) list.classList.remove('open');
        const btn = ddw.querySelector('.dropdown-button');
        if (btn) {
            btn.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
    }
});
window.toggleDropdownCategorias = function (button) {
    const list = button.nextElementSibling || button.querySelector('.dropdown-list');
    if (list) {
        const opened = list.classList.toggle('open');
        button.classList.toggle('open', opened);
        button.setAttribute('aria-expanded', opened ? 'true' : 'false');
    }
};
window.toggleDropdownLinhas = function (button) {
    const list = button.nextElementSibling || button.querySelector('.dropdown-list');
    if (list) {
        const opened = list.classList.toggle('open');
        button.classList.toggle('open', opened);
        button.setAttribute('aria-expanded', opened ? 'true' : 'false');
    }
};
window.toggleDropdownDesenhos = function (button) {
    const list = button.nextElementSibling || button.querySelector('.dropdown-list');
    if (list) {
        const opened = list.classList.toggle('open');
        button.classList.toggle('open', opened);
        button.setAttribute('aria-expanded', opened ? 'true' : 'false');
    }
};
if (resultadoBusca) {
    resultadoBusca.setAttribute('role', 'listbox');
    resultadoBusca.setAttribute('aria-label', 'Sugestões de produtos');
    resultadoBusca.setAttribute('aria-expanded', 'false');
}
