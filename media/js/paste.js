/**
 * Webmarka Clean: очистка вставки в визуальном редакторе (TinyMCE) и список оформления таблиц.
 *
 * Правила те же, что у серверного очистителя (plg_system_wmarka_clean, WordClean.php): то, что редактор показывает после вставки, совпадает с тем,
 * что сохранится. Убираются: мусор Word, Word Online, Google Docs (style, lang, align, ширины, служебные классы и id, span и font, комментарии и
 * условные блоки, пустые абзацы); ВСЕ эмодзи; значки из настроек (№ ≈ стрелки ★ ✓ …); хештеги; «**». Остаются ссылки, якоря, сноски, табличные
 * классы UIkit 3, знак тенге, обозначения списков, одиночная «*» (знак сноски), минус и °C. Якорь Word <a name="…"> становится <a id="…">;
 * списки Word собираются в настоящие ul/ol; <p> ставится вместо div и br, пустых абзацев не бывает.
 */
(() => {
  'use strict';

  const options = (window.Joomla && Joomla.getOptions && Joomla.getOptions('wmarka_clean')) || {};
  const globs = Array.isArray(options.keep) && options.keep.length ? options.keep : ['uk-table*', 'uk-overflow-auto'];
  const keep = globs.map((g) => new RegExp('^' + g.trim().replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[a-z0-9_-]*') + '$', 'i'));
  const dropEmpty = options.dropEmpty !== false;
  const stripEmoji = options.emoji !== false;
  const stripHashtags = options.hashtags !== false;
  const linkMode = ['all', 'external', 'off'].includes(options.linkMode) ? options.linkMode : 'all';
  const siteHost = String(options.siteHost || '').toLowerCase().replace(/^www\./, '');
  const embedHosts = String(options.embedHosts || 'youtube.com youtube-nocookie.com vimeo.com vk.com vkvideo.ru rutube.ru ok.ru dailymotion.com tiktok.com instagram.com facebook.com t.me twitter.com x.com syndication.twitter.com open.spotify.com soundcloud.com music.yandex.ru threads.net threads.com bsky.app embed.bsky.app')
    .toLowerCase().split(/[\s,]+/).filter(Boolean);
  const SOCIAL = ['twitter-tweet', 'instagram-media', 'tiktok-embed', 'text-post-media', 'bluesky-embed'];
  // снимок, видео и документ из Wmarka Media: figure.wm-figure…, подпись span.wm-figure__credit, адрес ролика data-wm-video, a.wm-doc
  const WMARKA = /^wm-(?:figure|doc)(?:--[a-z0-9]+|__[a-z]+)?$/;
  const extraSymbols = typeof options.extra === 'string' ? options.extra : '↑ ↓ ← → ↔ ↕ ↗ ↘ ↖ ↙ ★ ☆ ✓ ✗ ✘';
  /** Знаки, которые остаются (как WordClean::protect): на время очистки — символ частной области Unicode, потом обратно; «©️» → «©» */
  const KEEP = (typeof options.keepSymbols === 'string' ? options.keepSymbols : '© ® ™')
    .split(/\s+/).map((t) => t.replace(/[\uFE0E\uFE0F]/g, '')).filter((t, i, a) => t && a.indexOf(t) === i)
    .sort((a, b) => [...b].length - [...a].length)
    .map((t, i) => [new RegExp(t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[\\uFE0E\\uFE0F]?', 'gu'), String.fromCodePoint(0xF0000 + i), t]);

  const UNWRAP = new Set(['SPAN', 'FONT', 'CENTER', 'BIG', 'TT', 'NOBR', 'BDO', 'U', 'TG-EMOJI', 'TG-SPOILER']);
  const BLOCK = new Set(['P', 'DIV', 'UL', 'OL', 'LI', 'TABLE', 'BLOCKQUOTE', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR', 'PRE', 'FIGURE', 'SECTION', 'ARTICLE', 'ASIDE', 'DL']);
  const EDGE_BLOCK = new Set(['P', 'LI', 'TD', 'TH', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'BLOCKQUOTE', 'DIV', 'CAPTION']);
  const INLINE_BR = new Set(['STRONG', 'EM', 'A', 'SUP', 'SUB', 'S', 'DEL', 'INS', 'ABBR', 'STRIKE']);
  const MEDIA = 'img, iframe, video, audio, embed, object, picture, svg, table';
  const ATTRS = {
    A: ['href', 'title', 'rel', 'target'],
    IMG: ['src', 'alt', 'width', 'height', 'title', 'loading', 'decoding', 'srcset', 'sizes'],
    TD: ['colspan', 'rowspan', 'scope'],
    TH: ['colspan', 'rowspan', 'scope'],
    COL: ['span'],
    COLGROUP: ['span'],
    OL: ['start', 'type'],
    BLOCKQUOTE: ['cite'],
    ABBR: ['title'],
  };
  const WORD_ID = /^_(?:toc|ref|hlk|goback|hlt|mso)/i;
  const JUNK_ID = /^(?:mce_|docs-internal-guid)/i;

  const classAllowed = (c) => keep.some((re) => re.test(c));

  /* ---------------------------------------------------------------- эмодзи и значки */

  /* Эмодзи — все: пиктографические знаки Unicode, флаги, цвета кожи, цифры в рамке, составные знаки (U+200D), теги флагов регионов. */
  const ATOM = '(?:\\p{Extended_Pictographic}|\\p{Regional_Indicator}|\\p{Emoji_Modifier})';
  const MODS = '[\\uFE0E\\uFE0F\\u20E3]*';
  let EMOJI_RE = null;
  let EXTRA_RE = null;
  let TAIL_RES = [];
  let MARK_RE = null;
  try {
    EMOJI_RE = new RegExp('(?:[0-9#*]\\uFE0F?\\u20E3|' + ATOM + MODS + '(?:\\u200D' + ATOM + MODS + ')*[\\u{E0020}-\\u{E007F}]*)', 'gu');
    const tokens = extraSymbols.split(/\s+/).filter(Boolean).map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    EXTRA_RE = tokens.length ? new RegExp('(?:' + tokens.join('|') + ')', 'gu') : null;
    MARK_RE = new RegExp('(?<![\\p{L}\\p{N}_&/])#(?=[\\p{L}\\p{N}_])', 'gu');
    TAIL_RES = [
      new RegExp('(?:^|(?<=[.!?…»)\\]:])[ \\t\\u00a0]+)(?:#[\\p{L}\\p{N}_]+[ \\t\\u00a0]*)+$', 'u'),
      new RegExp('[ \\t\\u00a0]+(?:#[\\p{L}\\p{N}_]+[ \\t\\u00a0]+)+#[\\p{L}\\p{N}_]+[ \\t\\u00a0.,;]*$', 'u'),
    ];
  } catch (e) {
    /* браузер без свойств Unicode или lookbehind в регулярных выражениях: на клиенте эмодзи не чистятся, сервер уберёт их при сохранении */
  }

  /** Строка без эмодзи, лишних значков и «**»; хештеги — по флагам (хвосты целой строкой; иначе их решает проход по блокам) */
  function stripSymbols(text, withTails, withMarks) {
    if (!EMOJI_RE) {
      return text;
    }
    const orig = text;
    KEEP.forEach(([re, ph]) => { text = text.replace(re, ph); });
    text = text.replace(EMOJI_RE, '').replace(/[\uFE0E\uFE0F\u200D\u20E3\u{E0020}-\u{E007F}]/gu, '');
    if (EXTRA_RE) {
      text = text.replace(EXTRA_RE, '');
    }
    text = text.replace(/\*{2,}/g, '');
    if (withTails && stripHashtags) {
      TAIL_RES.forEach((re) => { text = text.replace(re, ''); });
    }
    if (withMarks && stripHashtags && MARK_RE) {
      text = text.replace(MARK_RE, '');
    }
    KEEP.forEach(([, ph, t]) => { text = text.split(ph).join(t); });
    if (text === orig) {
      return text;
    }
    return text
      .replace(/[ \t\u00a0]{2,}/g, ' ')
      .replace(/[ \t\u00a0]+([.,;:!?»)\]])/g, '$1')
      .replace(/([,;:])(?:[ \t\u00a0]*\1)+/g, '$1')
      .replace(/([«(\[])[ \t\u00a0]+/g, '$1');
  }

  /** Узел стоит на краю блока, если, поднимаясь по строчным предкам, он остаётся первым (последним) у каждого */
  function atEdge(node, side) {
    while (!node[side]) {
      const parent = node.parentNode;
      if (!parent || parent.nodeType !== 1 || EDGE_BLOCK.has(parent.nodeName)) {
        return true;
      }
      node = parent;
    }
    return false;
  }

  function trimEdges(node, value) {
    if (atEdge(node, 'previousSibling')) {
      value = value.replace(/^[ \t\u00a0]+/, '');
    }
    if (atEdge(node, 'nextSibling')) {
      value = value.replace(/[ \t\u00a0]+$/, '');
    }
    return value;
  }

  function textNodes(root) {
    const walker = root.ownerDocument.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) {
      nodes.push(walker.currentNode);
    }
    return nodes;
  }

  function symbolNodes(root) {
    let seen = false;
    textNodes(root).forEach((t) => {
      const before = t.nodeValue;
      const after = trimEdges(t, stripSymbols(before, false, false));
      if (after !== before) {
        t.nodeValue = after;
        seen = true;
      }
    });
    return seen;
  }

  /** Хештеги в конце абзаца и абзацы из одних хештегов — целиком; решение по тексту всего абзаца («#MeToo» в <strong> внутри предложения остаётся словом) */
  function hashtagTails(root) {
    let seen = false;
    root.querySelectorAll('p, li, td, th, h1, h2, h3, h4, h5, h6, blockquote, caption').forEach((block) => {
      if (block.querySelector('p, ul, ol, li, table, blockquote, div')) {
        return;
      }
      const nodes = textNodes(block);
      let text = nodes.map((t) => t.nodeValue).join('');
      if (!text.includes('#')) {
        return;
      }
      TAIL_RES.forEach((re) => {
        const m = re.exec(text);
        if (!m) {
          return;
        }
        let pos = 0;
        nodes.forEach((t) => {
          const v = t.nodeValue;
          if (pos >= m.index) {
            t.nodeValue = '';
          } else if (pos + v.length > m.index) {
            t.nodeValue = v.slice(0, m.index - pos);
          }
          pos += v.length;
        });
        text = text.slice(0, m.index);
        seen = true;
      });
      block.querySelectorAll('a, strong, em, sup, sub, s').forEach((inline) => {
        if (inline.parentNode && !(inline.textContent || '').trim() && !inline.hasAttribute('id') && !inline.querySelector(MEDIA)) {
          inline.remove();
        }
      });
    });
    return seen;
  }

  function hashtagMarks(root) {
    if (!MARK_RE) {
      return false;
    }
    let seen = false;
    textNodes(root).forEach((t) => {
      const next = t.nodeValue.replace(MARK_RE, '');
      if (next !== t.nodeValue) {
        t.nodeValue = next;
        seen = true;
      }
    });
    return seen;
  }

  /* ---------------------------------------------------------------- вставки соцсетей */

  /** Адрес разрешён: https и хост из списка (или поддомен хоста из списка) */
  function embedAllowed(url) {
    url = String(url || '').trim();
    if (url.startsWith('//')) {
      url = 'https:' + url;
    }
    const m = /^https:\/\/([^/:?#]+)/i.exec(url);
    if (!m) {
      return false;
    }
    const host = m[1].toLowerCase();
    return embedHosts.some((h) => host === h || host.endsWith('.' + h));
  }

  /** Ссылка «для людей» к вставке: страница видео или поста, а не адрес плеера */
  function embedLink(src) {
    let m = /^https:\/\/(?:www\.)?youtube(?:-nocookie)?\.com\/embed\/([\w-]{6,})/i.exec(src);
    if (m) {
      return 'https://www.youtube.com/watch?v=' + m[1];
    }
    m = /^https:\/\/player\.vimeo\.com\/video\/(\d+)/i.exec(src);
    if (m) {
      return 'https://vimeo.com/' + m[1];
    }
    m = /^https:\/\/t\.me\/(\w+\/\d+)/i.exec(src);
    return m ? 'https://t.me/' + m[1] : src;
  }

  function embedNode(doc, src, w, h, link) {
    const div = doc.createElement('div');
    div.setAttribute('class', 'tm-embed');
    div.setAttribute('data-embed-src', src);
    if (w > 0) {
      div.setAttribute('data-embed-w', String(w));
    }
    if (h > 0) {
      div.setAttribute('data-embed-h', String(h));
    }
    const href = link || embedLink(src);
    const a = doc.createElement('a');
    a.setAttribute('href', href);
    a.textContent = href;
    div.appendChild(a);
    return div;
  }

  /**
   * iframe разрешённых хостов и виджет Telegram (<script data-telegram-post>) → метка tm-embed с адресом. Делается на строке HTML до обработки
   * вставки (штатный редактор вырезает iframe и script раньше) и при загрузке кода через окно «Исходный код»: метку пропускают и фильтры текста
   * Joomla, и редактор. iframe других хостов удаляются.
   */
  function convertEmbeds(html) {
    if (!/<iframe|data-telegram-post|fb-(?:post|video)|fb-root/i.test(html)) {
      return html;
    }
    // обёртка <body>: иначе ведущий <script> разбор уводит в <head>, и от вставки ничего не остаётся
    const doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
    doc.querySelectorAll('iframe').forEach((f) => {
      let src = (f.getAttribute('src') || '').trim();
      src = src.startsWith('//') ? 'https:' + src : src;
      if (embedAllowed(src)) {
        f.replaceWith(embedNode(doc, src, parseInt(f.getAttribute('width'), 10) || 0, parseInt(f.getAttribute('height'), 10) || 0, ''));
      } else {
        f.remove();
      }
    });
    doc.querySelectorAll('script[data-telegram-post]').forEach((sc) => {
      const post = sc.getAttribute('data-telegram-post') || '';
      if (/^\w+\/\d+$/.test(post)) {
        sc.replaceWith(embedNode(doc, 'https://t.me/' + post + '?embed=1&mode=tme', 0, 480, 'https://t.me/' + post));
      } else {
        sc.remove();
      }
    });
    doc.querySelectorAll('div.fb-post, div.fb-video').forEach((d) => {
      const href = d.getAttribute('data-href') || '';
      if (/^https:\/\/(?:www\.|m\.|web\.)?facebook\.com\//i.test(href)) {
        const kind = d.classList.contains('fb-video') ? 'video' : 'post';
        d.replaceWith(embedNode(doc, 'https://www.facebook.com/plugins/' + kind + '.php?href=' + encodeURIComponent(href) + '&show_text=true&width=500', 500, kind === 'video' ? 300 : 620, href));
      } else {
        d.remove();
      }
    });
    doc.querySelectorAll('#fb-root').forEach((d) => d.remove());
    return doc.body.innerHTML;
  }

  /**
   * Код вставки, скопированный из соцсети («Поделиться → Встроить»), попадает в буфер обмена текстом, а не HTML: <iframe …>, <blockquote class="twitter-tweet">…
   * Если вставляемый текст — такой код, он обрабатывается как HTML (иначе на страницу попали бы видимые угловые скобки).
   */
  function embedCode(content) {
    const probe = document.createElement('div');
    probe.innerHTML = content;
    const text = (probe.textContent || '').trim();
    const start = /^<(?:iframe\b|blockquote\b[^>]*class\s*=|script\b[^>]*data-telegram-post|div\b[^>]*class\s*=\s*["']fb-)/i.test(text);
    return start && /<\/(?:iframe|blockquote|script|div)\s*>/i.test(text) ? text : null;
  }

  /* ---------------------------------------------------------------- списки из знаков */

  const MARK_NUM = /^(?:(\d{1,3})[.)]|(\d)\uFE0F?\u20E3)[\s\u00a0]+(?=\S)/u;
  const MARK_KEYCAP = /^(\d)\uFE0F?\u20E3[\s\u00a0]*/u;
  const MARK_CHAR = /^[•●◉⬤◦‣○◌▪▫■□·∙▸▹►][\s\u00a0]*(?=\S)/u;
  const MARK_DASH = /^[–—-][\s\u00a0]+(?=\S)/u;
  let MARK_EMOJI = null;
  try {
    MARK_EMOJI = new RegExp('^(' + EMOJI_RE.source + ')[\\s\\u00a0]*(?=\\S)', 'u');
  } catch (e) {
    /* без свойств Unicode эмодзи-маркеры не распознаются */
  }

  /**
   * Маркер списка в начале абзаца: [тип, номер, число знаков, класс, ключ] или null. Знак и тире с пробелом, эмодзи, «1.», «2)», «1️⃣».
   * Одиночная «*» — знак сноски, маркером не считается.
   */
  function marker(text) {
    text = text.replace(/^[\s\u00a0]+/, '');
    let m = MARK_NUM.exec(text) || MARK_KEYCAP.exec(text);
    if (m) {
      return ['ol', parseInt(m[1] || m[2] || '0', 10), m[0].length, 'num', ''];
    }
    m = MARK_EMOJI && MARK_EMOJI.exec(text);
    if (m) {
      return ['ul', null, m[0].length, 'emoji', m[1].replace(/[\uFE0E\uFE0F]/g, '')];
    }
    m = MARK_CHAR.exec(text) || MARK_DASH.exec(text);
    return m ? ['ul', null, m[0].length, 'bullet', ''] : null;
  }

  /** Снять с начала текста элемента chars знаков (после пробелов); идёт по первым узлам текста, в том числе внутри <strong> */
  function cutPrefix(el, chars) {
    const nodes = textNodes(el);
    const all = nodes.map((t) => t.nodeValue).join('');
    let left = chars + (all.length - all.replace(/^[\s\u00a0]+/, '').length);
    nodes.forEach((t) => {
      if (left <= 0) {
        return;
      }
      const v = t.nodeValue;
      if (v.length <= left) {
        t.nodeValue = '';
        left -= v.length;
      } else {
        t.nodeValue = v.slice(left);
        left = 0;
      }
    });
  }

  /**
   * Списки — только ul/ol/li. Абзацы подряд с маркером собираются в список, маркер снимается: знак и тире — два подряд; эмодзи — один и тот же в двух
   * подряд или любые в трёх подряд («🔥 заголовок» и «⚡️ подзаголовок» — не список); нумерованные — номера подряд; одиночный абзац — не список.
   * В li готового списка маркер, оставшийся в тексте, снимается.
   */
  function textLists(root) {
    const doc = root.ownerDocument;
    [root, ...Array.from(root.querySelectorAll('blockquote, td, th, div[id], li'))].forEach((c) => {
      let run = [];
      let cls = null;
      const flush = () => {
        const n = run.length;
        let ok = false;
        if (cls === 'bullet') {
          ok = n >= 2;
        } else if (cls === 'emoji') {
          ok = n >= 3 || (n >= 2 && new Set(run.map((r) => r.key)).size === 1);
        } else if (cls === 'num') {
          ok = n >= 2 && run.every((r, i) => i === 0 || r.n === run[i - 1].n + 1);
        }
        if (ok) {
          const list = doc.createElement(cls === 'num' ? 'ol' : 'ul');
          if (cls === 'num' && run[0].n > 1) {
            list.setAttribute('start', String(run[0].n));
          }
          run[0].p.parentNode.insertBefore(list, run[0].p);
          run.forEach((item) => {
            cutPrefix(item.p, item.len);
            const li = doc.createElement('li');
            while (item.p.firstChild) {
              li.appendChild(item.p.firstChild);
            }
            list.appendChild(li);
            item.p.remove();
          });
        }
        run = [];
        cls = null;
      };
      Array.from(c.childNodes).forEach((n) => {
        if (n.nodeType === 3 && !n.nodeValue.replace(/[\s\u00a0]+/g, '')) {
          return;
        }
        const m = n.nodeType === 1 && n.nodeName === 'P' ? marker(n.textContent || '') : null;
        if (!m) {
          flush();
          return;
        }
        if (cls !== null && cls !== m[3]) {
          flush();
        }
        cls = m[3];
        run.push({ p: n, n: m[1], len: m[2], key: m[4] });
      });
      flush();
    });
    root.querySelectorAll('li').forEach((li) => {
      const m = marker(li.textContent || '');
      if (m && m[0] === 'ul') {
        cutPrefix(li, m[2]);
      }
    });
  }

  /* ---------------------------------------------------------------- ссылки */

  function isExternal(href) {
    const m = /^(?:https?:)?\/\/([^/:?#]+)/i.exec(href);
    if (!m) {
      return false;
    }
    return !siteHost || m[1].toLowerCase().replace(/^www\./, '') !== siteHost;
  }

  /** Ссылки открываются в новой вкладке (target="_blank" rel="noopener"); «#якоря» (сноски, оглавление), mailto и tel — нет */
  function links(root) {
    if (linkMode === 'off') {
      return;
    }
    root.querySelectorAll('a[href]').forEach((a) => {
      const href = (a.getAttribute('href') || '').trim();
      if (!href || href[0] === '#' || /^(?:mailto|tel|sms|javascript):/i.test(href) || (linkMode === 'external' && !isExternal(href))) {
        return;
      }
      a.setAttribute('target', '_blank');
      const rel = (a.getAttribute('rel') || '').toLowerCase().split(/\s+/).filter(Boolean);
      if (!rel.includes('noopener')) {
        rel.push('noopener');
      }
      a.setAttribute('rel', rel.join(' '));
    });
  }

  /* ---------------------------------------------------------------- списки Word */

  /**
   * Списки Word: абзацы со стилем mso-list и мнимым маркером («·», «o», «1.») → настоящие ul/ol (те же правила, что в WordClean.php).
   * Вызывается до очистки стилей, на строке HTML вставки.
   */
  function convertWordLists(html) {
    if (!/mso-list\s*:/i.test(html)) {
      return html;
    }
    const doc = new DOMParser().parseFromString(html.replace(/<!\[(?:if|endif)[^\]]*\]>/gi, ''), 'text/html');
    const items = Array.from(doc.body.querySelectorAll('p, div')).filter((p) => /mso-list\s*:\s*l\d+\s+level\d+/i.test(p.getAttribute('style') || ''));
    if (!items.length) {
      return html;
    }
    let stack = [];
    items.forEach((p) => {
      const m = /mso-list\s*:\s*(l\d+)\s+level(\d+)/i.exec(p.getAttribute('style'));
      const id = m[1];
      const level = parseInt(m[2], 10);
      let marker = '';
      const ign = Array.from(p.querySelectorAll('[style]')).find((n) => /mso-list\s*:\s*ignore/i.test(n.getAttribute('style')));
      if (ign) {
        marker = ign.textContent.replace(/\u00a0/g, ' ').trim();
        ign.remove();
      }
      const ordered = /^(?:\d+|[a-zа-я]|[ivxlc]+)[.)]$/i.test(marker);
      let prev = p.previousSibling;
      while (prev && prev.nodeType === 3 && !prev.nodeValue.trim()) {
        prev = prev.previousSibling;
      }
      if (!stack.length || prev !== stack[0].list) {
        stack = [];
      }
      const li = doc.createElement('li');
      while (p.firstChild) {
        li.appendChild(p.firstChild);
      }
      while (stack.length && stack[stack.length - 1].level > level) {
        stack.pop();
      }
      if (stack.length === 1 && stack[0].level === level && stack[0].id !== id) {
        stack = [];
      }
      const make = () => {
        const list = doc.createElement(ordered ? 'ol' : 'ul');
        const n = /^(\d+)/.exec(marker);
        if (ordered && n && parseInt(n[1], 10) > 1) {
          list.setAttribute('start', n[1]);
        }
        return list;
      };
      if (!stack.length) {
        const list = make();
        p.parentNode.insertBefore(list, p);
        stack.push({ list, level, id });
      } else if (stack[stack.length - 1].level < level) {
        const top = stack[stack.length - 1];
        const list = make();
        (top.list.lastChild || top.list).appendChild(list);
        stack.push({ list, level, id });
      }
      stack[stack.length - 1].list.appendChild(li);
      p.remove();
    });
    return doc.body.innerHTML;
  }

  /* ---------------------------------------------------------------- элементы */

  function unwrap(el) {
    const parent = el.parentNode;
    while (el.firstChild) {
      parent.insertBefore(el.firstChild, el);
    }
    parent.removeChild(el);
  }

  function rename(el, tag) {
    const next = el.ownerDocument.createElement(tag);
    for (const a of Array.from(el.attributes)) {
      next.setAttribute(a.name, a.value);
    }
    while (el.firstChild) {
      next.appendChild(el.firstChild);
    }
    el.parentNode.replaceChild(next, el);
    return next;
  }

  function hasAncestor(el, names) {
    for (let p = el.parentNode; p && p.nodeType === 1; p = p.parentNode) {
      if (names.includes(p.nodeName)) {
        return true;
      }
    }
    return false;
  }

  /** Жирный предок: B или STRONG, у которого шрифт не «normal» (обёртка Google Docs <b style="font-weight:normal"> жирным не считается) */
  function hasBoldAncestor(el) {
    for (let p = el.parentNode; p && p.nodeType === 1; p = p.parentNode) {
      if ((p.nodeName === 'B' || p.nodeName === 'STRONG') && !/(?:^|[;\s])font-weight\s*:\s*(?:normal|[1-4]00)/.test((p.getAttribute('style') || '').toLowerCase())) {
        return true;
      }
    }
    return false;
  }

  /** span/font со style → вложенные strong, em, sup, sub (Google Docs, Word Online): содержимое переносится внутрь, span снимется позже */
  function semantic(el, style) {
    const wrap = [];
    if (/(?:^|[;\s])font-weight\s*:\s*(?:bold|bolder|[6-9]00)/.test(style) && !hasBoldAncestor(el)) {
      wrap.push('strong');
    }
    if (/(?:^|[;\s])font-style\s*:\s*italic/.test(style) && !hasAncestor(el, ['I', 'EM'])) {
      wrap.push('em');
    }
    if (/(?:^|[;\s])vertical-align\s*:\s*super/.test(style)) {
      wrap.push('sup');
    } else if (/(?:^|[;\s])vertical-align\s*:\s*sub/.test(style)) {
      wrap.push('sub');
    }
    if (!wrap.length || !el.hasChildNodes()) {
      return;
    }
    let inner = null;
    let outer = null;
    wrap.forEach((name) => {
      const node = el.ownerDocument.createElement(name);
      if (inner) {
        inner.appendChild(node);
      } else {
        outer = node;
      }
      inner = node;
    });
    while (el.firstChild) {
      inner.appendChild(el.firstChild);
    }
    el.appendChild(outer);
  }

  /**
   * Жирный, курсив и индексы, заданные в style (Google Docs, Word Online), → strong, em, sup, sub. Нужно делать на строке HTML до обработки
   * вставки: штатный редактор убирает style из вставки браузера раньше, чем до неё дойдёт PastePostProcess. Обёртка Google Docs
   * <b style="font-weight:normal"> снимается: это не жирный шрифт.
   */
  function convertStyles(html) {
    if (!/font-weight|font-style|vertical-align/i.test(html)) {
      return html;
    }
    const doc = new DOMParser().parseFromString(html, 'text/html');
    Array.from(doc.body.querySelectorAll('b, strong, span, font')).reverse().forEach((el) => {
      const style = (el.getAttribute('style') || '').toLowerCase();
      if ((el.nodeName === 'B' || el.nodeName === 'STRONG') && /(?:^|[;\s])font-weight\s*:\s*(?:normal|[1-4]00)/.test(style)) {
        unwrap(el);
      } else if ((el.nodeName === 'SPAN' || el.nodeName === 'FONT') && style) {
        semantic(el, style);
      }
    });
    return doc.body.innerHTML;
  }

  /* ---------------------------------------------------------------- абзацы */

  function emptyChunk(nodes) {
    return nodes.every((n) => {
      if (n.nodeType === 3) {
        return !n.nodeValue.replace(/[\s\u00a0]+/g, '');
      }
      if (n.nodeType !== 1) {
        return true;
      }
      return !(n.textContent || '').replace(/[\s\u00a0]+/g, '') && !n.matches(MEDIA) && !n.querySelector(MEDIA);
    });
  }

  function anchorsOf(nodes) {
    const found = [];
    nodes.forEach((n) => {
      if (n.nodeType !== 1) {
        return;
      }
      if (n.nodeName === 'A' && n.hasAttribute('id')) {
        found.push(n);
      }
      n.querySelectorAll('a[id]').forEach((a) => found.push(a));
    });
    return found;
  }

  function splitParagraph(doc, p) {
    const chunks = [[]];
    Array.from(p.childNodes).forEach((n) => {
      if (n.nodeName === 'BR') {
        chunks.push([]);
        n.remove();
        return;
      }
      chunks[chunks.length - 1].push(n);
    });
    const real = chunks.filter((c) => !emptyChunk(c));
    if (real.length <= 1) {
      return;
    }
    real.forEach((chunk, i) => {
      const np = doc.createElement('p');
      if (i === 0) {
        for (const a of Array.from(p.attributes)) {
          np.setAttribute(a.name, a.value);
        }
      }
      chunk.forEach((n) => np.appendChild(n));
      p.parentNode.insertBefore(np, p);
    });
    p.remove();
  }

  function wrapRuns(doc, c, always) {
    const segments = [];
    const brs = [];
    let current = [];
    Array.from(c.childNodes).forEach((n) => {
      if (n.nodeName === 'BR') {
        brs.push(n);
        if (current.length) {
          segments.push(current);
          current = [];
        }
        return;
      }
      if (n.nodeType === 1 && BLOCK.has(n.nodeName)) {
        if (current.length) {
          segments.push(current);
          current = [];
        }
        return;
      }
      current.push(n);
    });
    if (current.length) {
      segments.push(current);
    }
    const real = segments.filter((s) => !emptyChunk(s));
    if (!always && real.length < 2) {
      brs.forEach((br) => br.parentNode && br.remove());
      return;
    }
    segments.forEach((segment) => {
      if (emptyChunk(segment)) {
        const anchors = anchorsOf(segment);
        segment.forEach((n) => n.parentNode && n.parentNode.removeChild(n));
        if (anchors.length) {
          const target = Array.from(c.childNodes).find((s) => s.nodeType === 1 && /^(?:P|H[1-6]|LI)$/.test(s.nodeName));
          if (target) {
            anchors.reverse().forEach((a) => target.insertBefore(a, target.firstChild));
          }
        }
        return;
      }
      const p = doc.createElement('p');
      segment[0].parentNode.insertBefore(p, segment[0]);
      segment.forEach((n) => p.appendChild(n));
    });
    brs.forEach((br) => br.parentNode && br.remove());
  }

  /** <p> вместо div и br; пустых абзацев не бывает; в пункте списка и ячейке абзацы только при двух и больше строках */
  function paragraphs(root) {
    const doc = root.ownerDocument;
    root.querySelectorAll('br').forEach((br) => {
      const name = br.parentNode.nodeName;
      if (/^H[1-6]$/.test(name) || INLINE_BR.has(name)) {
        br.replaceWith(doc.createTextNode(' '));
      }
    });
    Array.from(root.querySelectorAll('p')).filter((p) => Array.from(p.childNodes).some((n) => n.nodeName === 'BR')).forEach((p) => splitParagraph(doc, p));
    Array.from(root.querySelectorAll('blockquote, li, td, th, caption, div[id]')).reverse().forEach((c) => {
      wrapRuns(doc, c, c.nodeName === 'BLOCKQUOTE' || c.nodeName === 'DIV');
    });
    wrapRuns(doc, root, true);
  }

  /* ---------------------------------------------------------------- очистка фрагмента */

  /** Очистка фрагмента, вставляемого в редактор. root — узел с вставляемым содержимым. */
  function cleanNode(root) {
    // комментарии (условные блоки Word) и служебные элементы
    const walker = root.ownerDocument.createTreeWalker(root, NodeFilter.SHOW_COMMENT);
    const comments = [];
    while (walker.nextNode()) {
      comments.push(walker.currentNode);
    }
    comments.forEach((c) => c.parentNode && c.parentNode.removeChild(c));
    root.querySelectorAll('style, script, meta, link, title, xml, head').forEach((n) => n.remove());
    Array.from(root.querySelectorAll('*')).filter((n) => /^(?:o|w|v|m|st1|x):/i.test(n.nodeName)).forEach((n) => (n.childNodes.length ? unwrap(n) : n.remove()));

    let seen = false;

    // на какие якоря есть ссылки
    const targets = new Set();
    root.querySelectorAll('a[href^="#"]').forEach((a) => {
      const h = a.getAttribute('href');
      if (h.length > 1) {
        try { targets.add(decodeURIComponent(h.slice(1))); } catch (e) { targets.add(h.slice(1)); }
      }
    });

    Array.from(root.querySelectorAll('*')).reverse().forEach((el) => {
      if (!el.parentNode) {
        return;
      }
      let tag = el.nodeName;
      const style = (el.getAttribute('style') || '').toLowerCase();

      // картинка-эмодзи (веб-Telegram: <img class="emoji" alt="🔥">) становится самим эмодзи: так он может оказаться маркером списка; уберёт его общий проход
      if (stripEmoji && tag === 'IMG') {
        const alt = el.getAttribute('alt') || '';
        if (/emoji/i.test(el.getAttribute('class') || '') || (alt && !stripSymbols(alt, false, false).trim())) {
          el.replaceWith(el.ownerDocument.createTextNode(alt));
          seen = true;
          return;
        }
      }

      // корень SDK Facebook, метка вставки с чужим адресом, виджет Facebook
      if (tag === 'DIV' && el.id === 'fb-root') {
        el.remove();
        return;
      }
      if (tag === 'DIV' && el.classList.contains('tm-embed') && !embedAllowed(el.getAttribute('data-embed-src'))) {
        el.remove();
        return;
      }
      if (tag === 'DIV' && /(?:^|\s)fb-(post|video)(?:\s|$)/.test(el.getAttribute('class') || '') && el.hasAttribute('data-href')) {
        const href = el.getAttribute('data-href');
        if (/^https:\/\/(?:www\.|m\.|web\.)?facebook\.com\//i.test(href)) {
          const kind = el.classList.contains('fb-video') ? 'video' : 'post';
          el.replaceWith(embedNode(el.ownerDocument, 'https://www.facebook.com/plugins/' + kind + '.php?href=' + encodeURIComponent(href) + '&show_text=true&width=500', 500, kind === 'video' ? 300 : 620, href));
        } else {
          el.remove();
        }
        return;
      }

      // Google Docs оборачивает весь текст в <b style="font-weight:normal"> — это не жирный шрифт
      if ((tag === 'B' || tag === 'STRONG') && /(?:^|[;\s])font-weight\s*:\s*(?:normal|[1-4]00)/.test(style)) {
        unwrap(el);
        return;
      }
      if ((tag === 'SPAN' || tag === 'FONT') && style) {
        semantic(el, style);
      }

      // атрибуты
      if (tag === 'A' && el.hasAttribute('name') && !(el.getAttribute('id') || '').trim()) {
        el.setAttribute('id', el.getAttribute('name'));
      }
      const allowed = ATTRS[tag] || [];
      const cls0 = (el.getAttribute('class') || '').split(/\s+/);
      const social = tag === 'BLOCKQUOTE' && cls0.some((c) => SOCIAL.includes(c));
      const embed = tag === 'DIV' && cls0.includes('tm-embed');
      const wmarka = (tag === 'FIGURE' || tag === 'SPAN' || tag === 'A') && cls0.some((c) => WMARKA.test(c));
      for (const a of Array.from(el.attributes)) {
        const n = a.name.toLowerCase();
        const v = (a.value || '').trim();
        if (wmarka && tag === 'FIGURE' && (n === 'data-wm-video' || n === 'data-wm-title')) {
          continue;
        }
        // вставка соцсети (blockquote твита, Instagram, TikTok, Threads, Bluesky) и метка tm-embed несут данные в data-*: они остаются
        if ((social && (n.startsWith('data-') || n === 'cite')) || (embed && n.startsWith('data-embed-'))) {
          continue;
        }
        if (n === 'class') {
          const kept = v.split(/\s+/).filter((c) => c && (classAllowed(c) || (social && SOCIAL.includes(c)) || (embed && c === 'tm-embed') || (wmarka && WMARKA.test(c))));
          kept.length ? el.setAttribute('class', kept.join(' ')) : el.removeAttribute(a.name);
        } else if (n === 'id') {
          if (!v || JUNK_ID.test(v) || (WORD_ID.test(v) && !targets.has(v))) {
            el.removeAttribute(a.name);
          }
        } else if (allowed.includes(n)) {
          if (stripEmoji && (n === 'alt' || n === 'title') && v) {
            el.setAttribute(a.name, stripSymbols(v, false, false).trim());
          }
          const now = (el.getAttribute(a.name) || '').trim();
          if ((now === '' && n !== 'alt') || (['colspan', 'rowspan', 'span'].includes(n) && now === '1')) {
            el.removeAttribute(a.name);
          }
        } else {
          el.removeAttribute(a.name);
        }
      }

      if (tag === 'B' || tag === 'I') {
        el = rename(el, tag === 'B' ? 'strong' : 'em');
        tag = el.nodeName;
      }
      if (UNWRAP.has(tag) && !(tag === 'SPAN' && el.classList.contains('wm-figure__credit'))) {
        unwrap(el);
        return;
      }
      if (tag === 'IMG' && /^(?:file|cid|blob|about):/i.test((el.getAttribute('src') || '').trim())) {
        el.remove();
        return;
      }
      if (tag === 'A' && !el.hasAttribute('href')) {
        const id = el.getAttribute('id') || '';
        if (!id || (WORD_ID.test(id) && !targets.has(id))) {
          el.childNodes.length ? unwrap(el) : el.remove();
          return;
        }
      }
      if (tag === 'DIV' && !el.hasAttribute('id') && !el.hasAttribute('class')) {
        if (Array.from(el.children).some((c) => BLOCK.has(c.nodeName))) {
          unwrap(el);
        } else {
          rename(el, 'p');
        }
        return;
      }
      if (['STRONG', 'EM', 'SUP', 'SUB', 'S', 'STRIKE', 'DEL', 'INS', 'A'].includes(tag) && !el.childNodes.length && !el.hasAttribute('id')) {
        el.remove();
      }
    });

    // <p> вместо div и br
    paragraphs(root);

    // списки, набранные знаками, — настоящие ul/ol (пока эмодзи-маркеры ещё в тексте); затем эмодзи и значки из текста
    textLists(root);
    if (stripEmoji) {
      seen = symbolNodes(root) || seen;
    }

    // хештеги: в конце абзаца и целыми строками — целиком, остальные теряют только «#»
    if (stripEmoji && stripHashtags) {
      seen = hashtagTails(root) || seen;
      seen = hashtagMarks(root) || seen;
    }

    // после снятия значков и тегов рядом оказываются узлы текста: склеить и убрать пробел перед знаками препинания
    if (seen) {
      root.normalize();
      textNodes(root).forEach((t) => {
        const fixed = t.nodeValue.replace(/[ \t\u00a0]+([.,;:!?»)\]])/g, '$1');
        if (fixed !== t.nodeValue) {
          t.nodeValue = fixed;
        }
      });
    }

    // повторы пробелов и неразрывных пробелов; пробелы на краях блока
    textNodes(root).forEach((t) => {
      const v = trimEdges(t, t.nodeValue.replace(/(?:[ \t]*\u00a0[ \t]*){2,}|[ \t]{2,}/g, ' '));
      if (v !== t.nodeValue) {
        t.nodeValue = v;
      }
    });

    // ссылки открываются в новой вкладке
    links(root);

    // пустые абзацы
    if (dropEmpty) {
      root.querySelectorAll('p').forEach((p) => {
        if (p.querySelector(MEDIA + ', a, hr')) {
          return;
        }
        if (!(p.textContent || '').replace(/[\s\u00a0]+/g, '')) {
          p.remove();
        }
      });
    }
  }

  /** Список «Класс» в окне свойств таблицы: оформление UIkit 3 вместо правки кода */
  function tableClasses(editor) {
    if (!Array.isArray(options.tables) || !options.tables.length) {
      return;
    }
    // Joomla настраивает TinyMCE на относительные ссылки (/articles/a → articles/a), а на страницах сайта нет <base>: такая ссылка ведёт на /articles/articles/a.
    // Корневые пути без хоста (/articles/a) работают везде и переживают смену домена.
    try {
      editor.options.set('relative_urls', false);
      editor.options.set('remove_script_host', true);
    } catch (e) {
      /* параметры не приняты: внутренние ссылки останутся как их отдаёт Joomla */
    }
    try {
      editor.options.set('link_default_target', linkMode === 'off' ? '' : '_blank');
    } catch (e) {
      /* параметр не принят: ссылки получат target при сохранении */
    }
    try {
      editor.options.set('table_class_list', options.tables);
    } catch (e) {
      /* настройка не принята этой версией редактора — таблицы остаются без списка */
    }
  }

  function hook(editor) {
    if (editor.__wmarkaClean) {
      return;
    }
    editor.__wmarkaClean = true;
    editor.on('PastePreProcess', (e) => {
      const code = embedCode(e.content);
      e.content = convertStyles(convertWordLists(convertEmbeds(code !== null ? code : e.content)));
    });
    // код, введённый в окне «Исходный код»: iframe разрешённых хостов становятся метками, иначе редактор их вырежет
    editor.on('BeforeSetContent', (e) => {
      if (typeof e.content === 'string' && /<iframe|data-telegram-post|fb-post|fb-video/i.test(e.content)) {
        e.content = convertEmbeds(e.content);
      }
    });
    editor.on('PastePostProcess', (e) => cleanNode(e.node));
    editor.on('PreInit', () => tableClasses(editor));
    // «Показать блоки» включено с самого начала: структура текста (абзацы, списки, цитаты) видна сразу
    const blocksOn = () => {
      try {
        // у плагина блоков нет запроса состояния: признак включённости — класс на теле редактора
        if (!editor.getBody().classList.contains('mce-visualblocks')) {
          editor.execCommand('mceVisualBlocks');
        }
      } catch (e) {
        /* плагин блоков не загружен: остаётся как есть */
      }
    };
    editor.on('init', blocksOn);
    if (editor.initialized) {
      blocksOn();
    }
    if (editor.initialized) {
      tableClasses(editor);
    }
  }

  const attach = () => {
    if (!window.tinymce) {
      return false;
    }
    window.tinymce.on('AddEditor', (e) => hook(e.editor));
    (window.tinymce.editors || []).forEach(hook);
    return true;
  };

  if (!attach()) {
    // редактор загружается позже скрипта
    let tries = 0;
    const timer = setInterval(() => {
      if (attach() || ++tries > 100) {
        clearInterval(timer);
      }
    }, 100);
  }

  window.wmarkaCleanStripEmoji = (text) => stripSymbols(text, true, true);
  window.wmarkaCleanNode = cleanNode;      // для проверок и ручного вызова из консоли
  window.wmarkaCleanConvertWordLists = convertWordLists;
})();
