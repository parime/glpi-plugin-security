"""Parcours de toutes les pages d'un plugin GLPI dans un vrai navigateur (issue #23).

Repris du parcours de GRC Manager (glpi-iso27001-management#1050), rendu commun à tous les plugins.
Pour chaque compte (le premier est l'administrateur), ouvre dans Chromium (Playwright) l'accueil de
GLPI, les entrées du menu qui mènent au plugin, sa page de configuration, chaque fichier de front/,
une fiche existante de chaque formulaire et les objets du cœur auxquels il ajoute un onglet, puis
les sous-pages trouvées en chemin (liens vers le plugin). Anomalies, avec leur identifiant :

  http-500     page en erreur serveur (5xx)
  http-404     page proposée par un lien (menu, sous-page, fil d'Ariane) introuvable
  http-403     page refusée à l'administrateur, ou à un autre compte alors que son menu la propose
  php          erreur PHP ou Twig affichée dans la page
  blank        page blanche (200, HTML, aucun texte)
  js           erreur JavaScript ou boîte de dialogue, qui revient à une seconde visite
  ariane       fil d'Ariane absent ou incomplet, secteur où le plugin n'a pas d'entrée, ou, pour
               l'administrateur, différent de l'entrée de menu (ou de Configuration > Plugins)
  tab          onglet du plugin (fiches du plugin, objets du cœur) en erreur
  orphan       écran du plugin (front/, 200 dans la mise en page de GLPI) qu'aucun menu ne permet
               d'atteindre, même par des liens successifs ; formulaires et actions exclus
  axe:<règle>  violation d'accessibilité grave (axe-core, impact critical ou serious, WCAG 2.1 A et
               AA) dans le contenu de la page, hors violations déjà présentes sur les pages du cœur
  perf         temps de réponse du serveur au-delà du seuil (--max-ms)

Barres d'onglets des écrans du plugin (issue #34, mode propre --tabs-mode) : barres de liens ou
d'onglets (role=tablist, .nav-tabs, .nav-pills) du contenu, hors onglets des fiches du cœur :
  onglets-nombre  plus de 7 onglets visibles dans une barre
  onglets-lignes  barre sur plusieurs lignes à 1280 px de large
  onglets-role    barre sans role=tablist, ou onglet sans role=tab
  onglets-clavier onglet suivant inatteignable au clavier (Tab ou flèche droite)
  onglet-refuse   onglet vers une page refusée (403) au compte qui le voit
  onglet-inactif  onglet vers une page qui annonce un module, une fonction ou un référentiel désactivé

Une page s'identifie par son chemin depuis le dossier du plugin (front/x.php), ou depuis la racine
de GLPI pour un objet du cœur (front/computer.form.php), sans sa requête. Exceptions justifiées,
dans le fichier d'exceptions : « crawl <identifiant> <page> : raison » (motifs * et ? acceptés).

Parcours en tranches (issue #45) : « --shard k/N --report <fichier> » ne parcourt en profondeur que
les pages de la tranche k (répartition stable par empreinte SHA-1 de l'adresse, sans les jetons
CSRF), mais suit toutes les pages pour découvrir les mêmes liens que les autres tranches : celles
des autres tranches sont seulement demandées (requête HTTP, liens lus dans le HTML reçu). Un lien
que seul le navigateur voit (ajouté par JavaScript) est parcouru par la tranche qui le trouve. La
tranche écrit son rapport (JSON) sans rien juger ; « --merge <rapports>... » réunit les N rapports
puis applique les exceptions, les écrans orphelins et le résultat comme pour un parcours d'un seul
tenant. Sans --shard, parcours complet en une fois (comportement d'origine).

Usage :
  python3 crawl-pages.py <url GLPI> --key <clé> --data <fichier JSON de crawl-data.php> --axe <axe.min.js>
                         [--account identifiant:mot-de-passe]... [--exceptions <fichier>]
                         [--max-ms 3000] [--mode error|warning] [--tabs-mode error|warning]
                         [--shard k/N --report <rapport JSON>]
  python3 crawl-pages.py --merge <rapport JSON>... [--shards N] [--exceptions <fichier>]
                         [--mode error|warning] [--tabs-mode error|warning]
Sortie : les anomalies (::error:: ou ::warning::), les pages les plus lentes, puis
« RESULT crawl pages=<n> anomalies=<n> » ; code 1 s'il reste une anomalie en mode error.
"""
import argparse
import fnmatch
import hashlib
import html
import json
import re
import sys
from concurrent.futures import ProcessPoolExecutor
from urllib.parse import urljoin, urlsplit

ERR = re.compile(r'Fatal error|Uncaught |Stack trace|Twig\\Error'
                 r'|Undefined (variable|array key|method)|Call to undefined|SQL error', re.I)
CRUMBS = 'nav ol.breadcrumb li.breadcrumb-item'
LAYOUT = 'aside.navbar, header.navbar'
MENU = 'aside.navbar a[href], header.navbar a[href], #navbar-menu a[href]'
# Formulaires, exports et actions s'ouvrent depuis une autre page : seuls les écrans comptent.
ACTION = re.compile(r'\.(form|export|pdf|send|report|ics|csv|import|download|ajax)\.php$|^front/(ajax|api)')
# Pages du cœur de référence de l'accessibilité : leurs violations (menus, en-tête) ne comptent pas.
AXE_REFERENCE = ['front/central.php', 'front/computer.php', 'front/plugin.php', 'front/computer.form.php',
                 'front/ticket.form.php', 'front/config.form.php', 'front/crontask.php']
