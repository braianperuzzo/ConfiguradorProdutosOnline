(function (window, document) {
    'use strict';

    if (!window || !document) {
        return;
    }

    const registry = new Map();
    let bootstrapReadyBound = false;
    const HELP_FALLBACK_TEXT = 'Conteúdo de ajuda indisponível.';
    const HELP_FALLBACK_TITLE = 'Ajuda';
    const HELP_IMAGE_PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAAAAACwAAAAAAQABAAA=';
    const ALLOWED_HELP_TAGS = new Set(['p', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'br', 'img']);
    const DROP_WITH_CONTENT_TAGS = new Set(['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'template']);

    function isSafeImageSource(value) {
        if (typeof value !== 'string') {
            return false;
        }

        const src = value.trim();
        if (!src) {
            return false;
        }

        if (/^(https?:)?\/\//i.test(src)) {
            return true;
        }

        if (/^[/.]/.test(src) || src.startsWith('../')) {
            return true;
        }

        return /^data:image\/(png|jpe?g|gif|webp|avif);base64,[a-z0-9+/=]+$/i.test(src);
    }

    function hasVisibleHelpContent(html) {
        if (typeof html !== 'string' || !html.trim()) {
            return false;
        }

        const container = document.createElement('div');
        container.innerHTML = html;
        const text = (container.textContent || '').trim();
        return Boolean(text) || Boolean(container.querySelector('img'));
    }

    function sanitizeHelpHtml(value) {
        if (typeof value !== 'string' || !value.trim()) {
            return '';
        }

        const template = document.createElement('template');
        template.innerHTML = value;

        const sanitizeNode = function (node) {
            if (!node) {
                return;
            }

            if (node.nodeType === Node.TEXT_NODE) {
                return;
            }

            if (node.nodeType !== Node.ELEMENT_NODE) {
                node.remove();
                return;
            }

            const element = node;
            const tagName = element.tagName.toLowerCase();

            if (DROP_WITH_CONTENT_TAGS.has(tagName)) {
                element.remove();
                return;
            }

            if (!ALLOWED_HELP_TAGS.has(tagName)) {
                const parent = element.parentNode;
                if (!parent) {
                    element.remove();
                    return;
                }

                const fragment = document.createDocumentFragment();
                while (element.firstChild) {
                    fragment.appendChild(element.firstChild);
                }
                parent.replaceChild(fragment, element);
                return;
            }

            const allowedAttributes = tagName === 'img'
                ? new Set(['src', 'alt', 'title', 'loading', 'decoding', 'referrerpolicy', 'data-help-image-sources', 'style'])
                : new Set([]);

            Array.from(element.attributes).forEach(function (attr) {
                const attrName = attr.name.toLowerCase();
                const attrValue = (attr.value || '').trim();

                if (attrName.startsWith('on')) {
                    element.removeAttribute(attr.name);
                    return;
                }

                if (!allowedAttributes.has(attrName)) {
                    element.removeAttribute(attr.name);
                    return;
                }

                if (tagName === 'img' && attrName === 'src' && !isSafeImageSource(attrValue)) {
                    element.removeAttribute(attr.name);
                    return;
                }

                if (attrName === 'loading' && !/^(lazy|eager)$/i.test(attrValue)) {
                    element.removeAttribute(attr.name);
                    return;
                }

                if (attrName === 'decoding' && !/^(sync|async|auto)$/i.test(attrValue)) {
                    element.removeAttribute(attr.name);
                    return;
                }

                if (attrName === 'referrerpolicy' && !/^(no-referrer|origin|strict-origin|unsafe-url)$/i.test(attrValue)) {
                    element.removeAttribute(attr.name);
                }
            });

            if (tagName === 'img' && !element.getAttribute('src')) {
                element.remove();
                return;
            }

            Array.from(element.childNodes).forEach(sanitizeNode);
        };

        Array.from(template.content.childNodes).forEach(sanitizeNode);
        return template.innerHTML.trim();
    }

    function sanitizeHelpPayload(data) {
        const rawTitle = data && typeof data.title === 'string' ? data.title : '';
        const rawText = data && typeof data.text === 'string' ? data.text : '';
        const title = sanitizeHelpHtml(rawTitle);
        let text = sanitizeHelpHtml(rawText);

        if (!hasVisibleHelpContent(title) && !hasVisibleHelpContent(text)) {
            text = HELP_FALLBACK_TEXT;
            return {
                title: HELP_FALLBACK_TITLE,
                text: sanitizeHelpHtml(text)
            };
        }

        if (!hasVisibleHelpContent(text)) {
            text = sanitizeHelpHtml(HELP_FALLBACK_TEXT);
        }

        return {
            title: title,
            text: text
        };
    }

    function setButtonContent(button, data, bootstrapLib) {
        const sanitized = sanitizeHelpPayload(data);
        const title = sanitized.title;
        const text = sanitized.text;

        button.dataset.helpTitle = title;
        button.dataset.helpText = text;
        button.dataset.bsTitle = title;
        button.dataset.bsContent = text;

        const popoverApi = bootstrapLib && bootstrapLib.Popover;
        if (!popoverApi || typeof popoverApi.getInstance !== 'function') {
            return;
        }

        const popover = popoverApi.getInstance(button);
        if (!popover || typeof popover.setContent !== 'function') {
            return;
        }

        popover.setContent({
            '.popover-header': title,
            '.popover-body': text
        });

        const popoverId = button.getAttribute('aria-describedby');
        if (popoverId) {
            const popoverEl = document.getElementById(popoverId);
            if (popoverEl) {
                prepareHelpImages(popoverEl);
            }
        }

        if (typeof popover.update === 'function') {
            popover.update();
        }
    }

    function getHelpImageCandidates(img) {
        if (!img || !img.getAttribute) {
            return [];
        }

        const serialized = img.getAttribute('data-help-image-sources') || '';
        return serialized
            .split('|')
            .map(function (value) { return value.trim(); })
            .filter(Boolean)
            .filter(isSafeImageSource)
            .filter(function (value, index, list) { return list.indexOf(value) === index; });
    }

    function advanceHelpImage(img) {
        if (!img || !img.getAttribute) {
            return;
        }

        const candidates = getHelpImageCandidates(img);
        if (!candidates.length) {
            img.remove();
            return;
        }

        const currentIndex = Number(img.dataset.helpImageIndex || 0);
        const nextSrc = candidates[currentIndex];

        if (!nextSrc) {
            img.remove();
            return;
        }

        img.dataset.helpImageIndex = String(currentIndex + 1);
        if (img.getAttribute('src') !== nextSrc) {
            img.setAttribute('src', nextSrc);
        }
    }

    function prepareHelpImages(container) {
        if (!container || !container.querySelectorAll) {
            return;
        }

        container.querySelectorAll('img').forEach(function (img) {
            if (img.dataset.helpImagePrepared === 'true') {
                return;
            }

            img.dataset.helpImagePrepared = 'true';

            img.addEventListener('error', function () {
                advanceHelpImage(img);
            });

            img.addEventListener('load', function () {
                img.dataset.helpImageLoaded = 'true';
            });

            const candidates = getHelpImageCandidates(img);
            if (!candidates.length) {
                if (!isSafeImageSource(img.getAttribute('src') || '')) {
                    img.remove();
                }
                return;
            }

            img.dataset.helpImageIndex = '0';
            if ((img.getAttribute('src') || '') === HELP_IMAGE_PLACEHOLDER) {
                advanceHelpImage(img);
            }
        });
    }

    function resolveSectionConfig(button, helpConfigs) {
        if (!button) {
            return null;
        }

        const buttonKey = button.dataset && button.dataset.helpKey;
        if (buttonKey) {
            const keyFn = helpConfigs && helpConfigs[buttonKey];
            if (typeof keyFn === 'function') {
                return keyFn(button);
            }
        }

        const section = button.closest('section');
        if (!section) {
            return null;
        }

        const cfgFn = helpConfigs && helpConfigs[section.id];
        if (typeof cfgFn !== 'function') {
            return null;
        }

        return cfgFn();
    }

    function rememberOriginalValues(button) {
        if (typeof button.dataset.helpTextOriginal === 'undefined') {
            button.dataset.helpTextOriginal = button.dataset.helpText || '';
        }

        if (typeof button.dataset.helpTitleOriginal === 'undefined') {
            button.dataset.helpTitleOriginal = button.dataset.helpTitle || '';
        }
    }

    function buildFallback(button) {
        return {
            title: button.dataset.helpTitleOriginal || '',
            text: button.dataset.helpTextOriginal || ''
        };
    }

    function resolveAssociatedLabelText(button) {
        if (!button || !button.closest) {
            return '';
        }

        const normalizeLabelText = function (value) {
            return (value || '')
                .replace(/\s+/g, ' ')
                .replace(/:+\s*$/, '')
                .trim();
        };

        const parentLabel = button.closest('label');
        if (parentLabel) {
            const cloned = parentLabel.cloneNode(true);
            cloned.querySelectorAll('button.help-icon').forEach(function (icon) {
                icon.remove();
            });
            const fromParent = normalizeLabelText(cloned.textContent || '');
            if (fromParent) {
                return fromParent;
            }
        }

        const section = button.closest('section');
        if (!section) {
            return '';
        }

        const select = section.querySelector('select[id]');
        if (!select || !select.id) {
            return '';
        }

        const associatedLabel = Array.from(section.querySelectorAll('label[for]')).find(function (label) {
            return label.getAttribute('for') === select.id;
        });
        return normalizeLabelText(associatedLabel ? associatedLabel.textContent || '' : '');
    }

    function isPageRootConnected(pageRoot) {
        if (!pageRoot) {
            return false;
        }

        if (typeof pageRoot.isConnected === 'boolean') {
            return pageRoot.isConnected;
        }

        if (pageRoot === document) {
            return true;
        }

        return true;
    }

    function runUpdate(entry, bootstrapLib) {
        if (!entry || !isPageRootConnected(entry.pageRoot)) {
            return;
        }

        const buttons = entry.pageRoot.querySelectorAll(entry.buttonSelector);
        buttons.forEach((button) => {
            rememberOriginalValues(button);
            const resolved = resolveSectionConfig(button, entry.helpConfigs) || buildFallback(button);
            setButtonContent(button, resolved, bootstrapLib || window.bootstrap);
        });
    }

    function bindTriggerListeners(entry) {
        entry.triggerCleanup.forEach((cleanup) => cleanup());
        entry.triggerCleanup = [];

        entry.updateTriggerIds.forEach((id) => {
            const element = entry.pageRoot.querySelector('#' + id) || document.getElementById(id);
            if (!element) {
                return;
            }

            const handler = function () {
                runUpdate(entry, window.bootstrap);
            };

            element.addEventListener('change', handler);
            entry.triggerCleanup.push(function () {
                element.removeEventListener('change', handler);
            });
        });
    }

    function cleanupEntry(entry) {
        if (!entry) {
            return;
        }

        entry.triggerCleanup.forEach((cleanup) => cleanup());
        entry.triggerCleanup = [];
    }

    function bindBootstrapReady() {
        if (bootstrapReadyBound) {
            return;
        }

        bootstrapReadyBound = true;
        document.addEventListener('bootstrap:ready', function (event) {
            const bootstrapLib = (event && event.detail) || window.bootstrap;
            if (!bootstrapLib || !bootstrapLib.Popover) {
                return;
            }

            registry.forEach((entry) => runUpdate(entry, bootstrapLib));
        });
    }

    function registerPageHelp(options) {
        const pageId = options && options.pageId;
        const helpConfigs = (options && options.helpConfigs) || {};
        const buttonSelector = (options && options.buttonSelector) || 'button.help-icon[data-help-text]';
        const updateTriggerIds = (options && options.updateTriggerIds) || [];
        const pageRoot = (options && options.pageRoot) || document;

        if (!pageId) {
            throw new Error('LayoutAjudaSeletores.registerPageHelp: pageId é obrigatório.');
        }

        bindBootstrapReady();

        const previous = registry.get(pageId);
        if (previous) {
            cleanupEntry(previous);
        }

        const entry = {
            pageId: pageId,
            pageRoot: pageRoot,
            helpConfigs: helpConfigs,
            buttonSelector: buttonSelector,
            updateTriggerIds: updateTriggerIds,
            triggerCleanup: []
        };

        registry.set(pageId, entry);

        bindTriggerListeners(entry);
        initHelpButtons(pageRoot);
        runUpdate(entry, window.bootstrap);

        return {
            updateHelps: function (bootstrapLib) {
                runUpdate(entry, bootstrapLib || window.bootstrap);
            },
            unregister: function () {
                cleanupEntry(entry);
                registry.delete(pageId);
            }
        };
    }



    let helpUiBound = false;
    let helpButtonsInitializer = null;
    let helpButtonsObserver = null;
    const HELP_BUTTON_SELECTOR = 'button.help-icon[data-help-text]';


    let helpStylesChecked = false;

    function ensureHelpStyles() {
        if (helpStylesChecked || !document || !document.body) {
            return;
        }

        helpStylesChecked = true;

        if (typeof window.getComputedStyle !== 'function') {
            return;
        }

        const styleEl = document.createElement('style');
        styleEl.id = 'help-popover-inline-styles';
        styleEl.textContent = `
.help-icon{padding:4px;border:0;background:0 0;line-height:1;box-shadow:none;-webkit-appearance:none;appearance:none;color:rgba(var(--ibr-orange),1);width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;vertical-align:middle}
.help-icon::-moz-focus-inner{border:0;padding:0}
.help-icon svg{display:block;stroke:currentColor}
.help-icon:focus,.help-icon:hover{background:0 0;box-shadow:none;color:rgba(var(--ibr-orange),1)}
.help-icon:focus-visible{outline:2px solid rgba(var(--ibr-orange),1);outline-offset:2px;border-radius:4px}
body.dark-mode .help-icon:focus-visible{outline-color:#ffd38a}
.perm-popover{width:clamp(260px,68vw,500px);max-width:calc(100vw - 16px);min-width:220px;min-height:200px}
.perm-popover.fade{transition:none}
.perm-popover .popover-header{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 10px;font-size:.84rem;line-height:1.2}
.perm-popover .popover-title-text{flex:1;min-width:0;font-size:1rem;font-weight:700;font-style:italic;line-height:1.35}
.perm-popover .popover-close{border:0;background:transparent;color:inherit;font-size:22px;line-height:1;padding:0 8px;cursor:pointer;min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center}
.perm-popover .popover-commands{position:absolute;bottom:6px;left:6px;right:auto;display:inline-flex;align-items:center;gap:6px;z-index:2}
.perm-popover .popover-close:focus,.perm-popover .popover-close:hover{opacity:.75}
.perm-popover .popover-body{max-height:clamp(220px,60vh,420px);overflow-y:auto;text-align:justify;font-size:1rem;line-height:1.45;padding:14px 16px}
.perm-popover .popover-resizer{width:16px;height:16px;cursor:nesw-resize;opacity:.8;background:center/contain no-repeat url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M14 20L4 20L4 10' stroke='%23222222' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M12 17L7 17L7 12' stroke='%23222222' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");touch-action:none}
body.dark-mode .perm-popover{--bs-popover-bg:#2b2b2b;--bs-popover-border-color:rgba(var(--ibr-orange),1);--bs-popover-body-color:#ffffff;--bs-popover-header-bg:#1f1f1f;--bs-popover-header-color:#ffffff;--bs-popover-arrow-border:rgba(var(--ibr-orange),1)}
body.dark-mode .perm-popover .popover-resizer{background:center/contain no-repeat url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M14 20L4 20L4 10' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M12 17L7 17L7 12' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E")}
.perm-popover img{display:block;max-width:100%;width:min(100%,280px);max-height:200px;height:auto;object-fit:contain;margin:0 auto 10px;cursor:pointer}
@media (max-width:990px){.perm-popover{max-width:min(92vw,460px)}}
@media (max-width:576px){
.perm-popover{width:min(94vw,420px);max-width:min(94vw,420px);min-width:220px;min-height:180px;border-radius:14px;max-height:min(78dvh,560px)}
.perm-popover .popover-commands,.perm-popover .popover-resizer{display:none!important}
.perm-popover .popover-header{position:sticky;top:0;z-index:2;background:var(--bs-popover-header-bg);padding:6px 9px;font-size:.8rem}
.perm-popover .popover-body{max-height:calc(78dvh - 96px);padding:12px 14px 16px;font-size:1rem}
.perm-popover-mobile-sheet .popover-arrow{display:none!important}
.popover.fade{transition:none}
}
`;
        document.head.appendChild(styleEl);
    }

    function openHelpImageLightbox(img) {
        if (!img) return;
        const container = img.closest('.popover-body');
        if (!container) return;

        const imgs = Array.from(container.querySelectorAll('img')).filter(function (i) {
            return i.naturalWidth;
        });
        const index = imgs.indexOf(img);
        if (index < 0 || imgs.length === 0) return;

        const items = imgs.map(function (i) {
            return {
                src: i.src,
                width: i.naturalWidth || 800,
                height: i.naturalHeight || 600
            };
        });

        const lb = new PhotoSwipeLightbox({
            dataSource: items,
            pswpModule: PhotoSwipe,
            initialZoomLevel: 'fit',
            secondaryZoomLevel: 1,
            wheelToZoom: true,
            imageClickAction: 'zoom'
        });
        lb.on('close', function () { lb.destroy(); });
        lb.init();
        lb.loadAndOpen(index);
    }

    function initHelpUI() {
        if (helpUiBound) {
            return;
        }

        if (!window.bootstrap || !window.bootstrap.Popover || window.bootstrap.__isFallback) {
            return;
        }

        ensureHelpStyles();
        helpUiBound = true;
        const bootstrap = window.bootstrap;
        const focusableSelector = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        const interactiveArrowKeySelector = 'input, select, textarea, button, a, [contenteditable="true"], [role="textbox"], [role="combobox"], [role="listbox"], [role="menu"], [role="slider"], [role="spinbutton"]';
        let isResizingPopover = false;
        let suppressOutsideDismissUntil = 0;
        const getHelpButtons = function (root) {
            const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
            const found = Array.from(scope.querySelectorAll(HELP_BUTTON_SELECTOR));
            if (root && root.matches && root.matches(HELP_BUTTON_SELECTOR)) {
                found.unshift(root);
            }
            return found;
        };
        const isEditableTarget = function (element) {
            if (!element || !(element instanceof HTMLElement)) {
                return false;
            }

            const tagName = element.tagName;
            if (tagName === 'INPUT' || tagName === 'SELECT' || tagName === 'TEXTAREA') {
                return true;
            }

            return element.isContentEditable;
        };
        const isElementVisible = function (element) {
            if (!element || !(element instanceof HTMLElement)) {
                return false;
            }

            const style = window.getComputedStyle(element);
            if (style.visibility === 'hidden' || style.display === 'none') {
                return false;
            }

            return !element.hasAttribute('hidden');
        };
        const getFocusableElements = function (container) {
            if (!container) {
                return [];
            }

            return Array.from(container.querySelectorAll(focusableSelector)).filter(function (item) {
                if (!(item instanceof HTMLElement)) {
                    return false;
                }

                if (!isElementVisible(item)) {
                    return false;
                }

                return !item.hasAttribute('disabled');
            });
        };
        const getOpenPopover = function () {
            return document.querySelector('.popover.show');
        };
        const getPopoverOwner = function (popoverEl) {
            if (!popoverEl) {
                return null;
            }

            const popoverId = popoverEl.getAttribute('id');
            if (!popoverId) {
                return null;
            }

            return document.querySelector('button.help-icon[aria-describedby="' + popoverId + '"]');
        };
        const closeActivePopover = function () {
            const activePopover = getOpenPopover();
            if (!activePopover) {
                return false;
            }

            const owner = getPopoverOwner(activePopover);
            if (!owner) {
                activePopover.remove();
                return true;
            }

            const instance = bootstrap.Popover.getInstance(owner);
            if (instance) {
                instance.hide();
                return true;
            }

            activePopover.remove();
            return true;
        };
        const setupPopoverA11yAttributes = function (btn) {
            const popoverId = btn && btn.getAttribute ? btn.getAttribute('aria-describedby') : null;
            if (!popoverId) {
                return;
            }

            const popoverEl = document.getElementById(popoverId);
            if (!popoverEl) {
                return;
            }

            const header = popoverEl.querySelector('.popover-header');
            const body = popoverEl.querySelector('.popover-body');
            if (header) {
                if (!header.id) {
                    header.id = popoverId + '-title';
                }
                popoverEl.setAttribute('aria-labelledby', header.id);
            }
            if (body) {
                if (!body.id) {
                    body.id = popoverId + '-body';
                }
                popoverEl.setAttribute('aria-describedby', body.id);
            }

            popoverEl.setAttribute('role', 'dialog');
            popoverEl.setAttribute('aria-modal', 'false');
        };
        const getHeaderOffset = function () {
            const header = document.querySelector('.header');
            if (!header) return 0;
            const rect = header.getBoundingClientRect();
            return Math.max(rect.bottom, 0);
        };
        const toModifierArray = function (value) {
            if (Array.isArray(value)) {
                return value;
            }
            if (value && typeof value === 'object' && typeof value[Symbol.iterator] === 'function') {
                return Array.from(value);
            }
            return [];
        };
        const mergeModifiers = function (base, overrides) {
            const merged = toModifierArray(base).map(function (mod) { return Object.assign({}, mod); });
            toModifierArray(overrides).forEach(function (mod) {
                if (!mod || !mod.name) {
                    merged.push(mod);
                    return;
                }
                const index = merged.findIndex(function (item) { return item && item.name === mod.name; });
                if (index === -1) {
                    merged.push(mod);
                    return;
                }
                const existing = merged[index] || {};
                merged[index] = Object.assign({}, existing, mod, {
                    options: Object.assign({}, existing.options || {}, mod.options || {})
                });
            });
            return merged;
        };
        const validPlacements = new Set([
            'auto', 'top', 'bottom', 'left', 'right',
            'top-start', 'top-end', 'bottom-start', 'bottom-end',
            'left-start', 'left-end', 'right-start', 'right-end'
        ]);
        const normalizePlacement = function (value) {
            if (typeof value !== 'string') return null;
            const trimmed = value.trim();
            if (!trimmed) return null;
            const lowered = trimmed.toLowerCase();
            if (lowered === 'undefined' || lowered === 'null') return null;
            return trimmed;
        };
        const updateOpenHelpPopovers = function () {
            getHelpButtons().forEach(function (btn) {
                const popoverId = btn.getAttribute('aria-describedby');
                if (!popoverId) {
                    return;
                }
                const instance = bootstrap.Popover.getInstance(btn);
                if (instance && typeof instance.update === 'function') {
                    instance.update();
                }
                const popoverEl = document.getElementById(popoverId);
                if (popoverEl) {
                    window.requestAnimationFrame(function () {
                        constrainPopoverToViewport(popoverEl);
                    });
                }
            });
        };
        const isMobileViewport = function () { return window.matchMedia('(max-width: 576px)').matches; };
        const applyMobileBottomSheet = function (popoverEl) {
            if (!popoverEl) return;
            const viewportPadding = 8;
            const headerOffset = getHeaderOffset();
            const maxWidth = Math.max(220, Math.min(window.innerWidth - (viewportPadding * 2), 420));
            const maxHeight = Math.max(220, Math.min(window.innerHeight - headerOffset - (viewportPadding * 2), Math.floor(window.innerHeight * 0.78)));
            popoverEl.style.height = 'auto';
            popoverEl.style.width = 'min(94vw, 420px)';
            popoverEl.style.maxWidth = maxWidth + 'px';
            popoverEl.style.minWidth = Math.min(maxWidth, 220) + 'px';
            popoverEl.style.maxHeight = maxHeight + 'px';
            popoverEl.style.position = 'fixed';
            popoverEl.style.transform = 'none';
            popoverEl.style.right = 'auto';
            popoverEl.style.bottom = 'auto';
            popoverEl.style.inset = 'auto';
            const header = popoverEl.querySelector('.popover-header');
            const body = popoverEl.querySelector('.popover-body');
            const rect = popoverEl.getBoundingClientRect();
            const popoverWidth = Math.min(rect.width || maxWidth, maxWidth);
            const popoverHeight = Math.min(rect.height || maxHeight, maxHeight);
            const minLeft = viewportPadding;
            const maxLeft = Math.max(minLeft, window.innerWidth - popoverWidth - viewportPadding);
            const minTop = Math.max(headerOffset + viewportPadding, viewportPadding);
            const maxTop = Math.max(minTop, window.innerHeight - popoverHeight - viewportPadding);
            const nextLeft = Math.min(Math.max(rect.left || minLeft, minLeft), maxLeft);
            const nextTop = Math.min(Math.max(rect.top || minTop, minTop), maxTop);
            popoverEl.style.left = nextLeft + 'px';
            popoverEl.style.top = nextTop + 'px';
            if (body) {
                const headerHeight = header ? header.getBoundingClientRect().height : 0;
                const available = Math.max(140, maxHeight - headerHeight - 16);
                body.style.height = 'auto';
                body.style.maxHeight = available + 'px';
            }
        };
        const applyDesktopPopoverBounds = function (popoverEl) {
            if (!popoverEl) return;
            popoverEl.style.height = '';
            popoverEl.style.maxWidth = '';
            popoverEl.style.width = '';
            popoverEl.style.minWidth = '';
            popoverEl.style.maxHeight = '';
            popoverEl.style.position = '';
            popoverEl.style.inset = '';
            popoverEl.style.transform = '';
            popoverEl.style.left = '';
            popoverEl.style.top = '';
            popoverEl.style.right = '';
            popoverEl.style.bottom = '';
            const body = popoverEl.querySelector('.popover-body');
            if (body) {
                body.style.height = '';
                body.style.maxHeight = '';
            }
        };
        const applyPopoverPresentation = function (popoverEl) {
            if (!popoverEl) return;
            if (isMobileViewport()) {
                popoverEl.classList.add('perm-popover-mobile-sheet');
                applyMobileBottomSheet(popoverEl);
                return;
            }
            popoverEl.classList.remove('perm-popover-mobile-sheet');
            applyDesktopPopoverBounds(popoverEl);
        };
        const constrainPopoverToViewport = function (popoverEl) {
            if (!popoverEl) return;
            applyPopoverPresentation(popoverEl);
            if (isMobileViewport()) return;
            const padding = 8;
            const headerOffset = getHeaderOffset();
            const maxHeight = Math.max(200, window.innerHeight - headerOffset - padding * 2);
            popoverEl.style.maxWidth = 'calc(100vw - ' + (padding * 2) + 'px)';
            popoverEl.style.width = 'min(92vw, 500px)';
            popoverEl.style.maxHeight = maxHeight + 'px';
            const body = popoverEl.querySelector('.popover-body');
            const header = popoverEl.querySelector('.popover-header');
            if (body) {
                const headerHeight = header ? header.getBoundingClientRect().height : 0;
                const available = Math.max(120, maxHeight - headerHeight - padding);
                body.style.maxHeight = available + 'px';
            }
        };
        const closePopoverIfOutOfViewport = function () {
            if (isResizingPopover) return;
            const popovers = document.querySelectorAll('.popover.show');
            popovers.forEach(function (popoverEl) {
                const rect = popoverEl.getBoundingClientRect();
                const headerOffset = getHeaderOffset();
                const padding = 8;
                const minTop = headerOffset + padding;
                const isPopoverVisible = rect.top >= minTop &&
                    rect.left >= padding &&
                    rect.bottom <= (window.innerHeight - padding) &&
                    rect.right <= (window.innerWidth - padding);
                const popoverId = popoverEl.getAttribute('id');
                if (!popoverId) {
                    popoverEl.remove();
                    return;
                }
                const owner = document.querySelector('button.help-icon[aria-describedby="' + popoverId + '"]');
                if (!owner) {
                    popoverEl.remove();
                    return;
                }
                const ownerRect = owner.getBoundingClientRect();
                const ownerVisible = ownerRect.bottom > minTop &&
                    ownerRect.top < (window.innerHeight - padding) &&
                    ownerRect.right > padding &&
                    ownerRect.left < (window.innerWidth - padding);
                const instance = bootstrap.Popover.getInstance(owner);
                if (instance && !ownerVisible) {
                    instance.hide();
                    return;
                }
                if (instance && !isPopoverVisible && typeof instance.update === 'function') {
                    instance.update();
                    constrainPopoverToViewport(popoverEl);
                }
            });
        };
        const ensurePopoverCommandContainer = function (popoverEl) {
            if (!popoverEl) return null;
            let container = popoverEl.querySelector('.popover-commands');
            if (!container) {
                container = document.createElement('div');
                container.className = 'popover-commands';
                popoverEl.appendChild(container);
            }
            return container;
        };
        const ensurePopoverCloseButton = function (btn) {
            const popoverId = btn.getAttribute('aria-describedby');
            if (!popoverId) return;
            const popoverEl = document.getElementById(popoverId);
            if (!popoverEl) return;
            const header = popoverEl.querySelector('.popover-header');
            if (!header || header.querySelector('.popover-close')) return;
            const titleWrapper = document.createElement('span');
            titleWrapper.className = 'popover-title-text';
            while (header.firstChild) {
                titleWrapper.appendChild(header.firstChild);
            }
            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'popover-close';
            closeBtn.setAttribute('aria-label', 'Fechar');
            closeBtn.innerHTML = '&times;';
            header.appendChild(titleWrapper);
            header.appendChild(closeBtn);
        };
        const ensurePopoverResizeHandle = function (btn) {
            const popoverId = btn.getAttribute('aria-describedby');
            if (!popoverId) return;
            const popoverEl = document.getElementById(popoverId);
            if (isMobileViewport()) {
                const handleOnMobile = popoverEl && popoverEl.querySelector('.popover-resizer');
                if (handleOnMobile) {
                    handleOnMobile.remove();
                }
                return;
            }
            if (!popoverEl || popoverEl.querySelector('.popover-resizer')) return;
            const handle = document.createElement('span');
            handle.className = 'popover-resizer';
            handle.setAttribute('aria-hidden', 'true');
            const commandContainer = ensurePopoverCommandContainer(popoverEl);
            if (commandContainer) {
                commandContainer.appendChild(handle);
            } else {
                popoverEl.appendChild(handle);
            }

            const minWidth = 240;
            const minHeight = 220;
            const padding = 8;
            let resizeRaf = null;
            const schedulePopoverUpdate = function () {
                if (resizeRaf) return;
                resizeRaf = window.requestAnimationFrame(function () {
                    resizeRaf = null;
                    const instance = bootstrap.Popover.getInstance(btn);
                    if (instance && typeof instance.update === 'function') {
                        instance.update();
                    }
                });
            };
            const updateBodyHeight = function (height) {
                const body = popoverEl.querySelector('.popover-body');
                if (!body || !height) return;
                const header = popoverEl.querySelector('.popover-header');
                const cachedHeaderHeight = Number(popoverEl.dataset.headerHeight || 0);
                const headerHeight = cachedHeaderHeight > 0
                    ? cachedHeaderHeight
                    : (header ? header.offsetHeight : 0);
                const available = Math.max(height - headerHeight, 120);
                body.style.maxHeight = 'none';
                body.style.height = available + 'px';
            };
            handle.addEventListener('pointerdown', function (event) {
                event.preventDefault();
                event.stopPropagation();
                isResizingPopover = true;
                popoverEl.dataset.resizing = 'true';
                const rect = popoverEl.getBoundingClientRect();
                const startX = event.clientX;
                const startY = event.clientY;
                const startWidth = rect.width;
                const startHeight = rect.height;
                const headerOffset = getHeaderOffset();
                const header = popoverEl.querySelector('.popover-header');
                if (header) {
                    popoverEl.dataset.headerHeight = String(Math.ceil(header.offsetHeight || 0));
                }
                const maxWidth = Math.max(minWidth, window.innerWidth - padding * 2);
                const maxHeight = Math.max(minHeight, window.innerHeight - Math.max(rect.top, headerOffset + padding) - padding);
                let moved = false;
                let resizedOnce = false;

                const onPointerMove = function (moveEvent) {
                    const deltaX = moveEvent.clientX - startX;
                    const deltaY = moveEvent.clientY - startY;
                    moved = true;
                    if (!resizedOnce) {
                        popoverEl.style.maxWidth = 'none';
                        popoverEl.style.maxHeight = 'none';
                        resizedOnce = true;
                    }
                    const nextWidth = Math.min(Math.max(startWidth - deltaX, minWidth), maxWidth);
                    const nextHeight = Math.min(Math.max(startHeight + deltaY, minHeight), maxHeight);
                    popoverEl.style.width = nextWidth + 'px';
                    popoverEl.style.height = nextHeight + 'px';
                    updateBodyHeight(nextHeight);
                    schedulePopoverUpdate();
                };
                const onPointerUp = function () {
                    document.removeEventListener('pointermove', onPointerMove);
                    document.removeEventListener('pointerup', onPointerUp);
                    delete popoverEl.dataset.resizing;
                    if (moved) {
                        schedulePopoverUpdate();
                    }
                    window.setTimeout(function () {
                        isResizingPopover = false;
                    }, 0);
                    delete popoverEl.dataset.headerHeight;
                };
                document.addEventListener('pointermove', onPointerMove);
                document.addEventListener('pointerup', onPointerUp);
            });
        };
        const getHelpTrackingLabel = function (btn) {
            if (!btn) return '';
            return (btn.dataset.helpTitle || btn.getAttribute('data-bs-title') || btn.getAttribute('title') || '')
                .toString()
                .trim();
        };
        const normalizeHelpTag = function (value) {
            return (value || '')
                .toString()
                .normalize('NFD')
                .replace(/[̀-ͯ]/g, '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        };
        const sendHelpTag = function (name, payload) {
            if (!name) return;
            const data = Object.assign({ event_category: 'Ajuda' }, payload || {});
            if (typeof window.sendEvent === 'function') {
                window.sendEvent(name, data);
            } else if (typeof window.dataLayer !== 'undefined' && window.dataLayer && typeof window.dataLayer.push === 'function') {
                window.dataLayer.push(Object.assign({ event: name }, data));
            }

            if (typeof window.sendSelectorEvent === 'function' && typeof window.isSelectorPage === 'function' && window.isSelectorPage()) {
                const suffix = (name || '').replace(/^helpbox-popover-/, 'help-icon-');
                if (suffix) {
                    window.sendSelectorEvent(suffix, data);
                }
            }
        };
        const trackHelpClick = function (btn) {
            const label = normalizeHelpTag(getHelpTrackingLabel(btn));
            sendHelpTag('helpbox-popover-clique', {
                event_label: label || undefined,
                help_label: label || undefined
            });
        };

        const processButtonsInBatches = function (buttons, processor) {
            if (!Array.isArray(buttons) || !buttons.length || typeof processor !== 'function') {
                return;
            }

            const total = buttons.length;
            let index = 0;

            const runBatch = function () {
                const batchStart = (typeof performance !== 'undefined' && typeof performance.now === 'function')
                    ? performance.now()
                    : Date.now();

                while (index < total) {
                    processor(buttons[index]);
                    index += 1;

                    const now = (typeof performance !== 'undefined' && typeof performance.now === 'function')
                        ? performance.now()
                        : Date.now();
                    if ((now - batchStart) >= 8) {
                        break;
                    }
                }

                if (index >= total) {
                    return;
                }

                if (typeof window.requestAnimationFrame === 'function') {
                    window.requestAnimationFrame(runBatch);
                    return;
                }

                window.setTimeout(runBatch, 0);
            };

            runBatch();
        };

        const initHelpButton = function (btn) {
            if (btn.dataset.helpInitialized === 'true') {
                return;
            }

            if (!btn.innerHTML.trim()) {
                btn.innerHTML = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.82 1c0 2-3 2-3 4"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
            }
            btn.type = btn.type || 'button';
            if (!btn.hasAttribute('aria-label')) {
                const fieldName = resolveAssociatedLabelText(btn);
                if (fieldName) {
                    btn.setAttribute('aria-label', 'Ajuda sobre ' + fieldName);
                }
            }
            btn.dataset.bsToggle = 'popover';
            if (!btn.dataset.bsTrigger) btn.dataset.bsTrigger = 'manual';
            if (!btn.dataset.bsContainer) btn.dataset.bsContainer = 'body';
            if (!btn.dataset.bsPlacement) btn.dataset.bsPlacement = 'auto';
            if (!btn.dataset.bsHtml) btn.dataset.bsHtml = 'true';
            const sanitizedHelp = sanitizeHelpPayload({
                title: btn.dataset.helpTitle || btn.dataset.bsTitle || '',
                text: btn.dataset.helpText || btn.dataset.bsContent || ''
            });
            btn.dataset.helpTitle = sanitizedHelp.title;
            btn.dataset.helpText = sanitizedHelp.text;
            btn.dataset.bsContent = sanitizedHelp.text;
            btn.dataset.bsTitle = sanitizedHelp.title;
            if (btn.dataset.helpClass && !btn.dataset.bsCustomClass) btn.dataset.bsCustomClass = btn.dataset.helpClass;
            if (!btn.dataset.bsCustomClass) {
                btn.dataset.bsCustomClass = 'perm-popover';
            } else if (!btn.dataset.bsCustomClass.split(/\s+/).includes('perm-popover')) {
                btn.dataset.bsCustomClass = btn.dataset.bsCustomClass + ' perm-popover';
            }
            if (!btn.dataset.bsArrow) btn.dataset.bsArrow = 'true';
            btn.dataset.bsBoundary = 'viewport';
            btn.setAttribute('aria-expanded', 'false');
            const popoverPadding = { top: getHeaderOffset() + 8, right: 8, bottom: 8, left: 8 };
            const popover = new bootstrap.Popover(btn, {
                title: sanitizedHelp.title,
                content: sanitizedHelp.text,
                trigger: 'manual',
                html: true,
                container: 'body',
                placement: 'auto',
                boundary: 'viewport',
                arrow: true,
                popperConfig: function (defaultConfig) {
                    const resolvedConfig = defaultConfig && typeof defaultConfig === 'object' ? defaultConfig : {};
                    const resolvedModifiers = toModifierArray(resolvedConfig.modifiers);
                    const placementFromConfig = normalizePlacement(resolvedConfig.placement);
                    const placementFromDataset = normalizePlacement(btn.dataset.bsPlacement);
                    const resolvedPlacement = placementFromConfig || placementFromDataset || 'auto';
                    const normalizedPlacement = validPlacements.has(resolvedPlacement) ? resolvedPlacement : 'auto';
                    if (btn.dataset.bsPlacement !== normalizedPlacement) {
                        btn.dataset.bsPlacement = normalizedPlacement;
                    }
                    return Object.assign({}, resolvedConfig, {
                        placement: normalizedPlacement,
                        strategy: 'fixed',
                        modifiers: mergeModifiers(resolvedModifiers, [
                            { name: 'preventOverflow', options: { boundary: 'viewport', padding: popoverPadding } },
                            { name: 'flip', options: { boundary: 'viewport', padding: popoverPadding } },
                            { name: 'eventListeners', enabled: true }
                        ])
                    });
                }
            });
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (btn.dataset.helpToggleLock === 'true') {
                    return;
                }

                btn.dataset.helpToggleLock = 'true';
                window.setTimeout(function () {
                    delete btn.dataset.helpToggleLock;
                }, 0);

                suppressOutsideDismissUntil = Date.now() + 450;
                trackHelpClick(btn);
                getHelpButtons().forEach(function (other) {
                    if (other === btn) return;
                    const inst = bootstrap.Popover.getInstance(other);
                    if (inst) inst.hide();
                });
                popover.toggle();
            });
            btn.addEventListener('inserted.bs.popover', function () {
                ensurePopoverCloseButton(btn);
                ensurePopoverResizeHandle(btn);
                const popoverId = btn.getAttribute('aria-describedby');
                if (popoverId) {
                    const popoverEl = document.getElementById(popoverId);
                    prepareHelpImages(popoverEl);
                    constrainPopoverToViewport(popoverEl);
                }
                const instance = bootstrap.Popover.getInstance(btn);
                if (instance && typeof instance.update === 'function') {
                    window.requestAnimationFrame(function () {
                        instance.update();
                        const currentPopoverId = btn.getAttribute('aria-describedby');
                        const currentPopoverEl = currentPopoverId ? document.getElementById(currentPopoverId) : null;
                        constrainPopoverToViewport(currentPopoverEl);
                    });
                }
            });
            btn.addEventListener('shown.bs.popover', function () {
                const popoverId = btn.getAttribute('aria-describedby');
                btn.setAttribute('aria-expanded', 'true');
                if (popoverId) {
                    btn.setAttribute('aria-controls', popoverId);
                }
                if (popoverId) {
                    const popoverEl = document.getElementById(popoverId);
                    prepareHelpImages(popoverEl);
                    constrainPopoverToViewport(popoverEl);
                    const closeBtn = popoverEl && popoverEl.querySelector('.popover-close');
                    const fallbackFocusable = popoverEl && popoverEl.querySelector(focusableSelector);
                    const focusTarget = closeBtn || fallbackFocusable;
                    if (focusTarget && typeof focusTarget.focus === 'function') {
                        window.requestAnimationFrame(function () {
                            focusTarget.focus({ preventScroll: true });
                        });
                    }
                }
                closePopoverIfOutOfViewport();
                const instance = bootstrap.Popover.getInstance(btn);
                if (instance && typeof instance.update === 'function') {
                    window.requestAnimationFrame(function () {
                        instance.update();
                        const currentPopoverId = btn.getAttribute('aria-describedby');
                        const currentPopoverEl = currentPopoverId ? document.getElementById(currentPopoverId) : null;
                        constrainPopoverToViewport(currentPopoverEl);
                    });
                }
                sendHelpTag('helpbox-popover-expandir');
            });
            btn.addEventListener('hidden.bs.popover', function () {
                btn.setAttribute('aria-expanded', 'false');
                btn.removeAttribute('aria-controls');
                if (typeof btn.focus === 'function') {
                    btn.focus({ preventScroll: true });
                }
            });

            btn.dataset.helpInitialized = 'true';
        };

        const initHelpButtonsLocal = function (root) {
            processButtonsInBatches(getHelpButtons(root), initHelpButton);
        };

        helpButtonsInitializer = initHelpButtonsLocal;
        initHelpButtonsLocal(document);

        if (!helpButtonsObserver && window.MutationObserver && document.body) {
            helpButtonsObserver = new MutationObserver(function (mutationList) {
                mutationList.forEach(function (mutation) {
                    Array.from(mutation.addedNodes || []).forEach(function (addedNode) {
                        if (!addedNode || addedNode.nodeType !== Node.ELEMENT_NODE) {
                            return;
                        }

                        initHelpButtonsLocal(addedNode);
                    });
                });
            });

            helpButtonsObserver.observe(document.body, {
                childList: true,
                subtree: true
            });
        }

        let viewportChangeRaf = null;
        const handleViewportChange = function (event) {
            if (isResizingPopover) return;
            const eventTarget = event && event.target;
            if (event && event.type === 'scroll' && eventTarget && eventTarget.closest && eventTarget.closest('.popover .popover-body')) {
                return;
            }
            if (viewportChangeRaf !== null) {
                return;
            }
            viewportChangeRaf = window.requestAnimationFrame(function () {
                viewportChangeRaf = null;
                updateOpenHelpPopovers();
                closePopoverIfOutOfViewport();
            });
        };
        window.addEventListener('resize', handleViewportChange);
        window.addEventListener('scroll', handleViewportChange, true);

        document.addEventListener('click', function (e) {
            const target = e && e.target instanceof Element ? e.target : null;
            if (!target) {
                return;
            }

            if (Date.now() < suppressOutsideDismissUntil) {
                return;
            }

            if (isResizingPopover || target.closest('.popover-resizer')) return;
            if (!target.closest('.popover') && !target.closest('button.help-icon')) {
                const openPopover = document.querySelector('.popover.show');
                if (openPopover) {
                    sendHelpTag('helpbox-popover-abandono');
                }
                getHelpButtons().forEach(function (btn) {
                    const instance = bootstrap.Popover.getInstance(btn);
                    if (instance) instance.hide();
                });
            }
        });

        document.addEventListener('click', function (e) {
            const closeBtn = e.target.closest('.popover-close');
            if (!closeBtn) return;
            e.preventDefault();
            sendHelpTag('helpbox-popover-abandono');
            const popoverEl = closeBtn.closest('.popover');
            if (!popoverEl) return;
            const popoverId = popoverEl.getAttribute('id');
            if (!popoverId) {
                popoverEl.remove();
                return;
            }
            const owner = document.querySelector('button.help-icon[aria-describedby="' + popoverId + '"]');
            if (!owner) {
                popoverEl.remove();
                return;
            }
            const instance = bootstrap.Popover.getInstance(owner);
            if (instance) instance.hide();
        });

        document.addEventListener('keydown', function (e) {
            const activePopover = getOpenPopover();
            const activeElement = document.activeElement;
            if (e.key === 'Escape') {
                getHelpButtons().forEach(function (btn) {
                    const instance = bootstrap.Popover.getInstance(btn);
                    if (instance) instance.hide();
                });
                return;
            }
            if (e.key === 'Tab' && activePopover) {
                const owner = getPopoverOwner(activePopover);
                const isFocusInPopover = activeElement && activeElement.closest && activeElement.closest('.popover.show');
                const isOwnerFocused = owner && activeElement === owner;

                if (!isFocusInPopover && !isOwnerFocused) {
                    return;
                }

                const focusables = getFocusableElements(activePopover);
                if (!focusables.length) {
                    return;
                }

                if (isOwnerFocused) {
                    e.preventDefault();
                    (e.shiftKey ? focusables[focusables.length - 1] : focusables[0]).focus({ preventScroll: true });
                    return;
                }

                const first = focusables[0];
                const last = focusables[focusables.length - 1];
                if (!e.shiftKey && activeElement === last) {
                    e.preventDefault();
                    first.focus({ preventScroll: true });
                    return;
                }

                if (e.shiftKey && activeElement === first) {
                    e.preventDefault();
                    last.focus({ preventScroll: true });
                }
                return;
            }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!activePopover || isEditableTarget(activeElement) || e.altKey || e.ctrlKey || e.metaKey) {
                    return;
                }

                const eventTarget = e.target instanceof HTMLElement ? e.target : null;
                if (eventTarget && eventTarget.closest(interactiveArrowKeySelector)) {
                    return;
                }

                const activeInsidePopover = activeElement && activeElement.closest ? activeElement.closest('.popover.show') : null;
                if (!activeInsidePopover) {
                    return;
                }

                const popoverBody = activePopover.querySelector('.popover-body');
                if (!popoverBody) {
                    return;
                }

                e.preventDefault();
                popoverBody.scrollBy({ top: e.key === 'ArrowDown' ? 20 : -20 });
            }
        });

        document.addEventListener('click', function (e) {
            const img = e.target.closest('.popover img');
            if (!img) return;
            e.preventDefault();
            sendHelpTag('helpbox-popover-imagem-expandir');
            openHelpImageLightbox(img);
        });
    }



    function initHelpButtons(root) {
        initHelpUI();
        if (typeof helpButtonsInitializer !== 'function') {
            return;
        }

        helpButtonsInitializer(root || document);
    }


    document.addEventListener('DOMContentLoaded', function () {
        initHelpButtons(document);
    });

    const helpPopoverApi = {
        registerPageHelp: registerPageHelp,
        updatePageHelp: function (pageId, bootstrapLib) {
            const entry = registry.get(pageId);
            if (entry && entry.pageRoot) {
                initHelpButtons(entry.pageRoot);
            }
            runUpdate(entry, bootstrapLib || window.bootstrap);
        },
        initHelpUI: initHelpUI,
        initHelpButtons: initHelpButtons
    };
    window.HelpPopover = helpPopoverApi;
    window.LayoutAjudaSeletores = helpPopoverApi;
})(window, document);
