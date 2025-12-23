# Instructions pour Finaliser la Migration

## ✅ Ce qui a été fait

La nouvelle structure du projet est prête dans:
```
C:\Users\brucy\Desktop\DevDynamics-Project-New\
```

Structure:
```
DevDynamics-Project-New/
├── api/              ✓ (ancien backend/)
├── assets/           ✓ (de frontend/)
├── css/              ✓ (de frontend/)
├── js/               ✓ (de frontend/)
├── pages/            ✓ (de frontend/)
├── index.html        ✓ (de frontend/)
├── .dev/             ✓ (fichiers de développement)
│   ├── .gitkeep
│   └── reorganize-hostinger.php
├── .gitignore        ✓ (mis à jour)
└── README.md
```

Le commit a été créé avec le message:
```
Restructure project for Hostinger compatibility
```

## 📋 Étapes à Suivre

### 1. Fermer tous les programmes qui utilisent l'ancien projet

- [ ] Fermez VS Code complètement
- [ ] Arrêtez WAMP (clic droit → "Stop All Services")
- [ ] Fermez tous les navigateurs

### 2. Supprimer le lien symbolique WAMP actuel

Ouvrez PowerShell en tant qu'**Administrateur** et exécutez:

```powershell
Remove-Item "C:\wamp64\www\api" -Force
```

### 3. Remplacer l'ancien projet

```powershell
# Renommer l'ancien projet (sauvegarde)
Rename-Item "C:\Users\brucy\Desktop\DevDynamics-Project" "DevDynamics-Project-OLD-$(Get-Date -Format 'yyyyMMdd')"

# Renommer le nouveau projet
Rename-Item "C:\Users\brucy\Desktop\DevDynamics-Project-New" "DevDynamics-Project"
```

### 4. Créer le nouveau lien symbolique WAMP

Toujours en PowerShell Administrateur:

```powershell
New-Item -ItemType SymbolicLink -Path "C:\wamp64\www\api" -Target "C:\Users\brucy\Desktop\DevDynamics-Project\api"
```

**Note:** Le chemin a changé de `backend` à `api`!

### 5. Redémarrer WAMP et tester

1. Démarrez WAMP
2. Ouvrez votre navigateur
3. Testez: `http://localhost/api/organization/info`
4. Testez: `http://localhost` (devrait afficher la page d'accueil)

### 6. Pousser sur GitHub

```bash
cd C:\Users\brucy\Desktop\DevDynamics-Project
git push origin main
```

### 7. Sur Hostinger

Connectez-vous à Hostinger et dans `public_html/`:

```bash
cd ~/public_html
git pull origin main
```

**C'est tout!** Plus besoin de réorganisation, la structure est déjà correcte!

## ✅ Vérifications

Après la migration, vérifiez que:

- [ ] `http://localhost` affiche la page d'accueil
- [ ] `http://localhost/api/organization/info` retourne du JSON
- [ ] `http://localhost/pages/admin-login.html` affiche le login admin
- [ ] VSCode s'ouvre correctement dans le nouveau dossier
- [ ] Le lien symbolique fonctionne: `ls -la C:\wamp64\www\api`

## 🔙 En cas de problème

Si quelque chose ne fonctionne pas, vous pouvez revenir en arrière:

```powershell
# Restaurer l'ancien projet
Remove-Item "C:\Users\brucy\Desktop\DevDynamics-Project" -Recurse -Force
Rename-Item "C:\Users\brucy\Desktop\DevDynamics-Project-OLD-*" "DevDynamics-Project"

# Recréer l'ancien lien symbolique
Remove-Item "C:\wamp64\www\api" -Force
New-Item -ItemType SymbolicLink -Path "C:\wamp64\www\api" -Target "C:\Users\brucy\Desktop\DevDynamics-Project\backend"
```

## 📝 Avantages de la Nouvelle Structure

✅ **Structure identique** entre local et Hostinger
✅ **Git clone directement utilisable** sur Hostinger
✅ **Plus besoin de script** de réorganisation
✅ **Plus simple** à maintenir et comprendre
✅ **Fichiers de dev** isolés dans `.dev/`

## 🎯 Pour les Futurs Déploiements

Désormais, le workflow est simplifié:

1. **En local:** Développez normalement
2. **Commitez:** `git add . && git commit -m "message" && git push`
3. **Sur Hostinger:** `cd ~/public_html && git pull`
4. **C'est tout!**

Plus besoin de:
- ❌ Réorganiser les fichiers
- ❌ Exécuter des scripts de déploiement
- ❌ Déplacer manuellement les dossiers

---

**Une fois que tout fonctionne**, vous pouvez supprimer:
- `C:\Users\brucy\Desktop\DevDynamics-Project-OLD-*`
- Les fichiers `.ps1` et `.bat` dans `.dev/`
