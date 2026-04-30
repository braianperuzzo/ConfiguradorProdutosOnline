(function setupAccessibilityShortcuts(global) {
  if (!global || typeof document === 'undefined') {
    return;
  }

  var ACCESSIBILITY_HTML_URL = '/Acessibilidade/LayoutAcessibilidade.html';
  var ACCESSIBILITY_HTML_FALLBACK = [
    '<aside class="ibr-accessibility-shortcuts" aria-label="Atalhos de Acessibilidade">',
    '    <button class="ibr-accessibility-shortcuts__button" type="button" aria-label="Abrir Recursos Assistivos" data-accessibility-toggle="panel">',
    '        <span class="ibr-accessibility-shortcuts__icon" aria-hidden="true">',
    '            <svg viewBox="0 0 24 24" role="presentation" focusable="false">',
    '                <path d="M12 4.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6zm0 4.8a3.6 3.6 0 1 1 0-7.2 3.6 3.6 0 0 1 0 7.2zm-5.4 11.7a.9.9 0 0 1-.88-1.09l1.8-8.1a.9.9 0 1 1 1.76.39l-1.56 7.01h2.53l.87-3.04a.9.9 0 0 1 .87-.66h.12a.9.9 0 0 1 .87.66l.87 3.04h2.53l-1.56-7.01a.9.9 0 1 1 1.76-.39l1.8 8.1a.9.9 0 0 1-.88 1.09h-3.3a.9.9 0 0 1-.87-.66L12 17.78l-.81 2.83a.9.9 0 0 1-.87.66H6.6z"></path>',
    '            </svg>',
    '        </span>',
    '        <span class="ibr-accessibility-shortcuts__label">Recursos Assistivos</span>',
    '    </button>',
    '',
    '    <button class="ibr-accessibility-shortcuts__button ibr-accessibility-shortcuts__button--libras" type="button" aria-label="Ativar tradução em Libras" data-accessibility-toggle="libras">',
    '        <span class="ibr-accessibility-shortcuts__icon" aria-hidden="true">',
    '            <svg viewBox="0 0 64 64" role="presentation" focusable="false">',
    '                <path d="M12 18h40v28H12z" fill="none"/>',
    '                <path d="M21.5 25.4c2.2 0 4 1.2 4.9 3.1V25h3.8v13.9h-3.8v-3.4c-.9 1.9-2.7 3.1-4.9 3.1-3.4 0-6.2-2.8-6.2-6.6s2.8-6.6 6.2-6.6zm.8 9.8c2 0 3.4-1.5 3.4-3.2s-1.4-3.2-3.4-3.2-3.4 1.5-3.4 3.2 1.4 3.2 3.4 3.2zm10.7-9.2h3.8v2.6c.8-1.8 2.3-2.9 4.5-2.9v4h-1.1c-2 0-3.4.7-3.4 3.2v6h-3.8V26zm16.2 12.9h-3.8v-5.6h-5V39h-3.8V22h3.8v8.3h5V22h3.8v16.9z"></path>',
    '            </svg>',
    '        </span>',
    '        <span class="ibr-accessibility-shortcuts__label">Acessível em Libras</span>',
    '    </button>',
    '</aside>'
  ].join('\n');
  var MOBILE_BREAKPOINT = '(max-width: 991.98px)';

  var ROOT = document.documentElement;
  var READING_GUIDE_ID = 'ibr-reading-guide-line';
  var READING_MASK_ID = 'ibr-reading-mask';
  var MAGNIFIER_POPUP_ID = 'ibr-magnifier-popup';
  var SYNONYMS_POPUP_ID = 'ibr-synonyms-popup';
  var MAGNIFIER_TARGET_ATTRIBUTE = 'data-ibr-magnifier-target';
  var SYNONYMS_TARGET_ATTRIBUTE = 'data-ibr-synonyms-target';
  var FEATURE_STATE_STEPS = {
    'translator': 1,
    'synonyms': 1,
    'font-size': 3,
    'text-style': 3,
    'letters-highlight': 1,
    'line-spacing': 3,
    'letter-spacing': 3,
    'site-reader': 6,
    'reader-mode': 1,
    'reading-mask': 3,
    'reading-guide': 2,
    'links-highlight': 3,
    'page-structure': 1,
    'magnifier': 1,
    'hide-images': 1,
    'highlight-headings': 1,
    'pause-animations': 1,
    'stop-sounds': 1,
    'color-contrast': 3,
    'color-intensity': 3,
    'daltonic-mode': 3
  };
  var STORAGE_KEY = 'ibrA11ySettings';
  var UI_STORAGE_KEY = 'ibrA11yUiSettings';

  var state = {
    fontSize: 0,
    textStyle: 0,
    lettersHighlight: false,
    lineSpacing: 0,
    letterSpacing: 0,
    siteReaderMode: 0,
    readerMode: false,
    readingMask: 0,
    readingGuide: 0,
    linksHighlightLevel: 0,
    synonyms: false,
    magnifier: false,
    hideImages: false,
    highlightHeadings: false,
    pauseAnimations: false,
    stopSounds: false,
    colorContrast: 0,
    colorIntensity: 0,
    daltonicMode: 0
  };

  var DEFAULT_STATE = JSON.parse(JSON.stringify(state));
  var uiState = {
    panelOpen: false,
    panelExpanded: false,
    librasMode: false,
    vlibrasPanelOpen: false
  };
  var DEFAULT_UI_STATE = JSON.parse(JSON.stringify(uiState));
  var magnifierTrackingBound = false;
  var magnifierCurrentTarget = null;
  var synonymsTrackingBound = false;
  var synonymsCurrentHighlight = null;
  var synonymsCurrentWordSelection = null;
  var synonymsLastPointerEvent = null;
  var dictionaryCache = {};
  var DICTIONARY_CACHE_TTL_MS = 1000 * 60 * 60;
  var siteReaderTrackingBound = false;
  var siteReaderCurrentTarget = null;
  var siteReaderSpeakTimer = null;
  var speechSupported = typeof global.speechSynthesis !== 'undefined' && typeof global.SpeechSynthesisUtterance === 'function';
  var speechVoicesReady = false;
  var speechVoicesCache = [];

  var MAGNIFIER_CURSOR_INACTIVE = "url(\"data:image/svg+xml,%3Csvg width='96' height='96' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M12.5 12.5L15.5 15.5M15.5 12.5L12.5 15.5M19 14C19 16.7614 16.7614 19 14 19C11.2386 19 9 16.7614 9 14C9 11.2386 11.2386 9 14 9C16.7614 9 19 11.2386 19 14ZM7 11C7.5 9.5 9.5 7.5 11 7L5 5L7 11Z' stroke='%23ef4444' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\") 12 12, auto";
  var MAGNIFIER_CURSOR_ACTIVE = "url(\"data:image/svg+xml,%3Csvg width='96' height='96' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M16 13L13.5 15.5L12.5 14.5M19 14C19 16.7614 16.7614 19 14 19C11.2386 19 9 16.7614 9 14C9 11.2386 11.2386 9 14 9C16.7614 9 19 11.2386 19 14ZM7 11C7.5 9.5 9.5 7.5 11 7L5 5L7 11Z' stroke='%233b82f6' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\") 12 12, auto";
  var SYNONYMS_CURSOR_INACTIVE = "url(\"data:image/svg+xml,%3Csvg width='96' height='96' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M12.5 12.5L15.5 15.5M15.5 12.5L12.5 15.5M19 14C19 16.7614 16.7614 19 14 19C11.2386 19 9 16.7614 9 14C9 11.2386 11.2386 9 14 9C16.7614 9 19 11.2386 19 14ZM7 11C7.5 9.5 9.5 7.5 11 7L5 5L7 11Z' stroke='%23dc2626' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\") 12 12, auto";
  var SYNONYMS_CURSOR_ACTIVE = "url(\"data:image/svg+xml,%3Csvg width='96' height='96' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M16 13L13.5 15.5L12.5 14.5M19 14C19 16.7614 16.7614 19 14 19C11.2386 19 9 16.7614 9 14C9 11.2386 11.2386 9 14 9C16.7614 9 19 11.2386 19 14ZM7 11C7.5 9.5 9.5 7.5 11 7L5 5L7 11Z' stroke='%231d4ed8' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\") 12 12, auto";
  var LIBRAS_CURSOR_INACTIVE = "url(\"data:image/svg+xml,%3Csvg width='96' height='96' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M12.5 12.5L15.5 15.5M15.5 12.5L12.5 15.5M19 14C19 16.7614 16.7614 19 14 19C11.2386 19 9 16.7614 9 14C9 11.2386 11.2386 9 14 9C16.7614 9 19 11.2386 19 14ZM7 11C7.5 9.5 9.5 7.5 11 7L5 5L7 11Z' stroke='%23dc2626' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\") 12 12, auto";
  var LIBRAS_CURSOR_ACTIVE = "url(\"data:image/svg+xml,%3Csvg width='96' height='96' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M16 13L13.5 15.5L12.5 14.5M19 14C19 16.7614 16.7614 19 14 19C11.2386 19 9 16.7614 9 14C9 11.2386 11.2386 9 14 9C16.7614 9 19 11.2386 19 14ZM7 11C7.5 9.5 9.5 7.5 11 7L5 5L7 11Z' stroke='%231d4ed8' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\") 12 12, auto";
  var LIBRAS_TARGET_ATTRIBUTE = 'data-ibr-libras-target';
  var SITE_READER_TARGET_ATTRIBUTE = 'data-ibr-site-reader-target';
  var librasCurrentTarget = null;
  var librasModeEnabled = false;
  var librasTrackingBound = false;
  var librasWidgetPromise = null;
  var librasPluginReadyPromise = null;
  var librasAvailabilityPromise = null;
  var librasAvailabilityState = null;
  var LIBRAS_AVAILABILITY_TTL_MS = 30000;
  var LIBRAS_CONNECTIVITY_TEST_URLS = [
    'https://vlibras.gov.br/app/vlibras-plugin.js',
    'https://dicionario2.vlibras.gov.br/2018.3.1/WEBGL/BR/CONFIGURADOR'
  ];

  function persistLibrasCriticalLog(message, details) {
    try {
      var payload = {
        msg: '[A11Y:Libras] ' + message,
        url: global.location && global.location.href ? global.location.href : '',
        stack: typeof details === 'undefined' ? '' : JSON.stringify(details).slice(0, 2000)
      };

      var body = JSON.stringify(payload);

      if (global.navigator && typeof global.navigator.sendBeacon === 'function') {
        try {
          var blob = new Blob([body], { type: 'application/json' });
          if (global.navigator.sendBeacon('/LogsErros/RegistrarErroJS.php', blob)) {
            return;
          }
        } catch (_) {}
      }

      if (typeof global.fetch === 'function') {
        global.fetch('/LogsErros/RegistrarErroJS.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: body,
          keepalive: true
        }).catch(function noop() {});
      }
    } catch (_) {}
  }

  function logLibrasDebug(level, message, details) {
    if (level === 'error' || level === 'critical') {
      persistLibrasCriticalLog(message, details);
    }
  }

  function initializeVlibrasWidget() {
    if (!(global.VLibras && typeof global.VLibras.Widget === 'function')) {
      throw new Error('VLibras indisponível no escopo global.');
    }

    var wrapper = document.querySelector('[vw]');
    if (!wrapper) {
      wrapper = document.createElement('div');
      wrapper.setAttribute('vw', '');
      wrapper.className = 'enabled';
      document.body.appendChild(wrapper);
    }

    var accessButton = wrapper.querySelector('[vw-access-button]');
    if (!accessButton) {
      accessButton = document.createElement('div');
      accessButton.setAttribute('vw-access-button', '');
      accessButton.className = 'active';
      wrapper.appendChild(accessButton);
    }

    var pluginWrapper = wrapper.querySelector('[vw-plugin-wrapper], .vw-plugin-wrapper');
    if (!pluginWrapper) {
      pluginWrapper = document.createElement('div');
      pluginWrapper.setAttribute('vw-plugin-wrapper', '');
      wrapper.appendChild(pluginWrapper);
    }

    if (!pluginWrapper.querySelector('.vw-plugin-top-wrapper')) {
      var topWrapper = document.createElement('div');
      topWrapper.className = 'vw-plugin-top-wrapper';
      pluginWrapper.appendChild(topWrapper);
    }

    if (!global.__ibrVlibrasWidgetInitialized) {
      var shouldManuallyInitialize = document.readyState === 'complete';

      if (shouldManuallyInitialize) {
        /**
         * O bundle oficial do VLibras inicializa o widget via `window.onload`.
         * Quando carregamos o plugin tardiamente (após o `load` da página), esse
         * callback nunca roda sozinho. Ao limpar o `onload` antes de instanciar,
         * evitamos reexecutar handlers antigos da página caso precisemos disparar
         * a inicialização manualmente logo em seguida.
         */
        global.onload = null;
      }

      global.__ibrVlibrasWidgetInitialized = true;
      global.__ibrVlibrasWidget = new global.VLibras.Widget('https://vlibras.gov.br/app');

      if (shouldManuallyInitialize && typeof global.onload === 'function' && !global.__ibrVlibrasWidgetLoadTriggered) {
        global.__ibrVlibrasWidgetLoadTriggered = true;
        setTimeout(function runVlibrasLateInitialization() {
          try {
            global.onload();
            logLibrasDebug('info', 'Inicialização tardia do VLibras executada manualmente após load da página.');
          } catch (error) {
            logLibrasDebug('error', 'Falha ao executar inicialização tardia do VLibras.', error);
          }
        }, 0);
      }
    }
  }

  function openVlibrasPanel() {
    var accessButton = document.querySelector('[vw-access-button]');
    if (!accessButton || typeof accessButton.click !== 'function') {
      return false;
    }

    var pluginWrapper = document.querySelector('[vw-plugin-wrapper], .vw-plugin-wrapper');
    var wrapperExpanded = !!(pluginWrapper && pluginWrapper.classList && pluginWrapper.classList.contains('active'));
    var isAlreadyExpanded = wrapperExpanded
      || accessButton.getAttribute('aria-expanded') === 'true'
      || accessButton.getAttribute('aria-pressed') === 'true';

    if (isAlreadyExpanded) {
      return true;
    }

    accessButton.click();
    return true;
  }

  function ensureVlibrasPanelOpen(options) {
    var config = options && typeof options === 'object' ? options : {};
    var maxAttempts = typeof config.maxAttempts === 'number' && config.maxAttempts > 0 ? config.maxAttempts : 8;
    var intervalMs = typeof config.intervalMs === 'number' && config.intervalMs > 0 ? config.intervalMs : 220;
    var attempt = 0;

    function isPanelOpen() {
      var accessButton = document.querySelector('[vw-access-button]');
      var pluginWrapper = document.querySelector('[vw-plugin-wrapper], .vw-plugin-wrapper');

      return !!((accessButton && (accessButton.getAttribute('aria-expanded') === 'true' || accessButton.getAttribute('aria-pressed') === 'true'))
        || (pluginWrapper && pluginWrapper.classList && pluginWrapper.classList.contains('active')));
    }

    return new Promise(function waitForPanelOpen(resolve) {
      function runAttempt() {
        attempt += 1;
        openVlibrasPanel();

        if (isPanelOpen() || attempt >= maxAttempts) {
          resolve(isPanelOpen());
          return;
        }

        setTimeout(runAttempt, intervalMs);
      }

      runAttempt();
    });
  }

  function isVlibrasWidgetElement(element) {
    if (!element || element.nodeType !== 1 || typeof element.closest !== 'function') {
      return false;
    }

    return !!element.closest('[vw-plugin-wrapper], .vw-plugin-wrapper, [vw], [vw-access-button], [vw-button-wrapper]');
  }

  function isInteractiveElement(element) {
    if (!element || element.nodeType !== 1 || typeof element.closest !== 'function') {
      return false;
    }

    return !!element.closest('a[href], button, input, select, textarea, summary, label, details, [role="button"], [role="link"], [data-ibr-allow-native-click="true"]');
  }

  function notify(shortcuts, message) {
    var existing = shortcuts.querySelector('.ibr-assistive-panel__notice');
    if (!existing) {
      existing = document.createElement('div');
      existing.className = 'ibr-assistive-panel__notice';
      var body = shortcuts.querySelector('.ibr-assistive-panel__body');
      if (!body) {
        return;
      }
      body.insertBefore(existing, body.firstChild);
    }

    existing.textContent = message;
    existing.classList.add('is-visible');
    clearTimeout(existing._noticeTimer);
    existing._noticeTimer = setTimeout(function hideNotice() {
      existing.classList.remove('is-visible');
    }, 1800);
  }

  function trackAccessibilityEvent(eventName, details) {
    if (!eventName || typeof global.gtag !== 'function') {
      return;
    }

    var payload = {
      event_category: 'Acessibilidade',
      event_label: 'interacao',
      flow_context: 'recursos_assistivos',
      component: 'painel_flutuante'
    };

    if (details && typeof details === 'object') {
      Object.keys(details).forEach(function assignDetail(key) {
        if (details[key] !== '' && details[key] !== null && typeof details[key] !== 'undefined') {
          payload[key] = details[key];
        }
      });
    }

    global.gtag('event', eventName, payload);
  }

  function saveState() {
    try {
      global.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (error) {
      // noop
    }
  }

  function saveUiState() {
    try {
      global.localStorage.setItem(UI_STORAGE_KEY, JSON.stringify(uiState));
    } catch (error) {
      // noop
    }
  }

  function loadState() {
    try {
      var saved = global.localStorage.getItem(STORAGE_KEY);
      if (!saved) {
        return;
      }
      var parsed = JSON.parse(saved);
      Object.keys(state).forEach(function applyKey(key) {
        if (Object.prototype.hasOwnProperty.call(parsed, key)) {
          state[key] = parsed[key];
        }
      });
    } catch (error) {
      // noop
    }
  }

  function loadUiState() {
    try {
      var saved = global.localStorage.getItem(UI_STORAGE_KEY);
      if (!saved) {
        return;
      }
      var parsed = JSON.parse(saved);
      Object.keys(uiState).forEach(function applyKey(key) {
        if (Object.prototype.hasOwnProperty.call(parsed, key)) {
          uiState[key] = parsed[key];
        }
      });
    } catch (error) {
      // noop
    }
  }

  function normalizeUiState() {
    Object.keys(DEFAULT_UI_STATE).forEach(function normalizeKey(key) {
      var defaultValue = DEFAULT_UI_STATE[key];
      if (typeof defaultValue === 'boolean') {
        uiState[key] = !!uiState[key];
      }
    });

    if (!uiState.panelOpen) {
      uiState.panelExpanded = false;
    }

    if (!uiState.librasMode) {
      uiState.vlibrasPanelOpen = false;
    }
  }


  function normalizeState() {
    if (typeof state.textStyle !== 'number') {
      state.textStyle = state.textStyle ? 1 : 0;
    }

    if (typeof state.lineSpacing !== 'number') {
      state.lineSpacing = state.lineSpacing ? 1 : 0;
    }

    if (typeof state.letterSpacing !== 'number') {
      state.letterSpacing = state.letterSpacing ? 1 : 0;
    }

    if (typeof state.linksHighlightLevel !== 'number') {
      state.linksHighlightLevel = state.linksHighlightLevel ? 1 : 0;
    }

    if (typeof state.readingMask !== 'number') {
      state.readingMask = state.readingMask ? 1 : 0;
    }

    if (typeof state.readingGuide !== 'number') {
      state.readingGuide = state.readingGuide ? 1 : 0;
    }

    if (typeof state.siteReaderMode !== 'number') {
      state.siteReaderMode = state.siteReaderMode ? 1 : 0;
    }

    state.linksHighlightLevel = Math.min(Math.max(state.linksHighlightLevel, 0), 3);
    state.textStyle = Math.min(Math.max(state.textStyle, 0), 3);
    state.lineSpacing = Math.min(Math.max(state.lineSpacing, 0), 3);
    state.letterSpacing = Math.min(Math.max(state.letterSpacing, 0), 3);
    state.readingMask = Math.min(Math.max(state.readingMask, 0), 3);
    state.readingGuide = Math.min(Math.max(state.readingGuide, 0), 2);
    state.siteReaderMode = Math.min(Math.max(state.siteReaderMode, 0), 6);
    state.colorContrast = Math.min(Math.max(state.colorContrast, 0), 3);
    state.colorIntensity = Math.min(Math.max(state.colorIntensity, 0), 3);
    state.daltonicMode = Math.min(Math.max(state.daltonicMode, 0), 3);
  }

  function applyColorContrastMode() {
    // O recurso de contraste não deve forçar a troca do modo de cor do site.
    // Ele atua somente via classes de acessibilidade/filtros locais.
  }

  function syncSvgAnimationsState() {
    var svgs = document.querySelectorAll('svg');
    svgs.forEach(function handleSvg(svg) {
      if (typeof svg.pauseAnimations !== 'function' || typeof svg.unpauseAnimations !== 'function') {
        return;
      }

      if (state.pauseAnimations) {
        svg.pauseAnimations();
        return;
      }

      svg.unpauseAnimations();
    });
  }


  var soundGuardsBound = false;
  var originalMediaPlay = null;

  function silenceMediaElement(element) {
    if (!element) {
      return;
    }

    element.muted = true;
    element.volume = 0;
    if (typeof element.pause === 'function') {
      element.pause();
    }
  }

  function bindSoundGuards() {
    if (soundGuardsBound) {
      return;
    }

    soundGuardsBound = true;

    document.addEventListener('play', function onAnyPlay(event) {
      if (!state.stopSounds) {
        return;
      }
      silenceMediaElement(event.target);
    }, true);

    var MediaElement = global.HTMLMediaElement;
    if (MediaElement && MediaElement.prototype && typeof MediaElement.prototype.play === 'function') {
      originalMediaPlay = MediaElement.prototype.play;
      MediaElement.prototype.play = function guardedPlay() {
        if (state.stopSounds) {
          silenceMediaElement(this);
          return Promise.resolve();
        }
        return originalMediaPlay.apply(this, arguments);
      };
    }

    if (typeof global.Audio === 'function') {
      var OriginalAudio = global.Audio;
      global.Audio = function GuardedAudio() {
        var audio = new OriginalAudio(arguments[0]);
        if (state.stopSounds) {
          silenceMediaElement(audio);
        }
        return audio;
      };
      global.Audio.prototype = OriginalAudio.prototype;
    }

    var AudioContextCtor = global.AudioContext || global.webkitAudioContext;
    if (AudioContextCtor && AudioContextCtor.prototype && typeof AudioContextCtor.prototype.resume === 'function') {
      var originalAudioContextResume = AudioContextCtor.prototype.resume;
      AudioContextCtor.prototype.resume = function guardedResume() {
        if (state.stopSounds) {
          if (typeof this.suspend === 'function') {
            this.suspend();
          }
          return Promise.resolve();
        }
        return originalAudioContextResume.apply(this, arguments);
      };
    }
  }
  function mutePageMedia() {
    var media = document.querySelectorAll('audio, video');
    media.forEach(function mute(element) {
      silenceMediaElement(element);
    });
  }

  function ensureReadingGuide() {
    var guide = document.getElementById(READING_GUIDE_ID);
    if (!guide) {
      guide = document.createElement('div');
      guide.id = READING_GUIDE_ID;
      document.body.appendChild(guide);
    }
    guide.hidden = !state.readingGuide;
  }

  function ensureReadingMask() {
    var mask = document.getElementById(READING_MASK_ID);

    if (!state.readingMask) {
      if (mask) {
        mask.hidden = true;
      }
      return;
    }

    if (!mask) {
      mask = document.createElement('div');
      mask.id = READING_MASK_ID;
      mask.setAttribute('aria-hidden', 'true');
      document.body.appendChild(mask);
    }

    mask.hidden = false;
    mask.setAttribute('data-mask-level', String(state.readingMask));
  }

  function applyReaderModeStructure() {
    var targetsToHide = document.querySelectorAll('header, footer, .ibr-accessibility-shortcuts, #lgpdShieldFooter');
    targetsToHide.forEach(function hideTarget(node) {
      if (state.readerMode) {
        node.setAttribute('data-ibr-reader-hidden', 'true');
      } else {
        node.removeAttribute('data-ibr-reader-hidden');
      }
    });

    var existingReaderContent = document.querySelectorAll('[data-ibr-reader-content="true"]');
    existingReaderContent.forEach(function cleanup(node) {
      if (!state.readerMode) {
        node.removeAttribute('data-ibr-reader-content');
      }
    });

    if (!state.readerMode) {
      return;
    }

    var mainContent = document.querySelector('main')
      || document.querySelector('article')
      || document.querySelector('#content')
      || document.querySelector('.content')
      || document.querySelector('.container')
      || document.body;

    if (mainContent && mainContent !== document.body) {
      mainContent.setAttribute('data-ibr-reader-content', 'true');
    }
  }


  function getOrCreateMagnifierPopup() {
    var popup = document.getElementById(MAGNIFIER_POPUP_ID);
    if (!popup) {
      popup = document.createElement('div');
      popup.id = MAGNIFIER_POPUP_ID;
      popup.setAttribute('role', 'status');
      popup.setAttribute('aria-live', 'polite');
      popup.hidden = true;
      document.body.appendChild(popup);
    }
    return popup;
  }

  function clearMagnifierHighlight() {
    if (magnifierCurrentTarget) {
      magnifierCurrentTarget.removeAttribute(MAGNIFIER_TARGET_ATTRIBUTE);
      magnifierCurrentTarget = null;
    }

    var popup = document.getElementById(MAGNIFIER_POPUP_ID);
    if (popup) {
      popup.hidden = true;
      popup.textContent = '';
      popup.classList.remove('is-visible');
    }
  }

  function getOrCreateSynonymsPopup() {
    var popup = document.getElementById(SYNONYMS_POPUP_ID);
    if (!popup) {
      popup = document.createElement('div');
      popup.id = SYNONYMS_POPUP_ID;
      popup.setAttribute('role', 'dialog');
      popup.setAttribute('aria-live', 'polite');
      popup.hidden = true;
      document.body.appendChild(popup);
    }
    return popup;
  }

  function clearSynonymsHighlight() {
    if (synonymsCurrentHighlight) {
      synonymsCurrentHighlight.removeAttribute(SYNONYMS_TARGET_ATTRIBUTE);
      synonymsCurrentHighlight = null;
    }

    if (synonymsCurrentWordSelection && global.getSelection) {
      var selection = global.getSelection();
      if (selection) {
        selection.removeAllRanges();
      }
      synonymsCurrentWordSelection = null;
    }
  }

  function highlightWordRange(textNode, startIndex, endIndex) {
    if (!textNode || typeof startIndex !== 'number' || typeof endIndex !== 'number' || !global.getSelection) {
      return;
    }

    var selection = global.getSelection();
    if (!selection) {
      return;
    }

    if (
      synonymsCurrentWordSelection
      && synonymsCurrentWordSelection.node === textNode
      && synonymsCurrentWordSelection.start === startIndex
      && synonymsCurrentWordSelection.end === endIndex
    ) {
      return;
    }

    var range = document.createRange();
    range.setStart(textNode, startIndex);
    range.setEnd(textNode, endIndex);

    selection.removeAllRanges();
    selection.addRange(range);
    synonymsCurrentWordSelection = {
      node: textNode,
      start: startIndex,
      end: endIndex
    };
  }

  function hideSynonymsPopup() {
    var popup = document.getElementById(SYNONYMS_POPUP_ID);
    if (popup) {
      popup.hidden = true;
      popup.classList.remove('is-visible');
      popup.innerHTML = '';
    }
  }

  function sanitizeWord(value) {
    return (value || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]/g, '');
  }

  function getWordFromPointer(event) {
    if (!event || typeof event.clientX !== 'number' || typeof event.clientY !== 'number') {
      return null;
    }

    var textNode = getMagnifierTextNodeFromPoint(event.clientX, event.clientY);
    if (!textNode || textNode.nodeType !== Node.TEXT_NODE || !textNode.textContent) {
      return null;
    }

    var parent = textNode.parentElement;
    if (!isMagnifierTextNodeCandidate(parent)) {
      return null;
    }

    var value = textNode.textContent;
    var pointerOffset = value.length;
    if (typeof document.caretPositionFromPoint === 'function') {
      var caretPosition = document.caretPositionFromPoint(event.clientX, event.clientY);
      if (caretPosition && caretPosition.offsetNode === textNode) {
        pointerOffset = caretPosition.offset;
      }
    } else if (typeof document.caretRangeFromPoint === 'function') {
      var caretRange = document.caretRangeFromPoint(event.clientX, event.clientY);
      if (caretRange && caretRange.startContainer === textNode) {
        pointerOffset = caretRange.startOffset;
      }
    }

    var regex = /[A-Za-zÀ-ÖØ-öø-ÿ0-9]+/g;
    var match;
    while ((match = regex.exec(value)) !== null) {
      if (pointerOffset >= match.index && pointerOffset <= (match.index + match[0].length)) {
        var rawWord = match[0];
        if (rawWord.length < 2) {
          return null;
        }
        return {
          element: parent,
          textNode: textNode,
          startIndex: match.index,
          endIndex: match.index + match[0].length,
          rawWord: rawWord,
          normalizedWord: sanitizeWord(rawWord)
        };
      }
    }

    return null;
  }

  function setSynonymsCursor(isActiveTarget) {
    if (!state.synonyms) {
      ROOT.style.removeProperty('--ibr-synonyms-cursor');
      return;
    }

    ROOT.style.setProperty('--ibr-synonyms-cursor', isActiveTarget ? SYNONYMS_CURSOR_ACTIVE : SYNONYMS_CURSOR_INACTIVE);
  }

  function updateSynonymsPopupPosition(event, popup) {
    updateMagnifierPopupPosition(event, popup);
  }

  function renderSynonymsPopup(payload, word) {
    var synonyms = Array.isArray(payload && payload.sinonimos) ? payload.sinonimos : [];
    var meaning = (payload && payload.significado) ? String(payload.significado).trim() : '';
    var examples = Array.isArray(payload && payload.exemplos) ? payload.exemplos.filter(Boolean) : [];

    var lines = [
      '<strong>Palavra:</strong> ' + word,
      '',
      '<strong>Sinônimos:</strong> ' + (synonyms.length ? synonyms.join(', ') : 'Não encontrado'),
      '',
      '<strong>Significado:</strong> ' + (meaning || 'Não encontrado')
    ];

    if (examples.length) {
      lines.push('<strong>Exemplos de Uso:</strong>');
      examples.slice(0, 6).forEach(function addExample(example) {
        lines.push(String(example));
      });
    }

    return lines.map(function toLine(line) {
      return '<div class="ibr-synonyms-popup__line">' + line + '</div>';
    }).join('');
  }

  async function fetchDictionaryData(word) {
    var now = Date.now();
    var cachedEntry = dictionaryCache[word];
    if (cachedEntry && cachedEntry.expiry > now && cachedEntry.payload) {
      return cachedEntry.payload;
    }

    if (cachedEntry && cachedEntry.pendingRequest) {
      return cachedEntry.pendingRequest;
    }

    var requestPromise = (async function requestDictionary() {
      var response = await fetch('/Acessibilidade/ConsultarDicionario.php?palavra=' + encodeURIComponent(word), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      });

      var payload = await response.json();
      if (!response.ok || !payload || payload.ok === false) {
        throw new Error((payload && payload.erro) || 'Falha ao consultar dicionário.');
      }

      dictionaryCache[word] = {
        payload: payload,
        expiry: Date.now() + DICTIONARY_CACHE_TTL_MS,
        pendingRequest: null
      };

      return payload;
    })();

    dictionaryCache[word] = {
      payload: null,
      expiry: 0,
      pendingRequest: requestPromise
    };

    try {
      return await requestPromise;
    } catch (error) {
      delete dictionaryCache[word];
      throw error;
    }
  }

  function handleSynonymsPointerMove(event) {
    if (!state.synonyms) {
      return;
    }

    synonymsLastPointerEvent = event;
    var targetInfo = getWordFromPointer(event);

    if (!targetInfo) {
      clearSynonymsHighlight();
      setSynonymsCursor(false);
      return;
    }

    if (synonymsCurrentHighlight && synonymsCurrentHighlight !== targetInfo.element) {
      synonymsCurrentHighlight.removeAttribute(SYNONYMS_TARGET_ATTRIBUTE);
    }

    synonymsCurrentHighlight = targetInfo.element;
    synonymsCurrentHighlight.setAttribute(SYNONYMS_TARGET_ATTRIBUTE, 'true');
    highlightWordRange(targetInfo.textNode, targetInfo.startIndex, targetInfo.endIndex);
    setSynonymsCursor(true);
  }

  async function handleSynonymsClick(event) {
    if (!state.synonyms) {
      return;
    }

    var interactionPoint = getInteractionPoint(event) || event;

    if (isInteractiveElement(interactionPoint && interactionPoint.target)) {
      hideSynonymsPopup();
      clearSynonymsHighlight();
      setSynonymsCursor(false);
      return;
    }

    var targetInfo = getWordFromPointer(interactionPoint);
    if (!targetInfo || !targetInfo.normalizedWord) {
      hideSynonymsPopup();
      clearSynonymsHighlight();
      setSynonymsCursor(false);
      var shortcuts = document.querySelector('.ibr-accessibility-shortcuts');
      if (shortcuts) {
        notify(shortcuts, 'Passe o mouse sobre uma palavra para consultar sinônimos e significado.');
      }
      return;
    }

    if (event && typeof event.preventDefault === 'function') {
      event.preventDefault();
    }

    if (event && typeof event.stopPropagation === 'function') {
      event.stopPropagation();
    }

    var popup = getOrCreateSynonymsPopup();
    popup.hidden = false;
    popup.classList.add('is-visible');
    popup.innerHTML = '<div><strong>Consultando:</strong> ' + targetInfo.rawWord + '...</div>';
    updateSynonymsPopupPosition(interactionPoint, popup);

    try {
      var payload = await fetchDictionaryData(targetInfo.normalizedWord);
      popup.innerHTML = renderSynonymsPopup(payload, targetInfo.rawWord);
      updateSynonymsPopupPosition(synonymsLastPointerEvent || interactionPoint, popup);
    } catch (error) {
      popup.innerHTML = '<div><strong>Não foi possível consultar agora.</strong></div><div>Tente novamente em alguns instantes.</div>';
      updateSynonymsPopupPosition(synonymsLastPointerEvent || interactionPoint, popup);
    }
  }

  function bindSynonymsTracking() {
    if (synonymsTrackingBound) {
      return;
    }

    synonymsTrackingBound = true;
    document.addEventListener('pointermove', handleSynonymsPointerMove, true);
    document.addEventListener('pointerleave', function onSynonymsPointerLeave() {
      if (!state.synonyms) {
        return;
      }
      clearSynonymsHighlight();
      setSynonymsCursor(false);
      hideSynonymsPopup();
    }, true);
    document.addEventListener('scroll', function onSynonymsScroll() {
      if (!state.synonyms) {
        return;
      }
      hideSynonymsPopup();
    }, true);
    document.addEventListener('click', function onSynonymsClick(event) {
      handleSynonymsClick(event);
    }, true);
    document.addEventListener('touchstart', function onSynonymsTouchStart(event) {
      if (!state.synonyms || !event.touches || !event.touches.length) {
        return;
      }
      handleSynonymsPointerMove(getInteractionPoint(event));
    }, { capture: true, passive: true });
    document.addEventListener('touchend', function onSynonymsTouchEnd(event) {
      handleSynonymsClick(event);
    }, { capture: true, passive: false });
  }

  function syncSynonymsState() {
    if (state.synonyms) {
      bindSynonymsTracking();
      setSynonymsCursor(false);
      return;
    }

    clearSynonymsHighlight();
    hideSynonymsPopup();
    setSynonymsCursor(false);
  }

  function getMagnifierReadableText(element) {
    if (!element || !element.textContent) {
      return '';
    }

    var text = element.textContent.replace(/\s+/g, ' ').trim();
    if (!text || text.length < 2) {
      return '';
    }

    return text;
  }

  function getElementOwnReadableText(element) {
    if (!element || !element.childNodes || !element.childNodes.length) {
      return '';
    }

    var text = '';
    for (var i = 0; i < element.childNodes.length; i += 1) {
      var node = element.childNodes[i];
      if (node && node.nodeType === Node.TEXT_NODE && node.textContent) {
        text += node.textContent + ' ';
      }
    }

    text = text.replace(/\s+/g, ' ').trim();
    if (!text || text.length < 2) {
      return '';
    }

    return text;
  }

  function isMagnifierTextNodeCandidate(element) {
    if (!element || element.nodeType !== 1) {
      return false;
    }

    var tagName = element.tagName;
    if (!tagName) {
      return false;
    }

    if (/^(SCRIPT|STYLE|NOSCRIPT|SVG|PATH|IMG|VIDEO|AUDIO|CANVAS|IFRAME|INPUT|TEXTAREA|SELECT|OPTION|BUTTON)$/.test(tagName)) {
      return false;
    }

    var style = global.getComputedStyle(element);
    if (!style || style.visibility === 'hidden' || style.display === 'none' || style.pointerEvents === 'none') {
      return false;
    }

    return true;
  }

  function getMagnifierTextNodeFromPoint(clientX, clientY) {
    if (typeof document.caretPositionFromPoint === 'function') {
      var caretPosition = document.caretPositionFromPoint(clientX, clientY);
      if (caretPosition && caretPosition.offsetNode && caretPosition.offsetNode.nodeType === Node.TEXT_NODE) {
        return caretPosition.offsetNode;
      }
    }

    if (typeof document.caretRangeFromPoint === 'function') {
      var caretRange = document.caretRangeFromPoint(clientX, clientY);
      if (caretRange && caretRange.startContainer && caretRange.startContainer.nodeType === Node.TEXT_NODE) {
        return caretRange.startContainer;
      }
    }

    return null;
  }

  function isLibrasTextNodeCandidate(element) {
    return isMagnifierTextNodeCandidate(element);
  }

  function findBestReadableTextTarget(fromElement, event) {
    var current = fromElement && fromElement.nodeType === 1 ? fromElement : null;
    var textNode = null;

    if (event && typeof event.clientX === 'number' && typeof event.clientY === 'number') {
      textNode = getMagnifierTextNodeFromPoint(event.clientX, event.clientY);
      if ((!current || current.nodeType !== 1) && typeof document.elementFromPoint === 'function') {
        current = document.elementFromPoint(event.clientX, event.clientY);
      }
    }

    if (textNode && textNode.parentElement && isMagnifierTextNodeCandidate(textNode.parentElement)) {
      current = textNode.parentElement;
    }

    var fallback = null;

    while (current && current !== document.body && current !== document.documentElement) {
      if (!isMagnifierTextNodeCandidate(current)) {
        current = current.parentElement;
        continue;
      }

      var ownText = getElementOwnReadableText(current);
      if (ownText) {
        return current;
      }

      if (!fallback) {
        var fullText = getMagnifierReadableText(current);
        if (fullText && fullText.length <= 220) {
          fallback = current;
        }
      }

      current = current.parentElement;
    }

    return fallback;
  }

  function findMagnifierTextTarget(event) {
    var fromElement = event && event.target && event.target.nodeType === 1 ? event.target : null;
    return findBestReadableTextTarget(fromElement, event);
  }
  function findSiteReaderTextTarget(fromElement, event) {
    return findBestReadableTextTarget(fromElement, event);
  }

  function isInteractiveControlElement(element) {
    if (!element || element.nodeType !== 1 || typeof element.closest !== 'function') {
      return false;
    }

    return !!element.closest('a[href], button, input, select, textarea, summary, label, [role="button"], [role="link"], [data-accessibility-close], [data-accessibility-toggle], [data-page-structure-target]');
  }

  function setSiteReaderCursor(isActiveTarget) {
    if (!state.siteReaderMode) {
      ROOT.style.removeProperty('--ibr-site-reader-cursor');
      return;
    }

    ROOT.style.setProperty('--ibr-site-reader-cursor', isActiveTarget ? MAGNIFIER_CURSOR_ACTIVE : MAGNIFIER_CURSOR_INACTIVE);
  }

  function clearSiteReaderHighlight() {
    if (siteReaderCurrentTarget) {
      siteReaderCurrentTarget.removeAttribute(SITE_READER_TARGET_ATTRIBUTE);
      siteReaderCurrentTarget = null;
    }
  }

  function loadSpeechVoices() {
    if (!speechSupported) {
      return [];
    }

    speechVoicesCache = global.speechSynthesis.getVoices() || [];
    if (speechVoicesCache.length) {
      speechVoicesReady = true;
    }
    return speechVoicesCache;
  }

  function pickVoiceByGender(voices, gender) {
    if (!voices || !voices.length) {
      return null;
    }

    var femalePattern = /(female|mulher|feminina|zira|helena|maria|luciana|camila|francisca|vitoria|sofia|joana|ana|beatriz|isabela)/i;
    var malePattern = /(male|homem|masculina|masculino|ricardo|paulo|antonio|daniel|jorge|joao|carlos|pedro|mateus)/i;
    var preferredPattern = gender === 'female' ? femalePattern : malePattern;

    for (var i = 0; i < voices.length; i += 1) {
      var voiceName = ((voices[i].name || '') + ' ' + (voices[i].voiceURI || '')).toLowerCase();
      if (preferredPattern.test(voiceName)) {
        return voices[i];
      }
    }

    for (var j = 0; j < voices.length; j += 1) {
      if ((voices[j].lang || '').toLowerCase().indexOf('pt') === 0) {
        return voices[j];
      }
    }

    return voices[0] || null;
  }

  function getSiteReaderModeProfile() {
    switch (state.siteReaderMode) {
      case 1: return { label: 'Velocidade: Normal; Voz: Masculina', rate: 1, gender: 'male' };
      case 2: return { label: 'Velocidade: Normal; Voz: Feminina', rate: 1, gender: 'female' };
      case 3: return { label: 'Velocidade: Rápida; Voz: Masculina', rate: 2, gender: 'male' };
      case 4: return { label: 'Velocidade: Rápida; Voz: Feminina', rate: 2, gender: 'female' };
      case 5: return { label: 'Velocidade: Lenta; Voz: Masculina', rate: 0, gender: 'male' };
      case 6: return { label: 'Velocidade: Lenta; Voz: Feminina', rate: 0, gender: 'female' };
      default: return null;
    }
  }

  function speakSiteReaderText(text) {
    if (!speechSupported || !text) {
      return;
    }

    var profile = getSiteReaderModeProfile();
    if (!profile) {
      return;
    }

    var utterance = new global.SpeechSynthesisUtterance(text);
    utterance.lang = 'pt-BR';
    utterance.rate = profile.rate;
    utterance.pitch = 1;
    utterance.volume = 1;

    var voices = loadSpeechVoices();
    utterance.voice = pickVoiceByGender(voices, profile.gender);

    global.speechSynthesis.cancel();
    global.speechSynthesis.speak(utterance);
  }

  function stopSiteReaderSpeech() {
    if (siteReaderSpeakTimer) {
      clearTimeout(siteReaderSpeakTimer);
      siteReaderSpeakTimer = null;
    }

    if (speechSupported) {
      global.speechSynthesis.cancel();
    }
  }

  function queueSiteReaderSpeech(text) {
    if (!text) {
      return;
    }

    if (siteReaderSpeakTimer) {
      clearTimeout(siteReaderSpeakTimer);
    }

    siteReaderSpeakTimer = setTimeout(function delayedSpeak() {
      siteReaderSpeakTimer = null;
      speakSiteReaderText(text);
    }, 180);
  }

  function updateSiteReaderHighlight(target) {
    if (siteReaderCurrentTarget && siteReaderCurrentTarget !== target) {
      siteReaderCurrentTarget.removeAttribute(SITE_READER_TARGET_ATTRIBUTE);
      siteReaderCurrentTarget = null;
    }

    if (!target) {
      setSiteReaderCursor(false);
      return;
    }

    siteReaderCurrentTarget = target;
    siteReaderCurrentTarget.setAttribute(SITE_READER_TARGET_ATTRIBUTE, 'true');
    setSiteReaderCursor(true);
  }

  function handleSiteReaderPointerMove(event) {
    if (!state.siteReaderMode) {
      return;
    }

    var target = findSiteReaderTextTarget(event.target, event);
    if (!target) {
      clearSiteReaderHighlight();
      setSiteReaderCursor(false);
      stopSiteReaderSpeech();
      return;
    }

    if (target !== siteReaderCurrentTarget) {
      var text = getMagnifierReadableText(target);
      updateSiteReaderHighlight(target);
      queueSiteReaderSpeech(text);
      return;
    }

    updateSiteReaderHighlight(target);
  }

  function bindSiteReaderTracking() {
    if (siteReaderTrackingBound) {
      return;
    }

    siteReaderTrackingBound = true;

    if (speechSupported && !speechVoicesReady) {
      loadSpeechVoices();
      global.speechSynthesis.addEventListener('voiceschanged', function onVoicesChanged() {
        loadSpeechVoices();
      });
    }

    document.addEventListener('pointermove', handleSiteReaderPointerMove, true);
    document.addEventListener('pointerleave', function onSiteReaderPointerLeave() {
      if (!state.siteReaderMode) {
        return;
      }
      clearSiteReaderHighlight();
      setSiteReaderCursor(false);
      stopSiteReaderSpeech();
    }, true);
    document.addEventListener('scroll', function onSiteReaderScroll() {
      if (!state.siteReaderMode) {
        return;
      }
      clearSiteReaderHighlight();
      setSiteReaderCursor(false);
      stopSiteReaderSpeech();
    }, true);
    document.addEventListener('touchstart', function onSiteReaderTouchStart(event) {
      if (!state.siteReaderMode || !event.touches || !event.touches.length) {
        return;
      }
      handleSiteReaderPointerMove(getInteractionPoint(event));
    }, { capture: true, passive: true });
  }

  function syncSiteReaderState(shortcuts) {
    if (state.siteReaderMode) {
      bindSiteReaderTracking();
      setSiteReaderCursor(false);
      return;
    }

    clearSiteReaderHighlight();
    setSiteReaderCursor(false);
    stopSiteReaderSpeech();

    if (!speechSupported && shortcuts) {
      notify(shortcuts, 'Web Speech API não está disponível neste navegador/dispositivo.');
    }
  }

  function findLibrasTextTarget(fromElement, event) {
    if (isInteractiveControlElement(fromElement)) {
      return null;
    }

    return findBestReadableTextTarget(fromElement, event);
  }

  function setLibrasCursor(isActiveTarget) {
    if (!librasModeEnabled) {
      ROOT.style.removeProperty('--ibr-libras-cursor');
      return;
    }

    ROOT.style.setProperty('--ibr-libras-cursor', isActiveTarget ? LIBRAS_CURSOR_ACTIVE : LIBRAS_CURSOR_INACTIVE);
  }

  function clearLibrasHighlight() {
    if (librasCurrentTarget) {
      librasCurrentTarget.removeAttribute(LIBRAS_TARGET_ATTRIBUTE);
      librasCurrentTarget = null;
    }
  }

  function updateLibrasHighlight(target) {
    if (librasCurrentTarget && librasCurrentTarget !== target) {
      librasCurrentTarget.removeAttribute(LIBRAS_TARGET_ATTRIBUTE);
      librasCurrentTarget = null;
    }

    if (!target) {
      setLibrasCursor(false);
      return;
    }

    librasCurrentTarget = target;
    librasCurrentTarget.setAttribute(LIBRAS_TARGET_ATTRIBUTE, 'true');
    setLibrasCursor(true);
  }

  function ensureVlibrasWidget() {
    if (global.VLibras && typeof global.VLibras.Widget === 'function') {
      try {
        initializeVlibrasWidget();
        return Promise.resolve();
      } catch (error) {
        return Promise.reject(error);
      }
    }

    if (librasWidgetPromise) {
      return librasWidgetPromise;
    }

    librasWidgetPromise = new Promise(function createVlibrasPromise(resolve, reject) {
      var existingScript = document.querySelector('script[data-ibr-vlibras-script="true"]');

      function initializeWidget() {
        try {
          initializeVlibrasWidget();
          resolve();
        } catch (error) {
          reject(error);
        }
      }

      if (existingScript) {
        if (global.VLibras && typeof global.VLibras.Widget === 'function') {
          initializeWidget();
          return;
        }

        existingScript.addEventListener('load', initializeWidget, { once: true });
        existingScript.addEventListener('error', reject, { once: true });
        return;
      }

      var script = document.createElement('script');
      script.src = 'https://vlibras.gov.br/app/vlibras-plugin.js';
      script.async = true;
      script.defer = true;
      script.setAttribute('data-ibr-vlibras-script', 'true');
      script.addEventListener('load', initializeWidget, { once: true });
      script.addEventListener('error', reject, { once: true });
      document.head.appendChild(script);
    }).catch(function onVlibrasLoadError(error) {
      librasWidgetPromise = null;
      throw error;
    });

    return librasWidgetPromise;
  }

  function syncSelectionToElement(target) {
    if (!target) {
      return false;
    }

    var selection = global.getSelection ? global.getSelection() : null;
    if (!selection) {
      return false;
    }

    var range = document.createRange();
    range.selectNodeContents(target);
    selection.removeAllRanges();
    selection.addRange(range);
    target.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true, view: global }));
    return true;
  }

  function extractTextForLibrasTranslation(target) {
    if (!target) {
      return '';
    }

    var ownText = getElementOwnReadableText(target);
    if (ownText) {
      return ownText;
    }

    return getMagnifierReadableText(target);
  }

  function getVlibrasPluginInstance() {
    if (global.plugin && typeof global.plugin.translate === 'function') {
      return global.plugin;
    }

    return null;
  }

  function waitForVlibrasPluginReady(options) {
    var config = options && typeof options === 'object' ? options : {};
    var timeoutMs = typeof config.timeoutMs === 'number' && config.timeoutMs > 0 ? config.timeoutMs : 12000;
    var pollIntervalMs = typeof config.pollIntervalMs === 'number' && config.pollIntervalMs > 0 ? config.pollIntervalMs : 250;

    var readyPlugin = getVlibrasPluginInstance();
    if (readyPlugin) {
      return Promise.resolve(readyPlugin);
    }

    if (librasPluginReadyPromise) {
      return librasPluginReadyPromise;
    }

    librasPluginReadyPromise = new Promise(function waitForPlugin(resolve, reject) {
      var startedAt = Date.now();
      var settled = false;

      function finishWithError(error) {
        if (settled) {
          return;
        }
        settled = true;
        reject(error);
      }

      function finishWithPlugin(plugin) {
        if (settled) {
          return;
        }
        settled = true;
        resolve(plugin);
      }

      function checkPluginReadiness() {
        var plugin = getVlibrasPluginInstance();
        if (plugin) {
          finishWithPlugin(plugin);
          return;
        }

        if (Date.now() - startedAt >= timeoutMs) {
          finishWithError(new Error('Tempo limite ao aguardar window.plugin.translate.'));
          return;
        }

        setTimeout(checkPluginReadiness, pollIntervalMs);
      }

      checkPluginReadiness();
    }).finally(function clearPluginReadyPromise() {
      librasPluginReadyPromise = null;
    });

    return librasPluginReadyPromise;
  }

  function fetchWithTimeout(url, timeoutMs) {
    return new Promise(function resolveWithTimeout(resolve, reject) {
      var timeoutId = setTimeout(function onTimeout() {
        reject(new Error('Tempo limite ao validar conectividade com VLibras.'));
      }, timeoutMs);

      fetch(url, {
        method: 'GET',
        mode: 'no-cors',
        cache: 'no-store'
      })
        .then(function onFetchSuccess(response) {
          clearTimeout(timeoutId);
          resolve(response);
        })
        .catch(function onFetchError(error) {
          clearTimeout(timeoutId);
          reject(error);
        });
    });
  }

  function ensureVlibrasServiceAvailable() {
    var now = Date.now();
    if (librasAvailabilityState && librasAvailabilityState.expiry > now) {
      if (librasAvailabilityState.available) {
        return Promise.resolve(true);
      }
      return Promise.reject(new Error('Serviço VLibras indisponível no momento.'));
    }

    if (librasAvailabilityPromise) {
      return librasAvailabilityPromise;
    }

    librasAvailabilityPromise = LIBRAS_CONNECTIVITY_TEST_URLS.reduce(function chain(previousPromise, url) {
      return previousPromise.then(function tryNextIfNeeded(result) {
        if (result) {
          return result;
        }

        return fetchWithTimeout(url, 5000)
          .then(function markAvailable() {
            return true;
          })
          .catch(function ignoreAndContinue() {
            return false;
          });
      });
    }, Promise.resolve(false))
      .then(function finalizeAvailability(available) {
        librasAvailabilityState = {
          available: available,
          expiry: Date.now() + LIBRAS_AVAILABILITY_TTL_MS
        };

        if (!available) {
          throw new Error('Falha ao validar conectividade com os endpoints do VLibras.');
        }

        return true;
      })
      .finally(function clearAvailabilityPromise() {
        librasAvailabilityPromise = null;
      });

    return librasAvailabilityPromise;
  }

  function translateTextWithVlibras(text, shortcuts) {
    if (!text) {
      notify(shortcuts, 'Nenhum texto legível encontrado para tradução.');
      return;
    }

    /**
     * O fluxo oficial do VLibras traduz o texto atualmente selecionado.
     * Evitamos chamar `window.plugin.translate(...)` diretamente porque isso
     * dispara requisições XHR CORS bloqueadas em alguns navegadores/origens.
     */
    ensureVlibrasPanelOpen();
    waitForVlibrasPluginReady()
      .then(function onPluginReady() {
        logLibrasDebug('info', 'Texto selecionado para tradução no fluxo nativo do VLibras.', {
          textLength: text.length,
          preview: text.slice(0, 80)
        });
        trackAccessibilityEvent('acessibilidade-libras-traduzir-texto', {
          event_label: 'traduzir',
          feature: 'libras'
        });
        notify(shortcuts, 'Texto selecionado para tradução no VLibras.');
      })
      .catch(function onPluginNotReady(error) {
        logLibrasDebug('error', 'VLibras não ficou pronto a tempo para processar o texto selecionado.', error);
        notify(shortcuts, 'O VLibras ainda está carregando. Tente novamente em alguns segundos.');
      });
  }

  function handleLibrasPointerMove(event) {
    if (!librasModeEnabled) {
      return;
    }

    var target = findLibrasTextTarget(event.target, event);
    if (!target) {
      clearLibrasHighlight();
      setLibrasCursor(false);
      return;
    }

    updateLibrasHighlight(target);
  }

  function handleLibrasClick(event, shortcuts) {
    if (!librasModeEnabled) {
      return;
    }

    if (isInteractiveElement(event && event.target)) {
      clearLibrasHighlight();
      setLibrasCursor(false);
      return;
    }

    var target = findLibrasTextTarget(event.target, event);
    if (!target) {
      clearLibrasHighlight();
      setLibrasCursor(false);
      return;
    }

    updateLibrasHighlight(target);

    syncSelectionToElement(target);
    translateTextWithVlibras(extractTextForLibrasTranslation(target), shortcuts);
  }

  function bindLibrasTracking(shortcuts) {
    if (librasTrackingBound) {
      return;
    }

    librasTrackingBound = true;

    document.addEventListener('pointermove', handleLibrasPointerMove, true);
    document.addEventListener('pointerleave', function onLibrasPointerLeave() {
      if (!librasModeEnabled) {
        return;
      }
      clearLibrasHighlight();
      setLibrasCursor(false);
    }, true);
    document.addEventListener('click', function onLibrasClick(event) {
      handleLibrasClick(event, shortcuts);
    }, true);
    document.addEventListener('touchstart', function onLibrasTouchStart(event) {
      if (!librasModeEnabled || !event.touches || !event.touches.length) {
        return;
      }
      handleLibrasPointerMove(getInteractionPoint(event));
    }, { capture: true, passive: true });
    document.addEventListener('touchend', function onLibrasTouchEnd(event) {
      handleLibrasClick(getInteractionPoint(event), shortcuts);
    }, { capture: true, passive: false });
  }

  function setLibrasMode(shortcuts, enabled) {
    librasModeEnabled = !!enabled;
    uiState.librasMode = librasModeEnabled;
    uiState.vlibrasPanelOpen = librasModeEnabled;
    ROOT.classList.toggle('ibr-a11y-libras-mode', librasModeEnabled);

    if (!librasModeEnabled) {
      clearLibrasHighlight();
      setLibrasCursor(false);
      updateCardStates(shortcuts);
      updateResetButtonVisibility(shortcuts);
      notify(shortcuts, 'Tradutor de Libras desativado.');
      saveUiState();
      return;
    }

    if (!navigator.onLine) {
      librasModeEnabled = false;
      ROOT.classList.remove('ibr-a11y-libras-mode');
      updateCardStates(shortcuts);
      updateResetButtonVisibility(shortcuts);
      notify(shortcuts, 'Tradutor de Libras indisponível sem conexão com a internet.');
      saveUiState();
      return;
    }

    bindLibrasTracking(shortcuts);
    ensureVlibrasPanelOpen();
    setLibrasCursor(false);
    updateCardStates(shortcuts);
    updateResetButtonVisibility(shortcuts);
    logLibrasDebug('info', 'Modo Libras ativado. Aguardando interação para tradução.');
    notify(shortcuts, isMobileViewport() ? 'Tradutor de Libras ativado. Toque em um texto para traduzir.' : 'Tradutor de Libras ativado. Passe o mouse em textos e clique para traduzir.');
    saveUiState();
  }

  function restorePersistentLibrasMode(shortcuts) {
    if (!uiState.librasMode) {
      return;
    }

    ensureVlibrasServiceAvailable()
      .then(function onVlibrasServiceAvailable() {
        return ensureVlibrasWidget();
      })
      .then(function onWidgetLoaded() {
        setLibrasMode(shortcuts, true);
        if (uiState.vlibrasPanelOpen) {
          ensureVlibrasPanelOpen({ maxAttempts: 12, intervalMs: 260 });
        }
      })
      .catch(function onWidgetLoadError() {
        uiState.librasMode = false;
        uiState.vlibrasPanelOpen = false;
        saveUiState();
      });
  }

  function updateMagnifierPopupPosition(event, popup) {
    var margin = 24;
    var top = event.clientY + margin;
    var left = event.clientX + margin;

    popup.style.maxWidth = Math.round(Math.min(window.innerWidth * 0.6, 780)) + 'px';
    popup.style.maxHeight = Math.max(window.innerHeight - 24, 140) + 'px';
    popup.style.left = Math.round(left) + 'px';
    popup.style.top = Math.round(top) + 'px';

    var rect = popup.getBoundingClientRect();
    if (rect.right > window.innerWidth - 16) {
      left = Math.max(12, event.clientX - rect.width - margin);
    }
    if (rect.bottom > window.innerHeight - 16) {
      top = Math.max(12, event.clientY - rect.height - margin);
    }

    left = Math.min(Math.max(12, left), Math.max(12, window.innerWidth - rect.width - 12));
    top = Math.min(Math.max(12, top), Math.max(12, window.innerHeight - rect.height - 12));

    popup.style.left = Math.round(left) + 'px';
    popup.style.top = Math.round(top) + 'px';
  }

  function setMagnifierCursor(isActiveTarget) {
    if (!state.magnifier) {
      ROOT.style.removeProperty('--ibr-magnifier-cursor');
      return;
    }

    ROOT.style.setProperty('--ibr-magnifier-cursor', isActiveTarget ? MAGNIFIER_CURSOR_ACTIVE : MAGNIFIER_CURSOR_INACTIVE);
  }

  function handleMagnifierWheel(event) {
    if (!state.magnifier) {
      return;
    }

    var popup = document.getElementById(MAGNIFIER_POPUP_ID);
    if (!popup || popup.hidden) {
      return;
    }

    var rect = popup.getBoundingClientRect();
    var isPointerInsidePopup = (
      event.clientX >= rect.left &&
      event.clientX <= rect.right &&
      event.clientY >= rect.top &&
      event.clientY <= rect.bottom
    );

    if (!isPointerInsidePopup) {
      return;
    }

    var maxScrollTop = popup.scrollHeight - popup.clientHeight;
    if (maxScrollTop <= 0) {
      return;
    }

    var previousScrollTop = popup.scrollTop;
    popup.scrollTop = Math.min(Math.max(0, popup.scrollTop + event.deltaY), maxScrollTop);

    if (popup.scrollTop !== previousScrollTop) {
      event.preventDefault();
      event.stopPropagation();
    }
  }

  function handleMagnifierPointerMove(event) {
    if (!state.magnifier) {
      return;
    }

    var target = findMagnifierTextTarget(event);
    var popup = getOrCreateMagnifierPopup();

    if (magnifierCurrentTarget && magnifierCurrentTarget !== target) {
      magnifierCurrentTarget.removeAttribute(MAGNIFIER_TARGET_ATTRIBUTE);
      magnifierCurrentTarget = null;
    }

    if (!target) {
      clearMagnifierHighlight();
      setMagnifierCursor(false);
      return;
    }

    var text = getMagnifierReadableText(target);
    if (!text) {
      clearMagnifierHighlight();
      setMagnifierCursor(false);
      return;
    }

    magnifierCurrentTarget = target;
    magnifierCurrentTarget.setAttribute(MAGNIFIER_TARGET_ATTRIBUTE, 'true');
    popup.textContent = text;
    popup.hidden = false;
    popup.classList.add('is-visible');
    updateMagnifierPopupPosition(event, popup);
    setMagnifierCursor(true);
  }

  function bindMagnifierTracking() {
    if (magnifierTrackingBound) {
      return;
    }

    magnifierTrackingBound = true;

    document.addEventListener('pointermove', handleMagnifierPointerMove, true);
    document.addEventListener('pointerleave', function onMagnifierPointerLeave() {
      if (!state.magnifier) {
        return;
      }
      clearMagnifierHighlight();
      setMagnifierCursor(false);
    }, true);
    document.addEventListener('scroll', function onMagnifierScroll() {
      if (!state.magnifier) {
        return;
      }
      clearMagnifierHighlight();
      setMagnifierCursor(false);
    }, true);
    document.addEventListener('touchstart', function onMagnifierTouchStart(event) {
      if (!state.magnifier || !event.touches || !event.touches.length) {
        return;
      }
      handleMagnifierPointerMove(getInteractionPoint(event));
    }, { capture: true, passive: true });
    document.addEventListener('touchmove', function onMagnifierTouchMove(event) {
      if (!state.magnifier || !event.touches || !event.touches.length) {
        return;
      }
      handleMagnifierPointerMove(getInteractionPoint(event));
    }, { capture: true, passive: true });
    document.addEventListener('wheel', handleMagnifierWheel, { capture: true, passive: false });
  }

  function syncMagnifierState() {
    if (state.magnifier) {
      bindMagnifierTracking();
      setMagnifierCursor(false);
      return;
    }

    clearMagnifierHighlight();
    setMagnifierCursor(false);
  }

  function applyRootClasses() {
    ROOT.classList.remove(
      'ibr-a11y-font-size-1',
      'ibr-a11y-font-size-2',
      'ibr-a11y-font-size-3',
      'ibr-a11y-text-style-1',
      'ibr-a11y-text-style-2',
      'ibr-a11y-text-style-3',
      'ibr-a11y-letters-highlight',
      'ibr-a11y-line-spacing-1',
      'ibr-a11y-line-spacing-2',
      'ibr-a11y-line-spacing-3',
      'ibr-a11y-letter-spacing-1',
      'ibr-a11y-letter-spacing-2',
      'ibr-a11y-letter-spacing-3',
      'ibr-a11y-reader-mode',
      'ibr-a11y-reading-mask-1',
      'ibr-a11y-reading-mask-2',
      'ibr-a11y-reading-mask-3',
      'ibr-a11y-reading-guide-1',
      'ibr-a11y-reading-guide-2',
      'ibr-a11y-links-highlight-1',
      'ibr-a11y-links-highlight-2',
      'ibr-a11y-links-highlight-3',
      'ibr-a11y-site-reader',
      'ibr-a11y-synonyms',
      'ibr-a11y-magnifier',
      'ibr-a11y-hide-images',
      'ibr-a11y-highlight-headings',
      'ibr-a11y-pause-animations',
      'ibr-a11y-contrast-1',
      'ibr-a11y-contrast-2',
      'ibr-a11y-contrast-3',
      'ibr-a11y-intensity-1',
      'ibr-a11y-intensity-2',
      'ibr-a11y-intensity-3',
      'ibr-a11y-daltonic-1',
      'ibr-a11y-daltonic-2',
      'ibr-a11y-daltonic-3'
    );

    if (state.fontSize === 1) ROOT.classList.add('ibr-a11y-font-size-1');
    if (state.fontSize === 2) ROOT.classList.add('ibr-a11y-font-size-2');
    if (state.fontSize === 3) ROOT.classList.add('ibr-a11y-font-size-3');
    if (state.textStyle === 1) ROOT.classList.add('ibr-a11y-text-style-1');
    if (state.textStyle === 2) ROOT.classList.add('ibr-a11y-text-style-2');
    if (state.textStyle === 3) ROOT.classList.add('ibr-a11y-text-style-3');
    if (state.lettersHighlight) ROOT.classList.add('ibr-a11y-letters-highlight');
    if (state.lineSpacing === 1) ROOT.classList.add('ibr-a11y-line-spacing-1');
    if (state.lineSpacing === 2) ROOT.classList.add('ibr-a11y-line-spacing-2');
    if (state.lineSpacing === 3) ROOT.classList.add('ibr-a11y-line-spacing-3');
    if (state.letterSpacing === 1) ROOT.classList.add('ibr-a11y-letter-spacing-1');
    if (state.letterSpacing === 2) ROOT.classList.add('ibr-a11y-letter-spacing-2');
    if (state.letterSpacing === 3) ROOT.classList.add('ibr-a11y-letter-spacing-3');
    if (state.readerMode) ROOT.classList.add('ibr-a11y-reader-mode');
    if (state.readingMask === 1) ROOT.classList.add('ibr-a11y-reading-mask-1');
    if (state.readingMask === 2) ROOT.classList.add('ibr-a11y-reading-mask-2');
    if (state.readingMask === 3) ROOT.classList.add('ibr-a11y-reading-mask-3');
    if (state.readingGuide === 1) ROOT.classList.add('ibr-a11y-reading-guide-1');
    if (state.readingGuide === 2) ROOT.classList.add('ibr-a11y-reading-guide-2');
    if (state.linksHighlightLevel === 1) ROOT.classList.add('ibr-a11y-links-highlight-1');
    if (state.linksHighlightLevel === 2) ROOT.classList.add('ibr-a11y-links-highlight-2');
    if (state.linksHighlightLevel === 3) ROOT.classList.add('ibr-a11y-links-highlight-3');
    if (state.siteReaderMode) ROOT.classList.add('ibr-a11y-site-reader');
    if (state.synonyms) ROOT.classList.add('ibr-a11y-synonyms');
    if (state.magnifier) ROOT.classList.add('ibr-a11y-magnifier');
    if (state.hideImages) ROOT.classList.add('ibr-a11y-hide-images');
    if (state.highlightHeadings) ROOT.classList.add('ibr-a11y-highlight-headings');
    if (state.pauseAnimations) ROOT.classList.add('ibr-a11y-pause-animations');
    if (state.colorContrast === 1) ROOT.classList.add('ibr-a11y-contrast-1');
    if (state.colorContrast === 2) ROOT.classList.add('ibr-a11y-contrast-2');
    if (state.colorContrast === 3) ROOT.classList.add('ibr-a11y-contrast-3');
    if (state.colorIntensity === 1) ROOT.classList.add('ibr-a11y-intensity-1');
    if (state.colorIntensity === 2) ROOT.classList.add('ibr-a11y-intensity-2');
    if (state.colorIntensity === 3) ROOT.classList.add('ibr-a11y-intensity-3');
    if (state.daltonicMode === 1) ROOT.classList.add('ibr-a11y-daltonic-1');
    if (state.daltonicMode === 2) ROOT.classList.add('ibr-a11y-daltonic-2');
    if (state.daltonicMode === 3) ROOT.classList.add('ibr-a11y-daltonic-3');

    applyColorContrastMode();

    if (state.stopSounds) {
      bindSoundGuards();
      mutePageMedia();
    }

    syncSvgAnimationsState();

    ensureReadingGuide();
    ensureReadingMask();
    applyReaderModeStructure();
    syncMagnifierState();
    syncSynonymsState();
    syncSiteReaderState();

    var guide = document.getElementById(READING_GUIDE_ID);
    if (guide) {
      guide.setAttribute('data-guide-style', state.readingGuide > 1 ? 'high-contrast' : 'ibr');
    }
  }

  function bindReadingGuideTracking() {
    document.addEventListener('pointermove', function onPointerMove(event) {
      if (!state.readingGuide) {
        return;
      }

      var guide = document.getElementById(READING_GUIDE_ID);
      if (!guide) {
        return;
      }

      var guideWidth = guide.offsetWidth || 0;
      var maxLeft = Math.max(0, window.innerWidth - guideWidth);
      var targetLeft = Math.min(Math.max(0, event.clientX - (guideWidth / 2)), maxLeft);

      guide.style.top = Math.max(0, event.clientY - 6) + 'px';
      guide.style.left = Math.round(targetLeft) + 'px';
    });
  }

  function bindReadingMaskTracking() {
    var dragState = {
      active: false,
      pointerId: null,
      offsetY: 0
    };

    function updateMaskPosition(clientY) {
      var mask = document.getElementById(READING_MASK_ID);
      if (!mask || mask.hidden) {
        return;
      }

      var height = mask.offsetHeight || 150;
      var maxTop = Math.max(0, window.innerHeight - height);
      var top = Math.min(Math.max(0, clientY - dragState.offsetY), maxTop);
      mask.style.top = Math.round(top) + 'px';
      document.documentElement.style.setProperty('--ibr-reading-mask-top', Math.round(top) + 'px');
      document.documentElement.style.setProperty('--ibr-reading-mask-height', Math.round(height) + 'px');
    }

    document.addEventListener('pointerdown', function onMaskDown(event) {
      var mask = event.target && event.target.id === READING_MASK_ID ? event.target : null;
      if (!mask || !state.readingMask || event.button !== 0) {
        return;
      }

      dragState.active = true;
      dragState.pointerId = event.pointerId;
      dragState.offsetY = event.clientY - mask.getBoundingClientRect().top;
      mask.style.cursor = 'grabbing';
      if (mask.setPointerCapture) {
        mask.setPointerCapture(event.pointerId);
      }
      event.preventDefault();
    });

    document.addEventListener('pointermove', function onMaskMove(event) {
      if (!dragState.active || dragState.pointerId !== event.pointerId || !state.readingMask) {
        return;
      }
      updateMaskPosition(event.clientY);
    });

    document.addEventListener('pointerup', function onMaskUp(event) {
      if (!dragState.active || dragState.pointerId !== event.pointerId) {
        return;
      }

      var mask = document.getElementById(READING_MASK_ID);
      if (mask) {
        mask.style.cursor = 'grab';
        if (mask.releasePointerCapture) {
          try {
            mask.releasePointerCapture(event.pointerId);
          } catch (error) {
            // noop
          }
        }
      }

      dragState.active = false;
      dragState.pointerId = null;
    });
  }

  function getFeatureLevel(feature) {
    switch (feature) {
      case 'font-size': return state.fontSize;
      case 'text-style': return state.textStyle;
      case 'line-spacing': return state.lineSpacing;
      case 'letter-spacing': return state.letterSpacing;
      case 'color-contrast': return state.colorContrast;
      case 'color-intensity': return state.colorIntensity;
      case 'daltonic-mode': return state.daltonicMode;
      case 'reading-mask': return state.readingMask;
      case 'reading-guide': return state.readingGuide;
      case 'links-highlight': return state.linksHighlightLevel;
      case 'site-reader': return state.siteReaderMode;
      default: return 0;
    }
  }

  function getPanelElement(shortcuts) {
    if (!shortcuts) {
      return document.querySelector('.ibr-assistive-panel');
    }

    return shortcuts.querySelector('.ibr-assistive-panel') || document.querySelector('.ibr-assistive-panel');
  }

  function updateCardStates(shortcuts) {
    var panel = getPanelElement(shortcuts);
    if (!panel) {
      return;
    }

    var cards = panel.querySelectorAll('.ibr-card[data-feature]');
    cards.forEach(function updateCard(card) {
      var feature = card.getAttribute('data-feature');
      var active = false;

      var level = getFeatureLevel(feature);
      switch (feature) {
        case 'translator':
          active = librasModeEnabled;
          break;
        case 'font-size':
        case 'text-style':
        case 'line-spacing':
        case 'letter-spacing':
        case 'color-contrast':
        case 'color-intensity':
        case 'daltonic-mode':
        case 'reading-mask':
        case 'reading-guide':
        case 'links-highlight':
        case 'site-reader':
          active = level > 0;
          break;
        case 'page-structure':
          active = !!(panel && panel.classList.contains('ibr-assistive-panel--page-structure'));
          break;
        default:
          active = !!state[toCamelCase(feature)];
          break;
      }

      card.classList.toggle('is-active', active);
      card.setAttribute('aria-pressed', active ? 'true' : 'false');

      var dots = card.querySelectorAll('.ibr-card__meta-dots span');
      if (dots.length) {
        var activeDotIndex = -1;
        if (level > 0) {
          activeDotIndex = level - 1;
        } else if (active) {
          activeDotIndex = 0;
        }

        dots.forEach(function updateDot(dot, index) {
          dot.classList.toggle('is-active', index === activeDotIndex);
        });
      }

    });

    var daltonicCard = panel.querySelector('.ibr-card[data-feature="daltonic-mode"] strong');
    if (daltonicCard) {
      var daltonicLabel = 'Modo Daltônico';
      if (state.daltonicMode === 1) daltonicLabel += ': Verde Deuteranopia';
      if (state.daltonicMode === 2) daltonicLabel += ': Vermelho Protanopia';
      if (state.daltonicMode === 3) daltonicLabel += ': Azul Tritanopia';
      daltonicCard.textContent = daltonicLabel;
    }

    var siteReaderCard = panel.querySelector('.ibr-card[data-feature="site-reader"] strong');
    if (siteReaderCard) {
      var siteReaderLabel = 'Leitor de Sites';
      var siteReaderProfile = getSiteReaderModeProfile();
      if (siteReaderProfile) {
        siteReaderLabel += ': ' + siteReaderProfile.label;
      }
      siteReaderCard.textContent = siteReaderLabel;
    }

    updateResetButtonVisibility(shortcuts);
  }

  function hasAnyActiveState(shortcuts) {
    if (librasModeEnabled) {
      return true;
    }

    var hasFeatureActive = Object.keys(DEFAULT_STATE).some(function checkFeature(key) {
      return state[key] !== DEFAULT_STATE[key];
    });

    if (hasFeatureActive) {
      return true;
    }

    var panel = getPanelElement(shortcuts);
    return !!(panel && panel.classList.contains('ibr-assistive-panel--expanded'));
  }

  function updateResetButtonVisibility(shortcuts) {
    var panel = getPanelElement(shortcuts);
    var resetButton = panel ? panel.querySelector('[data-accessibility-reset]') : null;
    if (!resetButton) {
      return;
    }

    var showResetButton = hasAnyActiveState(shortcuts);
    resetButton.hidden = !showResetButton;
  }

  function toCamelCase(value) {
    return value.replace(/-([a-z])/g, function convert(_, chr) {
      return chr.toUpperCase();
    });
  }

  function toggleFeature(shortcuts, feature) {
    switch (feature) {
      case 'translator':
        ensureVlibrasServiceAvailable()
          .then(function onVlibrasServiceAvailable() {
            return ensureVlibrasWidget();
          })
          .then(function onWidgetLoaded() {
            var willEnable = !librasModeEnabled;
            setLibrasMode(shortcuts, willEnable);
            trackAccessibilityEvent(willEnable ? 'acessibilidade-libras-flutuante-ativar' : 'acessibilidade-libras-flutuante-desativar', {
              event_label: willEnable ? 'ativar_libras' : 'desativar_libras',
              feature: 'libras'
            });
          })
          .catch(function onWidgetLoadError() {
            trackAccessibilityEvent('acessibilidade-libras-flutuante-erro', {
              event_label: 'erro_libras',
              feature: 'libras'
            });
            notify(shortcuts, 'Não foi possível carregar o VLibras. Verifique conexão, CSP e bloqueios de conteúdo externo.');
          });
        return;
      case 'synonyms':
        state.synonyms = !state.synonyms;
        if (state.synonyms) {
          notify(shortcuts, isMobileViewport() ? 'Sinônimos e Significados ativado. Toque em uma palavra para consultar.' : 'Sinônimos e Significados ativado. Passe o mouse sobre uma palavra e clique para consultar.');
        } else {
          notify(shortcuts, 'Sinônimos e Significados desativado.');
        }
        break;
      case 'site-reader':
        if (!speechSupported) {
          notify(shortcuts, 'Web Speech API não está disponível neste navegador/dispositivo.');
          return;
        }
        state.siteReaderMode = (state.siteReaderMode + 1) % 7;
        if (!state.siteReaderMode) {
          notify(shortcuts, 'Leitor de Sites desativado.');
        } else {
          notify(shortcuts, 'Leitor de Sites ativado: ' + getSiteReaderModeProfile().label + '.');
        }
        break;
      case 'links-highlight':
        state.linksHighlightLevel = (state.linksHighlightLevel + 1) % 4;
        break;
      case 'page-structure':
        togglePageStructureView(shortcuts, true);
        updateCardStates(shortcuts);
        return;
      case 'font-size':
        state.fontSize = (state.fontSize + 1) % 4;
        break;
      case 'text-style':
        state.textStyle = (state.textStyle + 1) % 4;
        break;
      case 'line-spacing':
        state.lineSpacing = (state.lineSpacing + 1) % 4;
        break;
      case 'letter-spacing':
        state.letterSpacing = (state.letterSpacing + 1) % 4;
        break;
      case 'reading-mask':
        state.readingMask = (state.readingMask + 1) % 4;
        break;
      case 'reading-guide':
        state.readingGuide = (state.readingGuide + 1) % 3;
        break;
      case 'color-contrast':
        state.colorContrast = (state.colorContrast + 1) % 4;
        if (state.colorContrast === 0) notify(shortcuts, 'Contraste de Cores: padrão do site.');
        if (state.colorContrast === 1) notify(shortcuts, 'Contraste de Cores: alto contraste claro.');
        if (state.colorContrast === 2) notify(shortcuts, 'Contraste de Cores: alto contraste escuro.');
        if (state.colorContrast === 3) notify(shortcuts, 'Contraste de Cores: contraste quente.');
        break;
      case 'color-intensity':
        state.colorIntensity = (state.colorIntensity + 1) % 4;
        if (state.colorIntensity === 0) notify(shortcuts, 'Intensidade de Cores: padrão do site.');
        if (state.colorIntensity === 1) notify(shortcuts, 'Intensidade de Cores: muito mais opaca.');
        if (state.colorIntensity === 2) notify(shortcuts, 'Intensidade de Cores: muito mais brilhante.');
        if (state.colorIntensity === 3) notify(shortcuts, 'Intensidade de Cores: escala cinza e branco.');
        break;
      case 'daltonic-mode':
        state.daltonicMode = (state.daltonicMode + 1) % 4;
        if (state.daltonicMode === 0) notify(shortcuts, 'Modo Daltônico: desativado.');
        if (state.daltonicMode === 1) notify(shortcuts, 'Modo Daltônico: Verde Deuteranopia.');
        if (state.daltonicMode === 2) notify(shortcuts, 'Modo Daltônico: Vermelho Protanopia.');
        if (state.daltonicMode === 3) notify(shortcuts, 'Modo Daltônico: Azul Tritanopia.');
        break;
      case 'stop-sounds':
        state.stopSounds = !state.stopSounds;
        if (state.stopSounds) {
          bindSoundGuards();
          mutePageMedia();
        }
        break;
      default:
        var key = toCamelCase(feature);
        state[key] = !state[key];
        break;
    }

    var panel = getPanelElement(shortcuts);
    if (panel && panel.classList.contains('ibr-assistive-panel--page-structure')) {
      togglePageStructureView(shortcuts, false);
    }

    applyRootClasses();
    updateCardStates(shortcuts);
    saveState();
  }

  function getNearestTextContext(element) {
    if (!element || !element.parentElement) {
      return '';
    }

    var context = element.parentElement.textContent || '';
    context = context.replace(/\s+/g, ' ').trim();
    var ownText = (element.textContent || '').replace(/\s+/g, ' ').trim();

    if (!context || context === ownText) {
      return '';
    }

    return context.length > 120 ? context.slice(0, 117) + '...' : context;
  }

  function getPageStructureSections(panel) {
    if (!panel) {
      return null;
    }

    var section = panel.querySelector('[data-page-structure-section]');
    if (!section) {
      return null;
    }

    return {
      section: section,
      tabs: section.querySelectorAll('[data-page-structure-tab]'),
      lists: section.querySelectorAll('[data-page-structure-list]'),
      back: section.querySelector('[data-page-structure-back]')
    };
  }

  function getOrCreatePageStructureSection(panel) {
    var existing = getPageStructureSections(panel);
    if (existing) {
      return existing;
    }

    var body = panel.querySelector('.ibr-assistive-panel__body');
    if (!body) {
      return null;
    }

    var section = document.createElement('section');
    section.className = 'ibr-page-structure';
    section.setAttribute('data-page-structure-section', 'true');
    section.hidden = true;
    section.innerHTML = [
      '<header class="ibr-page-structure__header">',
      '  <button type="button" class="ibr-page-structure__back" data-page-structure-back aria-label="Voltar para Recursos Assistivos">',
      '    <span aria-hidden="true">←</span>',
      '  </button>',
      '  <strong>Estrutura da Página</strong>',
      '</header>',
      '<div class="ibr-page-structure__tabs" role="tablist" aria-label="Estrutura da Página">',
      '  <button type="button" class="is-active" role="tab" aria-selected="true" data-page-structure-tab="titles">Títulos</button>',
      '  <button type="button" role="tab" aria-selected="false" data-page-structure-tab="links">Links</button>',
      '  <button type="button" role="tab" aria-selected="false" data-page-structure-tab="regions">Regiões</button>',
      '</div>',
      '<div class="ibr-page-structure__body">',
      '  <ul class="ibr-page-structure__list is-active" data-page-structure-list="titles"></ul>',
      '  <ul class="ibr-page-structure__list" data-page-structure-list="links" hidden></ul>',
      '  <ul class="ibr-page-structure__list" data-page-structure-list="regions" hidden></ul>',
      '</div>'
    ].join('');

    body.appendChild(section);
    return getPageStructureSections(panel);
  }

  function scrollToElement(target) {
    if (!target || !target.scrollIntoView) {
      return;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
  }

  function fillPageStructureList(list, items) {
    if (!list) {
      return;
    }

    list.innerHTML = '';

    if (!items.length) {
      var empty = document.createElement('li');
      empty.className = 'ibr-page-structure__empty';
      empty.textContent = 'Nenhum item encontrado nesta aba.';
      list.appendChild(empty);
      return;
    }

    items.forEach(function addItem(item) {
      var listItem = document.createElement('li');
      listItem.className = 'ibr-page-structure__item';

      var action = document.createElement('button');
      action.type = 'button';
      action.className = 'ibr-page-structure__link';
      action.textContent = item.label;
      action.addEventListener('click', function onClick() {
        scrollToElement(item.target);
      });

      listItem.appendChild(action);

      if (item.badge) {
        var badge = document.createElement('span');
        badge.className = 'ibr-page-structure__badge';
        badge.textContent = item.badge;
        listItem.insertBefore(badge, action);
      }

      if (item.meta) {
        var meta = document.createElement('small');
        meta.className = 'ibr-page-structure__meta';
        meta.textContent = item.meta;
        listItem.appendChild(meta);
      }

      list.appendChild(listItem);
    });
  }

  function collectTitles() {
    var headings = document.querySelectorAll('h1, h2, h3, h4, h5, h6');
    return Array.prototype.map.call(headings, function mapHeading(node) {
      return {
        label: (node.textContent || '').replace(/\s+/g, ' ').trim() || 'Título sem texto',
        badge: node.tagName,
        target: node
      };
    });
  }

  function collectLinks() {
    var links = document.querySelectorAll('a[href], area[href], [role="link"]');
    return Array.prototype.map.call(links, function mapLink(node) {
      var label = (node.textContent || '').replace(/\s+/g, ' ').trim();
      if (!label) {
        label = node.getAttribute('aria-label')
          || node.getAttribute('title')
          || (node.querySelector('img') && node.querySelector('img').getAttribute('alt'))
          || node.getAttribute('href')
          || 'Link sem descrição';
      }

      return {
        label: label,
        target: node,
        meta: getNearestTextContext(node)
      };
    });
  }

  function collectRegions(inAreaCliente) {
    var header = document.querySelector('header, [role="banner"]');
    var content = document.querySelector('main, [role="main"], #content, .content, .container, body');
    var footer = inAreaCliente ? null : document.querySelector('footer, [role="contentinfo"]');
    var regions = [];

    if (header) {
      regions.push({ label: 'Cabeçalho', target: header });
    }
    if (content) {
      regions.push({ label: 'Conteúdo', target: content });
    }
    if (footer) {
      regions.push({ label: 'Rodapé', target: footer });
    }

    return regions;
  }

  function refreshPageStructureContent(panel, inAreaCliente) {
    var sections = getOrCreatePageStructureSection(panel);
    if (!sections) {
      return;
    }

    fillPageStructureList(sections.section.querySelector('[data-page-structure-list="titles"]'), collectTitles());
    fillPageStructureList(sections.section.querySelector('[data-page-structure-list="links"]'), collectLinks());
    fillPageStructureList(sections.section.querySelector('[data-page-structure-list="regions"]'), collectRegions(inAreaCliente));
  }

  function togglePageStructureView(shortcuts, shouldOpen) {
    var panel = getPanelElement(shortcuts);
    if (!panel) {
      return;
    }

    var body = panel.querySelector('.ibr-assistive-panel__body');
    var sections = getOrCreatePageStructureSection(panel);
    if (!body || !sections) {
      return;
    }

    var groups = body.querySelectorAll('h4, .ibr-assistive-panel__grid, .ibr-assistive-panel__notice, .ibr-reset-button');
    panel.classList.toggle('ibr-assistive-panel--page-structure', !!shouldOpen);
    sections.section.hidden = !shouldOpen;

    groups.forEach(function toggleGroup(node) {
      node.hidden = !!shouldOpen;
    });

    if (!shouldOpen) {
      return;
    }

    refreshPageStructureContent(panel, shortcuts.classList.contains('ibr-accessibility-shortcuts--area-cliente'));
  }

  function setupPageStructureInteractions(shortcuts) {
    var panel = getPanelElement(shortcuts);
    var sections = getOrCreatePageStructureSection(panel);
    if (!panel || !sections) {
      return;
    }

    if (sections.back) {
      sections.back.addEventListener('click', function onBack() {
        trackAccessibilityEvent('acessibilidade-estrutura-voltar', {
          event_label: 'voltar',
          section: 'estrutura_pagina'
        });
        togglePageStructureView(shortcuts, false);
        updateCardStates(shortcuts);
      });
    }

    Array.prototype.forEach.call(sections.tabs, function onTab(tabButton) {
      tabButton.addEventListener('click', function handleTabClick() {
        var selectedTab = tabButton.getAttribute('data-page-structure-tab');
        trackAccessibilityEvent('acessibilidade-estrutura-aba', {
          event_label: 'selecionar_aba',
          section: 'estrutura_pagina',
          tab_name: selectedTab || 'desconhecida'
        });

        Array.prototype.forEach.call(sections.tabs, function updateTabState(tab) {
          var isSelected = tab === tabButton;
          tab.classList.toggle('is-active', isSelected);
          tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });

        Array.prototype.forEach.call(sections.lists, function updateListState(list) {
          var active = list.getAttribute('data-page-structure-list') === selectedTab;
          list.classList.toggle('is-active', active);
          list.hidden = !active;
        });
      });
    });
  }

  function decorateMultiLevelCards(shortcuts) {
    var panel = getPanelElement(shortcuts);
    if (!panel) {
      return;
    }

    var cards = panel.querySelectorAll('.ibr-card[data-feature]');

    cards.forEach(function decorate(card) {
      var feature = card.getAttribute('data-feature');
      var steps = FEATURE_STATE_STEPS[feature] || 1;
      if (!card || card.querySelector('.ibr-card__meta-dots')) {
        return;
      }

      var dots = document.createElement('span');
      dots.className = 'ibr-card__meta-dots';
      dots.setAttribute('aria-hidden', 'true');

      for (var i = 0; i < steps; i += 1) {
        dots.appendChild(document.createElement('span'));
      }

      card.appendChild(dots);
    });
  }

  function resetAccessibility(shortcuts) {
    Object.keys(DEFAULT_STATE).forEach(function resetKey(key) {
      state[key] = DEFAULT_STATE[key];
    });

    if (librasModeEnabled) {
      setLibrasMode(shortcuts, false);
    }

    var panel = getPanelElement(shortcuts);
    if (panel && panel.classList.contains('ibr-assistive-panel--page-structure')) {
      togglePageStructureView(shortcuts, false);
    }

    stopSiteReaderSpeech();
    global.setTimeout(function forceStopSiteReaderSpeech() {
      stopSiteReaderSpeech();
    }, 40);

    applyRootClasses();
    updateCardStates(shortcuts);
    updateResetButtonVisibility(shortcuts);
    saveState();
    notify(shortcuts, 'Recursos restaurados para o padrão original.');
  }

  function setupFeatureInteractions(shortcuts) {
    var panel = getPanelElement(shortcuts);
    if (!panel) {
      return;
    }

    decorateMultiLevelCards(shortcuts);
    setupPageStructureInteractions(shortcuts);

    var cards = panel.querySelectorAll('.ibr-card[data-feature]');
    if (!cards.length) {
      return;
    }

    cards.forEach(function addListener(card) {
      card.addEventListener('click', function onCardClick() {
        var feature = card.getAttribute('data-feature');
        trackAccessibilityEvent('acessibilidade-recurso-acionar', {
          event_label: 'acionar_recurso',
          feature: feature || 'desconhecido'
        });
        toggleFeature(shortcuts, feature);
      });
    });

    updateCardStates(shortcuts);
    updateResetButtonVisibility(shortcuts);

    var resetButton = panel.querySelector('[data-accessibility-reset]');
    if (resetButton) {
      resetButton.addEventListener('click', function onResetClick() {
        resetButton.classList.add('is-active');
        global.setTimeout(function clearResetActive() {
          resetButton.classList.remove('is-active');
        }, 180);
        resetAccessibility(shortcuts);
      });
    }
  }

  function setupPanelInteractions(shortcuts) {
    var panel = shortcuts.querySelector('.ibr-assistive-panel') || document.querySelector('.ibr-assistive-panel');
    var toggle = shortcuts.querySelector('[data-accessibility-toggle="panel"]');
    var close = panel ? panel.querySelector('[data-accessibility-close]') : shortcuts.querySelector('[data-accessibility-close]');
    var expand = panel ? panel.querySelector('[data-accessibility-expand]') : shortcuts.querySelector('[data-accessibility-expand]');
    var librasButton = shortcuts.querySelector('[data-accessibility-toggle="libras"]');
    var dragHandle = panel ? panel.querySelector('[data-accessibility-drag-handle]') : null;
    var panelBody = panel ? panel.querySelector('.ibr-assistive-panel__body') : null;
    var resizeHandles = panel ? panel.querySelectorAll('[data-accessibility-resize]') : [];
    var lgpdShieldFooter = document.getElementById('lgpdShieldFooter');
    var MIN_PANEL_WIDTH = 320;
    var MIN_PANEL_HEIGHT = 360;
    var dragState = {
      active: false,
      pointerId: null,
      offsetX: 0,
      offsetY: 0
    };
    var resizeState = {
      active: false,
      pointerId: null,
      handle: null,
      startX: 0,
      startY: 0,
      startTop: 0,
      startLeft: 0,
      startWidth: 0,
      startHeight: 0
    };

    if (!panel || !toggle) {
      return;
    }

    function syncFloatingButtonVisibility() {
      if (!lgpdShieldFooter) {
        return;
      }

      if (panel.hidden) {
        lgpdShieldFooter.removeAttribute('hidden');
        lgpdShieldFooter.setAttribute('aria-hidden', 'false');
        return;
      }

      lgpdShieldFooter.setAttribute('hidden', '');
      lgpdShieldFooter.setAttribute('aria-hidden', 'true');
    }

    function positionPanelByToggleSide() {
      if (panel.classList.contains('ibr-assistive-panel--expanded')) {
        return;
      }

      panel.classList.remove('ibr-assistive-panel--expanded');

      if (isMobileViewport()) {
        panel.style.left = '50%';
        panel.style.right = 'auto';
        panel.style.top = '10px';
        panel.style.bottom = 'auto';
        panel.style.transform = 'translateX(-50%)';
        return;
      }

      var inAreaCliente = shortcuts.classList.contains('ibr-accessibility-shortcuts--area-cliente');
      panel.style.transform = '';
      panel.style.top = 'auto';
      panel.style.bottom = '0';

      if (inAreaCliente) {
        panel.style.right = '0';
        panel.style.left = 'auto';
        return;
      }

      panel.style.left = '0';
      panel.style.right = 'auto';
    }

    function keepPanelInViewport() {
      if (isMobileViewport()) {
        return;
      }

      if (panel.hidden || panel.classList.contains('ibr-assistive-panel--expanded')) {
        return;
      }

      var rect = panel.getBoundingClientRect();
      var maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
      var maxTop = Math.max(8, window.innerHeight - rect.height - 8);
      var targetLeft = Math.min(Math.max(8, rect.left), maxLeft);
      var targetTop = Math.min(Math.max(8, rect.top), maxTop);

      panel.style.left = Math.round(targetLeft) + 'px';
      panel.style.top = Math.round(targetTop) + 'px';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
    }

    function syncPanelOpenState() {
      shortcuts.classList.toggle('ibr-accessibility-shortcuts--panel-open', !panel.hidden);
      uiState.panelOpen = !panel.hidden;
      if (!uiState.panelOpen) {
        uiState.panelExpanded = false;
      }
      saveUiState();
      syncFloatingButtonVisibility();
    }

    function openPanel() {
      positionPanelByToggleSide();
      panel.hidden = false;
      syncPanelOpenState();
    }

    function closePanel(origin) {
      panel.hidden = true;
      panel.classList.remove('ibr-assistive-panel--expanded');
      if (expand) {
        expand.setAttribute('aria-pressed', 'false');
        expand.setAttribute('aria-label', 'Expandir painel');
      }
      dragState.active = false;
      dragState.pointerId = null;
      resizeState.active = false;
      resizeState.pointerId = null;
      resizeState.handle = null;
      trackAccessibilityEvent('acessibilidade-flutuante-fechar', {
        event_label: 'fechar',
        action_origin: origin || 'botao'
      });
      syncPanelOpenState();
    }

    function handleOutsideClick(event) {
      if (panel.hidden) {
        return;
      }

      var clickedInsidePanel = panel.contains(event.target);
      var clickedToggle = toggle.contains(event.target);
      if (!clickedInsidePanel && !clickedToggle) {
        closePanel('clique-fora');
      }
    }

    function handleEscape(event) {
      if (event.key === 'Escape' && !panel.hidden) {
        closePanel('tecla-esc');
      }
    }

    function updatePanelDragPosition(clientX, clientY) {
      var maxLeft = Math.max(8, window.innerWidth - panel.offsetWidth - 8);
      var maxTop = Math.max(8, window.innerHeight - panel.offsetHeight - 8);
      var targetLeft = Math.min(Math.max(8, clientX - dragState.offsetX), maxLeft);
      var targetTop = Math.min(Math.max(8, clientY - dragState.offsetY), maxTop);

      panel.style.left = Math.round(targetLeft) + 'px';
      panel.style.top = Math.round(targetTop) + 'px';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
    }

    function canStartDragFromTarget(target) {
      if (!target) {
        return true;
      }

      var selector = 'button, a, input, select, textarea, label, [role="button"], .ibr-card, [data-accessibility-resize]';
      var current = target.nodeType === 1 ? target : target.parentElement;

      while (current && current !== panel) {
        var canMatch = typeof current.matches === 'function';
        if (canMatch && current.matches(selector)) {
          return false;
        }
        current = current.parentElement;
      }

      return true;
    }

    function onDragStart(event) {
      if (panel.hidden || event.button !== 0 || resizeState.active) {
        return;
      }

      if (!canStartDragFromTarget(event.target)) {
        return;
      }

      dragState.active = true;
      dragState.pointerId = event.pointerId;
      dragState.offsetX = event.clientX - panel.getBoundingClientRect().left;
      dragState.offsetY = event.clientY - panel.getBoundingClientRect().top;
      panel.style.cursor = 'grabbing';

      if (event.currentTarget && event.currentTarget.setPointerCapture) {
        event.currentTarget.setPointerCapture(event.pointerId);
      }

      event.preventDefault();
    }

    function onDragMove(event) {
      if (!dragState.active || dragState.pointerId !== event.pointerId) {
        return;
      }

      updatePanelDragPosition(event.clientX, event.clientY);
    }

    function onDragEnd(event) {
      if (!dragState.active || dragState.pointerId !== event.pointerId) {
        return;
      }

      dragState.active = false;
      dragState.pointerId = null;
      panel.style.cursor = '';

      if (event.currentTarget && event.currentTarget.releasePointerCapture) {
        try {
          event.currentTarget.releasePointerCapture(event.pointerId);
        } catch (error) {
          // noop
        }
      }
    }

    function onResizeStart(event) {
      if (panel.hidden || event.button !== 0 || dragState.active) {
        return;
      }

      var handleName = event.currentTarget ? event.currentTarget.getAttribute('data-accessibility-resize') : null;
      if (!handleName) {
        return;
      }

      var rect = panel.getBoundingClientRect();
      resizeState.active = true;
      resizeState.pointerId = event.pointerId;
      resizeState.handle = handleName;
      resizeState.startX = event.clientX;
      resizeState.startY = event.clientY;
      resizeState.startTop = rect.top;
      resizeState.startLeft = rect.left;
      resizeState.startWidth = rect.width;
      resizeState.startHeight = rect.height;

      panel.style.top = Math.round(rect.top) + 'px';
      panel.style.left = Math.round(rect.left) + 'px';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';

      if (event.currentTarget.setPointerCapture) {
        event.currentTarget.setPointerCapture(event.pointerId);
      }

      event.preventDefault();
    }

    function onResizeMove(event) {
      if (!resizeState.active || resizeState.pointerId !== event.pointerId) {
        return;
      }

      var deltaX = event.clientX - resizeState.startX;
      var deltaY = event.clientY - resizeState.startY;
      var nextLeft = resizeState.startLeft;
      var nextTop = resizeState.startTop;
      var nextWidth = resizeState.startWidth;
      var nextHeight = resizeState.startHeight;
      var handle = resizeState.handle || '';
      var fromLeft = handle.indexOf('left') !== -1;
      var fromTop = handle.indexOf('top') !== -1;

      if (fromLeft) {
        nextWidth = resizeState.startWidth - deltaX;
        nextLeft = resizeState.startLeft + deltaX;
      } else {
        nextWidth = resizeState.startWidth + deltaX;
      }

      if (fromTop) {
        nextHeight = resizeState.startHeight - deltaY;
        nextTop = resizeState.startTop + deltaY;
      } else {
        nextHeight = resizeState.startHeight + deltaY;
      }

      var maxAllowedWidth = Math.max(MIN_PANEL_WIDTH, window.innerWidth - 16);
      var maxAllowedHeight = Math.max(MIN_PANEL_HEIGHT, window.innerHeight - 16);
      nextWidth = Math.max(MIN_PANEL_WIDTH, Math.min(nextWidth, maxAllowedWidth));
      nextHeight = Math.max(MIN_PANEL_HEIGHT, Math.min(nextHeight, maxAllowedHeight));

      if (fromLeft) {
        nextLeft = resizeState.startLeft + (resizeState.startWidth - nextWidth);
      }

      if (fromTop) {
        nextTop = resizeState.startTop + (resizeState.startHeight - nextHeight);
      }

      var maxLeft = Math.max(8, window.innerWidth - nextWidth - 8);
      var maxTop = Math.max(8, window.innerHeight - nextHeight - 8);
      nextLeft = Math.min(Math.max(8, nextLeft), maxLeft);
      nextTop = Math.min(Math.max(8, nextTop), maxTop);

      panel.style.width = Math.round(nextWidth) + 'px';
      panel.style.height = Math.round(nextHeight) + 'px';
      panel.style.left = Math.round(nextLeft) + 'px';
      panel.style.top = Math.round(nextTop) + 'px';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
    }

    function onResizeEnd(event) {
      if (!resizeState.active || resizeState.pointerId !== event.pointerId) {
        return;
      }

      resizeState.active = false;
      resizeState.pointerId = null;
      resizeState.handle = null;

      if (event.currentTarget && event.currentTarget.releasePointerCapture) {
        try {
          event.currentTarget.releasePointerCapture(event.pointerId);
        } catch (error) {
          // noop
        }
      }
    }

    toggle.addEventListener('click', function onTogglePanel() {
      if (panel.hidden) {
        trackAccessibilityEvent('acessibilidade-flutuante-abrir', {
          event_label: 'abrir'
        });
        openPanel();
        return;
      }

      closePanel('botao-principal');
    });

    if (close) {
      close.addEventListener('click', function onClosePanel() {
        closePanel('botao-fechar');
      });
    }

    if (expand) {
      expand.setAttribute('aria-pressed', 'false');
      expand.setAttribute('aria-label', 'Expandir painel');
      expand.addEventListener('click', function onExpandToggle() {
        var isExpanded = panel.classList.toggle('ibr-assistive-panel--expanded');
        uiState.panelExpanded = isExpanded;
        saveUiState();
        expand.setAttribute('aria-pressed', isExpanded ? 'true' : 'false');
        expand.setAttribute('aria-label', isExpanded ? 'Reduzir painel' : 'Expandir painel');
        trackAccessibilityEvent(isExpanded ? 'acessibilidade-flutuante-expandir' : 'acessibilidade-flutuante-reduzir', {
          event_label: isExpanded ? 'expandir' : 'reduzir'
        });
        if (!isExpanded) {
          positionPanelByToggleSide();
        }
        updateResetButtonVisibility(shortcuts);
      });
    }

    if (uiState.panelOpen) {
      openPanel();

      if (expand && uiState.panelExpanded) {
        panel.classList.add('ibr-assistive-panel--expanded');
        expand.setAttribute('aria-pressed', 'true');
        expand.setAttribute('aria-label', 'Reduzir painel');
      }
    } else {
      syncPanelOpenState();
    }

    if (librasButton) {
      librasButton.addEventListener('click', function onLibrasButtonClick(event) {
        event.preventDefault();
        ensureVlibrasServiceAvailable()
          .then(function onVlibrasServiceAvailable() {
            return ensureVlibrasWidget();
          })
          .then(function onWidgetLoaded() {
            var willEnable = !librasModeEnabled;
            setLibrasMode(shortcuts, willEnable);
            trackAccessibilityEvent(willEnable ? 'acessibilidade-libras-flutuante-ativar' : 'acessibilidade-libras-flutuante-desativar', {
              event_label: willEnable ? 'ativar_libras' : 'desativar_libras',
              feature: 'libras'
            });
          })
          .catch(function onWidgetLoadError() {
            trackAccessibilityEvent('acessibilidade-libras-flutuante-erro', {
              event_label: 'erro_libras',
              feature: 'libras'
            });
            notify(shortcuts, 'Não foi possível carregar o VLibras. Verifique conexão, CSP e bloqueios de conteúdo externo.');
          });
      });
    }

    if (dragHandle) {
      dragHandle.style.cursor = 'grab';
      dragHandle.addEventListener('pointerdown', onDragStart);
      dragHandle.addEventListener('pointermove', onDragMove);
      dragHandle.addEventListener('pointerup', onDragEnd);
      dragHandle.addEventListener('pointercancel', onDragEnd);
    }

    if (panelBody) {
      panelBody.addEventListener('pointerdown', onDragStart);
      panelBody.addEventListener('pointermove', onDragMove);
      panelBody.addEventListener('pointerup', onDragEnd);
      panelBody.addEventListener('pointercancel', onDragEnd);
    }

    resizeHandles.forEach(function bindResizeEvents(handle) {
      handle.addEventListener('pointerdown', onResizeStart);
      handle.addEventListener('pointermove', onResizeMove);
      handle.addEventListener('pointerup', onResizeEnd);
      handle.addEventListener('pointercancel', onResizeEnd);
    });

    document.addEventListener('click', handleOutsideClick);
    document.addEventListener('keydown', handleEscape);
    window.addEventListener('resize', function onPanelResize() {
      if (!panel.hidden && !dragState.active && !resizeState.active && !panel.classList.contains('ibr-assistive-panel--expanded')) {
        keepPanelInViewport();
      }
      syncFloatingButtonVisibility();
    });

    syncPanelOpenState();

    var resetButton = panel.querySelector('[data-accessibility-reset]');
    if (resetButton) {
      resetButton.hidden = !hasAnyActiveState(shortcuts);
      resetButton.addEventListener('click', function onResetPanelState() {
        if (librasModeEnabled) {
          setLibrasMode(shortcuts, false);
        }

        if (panel.classList.contains('ibr-assistive-panel--expanded')) {
          panel.classList.remove('ibr-assistive-panel--expanded');
          if (expand) {
            expand.setAttribute('aria-pressed', 'false');
          }
        }

        positionPanelByToggleSide();
      });
    }
  }

  function isAreaClienteContext() {
    var path = (window.location && window.location.pathname ? window.location.pathname : '').toLowerCase();

    return path.indexOf('/areacliente') === 0
      || path.indexOf('/paginas/areacliente') === 0
      || path.indexOf('/paginasareacliente') === 0
      || path.indexOf('/paginasareaclienteacessocadastro') === 0
      || path.indexOf('/paginas/areacliente/acessocadastro') === 0
      || path.indexOf('/paginasareaclientesessao') === 0
      || path.indexOf('/paginas/areacliente/sessao') === 0;
  }

  function isMobileViewport() {
    if (!window.matchMedia) {
      return window.innerWidth <= 991.98;
    }

    return window.matchMedia(MOBILE_BREAKPOINT).matches;
  }

  function getInteractionPoint(event) {
    if (event && event.changedTouches && event.changedTouches.length) {
      return event.changedTouches[0];
    }

    if (event && event.touches && event.touches.length) {
      return event.touches[0];
    }

    return event;
  }

  function getMenuContainer(inAreaCliente) {
    if (inAreaCliente) {
      return document.querySelector('#sidebar .navbar-nav, #sidebar .nav, #sidebar');
    }

    return document.querySelector('#mobileMenu nav.navbar-nav, #mobileMenu .navbar-nav, #mobileMenu .menu__block');
  }

  function isAreaClienteAccessLoginContext() {
    var path = (window.location && window.location.pathname ? window.location.pathname : '').toLowerCase();
    if (path.indexOf('/paginasareaclienteacessocadastro') === 0
      || path.indexOf('/paginas/areacliente/acessocadastro') === 0
      || path === '/areacliente'
      || path === '/areacliente/'
      || path.indexOf('/areacliente/acesso') === 0) {
      return true;
    }

    return !!document.querySelector('#main-content .login__box #formLogin .social-login-container');
  }

  function getAreaClienteAccessLoginContainer() {
    var socialContainer = document.querySelector('#main-content .login__box #formLogin .social-login-container');
    if (socialContainer) {
      return socialContainer;
    }

    return document.querySelector('#main-content .login__box') || null;
  }

  function syncShortcutsPlacement(shortcuts) {
    if (!shortcuts) {
      return;
    }

    var inAreaCliente = isAreaClienteContext();
    var inMobile = isMobileViewport();
    var menuContainer = inMobile ? getMenuContainer(inAreaCliente) : null;
    var loginContainer = inAreaCliente && isAreaClienteAccessLoginContext()
      ? getAreaClienteAccessLoginContainer()
      : null;

    ROOT.classList.toggle('ibr-vlibras-side-right', inAreaCliente);
    ROOT.classList.toggle('ibr-vlibras-side-left', !inAreaCliente);

    shortcuts.classList.remove('ibr-accessibility-shortcuts--outside', 'ibr-accessibility-shortcuts--area-cliente', 'ibr-accessibility-shortcuts--in-menu', 'ibr-accessibility-shortcuts--in-login-mobile');

    if (menuContainer) {
      shortcuts.classList.add('ibr-accessibility-shortcuts--in-menu');
      if (shortcuts.parentNode !== menuContainer) {
        menuContainer.appendChild(shortcuts);
      }

      var floatingPanelInBody = document.querySelector('.ibr-assistive-panel');
      if (floatingPanelInBody && floatingPanelInBody.parentNode !== document.body) {
        document.body.appendChild(floatingPanelInBody);
      }
      return;
    }

    if (loginContainer) {
      shortcuts.classList.add('ibr-accessibility-shortcuts--in-login-access');
      if (loginContainer.classList && loginContainer.classList.contains('social-login-container')) {
        if (shortcuts.parentNode !== loginContainer.parentNode || shortcuts.previousElementSibling !== loginContainer) {
          loginContainer.insertAdjacentElement('afterend', shortcuts);
        }
      } else if (shortcuts.parentNode !== loginContainer) {
        loginContainer.appendChild(shortcuts);
      }

      var floatingPanelInBodyOnLogin = document.querySelector('.ibr-assistive-panel');
      if (floatingPanelInBodyOnLogin && floatingPanelInBodyOnLogin.parentNode !== document.body) {
        document.body.appendChild(floatingPanelInBodyOnLogin);
      }
      return;
    }

    if (shortcuts.parentNode !== document.body) {
      document.body.appendChild(shortcuts);
    }

    var panel = shortcuts.querySelector('.ibr-assistive-panel');
    if (panel && panel.parentNode !== document.body) {
      document.body.appendChild(panel);
    }

    shortcuts.classList.add(inAreaCliente
      ? 'ibr-accessibility-shortcuts--area-cliente'
      : 'ibr-accessibility-shortcuts--outside');
  }

  function syncShortcutsLoadingVisibility(shortcuts) {
    if (!shortcuts || !document.documentElement) {
      return;
    }

    var root = document.documentElement;
    var loaderElement = document.getElementById('loader');
    var loaderVisible = false;

    if (loaderElement) {
      loaderVisible = !loaderElement.hidden;

      if (loaderVisible && window.getComputedStyle) {
        var loaderStyles = window.getComputedStyle(loaderElement);
        loaderVisible = !!loaderStyles
          && loaderStyles.display !== 'none'
          && loaderStyles.visibility !== 'hidden'
          && loaderStyles.opacity !== '0';
      }
    }

    var loadingActive = root.getAttribute('data-loading') === 'true'
      || root.classList.contains('is-initial-loading')
      || window.keepLoading === true
      || loaderVisible;

    shortcuts.classList.toggle('ibr-accessibility-shortcuts--hidden-loading', loadingActive);
  }

  function mountShortcuts(html) {
    function initializeShortcuts(shortcuts) {
      if (!shortcuts || shortcuts.getAttribute('data-accessibility-initialized') === 'true') {
        return;
      }

      shortcuts.setAttribute('data-accessibility-initialized', 'true');
      setupPanelInteractions(shortcuts);
      setupFeatureInteractions(shortcuts);
      restorePersistentLibrasMode(shortcuts);
    }

    var existing = document.querySelector('.ibr-accessibility-shortcuts');
    if (existing) {
      syncShortcutsPlacement(existing);
      syncShortcutsLoadingVisibility(existing);
      initializeShortcuts(existing);
      return;
    }

    var wrapper = document.createElement('div');
    wrapper.innerHTML = html;

    var shortcuts = wrapper.querySelector('.ibr-accessibility-shortcuts');
    if (!shortcuts) {
      return;
    }

    document.body.appendChild(shortcuts);
    syncShortcutsPlacement(shortcuts);
    syncShortcutsLoadingVisibility(shortcuts);
    initializeShortcuts(shortcuts);

    window.addEventListener('resize', function onResize() {
      syncShortcutsPlacement(shortcuts);
    });

    if (window.matchMedia) {
      var mobileQuery = window.matchMedia(MOBILE_BREAKPOINT);
      if (mobileQuery.addEventListener) {
        mobileQuery.addEventListener('change', function onMediaChange() {
          syncShortcutsPlacement(shortcuts);
        });
      } else if (mobileQuery.addListener) {
        mobileQuery.addListener(function onMediaChangeLegacy() {
          syncShortcutsPlacement(shortcuts);
        });
      }
    }

    var observer = new MutationObserver(function onMutations() {
      syncShortcutsPlacement(shortcuts);
      syncShortcutsLoadingVisibility(shortcuts);
    });

    observer.observe(document.body, { childList: true, subtree: true });

    if (document.documentElement) {
      observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-loading', 'class']
      });
    }
  }

  function init() {
    if (!document.body) {
      return;
    }

    loadState();
    loadUiState();
    normalizeState();
    normalizeUiState();
    applyRootClasses();
    bindReadingGuideTracking();
    bindReadingMaskTracking();
    if (!global.fetch) {
      return;
    }

    fetch(ACCESSIBILITY_HTML_URL, { credentials: 'same-origin' })
      .then(function onResponse(response) {
        if (!response.ok) {
          throw new Error('Falha ao carregar LayoutAcessibilidade.html');
        }

        return response.text();
      })
      .then(function onHtmlLoaded(html) {
        mountShortcuts(html);
      })
      .catch(function onError() {
        // noop
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
    return;
  }

  init();
})(window);