# Composants du cœur (en-tête des fiches, info-bulle des listes déroulantes, boutons de Tabler) dont
# les violations ne dépendent pas du plugin.
AXE_CORE = re.compile(r'^#header-friendlyname$|^\.status$|#comment_link_|\.btn-outline-[a-z]+|\.form-control\[readonly')
# Composants du cœur absents des pages de référence (issue #50), par règle : leur balisage est celui
# des macros et scripts de GLPI (identifiants aléatoires normalisés en #), pas celui du plugin. Un
# champ, une liste ou un bouton du plugin, de même défaut, garde une cible propre et reste signalé.
FLATPICKR = r'_#( > \.input|_input)$'
SELECT2 = r'\.select2-selection--(single|multiple)|\.select2-search__field|#select2-.+-container'
AXE_CORE_RULES = {
    # Sélecteur de date flatpickr, listes select2, envoi de fichiers (#fileupload<aléatoire>).
    'label': re.compile(FLATPICKR + '|' + SELECT2 + r'|^#fileupload#$'),
    'aria-allowed-attr': re.compile(FLATPICKR + r'|\.flatpickr-input'),  # aria-label du champ de date (GLPI 12)
    'aria-input-field-name': re.compile(SELECT2),
    # Nombre de lignes des listes (glpilist_limit) et sélecteur de tableau de bord (#dropdown_<aléatoire>).
    'select-name': re.compile(r'list_limit|^#dropdown_#$'),
    # Barre d'outils des tableaux de bord, champs de la fiche ITIL.
    'aria-prohibited-attr': re.compile(r'\.(auto-refresh|edit-dashboard|add-dashboard|ti-copy|itilstatus)\b'
                                       r'|^#time_to_resolve_#'),
    'list': re.compile(r'^\.list$'),  # grille des cartes des tableaux de bord
    # Boutons d'icône de la fiche ITIL (replier, largeur, filtre de la chronologie, action unique).
    'button-name': re.compile(r'\.(collapse-panel|switch-panel-width|open-timeline-filter-popover)\b|^#single-action$'),
    'aria-valid-attr-value': re.compile(r'button\[data-bs-target="#item-main"\]'),
    # Palette par défaut de Tabler et GLPI : boutons standard, statuts et avatars des fiches ITIL,
    # libellés des graphiques des tableaux de bord.
    'color-contrast': re.compile(r'\.btn-(success|info|warning)\b|\.status\.rounded-1|\.avatar\b'
                                 r'|^#chart[-_]# > \.main-label$'),
}
# Couleur principale de la palette par défaut « auror » : liens et boutons de la couleur du thème,
# quel que soit leur balisage (contraste lu par axe-core). Une couleur choisie par le plugin reste signalée.
AXE_CORE_COLORS = ['#fec95c']
# Composants du cœur reconnus dans la page elle-même (issue #50, cibles réelles du parcours de GRC) :
# axe-core ne donne souvent que « .input » ou « input[aria-label="…"] », sans le conteneur. L'élément
# signalé est du cœur s'il se trouve dans un de ces composants (Element.closest), règle par règle.
CORE_WIDGETS = '.flatpickr, .flatpickr-input, .flatpickr-calendar, .select2-container, .tox'
AXE_CORE_DOM = {
    'label': CORE_WIDGETS,
    'aria-allowed-attr': CORE_WIDGETS,
    'aria-input-field-name': '.select2-container',
    'select-name': 'select[name="glpilist_limit"], .dashboard',
    # Barre d'outils des tableaux de bord, éditeur TinyMCE, pied et champs de la fiche ITIL.
    'aria-prohibited-attr': '.dashboard, .tox, #itil-object-container .form-buttons, .itilstatus',
    'list': '.dashboard',
    'button-name': '#itil-object-container .form-buttons, .dashboard',
}
# Contrastes de la palette du thème (variables de Tabler lues dans la page) : texte ou fond d'une de
# ces couleurs, ou classe standard de Tabler de ces couleurs. Une couleur propre au plugin (style,
# classe du plugin) reste signalée.
PALETTE = ['primary', 'success', 'info', 'warning', 'danger', 'azure', 'green', 'link-color']
PALETTE_CLASS = (r'^(btn|btn-ghost|btn-outline|text|bg|badge|link|alert|status)-(primary|success|info|warning|danger'
                 r'|azure|green|dark-subtle|dark-emphasis)(-lt|-emphasis|-subtle)?$|^(status|avatar|badge)$')
