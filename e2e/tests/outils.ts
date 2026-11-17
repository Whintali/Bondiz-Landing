import { test as base, expect, type Page } from '@playwright/test';

/** Session partenaire enregistrée par connexion.setup.ts. */
export const SESSION_PARTENAIRE = '.auth/partenaire.json';

/** Pages publiques du site, avec le titre principal attendu. */
export const PAGES_PUBLIQUES = [
  { chemin: '/', titre: 'MOINS DE GHOSTING. PLUS DE RENCONTRES' },
  { chemin: '/nos-partenaires', titre: 'ENSEMBLE, FAISONS VIVRE LES RENCONTRES À ANGERS' },
  { chemin: '/posts', titre: 'DES IDÉES, DES RENCONTRES ET UNE VILLE À (RE)DÉCOUVRIR' },
  { chemin: '/a-propos', titre: 'UNE IDÉE SIMPLE, UN VRAI BESOIN.' },
  { chemin: '/devenir-partenaire', titre: 'ET SI VOTRE ÉTABLISSEMENT DEVENAIT UN LIEU DE RENCONTRES ?' },
  { chemin: '/nous-contacter', titre: 'UNE QUESTION ? UNE IDÉE ? PARLONS-EN !' },
  { chemin: '/telecharger', titre: 'MOINS DE GHOSTING. PLUS DE RENCONTRES' },
  { chemin: '/mentions-legales', titre: 'MENTIONS LÉGALES' },
  { chemin: '/politique-de-confidentialite', titre: 'POLITIQUE DE CONFIDENTIALITÉ' },
  { chemin: '/cookies', titre: 'POLITIQUE DE GESTION DES COOKIES' },
  { chemin: '/partenaires/connexion', titre: 'Bienvenue !' },
] as const;

/**
 * Le bandeau de cookies recouvre le bas de la page : les tests partent avec
 * un choix déjà fait (mesure d'audience refusée), sauf ceux du bandeau.
 */
export const test = base.extend<{ sansBandeauCookies: void }>({
  sansBandeauCookies: [
    async ({ context, baseURL }, use) => {
      await context.addCookies([{ name: 'bondiz_tarteaucitron', value: '!gtag=false', url: baseURL! }]);
      await use();
    },
    { auto: true },
  ],
});

export { expect };

/** Vérifie qu'on est sur une vraie page du site (ni erreur, ni page blanche). */
export async function attendrePageValide(page: Page, chemin: string | RegExp) {
  await expect(page).toHaveURL(typeof chemin === 'string' ? urlExacte(chemin) : chemin);
  await expect(page.locator('h1').first()).toBeVisible();
  await expect(page.locator('body')).not.toContainText(/(Oops! An Error Occurred|404 Not Found|500 Internal Server Error)/);
}

/** URL qui finit exactement par ce chemin (ancre et paramètres compris). */
export function urlExacte(chemin: string): RegExp {
  return new RegExp(`^https?://[^/]+${chemin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`);
}

/**
 * Le script de cookies (tarteaucitron 1.20.1) affiche le bandeau puis ne
 * branche ses boutons que 500 ms plus tard (setTimeout dans sa source) : un
 * clic plus rapide ne fait rien. On attend qu'il ait posé son cookie, puis ce délai.
 */
export async function attendreBandeauCookies(page: Page) {
  await page.waitForFunction(() => document.cookie.includes('bondiz_tarteaucitron='));
  await page.waitForTimeout(600);
}
