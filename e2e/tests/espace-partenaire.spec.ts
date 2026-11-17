import { test, expect, attendrePageValide, urlExacte, SESSION_PARTENAIRE } from './outils';
import type { Page } from '@playwright/test';

/**
 * Espace partenaire : navigation, boutons et parcours complet d'une activité
 * (création, modification, suppression). Il faut Strapi et un compte partenaire :
 *   E2E_PARTENAIRE_EMAIL=… E2E_PARTENAIRE_MOT_DE_PASSE=… npm test
 * Sans ces variables, ces tests sont ignorés.
 */

const EMAIL = process.env.E2E_PARTENAIRE_EMAIL;
const MOT_DE_PASSE = process.env.E2E_PARTENAIRE_MOT_DE_PASSE;

test.skip(!EMAIL || !MOT_DE_PASSE, 'E2E_PARTENAIRE_EMAIL et E2E_PARTENAIRE_MOT_DE_PASSE non définis');

const PAGES = [
  { menu: 'Tableau de bord', chemin: '/partenaires' },
  { menu: 'Mes activités', chemin: '/partenaires/activites' },
  { menu: 'Mes statistiques', chemin: '/partenaires/statistiques' },
  { menu: 'Mon établissement', chemin: '/partenaires/etablissement' },
  { menu: 'Messages', chemin: '/partenaires/messages' },
];

async function seConnecter(page: Page) {
  await page.goto('/partenaires/connexion');
  await page.getByPlaceholder('Adresse e-mail').fill(EMAIL!);
  await page.getByPlaceholder('Mot de passe').fill(MOT_DE_PASSE!);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await attendrePageValide(page, '/partenaires');
}

// Session ouverte une fois par connexion.setup.ts.
test.use({ storageState: SESSION_PARTENAIRE });

test.beforeEach(async ({ page }) => {
  await page.goto('/partenaires');
  await attendrePageValide(page, '/partenaires');
});

test('le menu latéral mène à chaque rubrique et marque la rubrique en cours', async ({ page }) => {
  const menu = page.locator('.bz-menu');
  test.skip(!(await menu.isVisible()), 'menu latéral masqué sur cet écran');

  for (const { menu: nom, chemin } of [...PAGES].reverse()) {
    await menu.getByRole('link', { name: nom, exact: true }).click();
    await attendrePageValide(page, chemin);
    await expect(menu.getByRole('link', { name: nom, exact: true })).toHaveAttribute('aria-current', 'page');
  }
});

test('le logo ramène au site public', async ({ page }) => {
  await page.getByRole('link', { name: 'Bondiz, retour au site' }).click();
  await attendrePageValide(page, '/');
});

test('le menu du compte affiche l’e-mail du partenaire', async ({ page }) => {
  await page.locator('.bz-compte__resume').click();
  await expect(page.locator('.bz-compte__email')).toHaveText(EMAIL!);
});

