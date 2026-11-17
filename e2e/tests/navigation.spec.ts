import { test, expect, PAGES_PUBLIQUES, attendrePageValide, urlExacte } from './outils';

/**
 * Clique sur les boutons et les liens du site, comme un visiteur, et vérifie
 * qu'on arrive sur la bonne page. Tourne sur ordinateur et sur mobile (375 px).
 */

test.describe('chaque page publique', () => {
  for (const { chemin, titre } of PAGES_PUBLIQUES) {
    test(`${chemin} s'affiche`, async ({ page }) => {
      const reponse = await page.goto(chemin);
      expect(reponse?.status()).toBe(200);
      // Titre sur plusieurs lignes dans le HTML : on compare le texte normalisé.
      expect((await page.locator('h1').first().textContent())?.replace(/\s+/g, ' ').trim()).toBe(titre);
    });
  }
});

test.describe('en-tête', () => {
  const MENU = [
    { lien: 'Nos partenaires', chemin: '/nos-partenaires' },
    { lien: 'Articles', chemin: '/posts' },
    { lien: 'À propos', chemin: '/a-propos' },
    { lien: 'Accueil', chemin: '/' },
  ];

  test('le menu mène à chaque page et marque la page en cours', async ({ page }) => {
    await page.goto('/mentions-legales');
    const nav = page.getByRole('navigation', { name: 'Navigation principale' });

    for (const { lien, chemin } of MENU) {
      await nav.getByRole('link', { name: lien, exact: true }).click();
      await attendrePageValide(page, chemin);
      await expect(nav.getByRole('link', { name: lien, exact: true })).toHaveAttribute('aria-current', 'page');
    }
  });

  test('le logo ramène à l’accueil', async ({ page }) => {
    await page.goto('/a-propos');
    await page.locator('.bz-entete').getByRole('link').first().click();
    await attendrePageValide(page, '/');
  });

  test('le bouton « Espace partenaire » mène à la connexion partenaire', async ({ page }) => {
    await page.goto('/');
    await page.locator('.bz-entete').getByRole('link', { name: 'Espace partenaire' }).click();
    await attendrePageValide(page, '/partenaires/connexion');
    await expect(page.getByRole('button', { name: 'Se connecter' })).toBeVisible();
  });
});

test.describe('pied de page', () => {
  test('chaque lien interne du pied de page mène à une page valide', async ({ page, baseURL }) => {
    await page.goto('/');
    const pied = page.locator('footer');
    const liens = await pied.locator('a[href^="/"]').evaluateAll((els) =>
      els.map((a) => ({ href: a.getAttribute('href')!, texte: (a.textContent ?? '').trim() })),
    );
    expect(liens.length).toBeGreaterThan(5);

    for (const { href, texte } of liens) {
      await test.step(`« ${texte} » → ${href}`, async () => {
        await page.goto('/');
        await pied.locator(`a[href="${href}"]`).first().click();
        const attendu = new URL(href, baseURL);
        await expect(page).toHaveURL(urlExacte(attendu.pathname + attendu.search + attendu.hash));
        await expect(page.locator('h1').first()).toBeVisible();
        if (attendu.hash) {
          await expect(page.locator(attendu.hash)).toBeVisible();
        }
      });
    }
  });
});

/**
 * Tous les liens et boutons du contenu de chaque page (hors en-tête et pied de
 * page, testés à part) : un clic doit mener à la page annoncée.
 */
