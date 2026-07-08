# Référence des routes — up2dev/user-totp

Ce document liste chaque route exposée (par le package ou côté host), ce
qu'elle fait, qui peut l'appeler, et à quoi ressemblent le body et la
réponse. Pour l'installation et les pièges, voir `INTEGRATION.md`.

Toutes les routes du package sont sous `{api_prefix}/{route_prefix}`, soit
`/api/totp/...` avec la config par défaut.

---

## Vue d'ensemble : qui possède quoi

| Route | Possédée par | Auth requise |
|---|---|---|
| `POST /api/auth/login` | **host** (`AuthController`) | aucune (c'est le login) |
| `POST /api/auth/totp/verify` | **host** (`AuthController`) | `pending_token` |
| `GET /api/totp/methods` | package | session (Bearer token) |
| `POST /api/totp/{method}/setup` | package | session (Bearer token) |
| `POST /api/totp/{method}/enable` | package | session (Bearer token) |
| `POST /api/totp/{method}/disable` | package | session (Bearer token) |
| `POST /api/totp/enroll/{method}/setup` | package | `pending_token` |
| `POST /api/totp/enroll/{method}/enable` | package | `pending_token` |
| `POST /api/totp/challenge` | package | `pending_token` |

`{method}` vaut `totp` ou `email` (voir `USER_TOTP_ENABLED_METHODS`).

Deux façons d'être identifié selon le contexte :
- **session (Bearer token)** : l'utilisateur est déjà connecté normalement,
  header `Authorization: Bearer {token}`. Utilisé pour gérer ses propres
  méthodes (les activer/désactiver depuis un écran de paramètres).
- **`pending_token`** : émis par `/api/auth/login` juste après le mot de
  passe, avant que le 2FA soit validé. Ce n'est **pas** un token de session
  — il est transmis dans le **body** de la requête (jamais en header), et ne
  permet rien d'autre que les routes listées ci-dessus.

---

## `POST /api/auth/login`

Le point d'entrée normal. Body : `{ login, password }`, inchangé par rapport
à avant l'intégration du 2FA.

**Compte sans aucune méthode 2FA, et pas obligatoire (`USER_TOTP_ENFORCED=false`)** :
```json
{ "token": "...", "token_type": "bearer", "expires_at": "...", "user": {...} }
```
Rien ne change par rapport à un login classique.

**Compte avec au moins une méthode 2FA active** :
```json
{ "requires_totp": true, "available_methods": ["totp", "email"], "pending_token": "..." }
```
Si une seule méthode est active et que c'est `email`, le code est **envoyé
automatiquement** à ce moment (pas besoin d'appeler `/challenge` en plus).

**Compte sans méthode active, mais 2FA obligatoire (`USER_TOTP_ENFORCED=true`)
et pas exempté** :
```json
{ "requires_totp_setup": true, "available_methods": ["totp", "email"], "pending_token": "..." }
```
→ direction les routes `enroll/*` (voir plus bas).

---

## `POST /api/totp/challenge`

À appeler uniquement si `available_methods` contient **plusieurs** méthodes
(sinon c'est déjà fait automatiquement par `/login`), pour indiquer laquelle
l'utilisateur choisit — ou pour renvoyer un email si le premier n'est pas
arrivé.

Body : `{ pending_token, method }`

- `method: "email"` → envoie (ou renvoie) un nouveau code par email
- `method: "totp"` → ne fait rien côté serveur (l'utilisateur consulte
  simplement son app), répond juste pour confirmer que la méthode est valide

Réponse : `{ "message": "..." }`

---

## `POST /api/auth/totp/verify`

Termine le login. Body : `{ pending_token, method, code }`.

- Code correct → même réponse qu'un login classique (`token`, `user`, etc.)
- Code incorrect → `422`, une tentative est comptée (`pending_max_attempts`,
  5 par défaut) ; au-delà, le `pending_token` est invalidé et il faut
  reprendre depuis `/login`
- `pending_token` expiré/inconnu → `401`

---

## `GET /api/totp/methods`

Authentifié (Bearer). Aucun body. Utile pour un écran "Mes méthodes de
connexion".

```json
{ "available": ["totp", "email"], "enabled": ["totp"] }
```

- `available` : ce que **le projet** propose (`USER_TOTP_ENABLED_METHODS`)
- `enabled` : ce que **cet utilisateur** a lui-même activé

---

## `POST /api/totp/{method}/setup`

Authentifié (Bearer). Démarre l'activation d'une méthode. Aucun body requis.

**`method = totp`** :
```json
{ "secret": "QTCFV4...", "otpauth_uri": "otpauth://totp/..." }
```
Le `secret` sert de fallback si le QR (généré à partir de `otpauth_uri` côté
front) ne scanne pas. Rien n'est encore actif.

**`method = email`** : envoie immédiatement un code à l'adresse email de
l'utilisateur connecté.
```json
{ "message": "Un code a été envoyé par email à ..." }
```

Si la méthode est déjà activée : `409`.

---

## `POST /api/totp/{method}/enable`

Authentifié (Bearer). Confirme l'activation démarrée par `setup`.

Body : `{ code }`

- Code valide → méthode passée à `enabled = true`, `{ "message": "Méthode activée." }`
- Code invalide → `422`
- Aucun `setup` en cours pour cette méthode → `422`

---

## `POST /api/totp/{method}/disable`

Authentifié (Bearer). Aucun body.

- Cas normal (2FA pas obligatoire, ou obligatoire mais utilisateur exempté) :
  désactive directement, `{ "message": "Méthode désactivée." }`
- Cas bloqué (2FA obligatoire, utilisateur pas exempté, et c'est sa
  **dernière** méthode active) : `403`, `{ "message": "Vous devez garder au
  moins une méthode de double authentification active." }`

---

## `POST /api/totp/enroll/{method}/setup` et `.../enable`

Équivalents de `setup`/`enable` ci-dessus, mais utilisables **sans être
connecté** — uniquement pertinents quand `login()` a répondu
`requires_totp_setup: true` (2FA obligatoire, pas encore enrôlé).

Différence : au lieu du header `Authorization`, l'identité passe par
`pending_token` dans le body (`{ pending_token }` pour `setup`,
`{ pending_token, code }` pour `enable`).

Une fois `enroll/{method}/enable` confirmé, la méthode est active — il faut
ensuite appeler `POST /api/auth/totp/verify` avec le **même** `pending_token`
et un code frais pour obtenir le token final (l'enrôlement ne le délivre pas
directement).

---

## Résumé des flux complets

**Login simple, une méthode active (TOTP)**
```
POST /auth/login  →  { requires_totp, available_methods: ["totp"], pending_token }
POST /auth/totp/verify  { pending_token, method: "totp", code }  →  token
```

**Login simple, une méthode active (email)**
```
POST /auth/login  →  { requires_totp, available_methods: ["email"], pending_token }
                      (email envoyé automatiquement à cet instant)
POST /auth/totp/verify  { pending_token, method: "email", code }  →  token
```

**Login, deux méthodes actives**
```
POST /auth/login  →  { requires_totp, available_methods: ["totp","email"], pending_token }
POST /totp/challenge  { pending_token, method: "email" }  →  envoie le code
POST /auth/totp/verify  { pending_token, method: "email", code }  →  token
```

**Activation d'une méthode (self-service, déjà connecté)**
```
POST /totp/totp/setup   (Bearer)  →  { secret, otpauth_uri }
POST /totp/totp/enable  (Bearer)  { code }  →  activé
```

**Enrôlement forcé (USER_TOTP_ENFORCED=true, pas encore de méthode)**
```
POST /auth/login  →  { requires_totp_setup, available_methods, pending_token }
POST /totp/enroll/totp/setup   { pending_token }  →  { secret, otpauth_uri }
POST /totp/enroll/totp/enable  { pending_token, code }  →  activé
POST /auth/totp/verify  { pending_token, method: "totp", code }  →  token
```
