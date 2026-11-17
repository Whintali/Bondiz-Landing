import { test as setup, expect } from '@playwright/test';
import { SESSION_PARTENAIRE } from './outils';

/**
 * Connexion partenaire faite une seule fois, puis réutilisée par les tests de
 * l'espace partenaire : Strapi limite le nombre de connexions par minute.
 */

setup('connexion à l’espace partenaire', async ({ page }) => {
  const email = process.env.E2E_PARTENAIRE_EMAIL;
  const motDePasse = process.env.E2E_PARTENAIRE_MOT_DE_PASSE;
  setup.skip(!email || !motDePasse, 'E2E_PARTENAIRE_EMAIL et E2E_PARTENAIRE_MOT_DE_PASSE non définis');

  await page.goto('/partenaires/connexion');
  await page.getByPlaceholder('Adresse e-mail').fill(email!);
  await page.getByPlaceholder('Mot de passe').fill(motDePasse!);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page, 'connexion refusée : vérifier les identifiants, ou attendre si Strapi a limité les tentatives').toHaveURL(/\/partenaires$/);

  await page.context().storageState({ path: SESSION_PARTENAIRE });
});
