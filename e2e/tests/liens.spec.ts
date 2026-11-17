import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, PAGES_PUBLIQUES } from './outils';

/**
 * Parcourt tout le site public à partir des pages connues, en suivant chaque
 * lien interne, et vérifie que chaque lien mène quelque part.
 */

type Lien = { page: string; href: string; texte: string; download: boolean; cible: string | null };

/** Pages qu'on ne parcourt pas (administration, outils de dev, déconnexion). */
const IGNORES = [/^\/admin/, /^\/_(profiler|wdt)/, /deconnexion|logout/, /^\/partenaires(?!\/connexion)/, /^\/(login|register|verify)/];

/** Fichiers servis par le Nginx racine du site, pas par le blog (dossier public/ de la landing). */
const DOSSIER_PUBLIC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public');

const liens: Lien[] = [];
const statuts = new Map<string, number>();
const ancres = new Map<string, Set<string>>();

// Un seul parcours du site pour tous les tests du fichier, qui tournent à la suite.
test.describe.configure({ mode: 'default' });

test.beforeAll(async ({ browser, baseURL }) => {
  const contexte = await browser.newContext({ baseURL });
  await contexte.addCookies([{ name: 'bondiz_tarteaucitron', value: '!gtag=false', url: baseURL! }]);
  const page = await contexte.newPage();
  const origine = new URL(baseURL!).origin;

  const aVisiter: string[] = PAGES_PUBLIQUES.map((p) => p.chemin);
  const vues = new Set<string>();

  while (aVisiter.length > 0) {
    const chemin = aVisiter.shift()!;
    if (vues.has(chemin)) continue;
    vues.add(chemin);

    const reponse = await page.goto(chemin);
    statuts.set(chemin, reponse?.status() ?? 0);
    if (!reponse?.ok()) continue;
    // Chemin final, après une éventuelle redirection.
    const ici = new URL(page.url()).pathname + new URL(page.url()).search;

    ancres.set(ici, new Set(await page.$$eval('[id]', (els) => els.map((e) => e.id))));

    const trouves = await page.$$eval('a', (els) =>
      els
        .filter((a) => !a.closest('.sf-toolbar, #tarteaucitronRoot'))
        .map((a) => ({
          href: a.getAttribute('href') ?? '',
          texte: (a.textContent ?? '').trim().replace(/\s+/g, ' ') || a.getAttribute('aria-label') || '',
          download: a.hasAttribute('download'),
          cible: a.getAttribute('target'),
        })),
    );

    for (const lien of trouves) {
      liens.push({ page: ici, ...lien });
      if (!lien.href || lien.href.startsWith('#')) continue;
      const url = new URL(lien.href, origine + ici);
      if (url.origin !== origine) continue;
      const suivant = url.pathname + url.search;
      if (IGNORES.some((motif) => motif.test(url.pathname))) continue;
      if (lien.download || /\.[a-z0-9]{2,4}$/i.test(url.pathname)) continue;
      if (!vues.has(suivant)) aVisiter.push(suivant);
    }
  }
  await contexte.close();
});

const decrire = (l: Lien) => `${l.page} → « ${l.texte} » (${l.href || 'sans href'})`;

test('chaque page interne liée répond sans erreur', async () => {
  const enErreur = [...statuts].filter(([, statut]) => statut >= 400 || statut === 0);
  expect(statuts.size, 'nombre de pages parcourues').toBeGreaterThanOrEqual(PAGES_PUBLIQUES.length);
  expect(enErreur, 'pages en erreur').toEqual([]);
});

test('aucun lien vide ou provisoire (« # »)', async () => {
  const morts = liens.filter((l) => !l.href || l.href === '#' || l.href.startsWith('javascript:'));
  expect(
    [...new Set(morts.map((l) => `« ${l.texte} » (${l.href || 'sans href'})`))],
    `liens qui ne mènent nulle part, trouvés sur : ${[...new Set(morts.map((l) => l.page))].join(', ')}`,
  ).toEqual([]);
});

test('les ancres (#section) mènent à une section qui existe', async ({ baseURL }) => {
  const cassees = liens
    .filter((l) => l.href.includes('#') && l.href !== '#' && l.href !== '#tarteaucitron')
    .filter((l) => {
      const url = new URL(l.href, baseURL + l.page);
      if (url.origin !== new URL(baseURL!).origin) return false;
      const ids = ancres.get(url.pathname + url.search);
      return ids !== undefined && !ids.has(decodeURIComponent(url.hash.slice(1)));
    });
  expect(cassees.map(decrire)).toEqual([]);
});

test('les fichiers à télécharger existent', async ({ request, baseURL }) => {
  const fichiers = [...new Set(liens.filter((l) => l.download || /\.(apk|pdf|zip)$/i.test(l.href)).map((l) => l.href))];
  const manquants: string[] = [];
  for (const href of fichiers) {
    const reponse = await request.get(href, { maxRedirects: 0 });
    if (reponse.ok()) continue;
    // En local, seul le blog tourne : les fichiers du dossier public/ de la
    // landing sont servis par le Nginx racine en production.
    const local = path.join(DOSSIER_PUBLIC, new URL(href, baseURL).pathname);
    if (reponse.status() === 404 && fs.existsSync(local)) {
      test.info().annotations.push({ type: 'info', description: `${href} : absent du blog seul, présent dans public/ (servi par le Nginx racine)` });
      continue;
    }
    manquants.push(`${href} (${reponse.status()})`);
  }
  expect(fichiers.length).toBeGreaterThan(0);
  expect(manquants).toEqual([]);
});

test('les liens externes sont en https et les e-mails bien formés', async ({ baseURL }) => {
  const origine = new URL(baseURL!).origin;
  const fautifs = liens.filter((l) => {
    if (l.href.startsWith('mailto:')) return !/^mailto:[^@\s]+@[^@\s]+\.[a-z]{2,}(\?.*)?$/i.test(l.href);
    if (!/^https?:/.test(l.href)) return false;
    return new URL(l.href).origin !== origine && !l.href.startsWith('https://');
  });
  expect(fautifs.map(decrire)).toEqual([]);
});

test('chaque lien a un texte ou un libellé accessible', async () => {
  const muets = liens.filter((l) => !l.texte);
  expect([...new Set(muets.map(decrire))]).toEqual([]);
});