AXE_JS = r'''async (o) => {
  const c = document.querySelector("main#page") || document;
  const r = await axe.run(c, o.options);
  const hex = (v) => { const e = document.createElement("i"); e.style.color = String(v || "").trim();
    if (!e.style.color) return ""; document.body.appendChild(e); const m = getComputedStyle(e).color.match(/\d+/g);
    e.remove(); return m ? "#" + m.slice(0, 3).map(x => (+x).toString(16).padStart(2, "0")).join("") : ""; };
  const root = getComputedStyle(document.documentElement);
  const colors = new Set(o.colors.concat(o.palette.map(n => hex(root.getPropertyValue("--tblr-" + n)))).filter(Boolean));
  const cls = new RegExp(o.paletteClass);
  const el = (n) => { try { return n.target.length === 1 ? document.querySelector(n.target[0]) : null; } catch (e) { return null; } };
  const core = (v, n) => {
    const e = el(n);
    if (e && o.dom[v.id] && e.closest(o.dom[v.id])) return true;
    if (v.id !== "color-contrast") return false;
    const data = (n.any || []).map(a => a.data).filter(Boolean);
    if (data.some(d => [d.fgColor, d.bgColor].some(x => colors.has(hex(x))))) return true;
    for (let p = e, i = 0; p && i < 3; p = p.parentElement, i++) {
      if ([...p.classList].some(k => cls.test(k))) return true;
    }
    return false;
  };
  return r.violations.filter(v => ["critical", "serious"].includes(v.impact))
    .map(v => [v.id, v.nodes.filter(n => !core(v, n)).map(n => n.target.join(" ")), v.help]);
}'''


def core_violation(rule, target):
    """Violation d'un composant du cœur de GLPI, qui ne dépend pas du plugin."""
    pattern = AXE_CORE_RULES.get(rule)
    return bool(AXE_CORE.search(target) or (pattern and pattern.search(target)))
