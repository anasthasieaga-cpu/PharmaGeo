# Analyse Complète du Projet PharmaGeo

---

## 1. Architecture Générale

### 1.1 Structure des dossiers
```
PharmaGeo/
├── admin/              → Panneau administrateur (11 fichiers)
├── pharmacien/         → Panneau pharmacien (7 fichiers)
├── pages/patient/      → Espace patient (9 fichiers, 2 vides)
├── api/                → API REST (4 fichiers, TOUS VIDES)
├── assets/
│   ├── css/style.css   → Feuille de style unique (393 lignes)
│   ├── js/             → 3 fichiers JS (app.js, recherche.js, geolocalisation.js)
│   └── vendor/         → Dépendances third-party
├── includes/           → Composants partagés (config, session, header, footer, fonctions)
├── sql/                → Fichier SQL (VIDE)
├── index.php           → Page d'accueil
├── index_simple.php    → Version simplifiée de l'accueil
├── connexion.php       → Connexion
├── inscription.php     → Inscription (patient + pharmacien)
├── deconnexion.php     → Déconnexion
├── test_global.php     → Script de diagnostic
├── .htaccess           → Sécurité racine
└── .htpasswd           → Protection admin (basique)
```

### 1.2 Pattern d'architecture
Le projet suit un **pattern monolithique sans framework**, avec une approche **page-par-page** : chaque fichier PHP contient sa propre logique de connexion DB, session, et rendu HTML. Il n'y a **aucun routeur central** — la navigation se fait par liens directs vers les fichiers.

### 1.3 Rôles utilisateurs
- **administrateur** → Gère les utilisateurs, pharmacies, catégories, médicaments, paramètres, statistiques, réservations
- **pharmacien** → Gère sa pharmacie, ses stocks, ses médicaments, ses réservations, consulte ses statistiques
- **patient** → Recherche des médicaments, consulte la carte, fait des réservations, gère son profil

---

## 2. Base de Données

### 2.1 Informations de connexion
- **Moteur** : MySQL / MariaDB via PDO
- **Nom de la base** : `gestion_soutenance` (⚠️ pas "pharmageo")
- **Identifiants hardcodés** dans **tous les fichiers** sauf `connexion.php` : `root` / mot de passe vide (`""`) sur `localhost`
- Charset : `utf8mb4`

