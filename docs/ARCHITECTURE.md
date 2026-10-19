# Architecture SonkeMap

Stack : HTML/CSS/JS sans build (modules ES natifs) + OpenLayers 10, PHP sur Alwaysdata.

```
sonkemap/
├── calcul_itineraire/        Version actuelle en production (monolithe, ne pas modifier)
│
├── public/                   ← racine web à déployer
│   ├── index.html            Squelette HTML, charge js/main.js en type="module"
│   ├── manifest.json         PWA
│   ├── sw.js                 Service worker (cache hors-ligne)
│   ├── assets/
│   │   ├── css/              base, layout, panels, components, map
│   │   ├── icons/            Icônes PWA hébergées localement
│   │   └── img/
│   ├── js/
│   │   ├── main.js           Point d'entrée
│   │   ├── config.js         URL des API, couleurs, profils
│   │   ├── core/             state, storage, http, events
│   │   ├── utils/            geo, format
│   │   ├── map/              carte, couches, styles OpenLayers
│   │   ├── services/         Appels réseau uniquement (aucun DOM)
│   │   ├── features/         Une fonctionnalité = un dossier (logique + UI)
│   │   ├── ui/               Composants génériques (toast, modal)
│   │   └── pwa/
│   └── api/                  Points d'entrée PHP minces
│       ├── ors_proxy.php
│       ├── dangers.php
│       ├── auth.php
│       └── contact.php
│
├── server/                   ← hors racine web (jamais accessible par URL)
│   ├── bootstrap.php         Charge config + lib
│   ├── config/
│   │   └── config.example.php   Copier en config.php (clé ORS, domaines)
│   ├── lib/                  cors, rate_limit, response, json_store
│   └── data/                 dangers.json, etc.
│
├── tests/                    js/ et php/
├── scripts/                  Scripts de déploiement
└── docs/
```

## Règles

- `services/` ne touche jamais au DOM ; `features/` utilise les services et gère l'affichage.
- Les modules communiquent via `core/state.js` et `core/events.js`, pas via des variables globales.
- Aucune clé API côté client : tout passe par `public/api/`, qui lit `server/config/config.php`.

## Migration depuis `calcul_itineraire/SonkeMap.html`

| Lignes      | Contenu                                | Destination                          |
|-------------|----------------------------------------|--------------------------------------|
| 24–1265     | CSS                                    | `assets/css/*.css`                   |
| 1266–2171   | HTML                                   | `index.html`                         |
| 2172–2215   | État global, POI_CFG, couches          | `core/state.js`, `config.js`, `map/` |
| 2216–2564   | Calcul d'itinéraire, navigation        | `features/itinerary/`, `features/navigation/`, `services/routing.js` |
| 2565–2842   | Favoris, historique, altitude, météo   | `features/favorites/`, `services/elevation.js`, `services/weather.js` |
| 2843–2870   | Menu POI                               | `features/poi/`, `services/poi.js`   |
| 2871–3605   | Signalement et partage des dangers     | `features/dangers/`, `services/dangers.js` |
| 3606–3979   | Boutique de cartes, deep links         | `features/shop/`                     |
| 3980–4073   | Contact                                | `features/contact/`                  |
| 4074–4499   | Multi-arrêts (plus proche voisin + 2-opt) | `features/multistop/`             |
| 4500–4967   | Authentification (email, Google, SMS)  | `features/auth/`, `services/auth.js` |
| 4968–5072   | PWA                                    | `pwa/install.js`                     |

Ordre conseillé : CSS → `config`/`state` → `map/` → services → features, une par une, en testant à chaque étape.