for (const { chemin } of PAGES) {
  test(`${chemin} : chaque bouton mène à destination`, async ({ page, baseURL }) => {
    await page.goto(chemin);
    const origine = new URL(baseURL!).origin;
    const liens = await page.locator('a[href]').evaluateAll((els) =>
      els
        .filter((a) => !a.closest('.bz-menu, .sf-toolbar, #tarteaucitronRoot'))
        .map((a) => ({ href: a.getAttribute('href')!, texte: ((a.textContent ?? '').trim() || a.getAttribute('aria-label') || '').replace(/\s+/g, ' ') })),
    );

    for (const lien of liens) {
      const url = new URL(lien.href, origine + chemin);
      // La déconnexion est testée à part.
      if (url.origin !== origine || /deconnexion/.test(url.pathname)) continue;
      await test.step(`« ${lien.texte} » → ${lien.href}`, async () => {
        expect(lien.href, `« ${lien.texte} » ne mène nulle part`).not.toMatch(/^#?$/);
        await page.goto(chemin);
        const cible = page.locator(`a[href="${lien.href}"]`).filter({ visible: true }).first();
        if ((await cible.count()) === 0) return;
        await cible.click();
        await expect(page).toHaveURL(urlExacte(url.pathname + url.search + url.hash));
        await expect(page.locator('h1').first()).toBeVisible();
      });
    }
  });
}

test('le choix de la période recharge le tableau de bord', async ({ page }) => {
  await page.locator('#periode').selectOption('8');
  await expect(page).toHaveURL(/\/partenaires\?semaines=8$/);
  await expect(page.locator('#periode')).toHaveValue('8');
});

test('les filtres de « Mes activités »', async ({ page }) => {
  await page.goto('/partenaires/activites');
  const filtres = page.getByRole('navigation', { name: 'Filtrer les activités' });

  await filtres.getByRole('link', { name: 'À venir' }).click();
  await expect(page).toHaveURL(/filtre=a-venir$/);
  await expect(filtres.getByRole('link', { name: 'À venir' })).toHaveAttribute('aria-current', 'page');

  await filtres.getByRole('link', { name: 'Passées' }).click();
  await expect(page).toHaveURL(/filtre=passees$/);

  await page.getByRole('link', { name: 'Voir tous' }).click();
  await attendrePageValide(page, '/partenaires/activites');
  await expect(filtres.getByRole('link', { name: 'Toutes mes activités' })).toHaveAttribute('aria-current', 'page');
});

test('« + Nouvelle activité », puis « Annuler » et « ← Mes activités » ramènent à la liste', async ({ page }) => {
  await page.goto('/partenaires/activites');
  await page.getByRole('link', { name: '+ Nouvelle activité' }).click();
  await attendrePageValide(page, '/partenaires/activites/nouvelle');

  await page.getByRole('link', { name: 'Annuler' }).click();
  await attendrePageValide(page, '/partenaires/activites');

  await page.goto('/partenaires/activites/nouvelle');
  await page.getByRole('link', { name: '← Mes activités' }).click();
  await attendrePageValide(page, '/partenaires/activites');
});

/**
 * Parcours complet avec sa propre connexion : les messages de confirmation
 * vivent dans la session, que les autres tests (en parallèle) partagent.
 */
test('parcours complet : créer, modifier, supprimer une activité puis se déconnecter', async ({ browser, baseURL }, testInfo) => {
  const contexte = await browser.newContext({ ...testInfo.project.use, storageState: { cookies: [], origins: [] } });
  // Choix de cookies déjà fait : sur mobile, le bandeau recouvrirait les boutons.
  await contexte.addCookies([{ name: 'bondiz_tarteaucitron', value: '!gtag=false', url: baseURL! }]);
  const page = await contexte.newPage();
  await seConnecter(page);

  const titre = `Activité E2E ${testInfo.project.name} ${Date.now()}`;
  const nouveauTitre = `${titre} (modifiée)`;
  const ligne = (t: string) => page.locator('.bz-ligne-activite').filter({ has: page.getByRole('heading', { name: t, exact: true }) });

  await test.step('création', async () => {
    await page.goto('/partenaires/activites/nouvelle');
    await page.getByLabel('Titre').fill(titre);
    await page.getByLabel(/^Lieu/).fill('Angers');
    await page.getByLabel(/^Début/).fill('2027-03-12T19:00');
    await page.getByLabel(/^Participants maximum/).fill('8');
    await page.getByRole('button', { name: 'Publier l’activité' }).click();

    await attendrePageValide(page, '/partenaires/activites');
    await expect(page.getByText(`« ${titre} » est en ligne`)).toBeVisible();
    await expect(ligne(titre)).toBeVisible();
    await expect(ligne(titre)).toContainText('Ven. 12 mars 2027 - 19h00');
  });

  await test.step('modification', async () => {
    await ligne(titre).getByRole('link', { name: 'Modifier' }).first().click();
    await expect(page).toHaveURL(/\/partenaires\/activites\/[^/]+\/modifier$/);
    await expect(page.getByLabel(/^Début/)).toHaveValue('2027-03-12T19:00');
    await page.getByLabel('Titre').fill(nouveauTitre);
    await page.getByRole('button', { name: 'Enregistrer' }).click();

    await attendrePageValide(page, '/partenaires/activites');
    await expect(page.getByText(`« ${nouveauTitre} » a été mise à jour`)).toBeVisible();
    await expect(ligne(nouveauTitre)).toBeVisible();
  });

  await test.step('suppression (menu « ⋮ »)', async () => {
    page.once('dialog', (dialogue) => dialogue.accept());
    await ligne(nouveauTitre).locator('summary').click();
    await ligne(nouveauTitre).getByRole('button', { name: 'Supprimer' }).click();

    await attendrePageValide(page, '/partenaires/activites');
    await expect(page.getByText('L’activité a été supprimée.')).toBeVisible();
    await expect(ligne(nouveauTitre)).toHaveCount(0);
  });

  await test.step('déconnexion', async () => {
    await page.getByRole('link', { name: 'Se déconnecter' }).click();
    await attendrePageValide(page, '/partenaires/connexion');
    await page.goto('/partenaires/activites');
    await attendrePageValide(page, '/partenaires/connexion');
  });

  await contexte.close();
});