AXE_OPTIONS = {'runOnly': {'type': 'tag', 'values': ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']},
               'resultTypes': ['violations']}
MAX_PAGES = 1500
MAX_TABS = 7
TABS_WIDTH = 1280
# Page d'un module désactivé : message de GLPI ou du plugin (français et anglais).
DISABLED = re.compile(r"(module|fonction(nalité)?|référentiel|feature|framework|connect(eur|or))[^.\n]{0,60}?"
                      r"(désactivé|desactive|inactif|disabled|not enabled|n'est pas activé)", re.I)
# Barres d'onglets du contenu, hors onglets des fiches (CommonGLPI : data-glpi-ajax-content).
BARS = '''const root = document.querySelector("main#page") || document.body;
  const sel = '[role="tablist"], ul.nav-tabs, ul.nav-pills, div.nav-tabs, div.nav-pills';
  const itemSel = 'a.nav-link, button.nav-link, [role="tab"]';
  const bars = [...new Set(root.querySelectorAll(sel))]
    .filter(b => !(b.parentElement && b.parentElement.closest(sel)))
    .map(b => [b, [...new Set(b.querySelectorAll(itemSel))]])
    .filter(([b, it]) => it.length >= 2 && !it.some(e => e.hasAttribute('data-glpi-ajax-content')))
    .map(([b, it]) => [b, it.filter(e => { const r = e.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; })])
    // Barre sans onglet visible (masquée par un ancêtre, comme le panneau « Recherches enregistrées »
    // du cœur GLPI) : rien à compter ni à parcourir au clavier (issue #46).
    .filter(([b, visible]) => visible.length > 0);'''
TAB_BARS_JS = '''() => { %s
  return bars.map(([bar, visible]) => ({
    role: bar.getAttribute('role') || '',
    label: (bar.getAttribute('aria-label') || visible.map(e => e.innerText.trim()).slice(0, 3).join(' | ')).slice(0, 80),
    count: visible.length,
    rows: new Set(visible.map(e => Math.round(e.getBoundingClientRect().top))).size,
    noTabRole: visible.filter(e => e.getAttribute('role') !== 'tab').length,
    hrefs: visible.map(e => e.getAttribute('href') || ''),
  }));
}''' % BARS
# Le premier onglet visible reçoit le focus ; Tab ou flèche droite doit le passer à un autre onglet.
TAB_FOCUS_JS = '''(index) => { %s
  const bar = bars[index];
  if (!bar || bar[1].length < 2) return false;
  window.__tabItems = bar[1];
  bar[1][0].focus();
  return document.activeElement === bar[1][0];
}''' % BARS


# Liens du HTML reçu (tranches) : balises <a href>, comme le sélecteur a[href] du navigateur.
RAW_LINK = re.compile(r'<a\b([^>]*?)\shref\s*=\s*(["\'])(.*?)\2([^>]*)>', re.I | re.S)
# Lien masqué du HTML reçu (classe bs-invisible, visibility: hidden), écarté comme dans links() (issue #50).
RAW_HIDDEN = re.compile(r'class\s*=\s*["\'][^"\']*\bbs-invisible\b|visibility\s*:\s*hidden', re.I)
# Paramètres propres à chaque instance de GLPI (jetons) : ignorés pour répartir les pages.
VOLATILE = re.compile(r'csrf|token', re.I)


def page_key(name):
    """Adresse d'une page sans ses jetons : la même dans chaque tranche, chacune ayant son GLPI."""
    path, _, query = name.partition('?')
    keep = [kv for kv in query.split('&') if kv and not VOLATILE.search(kv.split('=')[0])] if query else []
    return path + ('?' + '&'.join(keep) if keep else '')


def shard_of(name, count):
    """Tranche (1 à count) d'une page : empreinte stable de son adresse."""
    return int(hashlib.sha1(page_key(name).encode('utf-8')).hexdigest()[:8], 16) % count + 1


def norm(text):
    """Libellé sans espaces superflus ni compteur final (« Mon espace (8) »)."""
    return re.sub(r' \(\d+\)$', '', re.sub(r'\s+', ' ', text or '').strip())


def load_exceptions(path):
    out = []
    if not path:
        return out
    try:
        with open(path, encoding='utf-8') as f:
            lines = f.read().splitlines()
    except OSError:
        return out
    for line in lines:
        words = line.split()
        if len(words) >= 3 and words[0] == 'crawl':
            if ' : ' not in line:
                print('::error::exception sans raison : ' + line)
                continue
            out.append((words[1], words[2]))
    return out


class Crawler:
    def __init__(self, args, data):
        self.base = args.base
        self.prefix = data.get('prefix') or 'plugins/'
        self.front = list(data.get('front') or [])
        self.menu = {self.prefix + k: v for k, v in (data.get('menu') or {}).items()}
        self.sectors = {norm(s) for s in data.get('sectors') or []}
        config = data.get('config') or {}
        self.config = self.prefix + config['page'] if config.get('page') else None
        if self.config:
            self.menu.setdefault(self.config, config.get('crumbs') or [])
        forms = data.get('forms') or {}
        self.forms = ['%s?id=%d' % (p, n) for p, n in sorted(forms.items()) if p.startswith(self.prefix)]
        self.core = ['%s?id=%d' % (p, n) for p, n in sorted(forms.items()) if not p.startswith(self.prefix)]
        self.max_ms = args.max_ms
        self.shard, self.shards = args.shard_index, args.shard_count
        self.raw = ''
        with open(args.axe, encoding='utf-8') as f:
            self.axe = f.read()
        self.tab = ('[data-bs-toggle="tab"][data-glpi-ajax-content*="%s" i], [data-bs-toggle="tab"][href*="%s" i]'
                    % (args.key, args.key))

    def page_id(self, name):
        path = urlsplit(name).path
        return path[len(self.prefix):] if path.startswith(self.prefix) else path

    def rel(self, url):
        absolute = urljoin(self.base + '/', url)
        if not absolute.startswith(self.base + '/'):
            return None
        parts = urlsplit(absolute[len(self.base) + 1:])
        return parts.path + ('?' + parts.query if parts.query else '')

    def links(self, pg, selector):
        try:
            # Liens masqués (visibility: hidden, comme les liens bs-invisible vers id=-1 de l'en-tête de
            # navigation des fiches du cœur, issue #50) : personne ne peut les suivre. Les entrées des
            # menus repliés (display: none) restent suivies.
            hrefs = pg.eval_on_selector_all(selector, 'els => els.filter(e => !e.closest(".bs-invisible")'
                                            ' && getComputedStyle(e).visibility !== "hidden")'
                                            '.map(e => e.getAttribute("href") || "")')
        except Exception:
            return []
        out = []
        for href in hrefs:
            if href and not href.startswith(('#', 'javascript:', 'mailto:')):
                rel = self.rel(urljoin(pg.url, href))
                if rel and rel.startswith(self.prefix) and rel.split('?')[0].endswith('.php'):
                    out.append(rel)
        return out

    def raw_links(self, body, url):
        """Liens vers le plugin du HTML reçu du serveur (même filtre que links())."""
        out = []
        for before, _, href, after in RAW_LINK.findall(body or ''):
            if RAW_HIDDEN.search(before + ' ' + after):
                continue
            href = html.unescape(href).strip()
            if href and not href.startswith(('#', 'javascript:', 'mailto:')):
                rel = self.rel(urljoin(url, href))
                if rel and rel.startswith(self.prefix) and rel.split('?')[0].endswith('.php'):
                    out.append(rel)
        return out

    def light(self, pg, name):
        """Page d'une autre tranche : seulement demandée, pour en lire les liens (None si erreur)."""
        try:
            r = pg.request.get(self.base + '/' + name, timeout=60000)
            if r.status >= 400:
                return None
            if 'html' not in (r.headers.get('content-type', '') or 'text/html'):
                return []
            return self.raw_links(r.text(), r.url)
        except Exception:
            return None

    def open(self, pg, name, click_tabs):
        r = pg.goto(self.base + '/' + name, wait_until='load')
        if self.shards > 1:
            try:
                self.raw = r.text() if r else ''
            except Exception:
                self.raw = ''
        try:
            pg.wait_for_load_state('networkidle', timeout=2000)
        except Exception:
            pass
        if click_tabs:
            tabs = pg.locator(self.tab)
            for i in range(min(tabs.count(), 30)):
                try:
                    tabs.nth(i).click(timeout=5000)
                    pg.wait_for_load_state('networkidle', timeout=5000)
                except Exception:
                    pass
        return r

    def server_ms(self, pg):
        try:
            return pg.evaluate('(() => { const n = performance.getEntriesByType("navigation")[0];'
                               ' return n ? Math.round(n.responseStart - n.requestStart) : 0; })()')
        except Exception:
            return 0

    def run_axe(self, pg):
        """Violations graves (critical, serious) : ensemble de (règle, cible, résumé)."""
        try:
            if not pg.evaluate('typeof axe !== "undefined"'):
                pg.add_script_tag(content=self.axe)
            res = pg.evaluate(AXE_JS, {'options': AXE_OPTIONS, 'colors': AXE_CORE_COLORS, 'palette': PALETTE,
                                       'paletteClass': PALETTE_CLASS, 'dom': AXE_CORE_DOM})
        except Exception as exc:
            print('axe-core : %s' % str(exc).splitlines()[0][:160])
            return set()
        # Identifiants générés (comment_link_x_id1665379715) : chiffres normalisés pour comparer.
        return {(rule, re.sub(r'\d{3,}', '#', target), helptext) for rule, targets, helptext in res for target in targets}

    def check_crumbs(self, pg, name, admin, bad):
        try:
            if not pg.locator(LAYOUT).count():
                return False
            items = [norm(t) for t in pg.locator(CRUMBS).all_inner_texts()]
        except Exception:
            return False
        pid = self.page_id(name)
        if pid not in self.front:  # page servie par GLPI (formulaire générique) : son fil est celui du cœur
            return True
        if len(items) < 3:
            bad.append(('ariane', pid, 'fil d\'Ariane incomplet : %s' % ' > '.join(items)))
            return True
        if self.sectors and items[1] not in self.sectors:
            bad.append(('ariane', pid, 'secteur « %s » : le plugin n\'a d\'entrée que dans %s'
                        % (items[1], ', '.join(sorted(self.sectors)))))
            return True
        expected = self.menu.get(name) if admin else None
        if expected:
            got = items[1:1 + len(expected)]
            ok = len(got) == len(expected) and all(
                norm(e) == g or (g.endswith('...') and norm(e).startswith(g[:-3])) for g, e in zip(got, expected))
            if not ok:
                bad.append(('ariane', pid, 'attendu %s (menu), obtenu %s'
                            % (' > '.join(expected), ' > '.join(items[1:]))))
        return True

    def check_tabs(self, pg, admin, seen, bad):
        try:
            urls = pg.eval_on_selector_all(self.tab, 'els => els.map(e => e.getAttribute("data-glpi-ajax-content") || "")')
        except Exception:
            return
        for url in urls:
            if not url or url in seen:
                continue
            seen.add(url)
            m = re.search(r'_glpi_tab=([^&]+)', url)
            tid = 'tab:' + (m.group(1) if m else url)
            try:
                r = pg.request.get(urljoin(pg.url, url), headers={'X-Requested-With': 'XMLHttpRequest'}, timeout=60000)
                st, body = r.status, r.text()
            except Exception as exc:
                bad.append(('tab', tid, str(exc).splitlines()[0][:120]))
                continue
            e = ERR.search(body)
            if st >= 500 or st == 404 or (admin and st == 403) or e:
                bad.append(('tab', tid, 'code %d%s' % (st, ' : ' + e.group(0) if e else '')))

    def check_tab_bars(self, pg, pid, admin, bad, tablinks):
        # Barres d'onglets d'un écran du plugin : nombre, une ligne à 1280 px, rôles, clavier.
        try:
            pg.set_viewport_size({'width': TABS_WIDTH, 'height': 1000})
            pg.wait_for_timeout(100)
            bars = pg.evaluate(TAB_BARS_JS)
        except Exception:
            bars = []
        for index, bar in enumerate(bars):
            label = bar['label']
            if bar['count'] > MAX_TABS:
                bad.append(('onglets-nombre', pid, '%d onglets visibles dans la barre « %s » (au plus %d : regrouper '
                            'les écrans, ou les ranger en onglets des fiches)' % (bar['count'], label, MAX_TABS)))
            if bar['rows'] > 1:
                bad.append(('onglets-lignes', pid, 'barre « %s » sur %d lignes à %d px de large (une seule ligne '
                            'attendue)' % (label, bar['rows'], TABS_WIDTH)))
            if bar['role'] != 'tablist' or bar['noTabRole']:
                detail = ('role de la barre : « %s »' % bar['role'] if bar['role'] != 'tablist'
                          else '%d onglet(s) sans role=tab' % bar['noTabRole'])
                bad.append(('onglets-role', pid, 'barre « %s » : role=tablist sur la barre et role=tab sur chaque '
                            'onglet attendus (%s)' % (label, detail)))
            # Un seul onglet visible : pas d'onglet suivant vers lequel passer au clavier (issue #46).
            if admin and bar['count'] >= 2:
                try:
                    moved = False
                    if pg.evaluate(TAB_FOCUS_JS, index):
                        for key in ('Tab', 'ArrowRight'):
                            pg.evaluate('() => window.__tabItems[0].focus()')
                            pg.keyboard.press(key)
                            if pg.evaluate('() => window.__tabItems.slice(1).includes(document.activeElement)'):
                                moved = True
                                break
                    if not moved:
                        bad.append(('onglets-clavier', pid, 'barre « %s » : le focus ne passe pas d\'un onglet au '
                                    'suivant au clavier (Tab ou flèche droite)' % label))
                except Exception as exc:
                    print('clavier %s : %s' % (pid, str(exc).splitlines()[0][:120]))
            for href in bar['hrefs']:
                if href and not href.startswith(('#', 'javascript:')):
                    rel = self.rel(urljoin(pg.url, href))
                    if rel:
                        tablinks.append((pid, label, rel))
        try:
            pg.set_viewport_size({'width': 1440, 'height': 1000})
        except Exception:
            pass

    def check_tab_targets(self, pg, tablinks, status, disabled, bad):
        # Onglets vers une page refusée au compte (403) ou vers un module désactivé.
        for pid, label, rel in tablinks:
            if rel not in status:
                try:
                    r = pg.request.get(self.base + '/' + rel, timeout=60000)
                    status[rel] = r.status
                    disabled[rel] = bool(DISABLED.search(re.sub(r'<[^>]+>', ' ', r.text())))
                except Exception:
                    continue
            if status[rel] == 403:
                bad.append(('onglet-refuse', pid, 'onglet de la barre « %s » vers %s, refusé (403) à ce compte : '
                            'ne pas afficher un onglet que le profil ne peut pas ouvrir' % (label, self.page_id(rel))))
            elif disabled.get(rel):
                bad.append(('onglet-inactif', pid, 'onglet de la barre « %s » vers %s, qui annonce un module '
                            'désactivé : masquer l\'onglet tant que le module est désactivé' % (label, self.page_id(rel))))

    def crawl(self, browser, login, password, admin, axe_base):
        ctx = browser.new_context(viewport={'width': 1440, 'height': 1000})
        pg = ctx.new_page()
        pg.set_default_timeout(60000)
        bad, timings, screens = [], [], set()
        try:
            pg.goto(self.base + '/index.php')
            pg.fill('input[name="login_name"]', login)
            pg.fill('input[type="password"]', password)
            pg.click('button[type="submit"]')
            pg.wait_for_load_state('load')
            ok = 'login_name' not in pg.content()
        except Exception as exc:
            print('connexion de %s : %s' % (login, str(exc).splitlines()[0][:200]))
            ok = False
        if not ok:
            ctx.close()
            return {'login': login, 'bad': [('login', 'index.php', 'connexion impossible pour ' + login)],
                    'timings': [], 'menu': [], 'outlinks': {}, 'light': {}, 'screens': [], 'done': [], 'visited': []}
        found = []
        pg.on('pageerror', lambda e: found.append(str(e)[:160]))

        def on_dialog(dialog):
            found.append('boîte de dialogue %s : %s' % (dialog.type, dialog.message[:120]))
            dialog.dismiss()

        pg.on('dialog', on_dialog)
        pg.goto(self.base + '/front/central.php', wait_until='load')
        menu = self.links(pg, MENU)
        if admin:
            menu += [p for p in self.menu if p not in menu]
        # Pages proposées par un lien : elles doivent s'ouvrir. Un fichier de front/ ouvert sans
        # paramètre peut être une action (POST, jeton, identifiant obligatoire) : 400, 403 ou 404
        # y sont normaux, seuls 500, page blanche et erreurs comptent.
        reachable = set(menu)
        linked = set(menu)
        outlinks, light, variants = {}, {}, {}
        queue = menu + [self.prefix + p for p in self.front] + self.forms + self.core
        done, tabs_seen = set(), set()
        status, disabled, tablinks = {}, {}, []
        # Tranches : file commune à toutes (liens du HTML reçu), puis liens vus par le seul navigateur.
        sharded, adopting, visited, dom_only = self.shards > 1, False, [], []

        def enqueue(links):
            # Une même page avec d'autres paramètres (tri, pagination, fiches) : 5 variantes au plus.
            for p in links:
                path = p.split('?')[0]
                if p not in done and p not in queue and variants.get(path, 0) < 5:
                    variants[path] = variants.get(path, 0) + 1
                    queue.append(p)

        while True:
            if not queue and sharded and not adopting:
                adopting = True
                enqueue(dom_only)
            if not queue or len(done) >= MAX_PAGES:
                break
            name = queue.pop(0)
            if name in done:
                continue
            done.add(name)
            if sharded and not adopting and shard_of(name, self.shards) != self.shard:
                sub = self.light(pg, name)
                if sub is not None:
                    light[name] = sub
                    linked.update(sub)
                    if name in reachable:
                        reachable.update(sub)
                    enqueue(sub)
                continue
            visited.append(name)
            found.clear()
            pid = self.page_id(name)
            try:
                r = self.open(pg, name, name in self.core)
            except Exception as exc:
                if 'Download is starting' not in str(exc):
                    bad.append(('http-500', pid, 'ouverture impossible : ' + str(exc).splitlines()[0][:120]))
                continue
            st = r.status if r else 0
            status[name] = st
            ms = self.server_ms(pg)
            timings.append((ms, pid))
            if self.max_ms and ms > self.max_ms:
                bad.append(('perf', pid, '%d ms (seuil %d ms)' % (ms, self.max_ms)))
            try:
                body = pg.content()
                text = pg.evaluate('document.body ? document.body.innerText.trim().length : 0')
            except Exception:
                body, text = '', 1
            e = ERR.search(body)
            try:
                disabled[name] = bool(DISABLED.search(pg.inner_text('main#page', timeout=2000)))
            except Exception:
                disabled[name] = False
            ctype = (r.headers.get('content-type', '') if r else '') or 'text/html'
            strict = name in linked
            if st >= 500:
                bad.append(('http-500', pid, 'code %d%s' % (st, ' : ' + e.group(0) if e else '')))
            elif e:
                bad.append(('php', pid, e.group(0)))
            elif strict and st == 404:
                bad.append(('http-404', pid, 'page proposée par un lien, introuvable'))
            elif strict and st == 403 and (admin or name in reachable):
                bad.append(('http-403', pid, 'refusée à %s alors que %s la propose'
                            % (login, 'son menu' if name in reachable else 'un lien')))
            elif st == 200 and 'html' in ctype and not text:
                bad.append(('blank', pid, 'page blanche'))
            elif st == 200 and 'html' in ctype and name not in self.core:
                if self.check_crumbs(pg, name, admin, bad):
                    if pid in self.front:
                        self.check_tab_bars(pg, pid, admin, bad, tablinks)
                    if admin and urlsplit(name).path[len(self.prefix):] in self.front and '?' not in name:
                        screens.add(pid)
                    found_axe = {}
                    for rule, target, helptext in sorted(self.run_axe(pg)):
                        if (rule, target) not in axe_base and not core_violation(rule, target):
                            found_axe.setdefault((rule, helptext), []).append(target)
                    for (rule, helptext), targets in sorted(found_axe.items()):
                        bad.append(('axe:' + rule, pid, '%s : %d élément(s), %s'
                                    % (helptext, len(targets), ' ; '.join(targets[:3]))))
            if st < 400:
                sub = self.links(pg, 'a[href]')
                linked.update(sub)
                outlinks[name] = sub
                if name in reachable:
                    reachable.update(sub)
                if sharded and not adopting:
                    raw = self.raw_links(self.raw, pg.url)
                    enqueue(raw)
                    raw_set = set(raw)
                    dom_only += [p for p in sub if p not in raw_set]
                else:
                    enqueue(sub)
                self.check_tabs(pg, admin, tabs_seen, bad)
            if found:
                first = list(found)
                found.clear()
                try:
                    self.open(pg, name, name in self.core)
                    pg.wait_for_timeout(1500)
                except Exception:
                    pass
                if found:
                    bad.append(('js', pid, found[0]))
                else:
                    print('JS isolé (non retenu) %s %s : %s' % (login, pid, first[0]))
        self.check_tab_targets(pg, tablinks, status, disabled, bad)
        ctx.close()
        return {'login': login, 'bad': bad, 'timings': timings, 'menu': menu,
                'outlinks': outlinks, 'light': light, 'screens': sorted(screens),
                'done': sorted(done), 'visited': visited}

    def axe_reference(self, browser, login, password):
        ctx = browser.new_context(viewport={'width': 1440, 'height': 1000})
        pg = ctx.new_page()
        base = set()
        try:
            pg.goto(self.base + '/index.php')
            pg.fill('input[name="login_name"]', login)
            pg.fill('input[type="password"]', password)
            pg.click('button[type="submit"]')
            pg.wait_for_load_state('load')
            for page in AXE_REFERENCE:
                pg.goto(self.base + '/' + page, wait_until='load')
                base |= self.run_axe(pg)
        except Exception as exc:
            print('référence axe-core : %s' % str(exc).splitlines()[0][:160])
        ctx.close()
        # Une violation du cœur se reconnaît à sa règle et à sa cible, quel que soit le texte d'aide.
        return {(rule, target) for rule, target, _ in base}


def crawl_account(crawler, account, admin):
    from playwright.sync_api import sync_playwright  # inutile pour --merge (runner sans Playwright)
    login, _, password = account.partition(':')
    with sync_playwright() as p:
        browser = p.chromium.launch()
        try:
            axe_base = crawler.axe_reference(browser, login, password)
            return crawler.crawl(browser, login, password, admin, axe_base)
        finally:
            browser.close()


def merge(reports):
    """Réunit les rapports des tranches, compte par compte, comme un parcours d'un seul tenant."""
    prefix = reports[0]['prefix']

    def page_id(name):
        path = urlsplit(name).path
        return path[len(prefix):] if path.startswith(prefix) else path

    merged = []
    for i, first in enumerate(reports[0]['accounts']):
        parts = [r['accounts'][i] for r in reports]
        # Liens de chaque page : ceux du navigateur (tranche qui l'a parcourue), sinon ceux du HTML reçu.
        links = {}
        for part in parts:
            links.update(part['light'])
        for part in parts:
            links.update(part['outlinks'])
        # Atteignables : menu, puis liens successifs (quel que soit l'ordre de visite).
        reachable = set()
        for part in parts:
            reachable.update(part['menu'])
        todo = list(reachable)
        while todo:
            for p in links.get(todo.pop(), []):
                if p not in reachable:
                    reachable.add(p)
                    todo.append(p)
        visited, done = set(), set()
        for part in parts:
            visited.update(page_key(p) for p in part['visited'])
            done.update(page_key(p) for p in part['done'])
        merged.append({
            'login': first['login'],
            'bad': [tuple(b) for part in parts for b in part['bad']],
            'timings': [tuple(t) for part in parts for t in part['timings']],
            'reach': {page_id(p) for p in reachable},
            'screens': {s for part in parts for s in part['screens']},
            'count': len(visited),
            'missed': sorted(done - visited),
        })
    return merged


def verdict(results, args):
    """Anomalies retenues (exceptions, écrans orphelins, modes), pages les plus lentes, résultat."""
    exceptions = load_exceptions(args.exceptions)

    def excused(kind, pid):
        return any(fnmatch.fnmatchcase(kind, k) and fnmatch.fnmatchcase(pid, p) for k, p in exceptions)

    level = 'error' if args.mode == 'error' else 'warning'
    reachable, screens, issues, seen = set(), set(), 0, set()
    for res in results:
        reachable |= res['reach']
        screens |= res['screens']
    admin_login = results[0]['login']
    orphans = [('orphan', p, 'écran qu\'aucun menu ne permet d\'atteindre (ni lien depuis une page du menu)')
               for p in sorted(screens - reachable) if not ACTION.search(p)]
    for res in results:
        login = res['login']
        for kind, pid, msg in res['bad'] + (orphans if login == admin_login else []):
            if (login, kind, pid) in seen:  # une anomalie par page, compte et identifiant
                continue
            seen.add((login, kind, pid))
            if excused(kind, pid):
                print('exception crawl %s %s (%s) : %s' % (kind, pid, login, msg))
                continue
            if kind.startswith('onglet') and args.tabs_mode == 'warning':
                print('::warning::crawl %s %s (%s) : %s' % (kind, pid, login, msg))
                continue
            issues += 1
            print('::%s::crawl %s %s (%s) : %s' % (level, kind, pid, login, msg))
    pages = 0
    for res in results:
        pages += res['count']
        slow = sorted(res['timings'], reverse=True)[:5]
        print('%s : %d pages, plus lentes : %s' % (res['login'], res['count'],
                                                   ', '.join('%s %d ms' % (p, ms) for ms, p in slow)))
        if res['missed']:
            # Tranches en désaccord (page vue par l'une, pas par celle qui devait la parcourir).
            print('::warning::crawl %s : %d page(s) découverte(s) mais parcourue(s) par aucune tranche : %s'
                  % (res['login'], len(res['missed']), ', '.join(res['missed'][:10])))
    print('RESULT crawl pages=%d anomalies=%d' % (pages, issues))
    return 1 if issues and args.mode == 'error' else 0


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument('base', nargs='?')
    ap.add_argument('--key')
    ap.add_argument('--data')
    ap.add_argument('--axe')
    ap.add_argument('--account', action='append', default=[])
    ap.add_argument('--exceptions', default='')
    ap.add_argument('--max-ms', type=int, default=3000)
    ap.add_argument('--mode', default='error', choices=['error', 'warning'])
    ap.add_argument('--tabs-mode', default='error', choices=['error', 'warning'])
    ap.add_argument('--shard', default='1/1', help='tranche k/N parcourue en profondeur')
    ap.add_argument('--report', default='', help='rapport JSON de la tranche (aucun jugement)')
    ap.add_argument('--merge', nargs='+', default=None, help='rapports des tranches à réunir')
    ap.add_argument('--shards', type=int, default=0, help='nombre de tranches attendues (--merge)')
    args = ap.parse_args()
    level = 'error' if args.mode == 'error' else 'warning'

    if args.merge is not None:
        reports = []
        for path in args.merge:
            with open(path, encoding='utf-8') as f:
                reports.append(json.load(f))
        count = args.shards or reports[0]['shard'][1]
        got = sorted({r['shard'][0] for r in reports if r['shard'][1] == count})
        if got != list(range(1, count + 1)) or len(reports) != count:
            print('::%s::crawl : rapports des tranches incomplets (%d attendus, tranches présentes : %s)'
                  % (level, count, ', '.join(map(str, got)) or 'aucune'))
            print('RESULT crawl pages=0 anomalies=1')
            return 1 if args.mode == 'error' else 0
        reports.sort(key=lambda r: r['shard'][0])
        return verdict(merge(reports), args)

    m = re.fullmatch(r'(\d+)/(\d+)', args.shard)
    if not m or not 1 <= int(m.group(1)) <= int(m.group(2)):
        ap.error('--shard attend k/N, 1 <= k <= N')
    args.shard_index, args.shard_count = int(m.group(1)), int(m.group(2))
    if not (args.base and args.key and args.data and args.axe):
        ap.error('url GLPI, --key, --data et --axe sont obligatoires pour parcourir')
    args.base = args.base.rstrip('/')
    accounts = args.account or ['glpi:glpi', 'tech:tech']
    with open(args.data, encoding='utf-8') as f:
        lines = f.read().strip().splitlines()
    data = json.loads(lines[-1]) if lines else {}
    crawler = Crawler(args, data)

    with ProcessPoolExecutor(max_workers=len(accounts)) as pool:
        jobs = [pool.submit(crawl_account, crawler, a, i == 0) for i, a in enumerate(accounts)]
        accounts_res = [job.result() for job in jobs]
    report = {'shard': [args.shard_index, args.shard_count], 'prefix': crawler.prefix, 'accounts': accounts_res}

    if args.report:
        with open(args.report, 'w', encoding='utf-8') as f:
            json.dump(report, f)
        for res in accounts_res:
            print('tranche %s, %s : %d pages parcourues, %d demandées pour leurs liens, %d anomalie(s) brute(s)'
                  % (args.shard, res['login'], len(res['visited']), len(res['done']) - len(res['visited']),
                     len(res['bad'])))
        print('RESULT crawl shard=%s pages=%d' % (args.shard, sum(len(r['visited']) for r in accounts_res)))
        return 0
    return verdict(merge([report]), args)


if __name__ == '__main__':
    sys.exit(main())