### 2.2 Tables identifiées (via les requêtes SQL dans le code)
| Table | Utilisée dans | Colonnes identifiées |
|---|---|---|
| `utilisateur` | Partout | `Id_utilisateur`, `Nom`, `Prenom`, `Email`, `Telephone`, `Role`, `Statut`, `Mot_de_passe`, `Date_inscription` |
| `pharmacien` | pharmacien/*.php, admin/*, pages/patient/carte.php | `Id_pharmacien`, `Id_utilisateur`, `Numero_ordre`, `Statut_validation` |
| `pharmacie` | pharmacien/*.php, pages/patient/carte.php, recherche.php | `Id_pharmacie`, `Id_pharmacien`, `Nom`, `Adresse`, `Telephone`, `Quartier`, `Commune`, `Latitude`, `Longitude`, `Horaire`, `Statut` |
| `medicament` | pharmacien/medicaments.php, admin/medicaments.php, recherche.php | `Id_medicament`, `Nom_commercial`, `DCI`, `Forme`, `Dosage`, `Description` |
| `medicament_synonyme` | recherche.php | `Id_medicament`, `Libelle` |
| `stock` | pharmacien/stocks.php, recherche.php | `Id_stock`, `Id_medicament`, `Id_pharmacie`, `Quantite`, `Prix`, `Statut` |
| `reservation` | pharmacien/reservations.php, admin/reservations.php, pages/patient/dashboard.php | `Id_reservation`, `Id_utilisateur`, `Id_pharmacie`, `Id_medicament`, `Statut`, `Date_reservation` |
| `recherche` | pages/patient/dashboard.php, recherche.php | `Id_recherche`, `Mot_recherche`, `Latitude`, `Longitude`, `Id_utilisateur` |
| `categorie` | admin/categories.php | `Id_categorie`, `Nom`, `Description` |
| `parametre` | admin/parametres.php | `Id_parametre`, `Cle`, `Valeur` |
| `notification` | pharmacien/dashboard.php | `Id_notification`, `Id_utilisateur`, `Message`, `Lu` |
| `historique_stock` | pharmacien/historique_stocks.php | `Id_historique`, `Id_stock`, `Id_pharmacie`, `Quantite_avant`, `Quantite_apres`, `Date_modification` |
| `journal_activite` | admin/statistiques.php | `Id_journal`, `Id_utilisateur`, `Action`, `Date_action` |

### 2.3 Fichier SQL
⚠️ **`sql/pharmageo.sql` est VIDE** (0 lignes). Il n'existe aucun script de migration ou de création de schéma. La structure de la base doit être déduite entièrement du code source.

---

## 3. Sécurité

### 3.1 Vulnérabilités critiques

#### 🔴 3.1.1 — Credential exposure (CRITIQUE)
Les identifiants de la base de données sont **hardcodés en clair** dans au moins **20+ fichiers PHP** :
- `pharmacien/dashboard.php:11` → `new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "")`
- `pages/patient/dashboard.php:11` → idem
- `pages/patient/carte.php:11` → idem
- `admin/dashboard.php`, `admin/categories.php`, `admin/medicaments.php`, etc. → idem
- Même dans `inscription.php:11`, `deconnexion.php:11`

Seul `connexion.php` utilise `require_once 'includes/config.php'` correctement.

#### 🔴 3.1.2 — error_reporting(E_ALL) + display_errors = 1 (CRITIQUE)
**Quasi tous les fichiers** activent l'affichage complet des erreurs PHP en production :
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```
Cela expose les chemins absolus, les noms de tables, les requêtes SQL, et les détails internes aux attaquants. Présent dans : `index.php:3-4`, `inscription.php:3-4`, `deconnexion.php:3-4`, `pharmacien/dashboard.php:3-4`, `pages/patient/dashboard.php:3-4`, `pages/patient/carte.php:3-4`, `admin/*.php`, etc.

#### 🟠 3.1.3 — SQL Injection potentiellement possible
La plupart des requêtes utilisent des **requêtes préparées PDO** avec paramètres liés (bonne pratique). Cependant :
- `pages/patient/carte.php:47` — `$pharmacies = $pdo->query("...WHERE p.Statut = 'active'...")` → pas de paramètre variable, OK
- `recherche.php:38-42` → Utilise correctement `:terme`, `:lat`, `:lng`
- **Mais** : la fonction `cleanInput()` dans `inscription.php` n'est **pas utilisée de manière cohérente** — certains fichiers l'utilisent, d'autres non. La plupart des fichiers utilisent `trim()` au lieu de `cleanInput()`.

#### 🟠 3.1.4 — Pas de protection CSRF
Aucun token CSRF n'est généré ou vérifié dans les formulaires de :
- Inscription (`inscription.php`)
- Connexion (`connexion.php`)
- Tous les formulaires dans `admin/*.php`
- Tous les formulaires dans `pharmacien/*.php`

#### 🟠 3.1.5 — Brute-force login sans limitation
`connexion.php` ne limite pas le nombre de tentatives de connexion échouées. Aucun verrouillage temporaire, aucun CAPTCHA.

#### 🟡 3.1.6 — XSS (Cross-Site Scripting)
Les valeurs des variables PHP sont **injectées directement dans le HTML** sans `htmlspecialchars()` dans de nombreux endroits. Par exemple :
- `index.php` affiche `$stats['total_pharmacies']` etc. sans échappement
- Les résultats de recherche sont affichés dans `recherche.php` et les pages patient

#### 🟡 3.1.7 — Session fixation potentielle
`session_start()` est appelé sans régénération d'ID de session après authentification dans `connexion.php`.

#### 🟡 3.1.8 — Mot de passe hashé ?
Dans `inscription.php`, on voit `password_hash()` utilisé (ligne ~150+), ce qui est correct. Mais dans `connexion.php`, on doit vérifier que `password_verify()` est utilisé côté connexion.

#### 🟢 3.1.9 — Protections existantes
- `.htaccess` racine bloque les fichiers `.sql`, `.log`, `.env`, `.git`, etc.
- `.htaccess` dans `includes/` interdit l'accès direct
- `.htpasswd` protège l'accès admin en HTTP Basic Auth
- `session_status()` vérification avant `session_start()`

---

## 4. Qualité du Code

### 4.1 Problème majeur : duplication massive du code de connexion

**Seul 1 fichier sur 25+ utilise les includes partagés :**
- `connexion.php:1-2` → `require_once 'includes/config.php'` + `require_once 'includes/session.php'`

**Tous les autres fichiers dupliquent ce bloc** (~15 lignes identiques) :
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
define('BASE_URL', 'http://localhost/pharmageo/');
try {
    $pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
```

Ce bloc copié-collé se retrouve dans :
- `index.php:1-16`
- `inscription.php:1-21`
- `deconnexion.php:1-21`
- `pharmacien/dashboard.php:1-21`
- `pharmacien/profil.php:1-21`
- `pharmacien/pharmacie.php:1-21`
- `pharmacien/medicaments.php:1-21`
- `pharmacien/stocks.php:1-21`
- `pharmacien/reservations.php:1-21`
- `pharmacien/statistiques.php:1-21`
- `pharmacien/historique_stocks.php:1-21`
- `pages/patient/dashboard.php:1-21`
- `pages/patient/carte.php:1-21`
- `pages/patient/profil.php:1-21`
- `pages/patient/historique.php:1-21`
- `admin/dashboard.php:1-21`
- `admin/categories.php:1-21`
- `admin/medicaments.php:1-21`
- `admin/parametres.php:1-21`
- `admin/utilisateurs.php:1-21`
- `admin/statistiques.php:1-21`
- `admin/pharmacies.php:1-21`
- `admin/reservations.php:1-21`
- `admin/inscription.php:1-21`

**Exception** : `pages/patient/recherche.php:3-5` est le **seul autre fichier** qui utilise correctement les includes :
```php
require_once '../../includes/config.php';
require_once '../../includes/session.php';
```

### 4.2 Fonctions manquantes
`includes/fonctions.php` est **VIDE**. Or les fonctions suivantes sont référencées :
- `estConnecte()` — utilisée dans `includes/header.php`
- `aRole()` — utilisée dans `includes/header.php`
- `cleanInput()` — définie dans `includes/config.php` mais aussi dans `inscription.php`
- `getIdUtilisateur()` — utilisée dans `includes/header.php`
- `requireAuth()` — utilisée dans `pages/patient/recherche.php`
- `requireRole()` — potentiellement utilisé

Ces fonctions sont soit :
- Définies localement dans chaque fichier (dupliquées), soit
- **Non définies nulle part**, causant des erreurs fatales

### 4.3 Incohérence des noms de fichiers/config
- `inscription.php:7` → `define('BASE_URL', 'http://localhost/pharmageo/')`
- `includes/config.php` → `define('BASE_URL', 'http://localhost/PharmaGeo/')`
- **Conflit** : `pharmageo` vs `PharmaGeo` — sur un serveur sensible à la casse (Linux), cela causerait des erreurs 404.

### 4.4 Taille des fichiers
Les fichiers PHP sont **considérablement longs** car ils mélangent PHP et HTML dans un même fichier :
| Fichier | Lignes |
|---|---|
| `pharmacien/stocks.php` | 1541 |
| `admin/statistiques.php` | 1470 |
| `admin/utilisateurs.php` | 1416 |
| `inscription.php` | 1419 |
| `pharmacien/medicaments.php` | 1354 |
| `pharmacien/dashboard.php` | 1308 |
| `pharmacien/reservations.php` | 1313 |
| `admin/reservations.php` | 1261 |
| `admin/pharmacies.php` | 1252 |
| `pages/patient/carte.php` | 1255 |
| `pages/patient/historique.php` | 1197 |
| `pharmacien/historique_stocks.php` | 1187 |
| `pharmacien/profil.php` | 1276 |
| `pharmacien/statistiques.php` | 1172 |
| `pages/patient/dashboard.php` | 1142 |
| `pages/patient/profil.php` | 1144 |
| `admin/inscription.php` | 1123 |

Aucune séparation MVC, Aucun modèle de donnée, Aucune couche d'abstraction.

---

## 5. Fonctionnalités

### 5.1 Fonctionnalités implémentées et fonctionnelles (en théorie)

#### Authentification
- **Inscription** (`inscription.php`) — inscription patient OU pharmacien avec pharmacy, validation complète des champs, hashage mot de passe, coordonnées GPS
- **Connexion** (`connexion.php`) — authentification par email+mot de passe, redirection par rôle
- **Déconnexion** (`deconnexion.php`) — destruction de session

#### Espace Patient
- **Dashboard** (`pages/patient/dashboard.php`) — stats personnelles (recherches, réservations), nav rapides
- **Recherche de médicaments** (`pages/patient/recherche.php`) — recherche par nom/DCI/synonyme avec calcul de distance haversine, enregistrement de la recherche
- **Carte** (`pages/patient/carte.php`) — carte Leaflet avec marqueurs pharmacies, icônes personnalisées, filtres
- **Profil** (`pages/patient/profil.php`) — visualisation/modification profil
- **Historique** (`pages/patient/historique.php`) — historique des recherches et réservations

#### Espace Pharmacien
- **Dashboard** (`pharmacien/dashboard.php`) — stats, notifications, indicateurs stock
- **Gestion pharmacie** (`pharmacien/pharmacie.php`) — infos pharmacy
- **Gestion médicaments** (`pharmacien/medicaments.php`) — CRUD médicaments
- **Gestion stocks** (`pharmacien/stocks.php`) — CRUD stocks avec alertes
- **Réservations** (`pharmacien/reservations.php`) — gestion des réservations reçues
- **Statistiques** (`pharmacien/statistiques.php`) — graphiques et métriques
- **Historique stocks** (`pharmacien/historique_stocks.php`) — traçabilité des mouvements

#### Espace Admin
- **Dashboard** (`admin/dashboard.php`) — vue d'ensemble globale
- **Utilisateurs** (`admin/utilisateurs.php`) — CRUD utilisateurs
- **Pharmacies** (`admin/pharmacies.php`) — validation/gestion pharmacies
- **Catégories** (`admin/categories.php`) — CRUD catégories médicaments
- **Médicaments** (`admin/medicaments.php`) — CRUD médicaments
- **Paramètres** (`admin/parametres.php`) — configuration système
- **Statistiques** (`admin/statistiques.php`) — métriques globales
- **Réservations** (`admin/reservations.php`) — vue globale des réservations
- **Inscription admin** (`admin/inscription.php`) — création d'un admin (protégé : accessible seulement si aucun admin actif existe)
- **Logs** (`admin/logs.php`) — **VIDE**
- **Validation** (`admin/validation.php`) — **VIDE**
- **Rapports** (`admin/rapports.php`) — **VIDE**

### 5.2 Fonctionnalités manquantes / vides
- ❌ **API REST** — Tous les 4 fichiers API sont vides :
  - `api/recherche_api.php` (0 lignes)
  - `api/geoloc_api.php` (0 lignes)
  - `api/reservation_api.php` (0 lignes)
  - `api/stock_api.php` (0 lignes)
- ❌ **Reservation patient** — `pages/patient/reservation.php` est **VIDE**
- ❌ **Résultats** — `pages/patient/resultats.php` est **VIDE**
- ❌ **Logs admin** — `admin/logs.php` est **VIDE**
- ❌ **Validation admin** — `admin/validation.php` est **VIDE**
- ❌ **Rapports admin** — `admin/rapports.php` est **VIDE**

### 5.3 Conséquence des API vides
Le fichier `assets/js/recherche.js:15` appelle :
```javascript
fetch(`/api/recherche_api.php?q=${encodeURIComponent(query)}`)
```
Cet appel échouera systématiquement car `api/recherche_api.php` est vide. L'**autocomplétion de recherche** côté patient est donc **complètement cassée**.

---

## 6. APIs

### 6.1 APIs prévues
Le projet prévoit 4 endpoints REST :

| Endpoint | Fichier | État |
|---|---|---|
| `GET /api/recherche_api.php?q=...` | `api/recherche_api.php` | **VIDE** |
| `GET /api/geoloc_api.php?lat=...&lng=...` | `api/geoloc_api.php` | **VIDE** |
| `POST /api/reservation_api.php` | `api/reservation_api.php` | **VIDE** |
| `GET/POST /api/stock_api.php` | `api/stock_api.php` | **VIDE** |

### 6.2 Consommation côté client
Le JS (`assets/js/recherche.js`) est prêt à consommer ces APIs (format JSON attendu), mais elles ne retournent rien. La géolocalisation (`assets/js/geolocalisation.js`) fonctionne en natif (Leaflet + OpenStreetMap + navigator.geolocation) sans passer par l'API.

---

## 7. Frontend

### 7.1 Technologies
- **Bootstrap 5** (CDN) — layout responsive, composants UI
- **Font Awesome 6** (CDN) — icônes
- **Leaflet.js** — carte interactive OpenStreetMap
- **Chart.js** — graphiques (utilisé dans les pages statistiques)
- **Google Fonts** — Segoe UI, Tahoma fallback

### 7.2 Design
- **CSS unique** : `assets/css/style.css` (393 lignes)
- Palette : vert `rgb(42,195,42)` + orange `hsl(25,75%,47%)` + blanc + gris clair
- Animations : `fadeIn`, `slideInLeft`, `pulse` (keyframes CSS)
- Design responsive avec Bootstrap grid
- Carte Leaflet avec icônes personnalisées (pharmacies en vert, sélection en orange, utilisateur en bleu)

### 7.3 JavaScript
- `app.js` (157 lignes) — tooltips Bootstrap, animations au scroll, gestion notifications
- `recherche.js` (109 lignes) — autocomplétion avec debounce, appels API (cassés car API vides)
- `geolocalisation.js` (178 lignes) — carte Leaflet, géolocalisation utilisateur, marqueurs pharmacies, filtrage

### 7.4 Problèmes frontend
- Les fichiers PHP mélangent PHP et HTML → pas de templating
- Le menu navigation est dupliqué dans `includes/header.php` mais les dashboards admin/pharmacien/patient **redéfinissent leur propre navigation sidebar** en HTML inline
- Pas de thème sombre
- Les pages longues (1000+ lignes) rendent la maintenance difficile

---

## 8. Bugs Critiques et Problèmes Majeurs

### 🔴 CRITIQUE

#### Bug 1 — Fonctions PHP non définies (ERREUR FATALE)
`includes/fonctions.php` est **vide**. Les fonctions `estConnecte()`, `aRole()`, `getIdUtilisateur()` sont appelées dans `includes/header.php` (qui est inclus par les fichiers utilisant les includes). Or :
- Ces fonctions ne sont pas définies dans `includes/fonctions.php`
- Seul `connexion.php` et `pages/patient/recherche.php` incluent les fichiers partagés
- Les autres fichiers redéfinissent leur propre version inline de ces fonctions
- **Résultat** : les fichiers qui utilisent `includes/header.php` sans avoir défini ces fonctions auront une erreur fatale

#### Bug 2 — Conflit BASE_URL (casse)
- `includes/config.php` : `BASE_URL = 'http://localhost/PharmaGeo/'`
- Tous les fichiers dupliqués : `BASE_URL = 'http://localhost/pharmageo/'`
- Sur Linux (sensible à la casse), les redirections et liens会pointent vers des URLs incorrectes

#### Bug 3 — API autocomplétion cassée
`assets/js/recherche.js:15` appelle `api/recherche_api.php` qui est vide → l'autocomplétion de recherche est **totalement inopérante**.

#### Bug 4 — Affichage des erreurs en production
`error_reporting(E_ALL)` + `display_errors = 1` dans **tous les fichiers** → expose les détails internes (chemins DB, noms de tables, requêtes SQL).

#### Bug 5 — Credentials DB exposés dans 25+ fichiers
Même si `.gitignore` bloque `.env`, les identifiants `root/""` sont en dur dans chaque fichier PHP. Si le code source est compromis, la base est immédiatement accessible.

### 🟠 MAJEUR

#### Bug 6 — Aucun token CSRF
Les formulaires de connexion, inscription, et toutes les actions POST dans admin/pharmacien ne protègent pas contre les attaques CSRF.

#### Bug 7 — Pas de limitation tentatives login
`connexion.php` ne limite pas les tentatives → vulnerable au brute-force.

#### Bug 8 — Réservation patient impossible
`pages/patient/reservation.php` est **vide** → un patient ne peut pas réserver de médicament via cette page (même si la logique de réservation existe dans le dashboard pharmacien).

#### Bug 9 — Redirections incohérentes
- `inscription.php:26` → admin redirige vers `admin/dashboard.php`
- `inscription.php:28` → pharmacien redirige vers `pages/pharmacien/dashboard.php` (chemin inexistant !)
- `connexion.php` → pharmacien redirige vers `pages/pharmacien/dashboard.php` (aussi inexistant)
- Les fichiers pharmacien sont dans `pharmacien/`, pas `pages/pharmacien/`

#### Bug 10 — Sections admin non fonctionnelles
- `admin/logs.php` → **vide**
- `admin/validation.php` → **vide**
- `admin/rapports.php` → **vide**
Ces pages sont probablement liées dans la navigation admin mais afficheront une page blanche.

### 🟡 MODÉRÉ

#### Bug 11 — Pas de CSRF sur session
Après login dans `connexion.php`, l'ID de session n'est pas régénéré (`session_regenerate_id()`) → vulnerable au session fixation.

#### Bug 12 — XSS dans l'affichage des résultats
Les résultats de recherche et les noms de médicaments sont injectés dans le HTML sans `htmlspecialchars()` dans de multiples endroits.

#### Bug 13 — Fichiers vides non supprimés
Plusieurs fichiers vides crées comme placeholders :
- `includes/fonctions.php`
- `sql/pharmageo.sql`
- `api/recherche_api.php`, `api/geoloc_api.php`, `api/reservation_api.php`, `api/stock_api.php`
- `admin/logs.php`, `admin/validation.php`, `admin/rapports.php`
- `pages/patient/reservation.php`, `pages/patient/resultats.php`

#### Bug 14 — Pas de migration DB
Sans `sql/pharmageo.sql`, aucun moyen de reproduire la base de données. L'installation est impossible à partir de zéro.

#### Bug 15 — test_global.php exposé
Le fichier de diagnostic `test_global.php` est accessible publiquement et peut exposer des informations sensibles sur la configuration.

---

## Résumé des Priorités de Correction

| Priorité | Action |
|---|---|
| 🔴 P0 | Supprimer `error_reporting(E_ALL)` / `display_errors` de tous les fichiers en production |
| 🔴 P0 | Externaliser les credentials DB dans `.env` et les charger depuis `config.php` |
| 🔴 P0 | Implémenter les 4 fichiers API vides |
| 🔴 P0 | Corriger les redirections (`pages/pharmacien/` → `pharmacien/`) |
| 🔠 P1 | Unifier le bloc de connexion/session en utilisant les includes partagés dans TOUS les fichiers |
| 🔠 P1 | Implémenter `includes/fonctions.php` avec toutes les fonctions manquantes |
| 🔠 P1 | Corriger le conflit de casse `BASE_URL` |
| 🟠 P2 | Ajouter les tokens CSRF sur tous les formulaires |
| 🟠 P2 | Implémenter `pages/patient/reservation.php` |
| 🟠 P2 | Implémenter `admin/logs.php`, `admin/validation.php`, `admin/rapports.php` |
| 🟡 P3 | Créer `sql/pharmageo.sql` avec le schéma complet |
| 🟡 P3 | Ajouter `htmlspecialchars()` partout |
| 🟡 P3 | Ajouter `session_regenerate_id()` après login |
| 🟡 P3 | Supprimer ou protéger `test_global.php` |
