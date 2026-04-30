(function () {
    var SWIPER_DEFAULT_SRC = 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js';
    var SWIPER_DEFAULT_INTEGRITY = 'sha384-2UI1PfnXFjVMQ7/ZDEF70CR943oH3v6uZrFQGGqJYlvhh4g6z6uVktxYbOlAczav';
    var swiperModuleUrl;
    var swiperPromise;
    var photoSwipeModuleUrl;
    var photoSwipePromise;
    var imageManifestPromise;
    var resolvedAppVersion = (function () {
        if (typeof APP_VERSION === 'string' && APP_VERSION.trim()) {
            return APP_VERSION.trim();
        }
        if (typeof window !== 'undefined') {
            var versionFromWindow = window.APP_VERSION;
            if (typeof versionFromWindow === 'string' && versionFromWindow.trim()) {
                return versionFromWindow.trim();
            }
        }
        return null;
    })();
    var manifestUrl = resolvedAppVersion
        ? '/ImagensProdutos/manifest.json?v=' + encodeURIComponent(resolvedAppVersion)
        : '/ImagensProdutos/manifest.json';
    var galleryPlaceholderSrc = (function () {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480" viewBox="0 0 640 480" role="img" aria-label="Imagem indisponível">\
<rect width="100%" height="100%" fill="%23f2f2f2"/><rect x="24" y="24" width="592" height="432" rx="12" fill="%23fafafa" stroke="%23d9d9d9" stroke-width="2"/>\
<text x="50%" y="50%" text-anchor="middle" fill="%23777" font-family="Arial, sans-serif" font-size="20">Imagem indisponível</text></svg>';
        return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
    })();

    function loadSwiperBundle(src) {
        var resolvedSrc = src || SWIPER_DEFAULT_SRC;

        if (typeof Swiper === 'function') {
            return Promise.resolve(Swiper);
        }

        if (!swiperPromise || swiperModuleUrl !== resolvedSrc) {
            swiperModuleUrl = resolvedSrc;
            swiperPromise = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = resolvedSrc;
                script.async = true;
                if (resolvedSrc === SWIPER_DEFAULT_SRC) {
                    script.integrity = SWIPER_DEFAULT_INTEGRITY;
                    script.crossOrigin = 'anonymous';
                }

                function cleanup(error) {
                    if (script.parentNode) {
                        script.parentNode.removeChild(script);
                    }
                    swiperPromise = null;
                    reject(error);
                }

                script.onload = function () {
                    if (typeof Swiper === 'function') {
                        resolve(Swiper);
                        return;
                    }
                    cleanup(new Error('Swiper bundle did not expose expected globals.'));
                };

                script.onerror = function () {
                    cleanup(new Error('Failed to load Swiper bundle.'));
                };

                document.head.appendChild(script);
            });
        }

        return swiperPromise;
    }

    function loadPhotoSwipeBundle(src) {
        var resolvedSrc = src || '/Layout/ModuloPhotoSwipe.js';

        if (typeof PhotoSwipe !== 'undefined' && typeof PhotoSwipeLightbox !== 'undefined') {
            return Promise.resolve({ PhotoSwipe: PhotoSwipe, PhotoSwipeLightbox: PhotoSwipeLightbox });
        }

        if (!photoSwipePromise || photoSwipeModuleUrl !== resolvedSrc) {
            photoSwipeModuleUrl = resolvedSrc;
            photoSwipePromise = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = resolvedSrc;
                script.async = true;

                function cleanup(error) {
                    if (script.parentNode) {
                        script.parentNode.removeChild(script);
                    }
                    photoSwipePromise = null;
                    reject(error);
                }

                script.onload = function () {
                    if (typeof PhotoSwipe !== 'undefined' && typeof PhotoSwipeLightbox !== 'undefined') {
                        resolve({ PhotoSwipe: PhotoSwipe, PhotoSwipeLightbox: PhotoSwipeLightbox });
                        return;
                    }
                    cleanup(new Error('PhotoSwipe bundle did not expose expected globals.'));
                };

                script.onerror = function () {
                    cleanup(new Error('Failed to load PhotoSwipe bundle.'));
                };

                document.head.appendChild(script);
            });
        }

        return photoSwipePromise;
    }

    function loadImageManifest() {
        if (typeof window !== 'undefined' && window.__IMAGENS_PRODUTOS_MANIFEST__) {
            return Promise.resolve(window.__IMAGENS_PRODUTOS_MANIFEST__);
        }
        if (!imageManifestPromise) {
            var fetchOptions = resolvedAppVersion ? { cache: 'force-cache' } : undefined;
            imageManifestPromise = fetch(manifestUrl, fetchOptions)
                .then(function (response) { return response.ok ? response.json() : null; })
                .catch(function () { return null; });
        }
        return imageManifestPromise;
    }

    function resolveManifestFiles(folder, manifest) {
        if (!manifest || !folder) {
            return { matched: false, files: [] };
        }
        var entries = manifest.products || manifest.folders || {};
        var entry = entries[folder];
        if (!entry) {
            return { matched: false, files: [] };
        }
        if (!Array.isArray(entry.files)) {
            return { matched: true, files: [] };
        }
        var basePath = entry.basePath || ('/ImagensProdutos/' + folder + '/');
        var toAbsoluteUrl = function (fileName) {
            if (!fileName) return null;
            if (typeof fileName === 'object') {
                fileName = fileName.url || fileName.path || fileName.file || '';
            }
            if (!fileName) return null;
            if (/^https?:\/\//i.test(fileName)) return fileName;
            if (fileName.startsWith('/')) return fileName;
            return basePath + fileName.replace(/^\/+/, '');
        };
        var resolved = entry.files
            .map(function (file) { return toAbsoluteUrl(file); })
            .filter(Boolean);
        var unique = Array.from(new Set(resolved));
        return { matched: true, files: unique };
    }

    function normalizeManifestFolderKey(value) {
        if (!value) return '';
        return value
            .toString()
            .trim()
            .toUpperCase()
            .replace(/[\s._\-/]+/g, '');
    }

    function resolveManifestFilesWithNormalization(folder, manifest) {
        var exact = resolveManifestFiles(folder, manifest);
        if (exact.matched || !manifest || !folder) {
            return {
                matched: exact.matched,
                files: exact.files,
                strategy: exact.matched ? 'exact' : 'none',
                resolvedKey: exact.matched ? folder : null
            };
        }

        var entries = manifest.products || manifest.folders || {};
        var normalizedRequested = normalizeManifestFolderKey(folder);
        if (!normalizedRequested) {
            return { matched: false, files: [], strategy: 'none', resolvedKey: null };
        }

        var aliases = Object.assign({
            WEGT3: 'WEGT3A',
            IBRT3: 'IBRT3AT3C'
        }, (typeof window !== 'undefined' && window.GALERIA_MANIFEST_ALIASES) ? window.GALERIA_MANIFEST_ALIASES : {});
        var aliasCandidate = aliases[normalizedRequested];
        if (aliasCandidate && entries[aliasCandidate]) {
            var aliasResolved = resolveManifestFiles(aliasCandidate, manifest);
            return {
                matched: aliasResolved.matched,
                files: aliasResolved.files,
                strategy: 'alias',
                resolvedKey: aliasCandidate
            };
        }

        var normalizedMap = {};
        Object.keys(entries).forEach(function (key) {
            var normalizedKey = normalizeManifestFolderKey(key);
            if (normalizedKey && typeof normalizedMap[normalizedKey] === 'undefined') {
                normalizedMap[normalizedKey] = key;
            }
        });

        var normalizedMatch = normalizedMap[normalizedRequested];
        if (normalizedMatch) {
            var normalizedResolved = resolveManifestFiles(normalizedMatch, manifest);
            return {
                matched: normalizedResolved.matched,
                files: normalizedResolved.files,
                strategy: 'normalized',
                resolvedKey: normalizedMatch
            };
        }

        return { matched: false, files: [], strategy: 'none', resolvedKey: null };
    }

    function normalizeGalleryFile(fileName, basePath) {
        if (!fileName) return null;
        if (typeof fileName === 'object') {
            fileName = fileName.url || fileName.path || fileName.file || '';
        }
        if (!fileName) return null;
        if (/^https?:\/\//i.test(fileName)) return fileName;
        if (fileName.startsWith('/')) return fileName;
        if (!basePath) return null;
        return basePath + fileName.replace(/^\/+/, '');
    }

    function resolveDatasetFiles(container, folder) {
        if (!container || typeof container.dataset === 'undefined' || typeof container.dataset.files === 'undefined') {
            return { files: [], hasData: false };
        }
        var raw = container.dataset.files;
        var parsed = [];
        if (raw) {
            try {
                parsed = JSON.parse(raw);
            } catch (error) {
                parsed = raw.split(',').map(function (item) { return item.trim(); });
            }
        }
        if (!Array.isArray(parsed)) {
            parsed = parsed ? [parsed] : [];
        }
        var basePath = container.dataset.basePath || container.dataset.basepath || '';
        if (!basePath && folder) {
            basePath = '/ImagensProdutos/' + folder + '/';
        }
        var resolved = parsed
            .map(function (file) { return normalizeGalleryFile(file, basePath); })
            .filter(Boolean);
        return { files: Array.from(new Set(resolved)), hasData: true };
    }

    function aplicarFallbackImagem(img, alt) {
        if (!img || img.dataset.galeriaFallback === 'true') {
            return;
        }
        img.dataset.galeriaFallback = 'true';
        img.src = galleryPlaceholderSrc;
        img.removeAttribute('srcset');
        img.alt = alt || 'Imagem indisponível';
        img.classList.add('galeria-img-placeholder');
    }

    function monitorarImagem(img, alt) {
        if (!img) return;
        img.addEventListener('error', function () { aplicarFallbackImagem(img, alt); }, { once: true });
    }

    function initGallery() {
        var galleries = document.querySelectorAll('.galeria-produto[data-folder]');
        if (!galleries.length) {
            return;
        }

        galleries.forEach(function (container) {
            if (container.dataset.galeriaInicializada === 'true') {
                return;
            }
            var folder = (container.dataset.folder || '').trim().replace(/\s+/g, '');
            var datasetResolved = resolveDatasetFiles(container, folder);
            var customFiles = datasetResolved.files;
            var customFilesProvided = datasetResolved.hasData;
            if (!folder && !customFiles.length) return;

            var lightboxModuleSrc = container.dataset.lightboxModule;
            var swiperModuleSrc = container.dataset.swiperModule;
            var title = container.dataset.title || '';
            var h1Class = 'fs-3 fw-bolder text-orange';
            var titleHtml = title;
            var isComparacao = container.classList.contains('comparacao-galeria');
            var forceEnableLightbox = container.dataset.enableLightbox === 'true';
            var disableLightbox = container.dataset.disableLightbox === 'true' || (isComparacao && !forceEnableLightbox);
            var hideThumbs = container.dataset.hideThumbs === 'true';
            if (title.toUpperCase().startsWith('IBR ') || title.toLowerCase().startsWith('guia de ')) {
                var parts = title.split(' ');
                var first = parts.shift();
                if (title.toLowerCase().startsWith('guia de ')) {
                    var second = parts.shift();
                    if (second) {
                        first += ' ' + second;
                    }
                }
                var rest = parts.join(' ');
                h1Class = 'fs-3 fw-bolder';
                titleHtml = '<span class="text-dark">' + first + '</span>';
                if (rest) {
                    titleHtml += ' <span class="text-orange">' + rest + '</span>';
                }
            }

            var description = container.dataset.description || '';
            var site = container.dataset.site || '';
            var catalog = container.dataset.catalog || '';
            var siteLabel = container.dataset.siteLabel || 'Site do Produto';
            var catalogLabel = container.dataset.catalogLabel || 'Catálogo do Produto';
            var alt = container.dataset.alt || title;
            var gtagLabel = (title || folder || '').toString();
            var gtagLabelAttr = gtagLabel.replace(/"/g, '&quot;');
            var selectorLinha = (container.dataset.visible || '').toString().trim();
            var selectorTagPrefix = selectorLinha ? ('seletor-' + selectorLinha + '-') : '';
            var buildGaleriaEventName = function (suffix) {
                if (selectorTagPrefix) {
                    return selectorTagPrefix + suffix;
                }
                if (typeof window.buildConfiguradorEventName === 'function') {
                    return window.buildConfiguradorEventName(suffix);
                }
                return suffix;
            };
            var siteEventName = (container.dataset.siteGtagEvent || '').trim() || (selectorTagPrefix ? (selectorTagPrefix + 'produto-site') : 'acesso-site-produto');
            var catalogEventName = (container.dataset.catalogGtagEvent || '').trim() || (selectorTagPrefix ? (selectorTagPrefix + 'produto-catalogo') : 'acesso-catalogo-produto');
            var hideButtons = container.dataset.hideButtons === 'true';
            var exibirSite = !hideButtons && site && site !== '#';
            var exibirCatalog = !hideButtons && catalog && catalog !== '#';
            var botoesHtml = '';
            if (exibirSite || exibirCatalog) {
                botoesHtml = '<div class="botoes__produto d-flex gap-4 flex-column align-items-stretch mb-3">';
                if (exibirSite) {
                    botoesHtml += '<a href="' + site + '" target="_blank" rel="noopener noreferrer" class="btn btn-outline-orange btn-lg rounded-0 px-5 py-3 w-100" data-gtag-event="' + siteEventName + '" data-gtag-category="Produto" data-gtag-label="' + gtagLabelAttr + '"><strong class="text-orange">' + siteLabel + '</strong></a>';
                }
                if (exibirCatalog) {
                    botoesHtml += '<a href="' + catalog + '" target="_blank" rel="noopener noreferrer" class="btn btn-outline-orange btn-lg rounded-0 px-5 py-3 w-100" data-gtag-event="' + catalogEventName + '" data-gtag-category="Produto" data-gtag-label="' + gtagLabelAttr + '"><strong class="text-orange">' + catalogLabel + '</strong></a>';
                }
                botoesHtml += '</div>';
            }

            if (isComparacao) {
                container.innerHTML = '<div class="galeria__produto" data-folder="' + folder + '" data-alt="' + alt + '">\
    <div class="swiper galeria__corpo"><div class="swiper-wrapper align-items-center"></div>\
      <span class="swiper-button-prev galeria-arrow galeria-prev"></span>\
      <span class="swiper-button-next galeria-arrow galeria-next"></span>\
    </div>\
    <div thumbsslider class="swiper galeria__thumbs"><div class="swiper-wrapper align-items-center"></div>\
      <span class="galeria-arrow galeria-thumb-arrow galeria-thumb-prev d-none"></span>\
      <span class="galeria-arrow galeria-thumb-arrow galeria-thumb-next d-none"></span></div>\      </div>';
            } else {
                container.innerHTML = '<div class="row mb-3 justify-content-center justify-content-xl-between align-items-stretch">\
    <div class="galeria__produto col-xl-6 col-lg-7 col-md-10 col-12 mb-3 mb-xl-0" data-folder="' + folder + '" data-alt="' + alt + '">\
        <div class="swiper galeria__corpo"><div class="swiper-wrapper align-items-center"></div>\
            <span class="swiper-button-prev galeria-arrow galeria-prev"></span>\
            <span class="swiper-button-next galeria-arrow galeria-next"></span>\
        </div>\
        <div thumbsslider class="swiper galeria__thumbs"><div class="swiper-wrapper align-items-center"></div>\
            <span class="galeria-arrow galeria-thumb-arrow galeria-thumb-prev d-none"></span>\
            <span class="galeria-arrow galeria-thumb-arrow galeria-thumb-next d-none"></span>\
        </div>\
    </div>\
    <div class="col-lg-5 col-md-10 col-12 fw-bold d-flex flex-column h-100 justify-content-between">\
        <h1 class="' + h1Class + '">' + titleHtml + '</h1>\
        <p class="resumo__paragrafo fs-5 fw-normal mb-4" style="text-align: justify;">' + description + '</p>\
        ' + botoesHtml + '\
    </div>\
</div>';
            }
            container.dataset.galeriaInicializada = 'true';
            var galeria = container.querySelector('.galeria__produto');
            var corpo = galeria.querySelector('.galeria__corpo .swiper-wrapper');
            var thumbs = galeria.querySelector('.galeria__thumbs .swiper-wrapper');
            var corpoContainer = galeria.querySelector('.galeria__corpo');
            var thumbsContainer = galeria.querySelector('.galeria__thumbs');
            if (!corpo || (!thumbs && !hideThumbs)) return;

            var slidesCount = 0;
            var metadataEndpoint = container.dataset.pswpMetadataEndpoint || '';
            var metadataCache = {};
            var observer = null;
            var fallbackImageWidth = 640;
            var fallbackImageHeight = 480;

            var setImageDimensions = function (img, width, height) {
                if (!img || !width || !height) {
                    return;
                }
                img.setAttribute('width', width);
                img.setAttribute('height', height);
            };

            var setPswpDimensions = function (anchor, width, height) {
                if (!anchor || !width || !height) {
                    return;
                }
                anchor.setAttribute('data-pswp-width', width);
                anchor.setAttribute('data-pswp-height', height);
            };

            var buildMetadataUrl = function (src) {
                if (!metadataEndpoint) {
                    return '';
                }
                if (metadataEndpoint.indexOf('{src}') !== -1) {
                    return metadataEndpoint.replace('{src}', encodeURIComponent(src));
                }
                return metadataEndpoint + (metadataEndpoint.indexOf('?') === -1 ? '?src=' : '&src=') + encodeURIComponent(src);
            };

            var fetchMetadata = function (src) {
                if (!metadataEndpoint || !src) {
                    return Promise.resolve(null);
                }
                if (metadataCache[src]) {
                    return metadataCache[src];
                }
                var url = buildMetadataUrl(src);
                metadataCache[src] = fetch(url, { cache: 'force-cache' })
                    .then(function (response) { return response.ok ? response.json() : null; })
                    .then(function (data) {
                        if (!data) {
                            return null;
                        }
                        var width = data.width || data.w;
                        var height = data.height || data.h;
                        if (!width || !height) {
                            return null;
                        }
                        return { width: width, height: height };
                    })
                    .catch(function () { return null; });
                return metadataCache[src];
            };

            if ('IntersectionObserver' in window) {
                observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) {
                            return;
                        }
                        var img = entry.target;
                        var anchor = img.closest('a');
                        if (img.complete) {
                            setPswpDimensions(anchor, img.naturalWidth, img.naturalHeight);
                        } else {
                            img.addEventListener('load', function () {
                                setPswpDimensions(anchor, img.naturalWidth, img.naturalHeight);
                            }, { once: true });
                        }
                        if (anchor && (!anchor.dataset.pswpWidth || !anchor.dataset.pswpHeight)) {
                            fetchMetadata(anchor.href)
                                .then(function (meta) {
                                    if (meta) {
                                        setPswpDimensions(anchor, meta.width, meta.height);
                                    }
                                });
                        }
                        if (observer) {
                            observer.unobserve(img);
                        }
                    });
                });
            }

            var addSlides = function (count) {
                slidesCount = count;
                for (var i = 0; i < count; i++) {
                    var src = '/ImagensProdutos/' + folder + '/' + i + folder + '.webp';
                    var slide = document.createElement('div');
                    slide.className = 'swiper-slide';
                    var lazyAttr = i === 0 ? '' : ' loading="lazy"';
                    slide.innerHTML = disableLightbox
                        ? '<figure class="elemento-figure"><div class="img-block p-3">\
 <picture><img' + lazyAttr + ' src="' + src + '" alt="' + alt + '" class="img-fluid" width="' + fallbackImageWidth + '" height="' + fallbackImageHeight + '" /></picture>\
      </div></figure>'
                        : '<figure class="elemento-figure"><a href="' + src + '">\
      <div class="img-block p-3">\
 <picture><img' + lazyAttr + ' src="' + src + '" alt="' + alt + '" class="img-fluid" width="' + fallbackImageWidth + '" height="' + fallbackImageHeight + '" /></picture>\
      </div></a></figure>';
                    const anchor = slide.querySelector('a');
                    const img = slide.querySelector('img');

                    monitorarImagem(img, alt);
                    if (anchor && img.complete) {
                        setPswpDimensions(anchor, img.naturalWidth, img.naturalHeight);
                        setImageDimensions(img, img.naturalWidth, img.naturalHeight);
                    } else if (anchor) {
                        img.addEventListener('load', function () {
                            setPswpDimensions(anchor, img.naturalWidth, img.naturalHeight);
                            setImageDimensions(img, img.naturalWidth, img.naturalHeight);
                        }, { once: true });
                    }
                    if (anchor) {
                        fetchMetadata(anchor.href)
                            .then(function (meta) {
                                if (meta) {
                                    setPswpDimensions(anchor, meta.width, meta.height);
                                    setImageDimensions(img, meta.width, meta.height);
                                }
                            });
                    }
                    if (observer) {
                        observer.observe(img);
                    }
                    corpo.appendChild(slide);

                    if (!hideThumbs && thumbs) {
                        var thumb = document.createElement('div');
                        thumb.className = 'swiper-slide';
                        thumb.innerHTML = '<div class="img-block p-2">\
  <picture><img' + lazyAttr + ' src="' + src + '" alt="' + alt + '" class="img-fluid w-100 h-100 object-fit-cover" width="' + fallbackImageWidth + '" height="' + fallbackImageHeight + '" /></picture>\
  </div>';
                        monitorarImagem(thumb.querySelector('img'), alt);
                        thumbs.appendChild(thumb);
                    }
                }
            };
            var addSlidesFromFiles = function (files) {
                slidesCount = files.length;
                files.forEach(function (src, index) {
                    var slide = document.createElement('div');
                    slide.className = 'swiper-slide';
                    var lazyAttr = index === 0 ? '' : ' loading="lazy"';
                    slide.innerHTML = disableLightbox
                        ? '<figure class="elemento-figure"><div class="img-block p-3">\
 <picture><img' + lazyAttr + ' src="' + src + '" alt="' + alt + '" class="img-fluid" width="' + fallbackImageWidth + '" height="' + fallbackImageHeight + '" /></picture>\
      </div></figure>'
                        : '<figure class="elemento-figure"><a href="' + src + '">\
      <div class="img-block p-3">\
 <picture><img' + lazyAttr + ' src="' + src + '" alt="' + alt + '" class="img-fluid" width="' + fallbackImageWidth + '" height="' + fallbackImageHeight + '" /></picture>\
      </div></a></figure>';
                    var anchor = slide.querySelector('a');
                    var img = slide.querySelector('img');

                    monitorarImagem(img, alt);
                    if (anchor && img.complete) {
                        setPswpDimensions(anchor, img.naturalWidth, img.naturalHeight);
                        setImageDimensions(img, img.naturalWidth, img.naturalHeight);
                    } else if (anchor) {
                        img.addEventListener('load', function () {
                            setPswpDimensions(anchor, img.naturalWidth, img.naturalHeight);
                            setImageDimensions(img, img.naturalWidth, img.naturalHeight);
                        }, { once: true });
                    }
                    if (anchor) {
                        fetchMetadata(anchor.href)
                            .then(function (meta) {
                                if (meta) {
                                    setPswpDimensions(anchor, meta.width, meta.height);
                                    setImageDimensions(img, meta.width, meta.height);
                                }
                            });
                    }
                    if (observer) {
                        observer.observe(img);
                    }
                    corpo.appendChild(slide);

                    if (!hideThumbs && thumbs) {
                        var thumb = document.createElement('div');
                        thumb.className = 'swiper-slide';
                        thumb.innerHTML = '<div class="img-block p-2">\
  <picture><img' + lazyAttr + ' src="' + src + '" alt="' + alt + '" class="img-fluid w-100 h-100 object-fit-cover" width="' + fallbackImageWidth + '" height="' + fallbackImageHeight + '" /></picture>\
  </div>';
                        monitorarImagem(thumb.querySelector('img'), alt);
                        thumbs.appendChild(thumb);
                    }
                });
            };

            var permitirFallbackConvencao = container.dataset.legacyConventionFallback === 'true'
                || (typeof window !== 'undefined' && window.GALERIA_LEGACY_CONVENTION_FALLBACK === true);

            var registrarFolderInvalidoComparacao = function (origem, manifestInfo, origemDados) {
                if (!isComparacao) {
                    return;
                }
                if (origem !== 'manifest-folder-invalido') {
                    return;
                }
                var chaveResolvida = manifestInfo && manifestInfo.resolvedKey ? manifestInfo.resolvedKey : '(sem correspondência)';
                console.warn('[Galeria][Comparação] Chave de folder inválida no manifest.', {
                    folderSolicitado: folder,
                    folderResolvido: chaveResolvida,
                    origemDados: origemDados || 'desconhecida',
                    estrategiaManifest: manifestInfo && manifestInfo.strategy ? manifestInfo.strategy : 'none'
                });
            };

            var contarUrl = window.location.origin + '/PaginasPrincipal/ContarImagens.php?folder=' + encodeURIComponent(folder);
            var temImagens = true;
            var renderSemImagem = function () {
                temImagens = false;
                if (isComparacao) {
                    container.innerHTML = '<div class="comparacao-galeria-placeholder">Imagem Indisponível</div>';
                    return;
                }
                container.innerHTML = '<div class="galeria-sem-imagem">Imagem Indisponível</div>';
            };
            var verificarImagemDisponivel = function (src) {
                return new Promise(function (resolve) {
                    if (!src) {
                        resolve(false);
                        return;
                    }
                    var imgTeste = new Image();
                    imgTeste.onload = function () { resolve(true); };
                    imgTeste.onerror = function () { resolve(false); };
                    imgTeste.src = src;
                });
            };
            var carregarSlidesPromise = loadImageManifest()
                .then(function (manifest) {
                    var manifestInfo = resolveManifestFilesWithNormalization(folder, manifest);
                    if (manifestInfo.matched) {
                        if (manifestInfo.files.length) {
                            addSlidesFromFiles(manifestInfo.files);
                        } else {
                            renderSemImagem();
                        }
                        return { handled: true, origem: 'manifest', manifestInfo: manifestInfo };
                    }

                    if (customFilesProvided) {
                        if (customFiles.length) {
                            addSlidesFromFiles(customFiles);
                        } else {
                            renderSemImagem();
                        }
                        return { handled: true, origem: 'dataset-files', manifestInfo: manifestInfo };
                    }

                    if (manifest && folder) {
                        return { handled: false, origem: 'manifest-folder-invalido', manifestInfo: manifestInfo };
                    }

                    return { handled: false, origem: 'manifest-indisponivel', manifestInfo: manifestInfo };
                })
                .then(function (state) {
                    if (state.handled) {
                        return null;
                    }

                    registrarFolderInvalidoComparacao(state.origem, state.manifestInfo, 'ContarImagens.php');

                    return fetch(contarUrl, { cache: 'no-store' })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (d) {
                            var count = d && typeof d.count !== 'undefined' ? parseInt(d.count, 10) : null;
                            if (Number.isFinite(count) && count <= 0) {
                                if (!permitirFallbackConvencao) {
                                    renderSemImagem();
                                    return;
                                }
                                var primeiroSrc = '/ImagensProdutos/' + folder + '/0' + folder + '.webp';
                                return verificarImagemDisponivel(primeiroSrc).then(function (existeImagem) {
                                    if (existeImagem) {
                                        addSlides(1);
                                        return;
                                    }
                                    renderSemImagem();
                                });
                            }
                            if (Number.isFinite(count) && count > 0) {
                                addSlides(count);
                                return;
                            }
                            if (permitirFallbackConvencao) {
                                addSlides(1);
                                return;
                            }
                            renderSemImagem();
                        })
                        .catch(function () {
                            if (permitirFallbackConvencao) {
                                addSlides(1);
                                return;
                            }
                            renderSemImagem();
                        });
                })
                .catch(function () {
                    if (temImagens && !slidesCount) {
                        if (permitirFallbackConvencao) {
                            addSlides(1);
                            return;
                        }
                        renderSemImagem();
                    }
                });

            carregarSlidesPromise.finally(function () {
                if (!temImagens || !slidesCount) {
                    return;
                }
                var navPrev = galeria.querySelector('.swiper-button-prev');
                var navNext = galeria.querySelector('.swiper-button-next');
                var shouldShowNav = slidesCount > 1;
                if (navPrev) {
                    navPrev.style.display = shouldShowNav ? '' : 'none';
                }
                if (navNext) {
                    navNext.style.display = shouldShowNav ? '' : 'none';
                }
                loadSwiperBundle(swiperModuleSrc)
                    .then(function (SwiperClass) {
                        var spvMobile = Math.min(slidesCount, 3);
                        var spv550 = Math.min(slidesCount, 4);
                        var spv768 = Math.min(slidesCount, 5);
                        var maxSpv = Math.max(spvMobile, spv550, spv768);
                        var disableLoop = container.dataset.disableLoop === 'true' || container.classList.contains('comparacao-galeria');
                        var hasEnoughSlidesForLoop = function (count, slidesPerView, slidesPerGroup, loopedSlidesOverride) {
                            if (!Number.isFinite(count) || count <= 0) {
                                return false;
                            }
                            var perView = typeof slidesPerView === 'number' ? slidesPerView : count;
                            var perGroup = typeof slidesPerGroup === 'number' ? slidesPerGroup : 1;
                            var loopedSlides = typeof loopedSlidesOverride === 'number' ? loopedSlidesOverride : perGroup;
                            return count >= perView + loopedSlides;
                        };
                        var thumbsSlidesPerGroup = 1;
                        var thumbsLoop = !disableLoop && !hideThumbs && hasEnoughSlidesForLoop(slidesCount, maxSpv, thumbsSlidesPerGroup, thumbsSlidesPerGroup);
                        var mainSlidesPerView = 1;
                        var mainSlidesPerGroup = 1;
                        var mainLoop = !disableLoop && hasEnoughSlidesForLoop(slidesCount, mainSlidesPerView, mainSlidesPerGroup, mainSlidesPerGroup);
                        if (!hideThumbs && slidesCount > maxSpv) {
                            galeria.querySelector('.galeria__thumbs').classList.add('has-overflow');
                        }

                        var thumbsSwiper = null;
                        if (!hideThumbs) {
                            var thumbSlidesView = slidesCount <= 3 ? 'auto' : spvMobile;
                            var thumbBreakpoints = slidesCount <= 3 ? {} : {
                                550: { slidesPerView: spv550, spaceBetween: 0 },
                                768: { slidesPerView: spv768, spaceBetween: 0 }
                            };
                            var thumbCenter = slidesCount > 3;

                            thumbsSwiper = new SwiperClass(galeria.querySelector('.galeria__thumbs'), {
                                loop: thumbsLoop,
                                spaceBetween: 0,
                                breakpoints: thumbBreakpoints,
                                freeMode: true,
                                watchSlidesProgress: true,
                                direction: 'horizontal',
                                slidesPerView: thumbSlidesView,
                                slidesPerGroup: thumbsSlidesPerGroup,
                                loopedSlides: thumbsSlidesPerGroup,
                                centerInsufficientSlides: thumbCenter,
                                navigation: {
                                    nextEl: galeria.querySelector('.swiper-button-next'),
                                    prevEl: galeria.querySelector('.swiper-button-prev')
                                }
                            });

                            var thumbPrev = galeria.querySelector('.galeria-thumb-prev');
                            var thumbNext = galeria.querySelector('.galeria-thumb-next');
                            var needThumbNav = slidesCount > maxSpv;
                            if (thumbPrev && thumbNext && needThumbNav) {
                                thumbPrev.classList.remove('d-none');
                                thumbNext.classList.remove('d-none');
                                thumbPrev.addEventListener('click', function () { thumbsSwiper.slidePrev(); });
                                thumbNext.addEventListener('click', function () { thumbsSwiper.slideNext(); });
                                var toggleThumbArrows = function () {
                                    thumbPrev.style.display = thumbsSwiper.isBeginning ? 'none' : '';
                                    thumbNext.style.display = thumbsSwiper.isEnd ? 'none' : '';
                                };
                                thumbsSwiper.on('slideChange transitionEnd resize', toggleThumbArrows);
                                toggleThumbArrows();
                            }
                        }

                        var mainSwiper = new SwiperClass(galeria.querySelector('.galeria__corpo'), {
                            loop: mainLoop,
                            spaceBetween: 10,
                            effect: 'fade',
                            fadeEffect: { crossFade: true },
                            slidesPerGroup: mainSlidesPerGroup,
                            loopedSlides: mainSlidesPerGroup,
                            navigation: {
                                nextEl: galeria.querySelector('.swiper-button-next'),
                                prevEl: galeria.querySelector('.swiper-button-prev')
                            },
                            thumbs: thumbsSwiper ? { swiper: thumbsSwiper } : undefined
                        });
                        if (typeof window.sendEvent === 'function') {
                            mainSwiper.on('slideChange', function () {
                                var eventName = buildGaleriaEventName('galeria-navegar');
                                window.sendEvent(eventName, {
                                    event_category: 'Galeria',
                                    event_label: gtagLabel || 'galeria_produto'
                                });
                            });
                        }

                        if (!disableLightbox) {
                            loadPhotoSwipeBundle(lightboxModuleSrc)
                                .then(function (modules) {
                                    var PhotoSwipeCtor = modules.PhotoSwipe;
                                    var PhotoSwipeLightboxCtor = modules.PhotoSwipeLightbox;
                                    var corpoEl = galeria.querySelector('.galeria__corpo');
                                    var lightbox = null;
                                    var lgpdShieldFooter = document.getElementById('lgpdShieldFooter');
                                    var lgpdShieldHiddenByLightbox = false;

                                    var setLgpdShieldVisibility = function (shouldHide) {
                                        if (!lgpdShieldFooter) {
                                            return;
                                        }

                                        if (shouldHide) {
                                            lgpdShieldHiddenByLightbox = !lgpdShieldFooter.hasAttribute('hidden');
                                            if (lgpdShieldHiddenByLightbox) {
                                                lgpdShieldFooter.setAttribute('hidden', '');
                                                lgpdShieldFooter.setAttribute('aria-hidden', 'true');
                                            }
                                            return;
                                        }

                                        if (lgpdShieldHiddenByLightbox) {
                                            lgpdShieldFooter.removeAttribute('hidden');
                                            lgpdShieldFooter.setAttribute('aria-hidden', 'false');
                                        }
                                        lgpdShieldHiddenByLightbox = false;
                                    };

                                    var buildItems = function () {
                                        var anchors = Array.from(galeria.querySelectorAll('.elemento-figure a'));
                                        return anchors.map(function (anchor) {
                                            var img = anchor.querySelector('img');
                                            var width = parseInt(anchor.dataset.pswpWidth, 10) || (img && img.naturalWidth) || 800;
                                            var height = parseInt(anchor.dataset.pswpHeight, 10) || (img && img.naturalHeight) || 600;
                                            return {
                                                src: anchor.href,
                                                width: width,
                                                height: height,
                                                alt: (img && (img.getAttribute('alt') || img.alt)) || alt
                                            };
                                        });
                                    };

                                    var ensureLightbox = function () {
                                        if (lightbox) {
                                            return lightbox;
                                        }
                                        lightbox = new PhotoSwipeLightboxCtor({
                                            dataSource: buildItems(),
                                            pswpModule: PhotoSwipeCtor,
                                            initialZoomLevel: 1,
                                            secondaryZoomLevel: 2,
                                            wheelToZoom: true,
                                            imageClickAction: 'zoom'
                                        });
                                        lightbox.on('open', function () {
                                            if (document.body) {
                                                document.body.classList.add('galeria-lightbox-open');
                                            }
                                            setLgpdShieldVisibility(true);
                                            if (typeof window.sendEvent === 'function') {
                                                window.sendEvent(buildGaleriaEventName('galeria-expandir'), {
                                                    event_category: 'Galeria',
                                                    event_label: gtagLabel || 'galeria_produto'
                                                });
                                            }
                                            var pswp = lightbox.pswp;
                                            if (pswp) {
                                                var btn = pswp.element.querySelector('.pswp__button--zoom');
                                                if (btn) {
                                                    btn.addEventListener('click', function (e) {
                                                        e.preventDefault();
                                                        pswp.toggleZoom();
                                                    });
                                                }
                                            }
                                        });
                                        lightbox.on('close', function () {
                                            if (document.body) {
                                                document.body.classList.remove('galeria-lightbox-open');
                                            }
                                            setLgpdShieldVisibility(false);
                                            if (lightbox) {
                                                lightbox.destroy();
                                                lightbox = null;
                                            }
                                        });
                                        lightbox.init();
                                        return lightbox;
                                    };

                                    if (corpoEl) {
                                        corpoEl.addEventListener('click', function (event) {
                                            var anchor = event.target.closest('.elemento-figure a');
                                            if (!anchor) {
                                                return;
                                            }
                                            if (typeof window.sendEvent === 'function') {
                                                window.sendEvent(buildGaleriaEventName('galeria-clique-imagem'), {
                                                    event_category: 'Galeria',
                                                    event_label: gtagLabel || 'galeria_produto'
                                                });
                                            }
                                            event.preventDefault();
                                            var anchors = Array.from(galeria.querySelectorAll('.elemento-figure a'));
                                            var index = anchors.indexOf(anchor);
                                            var instance = ensureLightbox();
                                            if (instance) {
                                                instance.loadAndOpen(index < 0 ? 0 : index);
                                            }
                                        });
                                    }
                                })
                                .catch(function () { });
                        }

                        var updateWidth = function () {
                            var galleryHeight = parseFloat(getComputedStyle(corpoContainer).getPropertyValue('--gallery-height')) || 200;
                            var width = galleryHeight + 'px';
                            if (corpoContainer.style.width === width && (!thumbsContainer || hideThumbs || thumbsContainer.style.width === width)) {
                                return;
                            }
                            if (corpoContainer.style.width !== width) {
                                corpoContainer.style.width = width;
                            }
                            if (thumbsContainer && !hideThumbs && thumbsContainer.style.width !== width) {
                                thumbsContainer.style.width = width;
                            }
                        };
                        updateWidth();
                        window.addEventListener('resize', updateWidth);
                        if (thumbsSwiper) {
                            thumbsSwiper.on('slideChange transitionEnd resize', updateWidth);
                        }
                        mainSwiper.on('slideChange transitionEnd resize', updateWidth);
                    })
                    .catch(function (error) {
                        console.error('Falha ao carregar o Swiper para a galeria.', error);
                    });
            });
        });
    }
    window.initGaleriasProduto = initGallery;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGallery);
    } else {
        initGallery();
    }
})();
