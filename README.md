# Site de DevDynamics

Site public et administration de [dev-dynamics.org](https://dev-dynamics.org).
HTML, CSS et JavaScript sans étape de construction, API en PHP et MySQL, hébergés sur Hostinger.
Un `git push` sur `main` déploie automatiquement.

## Organisation

- `index.html`, `pages/` : pages du site et de l'administration
- `css/site.css` : variables de couleur et composants ; `css/pages/admin.css` pour l'administration
- `js/` : client de l'API (`api.js`), scripts communs et scripts de page
- `api/` : routes (`api/routes/`), structure et migrations (`api/sql/`), bibliothèques (`api/lib/`)
- `tools/layout/` : en-tête et pied de page, recopiés dans les pages
- `uploads/` : images envoyées depuis l'administration, hors du dépôt
- `bousol/`, `keskle/` : applications indépendantes, non reliées au site

## Avant de livrer

```bash
php tools/build-layout.php          # recopie en-tête et pied de page
php tools/bump-assets-version.php   # force le rechargement : le cache des fichiers dure 7 jours
for t in design projects forms modules presence auth; do php tests/$t.php; done
```

## Serveur

`api/.env` n'est pas dans le dépôt : il se crée à la main depuis `api/.env.example`.
Sans lui, l'API répond 500. Sans `SMTP_PASSWORD`, les messages restent visibles dans
l'administration, mais aucune notification ne part.
