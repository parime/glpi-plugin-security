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

Une page s'identifie par son chemin depuis le dossier du plugin (front/x.php), ou depuis la racine
de GLPI pour un objet du cœur (front/computer.form.php), sans sa requête. Exceptions justifiées,
dans le fichier d'exceptions : « crawl <identifiant> <page> : raison » (motifs * et ? acceptés).

Usage :
  python3 crawl-pages.py <url GLPI> --data <fichier JSON de crawl-data.php> --axe <axe.min.js>
                         [--account identifiant:mot-de-passe]... [--exceptions <fichier>]
                         [--max-ms 3000] [--mode error|warning]
Sortie : les anomalies (::error:: ou ::warning::), les pages les plus lentes, puis
« RESULT crawl pages=<n> anomalies=<n> » ; code 1 s'il reste une anomalie en mode error.
"""
import argparse
import fnmatch
import json
import re
import sys
from concurrent.futures import ProcessPoolExecutor
from urllib.parse import urljoin, urlsplit

from playwright.sync_api import sync_playwright

ERR = re.compile(r'Fatal error|Uncaught |Stack trace|Twig\\Error'
                 r'|Undefined (variable|array key|method)|Call to undefined|SQL error', re.I)
CRUMBS = 'nav ol.breadcrumb li.breadcrumb-item'
LAYOUT = 'aside.navbar, header.navbar'
MENU = 'aside.navbar a[href], header.navbar a[href], #navbar-menu a[href]'
# Formulaires, exports et actions s'ouvrent depuis une autre page : seuls les écrans comptent.
ACTION = re.compile(r'\.(form|export|pdf|send|report|ics|csv|import|download|ajax)\.php$|^front/(ajax|api)')
# Pages du cœur de référence de l'accessibilité : leurs violations (menus, en-tête) ne comptent pas.
AXE_REFERENCE = ['front/central.php', 'front/computer.php', 'front/plugin.php']
AXE_OPTIONS = {'runOnly': {'type': 'tag', 'values': ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']},
               'resultTypes': ['violations']}
MAX_PAGES = 1500


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
            hrefs = pg.eval_on_selector_all(selector, 'els => els.map(e => e.getAttribute("href") || "")')
        except Exception:
            return []
        out = []
        for href in hrefs:
            if href and not href.startswith(('#', 'javascript:', 'mailto:')):
                rel = self.rel(urljoin(pg.url, href))
                if rel and rel.startswith(self.prefix) and rel.split('?')[0].endswith('.php'):
                    out.append(rel)
        return out

    def open(self, pg, name, click_tabs):
        r = pg.goto(self.base + '/' + name, wait_until='load')
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
            res = pg.evaluate('async (o) => { const c = document.querySelector("main#page") || document;'
                              ' const r = await axe.run(c, o); return r.violations.filter(v =>'
                              ' ["critical", "serious"].includes(v.impact)).map(v => [v.id, v.nodes.map(n =>'
                              ' n.target.join(" ")), v.help]); }', AXE_OPTIONS)
        except Exception as exc:
            print('axe-core : %s' % str(exc).splitlines()[0][:160])
            return set()
        return {(rule, target, helptext) for rule, targets, helptext in res for target in targets}

    def check_crumbs(self, pg, name, admin, bad):
        try:
            if not pg.locator(LAYOUT).count():
                return False
            items = [norm(t) for t in pg.locator(CRUMBS).all_inner_texts()]
        except Exception:
            return False
        pid = self.page_id(name)
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
            return [('login', 'index.php', 'connexion impossible pour ' + login)], timings, set(), screens, 0
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
        outlinks = {}
        queue = menu + [self.prefix + p for p in self.front] + self.forms + self.core
        done, tabs_seen = set(), set()
        while queue and len(done) < MAX_PAGES:
            name = queue.pop(0)
            if name in done:
                continue
            done.add(name)
            found.clear()
            pid = self.page_id(name)
            try:
                r = self.open(pg, name, name in self.core)
            except Exception as exc:
                if 'Download is starting' not in str(exc):
                    bad.append(('http-500', pid, 'ouverture impossible : ' + str(exc).splitlines()[0][:120]))
                continue
            st = r.status if r else 0
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
                    if admin and urlsplit(name).path[len(self.prefix):] in self.front and '?' not in name:
                        screens.add(pid)
                    for rule, target, helptext in sorted(self.run_axe(pg) - axe_base):
                        bad.append(('axe:' + rule, pid, '%s : %s' % (helptext, target)))
            if st < 400:
                sub = self.links(pg, 'a[href]')
                linked.update(sub)
                outlinks[name] = sub
                if name in reachable:
                    reachable.update(sub)
                queue += [p for p in sub if p not in done]
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
        ctx.close()
        # Atteignables : menu, puis liens successifs (quel que soit l'ordre de visite).
        todo = list(reachable)
        while todo:
            for p in outlinks.get(todo.pop(), []):
                if p not in reachable:
                    reachable.add(p)
                    todo.append(p)
        return bad, timings, {self.page_id(p) for p in reachable}, screens, len(done)

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
        return {(rule, target, helptext) for rule, target, helptext in base}


def crawl_account(crawler, account, admin):
    login, _, password = account.partition(':')
    with sync_playwright() as p:
        browser = p.chromium.launch()
        try:
            axe_base = crawler.axe_reference(browser, login, password)
            return (login,) + crawler.crawl(browser, login, password, admin, axe_base)
        finally:
            browser.close()


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument('base')
    ap.add_argument('--key', required=True)
    ap.add_argument('--data', required=True)
    ap.add_argument('--axe', required=True)
    ap.add_argument('--account', action='append', default=[])
    ap.add_argument('--exceptions', default='')
    ap.add_argument('--max-ms', type=int, default=3000)
    ap.add_argument('--mode', default='error', choices=['error', 'warning'])
    args = ap.parse_args()
    args.base = args.base.rstrip('/')
    accounts = args.account or ['glpi:glpi', 'tech:tech']
    with open(args.data, encoding='utf-8') as f:
        lines = f.read().strip().splitlines()
    data = json.loads(lines[-1]) if lines else {}
    crawler = Crawler(args, data)
    exceptions = load_exceptions(args.exceptions)

    def excused(kind, pid):
        return any(fnmatch.fnmatchcase(kind, k) and fnmatch.fnmatchcase(pid, p) for k, p in exceptions)

    results = []
    with ProcessPoolExecutor(max_workers=len(accounts)) as pool:
        jobs = [pool.submit(crawl_account, crawler, a, i == 0) for i, a in enumerate(accounts)]
        results = [job.result() for job in jobs]

    level = 'error' if args.mode == 'error' else 'warning'
    reachable, screens, issues, seen = set(), set(), 0, set()
    for login, bad, _, reach, scr, _ in results:
        reachable |= reach
        screens |= scr
    admin_login = results[0][0]
    orphans = [('orphan', p, 'écran qu\'aucun menu ne permet d\'atteindre (ni lien depuis une page du menu)')
               for p in sorted(screens - reachable) if not ACTION.search(p)]
    for login, bad, _, _, _, _ in results:
        for kind, pid, msg in bad + (orphans if login == admin_login else []):
            if (login, kind, pid, msg) in seen:
                continue
            seen.add((login, kind, pid, msg))
            if excused(kind, pid):
                print('exception crawl %s %s (%s) : %s' % (kind, pid, login, msg))
                continue
            issues += 1
            print('::%s::crawl %s %s (%s) : %s' % (level, kind, pid, login, msg))
    pages = 0
    for login, _, timings, _, _, count in results:
        pages += count
        slow = sorted(timings, reverse=True)[:5]
        print('%s : %d pages, plus lentes : %s' % (login, count, ', '.join('%s %d ms' % (p, ms) for ms, p in slow)))
    print('RESULT crawl pages=%d anomalies=%d' % (pages, issues))
    return 1 if issues and args.mode == 'error' else 0


if __name__ == '__main__':
    sys.exit(main())