test.describe('boutons du contenu des pages', () => {
  for (const { chemin } of PAGES_PUBLIQUES) {
    test(`${chemin} : chaque bouton du contenu mène à destination`, async ({ page, baseURL }) => {
      await page.goto(chemin);
      const origine = new URL(baseURL!).origin;
      const contenu = page.locator('main, .bz-page > :not(header):not(footer)').first();
      const liens = await page.locator('a[href]').evaluateAll((els) =>
        els
          .filter((a) => !a.closest('header, footer, .sf-toolbar, #tarteaucitronRoot'))
          .map((a) => ({
            href: a.getAttribute('href')!,
            texte: ((a.textContent ?? '').trim() || a.getAttribute('aria-label') || '').replace(/\s+/g, ' '),
            download: a.hasAttribute('download'),
            nouvelOnglet: a.getAttribute('target') === '_blank',
          })),
      );
      test.skip(liens.length === 0, 'aucun lien dans le contenu de cette page');
      void contenu;

      for (const lien of liens) {
        const url = new URL(lien.href, origine + chemin);
        await test.step(`« ${lien.texte} » → ${lien.href}`, async () => {
          if (lien.href === '#' || lien.href === '') {
            expect.soft(lien.href, `« ${lien.texte} » ne mène nulle part`).not.toMatch(/^#?$/);
            return;
          }
          if (url.protocol === 'mailto:') {
            expect(url.pathname).toMatch(/@/);
            return;
          }
          if (url.origin !== origine) {
            // Lien externe : pas de visite, il doit être en https.
            expect(url.protocol).toBe('https:');
            return;
          }
          if (lien.download) {
            // Fichier à télécharger : vérifié dans liens.spec.ts.
            return;
          }

          await page.goto(chemin);
          const cible = page
            .locator(`a[href="${lien.href.replace(/"/g, '\\"')}"]`)
            .filter({ visible: true })
            .first();
          if ((await cible.count()) === 0) {
            test.info().annotations.push({ type: 'info', description: `« ${lien.texte} » masqué sur cet écran` });
            return;
          }
          await cible.click();
          await expect(page).toHaveURL(urlExacte(url.pathname + url.search + url.hash));
          const reponse = await page.request.get(url.pathname + url.search);
          expect(reponse.status(), `${url.pathname} répond`).toBe(200);
          if (url.hash && url.hash !== '#tarteaucitron') {
            await expect(page.locator(url.hash), `section ${url.hash}`).toBeAttached();
          }
        });
      }
    });
  }
});

test.describe('formulaires', () => {
  test('« Nous contacter » : envoyer un formulaire vide affiche les erreurs sans quitter la page', async ({ page }) => {
    await page.goto('/nous-contacter');
    await page.getByRole('button', { name: 'Envoyer un message' }).click();
    await expect(page).toHaveURL(/\/nous-contacter/);
    await expect(page.locator('.bz-demande__champ--erreur, .bz-formulaire__erreur, [class*="erreur"]').first()).toBeVisible();
  });

  test('« Devenir partenaire » : envoyer un formulaire vide affiche les erreurs sans quitter la page', async ({ page }) => {
    await page.goto('/devenir-partenaire');
    await page.getByRole('button', { name: 'Envoyer ma demande de partenariat' }).click();
    await expect(page).toHaveURL(/\/devenir-partenaire/);
    await expect(page.locator('.bz-demande__champ--erreur, [class*="erreur"]').first()).toBeVisible();
  });

  test('connexion partenaire : sans identifiants, on reste sur la connexion', async ({ page }) => {
    await page.goto('/partenaires/connexion');
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page).toHaveURL(/\/partenaires\/connexion/);
  });

  test('la lettre d’information ouvre la messagerie (formulaire mailto)', async ({ page }) => {
    await page.goto('/posts');
    const formulaire = page.locator('form.bz-lettre__formulaire');
    await expect(formulaire).toHaveAttribute('action', /^mailto:contact@bondiz\.fr/);
    await expect(formulaire.getByRole('button', { name: 'S’inscrire' })).toBeVisible();
  });
});

test.describe('protection des pages partenaires', () => {
  for (const chemin of ['/partenaires', '/partenaires/activites', '/partenaires/activites/nouvelle', '/partenaires/statistiques', '/partenaires/etablissement', '/partenaires/messages']) {
    test(`${chemin} renvoie un visiteur vers la connexion`, async ({ page }) => {
      await page.goto(chemin);
      await attendrePageValide(page, '/partenaires/connexion');
    });
  }
});

test('les pages légales ne contiennent plus de mention « à compléter »', async ({ page }) => {
  for (const chemin of ['/mentions-legales', '/politique-de-confidentialite', '/cookies']) {
    await page.goto(chemin);
    await expect(page.locator('main, body').first(), chemin).not.toContainText(/à compléter/i);
  }
});
