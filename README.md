# up2dev/user-totp

Double authentification **multi-méthodes** (TOTP + code OTP par email pour
l'instant, extensible), optionnelle et **par utilisateur**, à greffer sur un
système d'authentification Laravel existant **sans le modifier**.

Aucun service tiers : le TOTP est calculé localement
([RFC 6238](https://datatracker.ietf.org/doc/html/rfc6238), compatible Google
Authenticator, Authy, Duo, Microsoft Authenticator, FreeOTP, Bitwarden,
1Password, Aegis...) ; l'OTP par email utilise le mailer déjà configuré de
votre application (aucun gateway SMS/email payant).

## Pourquoi ce package

La plupart des libs 2FA pour Laravel possèdent tout le cycle d'authentification
(login inclus) et n'imposent qu'une seule méthode. `up2dev/user-totp` part de
deux principes inverses : votre système d'auth existant reste maître du
login, et chaque utilisateur choisit sa méthode selon son contexte — une app
d'authentification s'il en a une sur l'appareil approprié, un code par email
sinon (utile typiquement pour dissocier téléphone pro/perso).

Le package fournit :

- une table dédiée (`user_two_factor_methods`), indépendante de votre table `users`
- `TotpService` (génération/vérification TOTP) et `EmailOtpService` (génération/
  envoi/vérification d'un code par email)
- `TwoFactorManager`, point d'entrée unique pour le login : liste les méthodes
  actives d'un utilisateur et vérifie un code sans que votre code ait besoin
  de savoir laquelle c'est
- un mécanisme de "token en attente" entre le mot de passe et le 2FA, qui
  n'est **pas** un token de session et ne peut donc pas accéder au reste de
  l'API
- des routes HTTP autonomes : `{method}/setup`, `{method}/enable`,
  `{method}/disable`, `methods`, `enroll/{method}/setup`,
  `enroll/{method}/enable`, `challenge`
- un mode "obligatoire" optionnel (`USER_TOTP_ENFORCED`), avec exemption par
  rôle déléguée à l'application hôte

**Ce qu'il ne fait pas** : émettre le token final après un login réussi —
reste dans *votre* code, le format dépendant entièrement de votre projet.

## Prérequis

- PHP ^8.2
- Laravel 11 ou 12 (`illuminate/support`, `illuminate/database`, `illuminate/mail`)
- Un mailer fonctionnel si vous activez la méthode `email`

Testé et documenté en intégration avec [`lume-pack/foundation`](https://packagist.org/packages/lume-pack/foundation)
(stack Sanctum + middleware d'auth custom), mais ne dépend d'aucun package
LumePack — voir `Contracts\ResponseFormatter` et `Contracts\ExemptionResolver`
pour adapter à n'importe quelle stack.

## Installation

### Depuis Packagist (recommandé)

```bash
composer require up2dev/user-totp
```

Aucun bloc `repositories` nécessaire — le package est public sur
[packagist.org/packages/up2dev/user-totp](https://packagist.org/packages/up2dev/user-totp).

### Depuis un repository Git directement (sans passer par Packagist)

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/up2dev/user-totp.git" }
],
"require": {
    "up2dev/user-totp": "^0.1"
}
```

### En développement local (symlink)

```json
"repositories": [
    { "type": "path", "url": "../up2dev-user-totp" }
],
"require": {
    "up2dev/user-totp": "dev-main"
}
```

Puis, dans les deux cas :

```bash
composer require up2dev/user-totp
php artisan migrate
```

## Configuration rapide

```env
USER_TOTP_ISSUER="Mon App"
USER_TOTP_ENABLED_METHODS=totp,email
USER_TOTP_ROUTE_PREFIX=totp
USER_TOTP_MIDDLEWARE=auth:sanctum
```

Voir `config/user-totp.php` pour toutes les options.

## Guide d'intégration complet

Voir [`INTEGRATION.md`](./INTEGRATION.md) pour la liste exhaustive des
fichiers à créer/modifier, le flux multi-méthodes, le mode obligatoire, et
les pièges connus.

## Référence des routes

Voir [`ROUTES.md`](./ROUTES.md) : chaque route, ce qu'elle fait, qui peut
l'appeler, exemples de body/réponse, et les flux complets (login simple,
choix entre deux méthodes, enrôlement forcé).

## Licence

MIT

