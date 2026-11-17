# Tests de bout en bout (Playwright)

Ils ouvrent le site dans un vrai navigateur (Chromium), sur ordinateur et en
mobile (375 px), et vérifient que chaque lien et chaque bouton mène au bon
endroit.

## Lancer les tests

1. Démarrer le blog (`make up` dans `blog/`) : il doit répondre sur
   http://localhost:8080 (ou définir `E2E_BASE_URL`).
2. Installer une fois les dépendances :

   ```bash
   npm install
   npx playwright install chromium
   ```

3. Lancer les tests :

   ```bash
   npm test
   ```

   Rapport détaillé (captures et traces des échecs) : `npm run report`.

## Espace partenaire

Ces tests ont besoin de Strapi et d'un compte partenaire. Sans ces deux
variables, ils sont ignorés :

```bash
E2E_PARTENAIRE_EMAIL=partenaire@test.local E2E_PARTENAIRE_MOT_DE_PASSE=… npm test
```

Ils créent, modifient puis suppriment une activité « Activité E2E … » : à
lancer sur un Strapi de développement, jamais sur la production. Strapi limite
les connexions (5 par minute) : la suite se connecte 3 fois, attendre une
minute entre deux lancements rapprochés.

## Ce qui est vérifié

| Fichier | Contenu |
| --- | --- |
| `liens.spec.ts` | Parcourt tout le site public : pages en erreur, liens vides ou « # », ancres cassées, fichiers à télécharger, liens externes en https, liens sans texte. |
| `navigation.spec.ts` | Clique sur le menu, le logo, le bouton « Espace partenaire », chaque lien du pied de page et chaque bouton du contenu de chaque page ; formulaires envoyés vides ; pages partenaires interdites aux visiteurs. |
| `cookies.spec.ts` | Bandeau de cookies : accepter, refuser, personnaliser (défilement du panneau à la molette), lien vers la politique de confidentialité, « Gérer mes cookies ». |
| `espace-partenaire.spec.ts` | Menu latéral, logo, menu du compte, chaque bouton de chaque rubrique, période du tableau de bord, filtres, création / modification / suppression d'une activité, déconnexion. |
