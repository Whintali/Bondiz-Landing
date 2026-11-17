import { defineConfig, devices } from '@playwright/test';

/**
 * Tests de bout en bout du site vitrine (blog Symfony).
 *
 * Le site doit tourner avant de lancer les tests (par défaut sur
 * http://localhost:8080, voir `make up` dans blog/). L'espace partenaire a
 * besoin en plus de Strapi et d'un compte partenaire : voir README.md.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  timeout: 60_000,
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    // Connexion partenaire unique, réutilisée par espace-partenaire.spec.ts.
    { name: 'connexion', testMatch: /connexion\.setup\.ts/ },
    { name: 'ordinateur', use: { ...devices['Desktop Chrome'] }, dependencies: ['connexion'] },
    {
      name: 'mobile',
      use: { ...devices['Desktop Chrome'], viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true },
      // La vérification de tous les liens ne dépend pas de la taille d'écran.
      testIgnore: /liens\.spec\.ts/,
      dependencies: ['connexion'],
    },
  ],
});
