# Guide d'intégration — up2dev/user-totp

Ce guide couvre une intégration complète sur une stack Laravel + Sanctum, avec
le cas concret d'un package d'auth custom type `lume-pack/foundation`, et le
support de plusieurs méthodes de double authentification (TOTP + email OTP).

> ⚠️ Si vous migrez depuis une version antérieure du package (table
> `user_totp`, mono-méthode), c'est un changement de schéma : la table
> devient `user_two_factor_methods`. En phase de dev, un `migrate:fresh`
> suffit. En prod, il faudrait une migration de transfert de données —
> non couverte ici puisque non nécessaire à ce stade du projet.

## 0. Séquence complète après un changement de schéma (migrate:fresh)

À chaque fois que le schéma du package change (comme le passage
`user_totp` → `user_two_factor_methods`), la base doit être rejouée depuis
zéro. Séquence complète, dans l'ordre, depuis le conteneur (`make bash`) :

```bash
# 1. Rejoue toutes les migrations proprement
php artisan migrate:fresh

# 2. Vide le cache de routes (nouvelles routes ajoutées/renommées)
php artisan route:clear

# 3. Recrée le permission_type de base (perdu avec migrate:fresh — voir
#    section 4 pour le pourquoi de cette étape)
php artisan tinker
```
```php
\LumePack\Foundation\Data\Models\Dictionaries\Types\PermissionType::forceCreate([
    'uid' => 'ENDPOINT', 'name' => 'Endpoint',
]);
exit
```
```bash
# 4. Régénère les permissions (inclut les nouvelles routes du package)
php artisan rightsmanagement --action=create-permissions

# 5. Recrée le rôle et le user de test (perdus avec migrate:fresh)
php artisan rightsmanagement --action=create-role --roleuid=ADMIN --rolename=Administrateur --withdefaultpermissions=true
php artisan rightsmanagement --action=create-user --roleuid=ADMIN --name=John --surname=Doe --email=john.doe@test.local --password=motdepasse123
```

> ⚠️ **Piège** : `--withdefaultpermissions=true` ne donne que les
> permissions vendor de base (gestion des rôles), **pas** celles générées
> pour les routes du package à l'étape 4. Sans l'étape 6 ci-dessous, toutes
> les routes `totp/*` renvoient un `403` même avec un token valide.

```bash
# 6. Attache les permissions du package (et de la vérif de login) au rôle
php artisan tinker
```
```php
$role = \LumePack\Foundation\Data\Models\Auth\Role::where('uid', 'ADMIN')->first();

$permissions = \LumePack\Foundation\Data\Models\Auth\Permission::where('uid', 'like', 'U2DUTAT%')
    ->orWhere('uid', 'APPAATV_AUTHAUTH_TOTPVERIFY')
    ->get();

$role->permissions()->syncWithoutDetaching($permissions->pluck('id'));

// vérif
$role->refresh()->permissions()->pluck('uid');
exit
```

> ℹ️ `U2DUTAT%` est le préfixe d'UID généré pour les routes du package
> (dérivé du namespace `Up2Dev\UserTotp`, via la fonction interne
> `ra_to_uid()` du vendor) — couvre `methods`, `{method}/setup|enable|disable`,
> `enroll/{method}/setup|enable`, `challenge`. Ce préfixe est stable tant que
> le namespace du package ne change pas. `APPAATV_AUTHAUTH_TOTPVERIFY`
> correspond à `POST /api/auth/totp/verify` côté host — pas strictement
> nécessaire à attacher puisque cette route tourne avec
> `withoutMiddleware('lpfauth:sanctum')` (identité portée par le
> `pending_token`, pas un rôle), mais sans risque de l'attacher aussi.

Vérification :

```bash
php artisan route:list --path=totp
```

Doit lister : `GET totp/methods`, `POST totp/{method}/setup|enable|disable`,
`POST totp/enroll/{method}/setup|enable`, `POST totp/challenge`.

## 1. Fichiers à créer côté application hôte

### `app/Http/Controllers/Auth/AuthController.php`

Remplace le contrôleur de login du vendor. Route vers `TwoFactorManager` du
package sans jamais savoir si la méthode active d'un utilisateur est TOTP ou
email.

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Data\Models\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use LumePack\Foundation\Http\Controllers\BaseController;
use Up2Dev\UserTotp\Services\PendingLoginTokenService;
use Up2Dev\UserTotp\Services\TwoFactorManager;

class AuthController extends BaseController
{
    public function __construct(
        protected PendingLoginTokenService $pendingLogin,
        protected TwoFactorManager $twoFactor,
    ) {
        parent::__construct();
    }

    /**
     * Résout l'utilisateur comme le fait le vendor : casse du login
     * configurable (IS_LOGIN_KS), et surtout chargement dynamique des
     * relations demandées via `?with[]=...` (traduit par le middleware
     * QueryStringToConfig en config('query.relations')). À oublier ici fait
     * planter le front dès qu'il demande roles.permissions en eager-loading
     * ("role.permissions is not iterable").
     */
    protected function findUserForLogin(Request $request): ?User
    {
        if (env('IS_LOGIN_KS', false)) {
            $query = User::where('login', $request->login);
        } else {
            $query = User::where(\Illuminate\Support\Facades\DB::raw('LOWER(login)'), \Illuminate\Support\Str::lower($request->login));
        }

        foreach (config('query.relations', []) as $relation) {
            $query->with($relation);
        }

        return $query->first();
    }

    public function login(Request $request): JsonResponse
    {
        $user = $this->findUserForLogin($request);

        if (
            !$user || !is_null($user->deleted_at) ||
            !Hash::check($request->password, $user->password)
        ) {
            $this->setResponse(trans('foundation::auth.failed'), 400);
        } elseif (!$user->is_active) {
            $this->setResponse(trans('foundation::auth.inactive'), 400);
        } elseif (
            config('auth.is_mail_locked') && is_null($user->email_verified_at)
        ) {
            $this->setResponse(trans('foundation::auth.email'), 400);
        } else {
            $methods = $this->twoFactor->enabledMethods($user->id);

            if (!empty($methods)) {
                $pendingToken = $this->pendingLogin->issue($user->id, $user->email);

                if (count($methods) === 1) {
                    $this->twoFactor->challenge($user->id, $methods[0], $user->email);
                }

                $this->setResponse([
                    'requires_totp' => true,
                    'available_methods' => $methods,
                    'pending_token' => $pendingToken,
                ]);
            } elseif (method_exists($user, 'mustEnrollTotp') && $user->mustEnrollTotp()) {
                $this->setResponse([
                    'requires_totp_setup' => true,
                    'available_methods' => config('user-totp.enabled_methods', ['totp', 'email']),
                    'pending_token' => $this->pendingLogin->issue($user->id, $user->email),
                ]);
            } else {
                $this->cleanupStaleTokens($user, $request);
                $this->setResponse($this->issueToken($user, $request));
            }
        }

        return $this->response->format();
    }

    public function totpVerify(Request $request): JsonResponse
    {
        $request->validate([
            'pending_token' => 'required|string',
            'method'        => 'required|string',
            'code'          => 'required|string',
        ]);

        $pendingToken = $request->input('pending_token');
        $userId = $this->pendingLogin->peek($pendingToken);

        if ($userId === null) {
            $this->setResponse('Session de connexion expirée, reconnectez-vous.', 401);
            return $this->response->format();
        }

        // Même chargement de relations que dans findUserForLogin() : le
        // front peut redemander roles.permissions sur CET appel aussi.
        $userQuery = User::where('id', $userId);
        foreach (config('query.relations', []) as $relation) {
            $userQuery->with($relation);
        }
        $user = $userQuery->first();

        if (!$user) {
            $this->pendingLogin->invalidate($pendingToken);
            $this->setResponse(trans('foundation::auth.failed'), 401);
            return $this->response->format();
        }

        if (!$this->twoFactor->verify($userId, $request->input('method'), $request->input('code'))) {
            $this->pendingLogin->registerFailedAttempt($pendingToken);
            $this->setResponse('Code invalide.', 422);
            return $this->response->format();
        }

        $this->pendingLogin->invalidate($pendingToken);
        $this->cleanupStaleTokens($user, $request);
        $this->setResponse($this->issueToken($user, $request));

        return $this->response->format();
    }

    protected function cleanupStaleTokens(User $user, Request $request): void
    {
        foreach ($user->tokens()->getResults() as $access_token) {
            if (
                Hash::check($request->server('HTTP_USER_AGENT'), $access_token->name) || (
                    !is_null($access_token->expires_at) &&
                    new \DateTime($access_token->expires_at) < new \DateTime()
                )
            ) {
                $access_token->delete();
            }
        }
    }

    protected function issueToken(User $user, Request $request): array
    {
        $token = $user->createToken(
            Hash::make($request->server('HTTP_USER_AGENT')),
            ['*'],
            is_null(config('sanctum.expiration_override'))
                ? (
                    is_null(config('sanctum.expiration'))
                        ? null
                        : now()->addMinutes(config('sanctum.expiration'))
                )
                : now()->addMinutes(config('sanctum.expiration_override'))
        );

        return $this->setTokenBody($token, $user);
    }

    protected function setTokenBody(NewAccessToken $token, User $user): array
    {
        return [
            'token'      => $token->plainTextToken,
            'token_type' => 'bearer',
            'expires_at' => is_null($token->accessToken->expires_at)
                ? null
                : (new \DateTime($token->accessToken->expires_at))->format('Y-m-d\TH:i:s.u\Z'),
            'user'       => $user,
        ];
    }
}
```

### `app/Support/LumePackTotpResponseFormatter.php`

Optionnel, pour aligner les réponses du package sur `{ meta, data }`.

```php
<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use LumePack\Foundation\Services\ResponseService;
use Up2Dev\UserTotp\Contracts\ResponseFormatter;

class LumePackTotpResponseFormatter implements ResponseFormatter
{
    public function format(mixed $body, int $status = 200): JsonResponse
    {
        return (new ResponseService($body, $status))->format();
    }
}
```

### `app/Support/LumePackTotpExemptionResolver.php`

Optionnel, uniquement utile avec `USER_TOTP_ENFORCED=true`.

```php
<?php

namespace App\Support;

use Up2Dev\UserTotp\Contracts\ExemptionResolver;

class LumePackTotpExemptionResolver implements ExemptionResolver
{
    protected array $exemptRoleUids = [
        // 'SERVICE_ACCOUNT',
    ];

    public function isExempt(mixed $user): bool
    {
        if (empty($this->exemptRoleUids)) {
            return false;
        }

        return $user->roles()
            ->whereIn('uid', $this->exemptRoleUids)
            ->exists();
    }
}
```

### `app/Providers/AppServiceProvider.php`

Retire au runtime la route de login du vendor.

```php
<?php

namespace App\Providers;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $router = $this->app['router'];
            $rebuilt = new RouteCollection();

            foreach ($router->getRoutes() as $route) {
                $isVendorLoginRoute = in_array('POST', $route->methods(), true)
                    && $route->uri() === 'api/auth/login'
                    && $route->getActionName() === 'LumePack\\Foundation\\Http\\Controllers\\Auth\\AuthController@login';

                if ($isVendorLoginRoute) {
                    continue;
                }

                $rebuilt->add($route);
            }

            $router->setRoutes($rebuilt);
        });
    }
}
```

### `routes/api/auth/login-totp.php`

```php
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('auth')->controller(\App\Http\Controllers\Auth\AuthController::class)->group(function () {
    Route::withoutMiddleware('lpfauth:sanctum')->post('login', 'login');
    Route::withoutMiddleware('lpfauth:sanctum')->post('totp/verify', 'totpVerify');
});
```

## 2. Fichiers existants à modifier

### `composer.json`

**Recommandé, maintenant que le package est publié sur Packagist :**
```json
"require": {
    "up2dev/user-totp": "^0.1"
}
```
Aucun bloc `repositories` nécessaire.

**En développement local (symlink), avant publication ou pour tester une
modification non encore poussée :**
```json
"repositories": [
    { "type": "path", "url": "../up2dev-user-totp" }
],
"require": {
    "up2dev/user-totp": "dev-main"
}
```

> ⚠️ **Piège** : dossier `.git` présent → Composer versionne en `dev-main`,
> pas `*`. Avec `minimum-stability` par défaut (`stable`), une contrainte `*`
> échoue. Mettez `dev-main` explicitement en mode `path`.

> ⚠️ **Piège** : en changeant de méthode d'installation (path ↔ vcs ↔
> Packagist), le `composer.lock` garde en mémoire l'ancienne résolution et
> bloque avec *"is in the lock file as X but that does not satisfy your
> constraint Y"*. Relancer `composer update up2dev/user-totp` après chaque
> changement de `composer.json` pour resynchroniser le lock file.

### `.env`

```env
USER_TOTP_ISSUER="My App"
USER_TOTP_ENABLED_METHODS=totp,email
USER_TOTP_ROUTE_PREFIX=totp
USER_TOTP_MIDDLEWARE=lpfauth:sanctum
USER_TOTP_RESPONSE_FORMATTER=App\Support\LumePackTotpResponseFormatter
USER_TOTP_ENFORCED=false
USER_TOTP_EXEMPTION_RESOLVER=App\Support\LumePackTotpExemptionResolver
USER_TOTP_EMAIL_OTP_SUBJECT="Votre code de connexion My App"
```

> ⚠️ **Piège** : `USER_TOTP_MIDDLEWARE` doit correspondre au middleware
> d'auth *réel* (`lpfauth:sanctum` ici, pas le générique `auth:sanctum`),
> sinon 401 permanent sur `setup/enable/disable`.

> ℹ️ L'envoi d'email utilise le mailer déjà configuré (`MAIL_*` dans votre
> `.env`) — rien de plus à configurer si vos emails fonctionnent déjà ailleurs
> dans l'app.

### `routes/api.php`

```php
Route::middleware('api')->group(base_path('routes/api/auth/login-totp.php'));
```

### `app/Data/Models/Auth/User.php`

```php
use Up2Dev\UserTotp\Concerns\HasTotp;

class User extends BaseUser
{
    use HasTotp;
    // ... reste inchangé
```

### `docker/docker-compose.yml` (si package en sibling folder)

```yaml
volumes:
  - ../:${COMPOSE_PROJECT_PATH}
  - ../../up2dev-user-totp:${COMPOSE_PROJECT_PATH}/../up2dev-user-totp
```

> ⚠️ **Piège** : un repository `path` sibling est résolu **depuis
> l'intérieur du conteneur**. Sans volume dédié, `composer require` échoue
> avec *"the url ... does not exist"* même si le dossier existe sur l'hôte.

## 3. Séquence de commandes

```bash
composer require up2dev/user-totp
php artisan migrate
php artisan route:clear
php artisan rightsmanagement --action=create-permissions
```

Puis attacher les nouvelles permissions `totp/*` au(x) rôle(s) concerné(s)
(sinon 403 même avec un token valide).

## 4. Pièges rencontrés qui ne viennent PAS du package

Préexistants au starter, rencontrés au même moment (premier `migrate:fresh`
d'une base neuve) :

- **Deux migrations créent `users`** (squelette Laravel par défaut jamais
  supprimé + migration vendor). → Supprimer
  `0001_01_01_000000_create_users_table.php` avant tout `migrate:fresh`.

- **Connexion `mongodb` mal configurée** : lit `env('DB_HOST')`/`env('DB_PORT')`
  (= les variables PostgreSQL) au lieu de `DB_LOGS_HOST`/`DB_LOGS_PORT`. →
  Corriger le bloc `mongodb` de `config/database.php`.

- **Incompatibilité `mongodb/laravel-mongodb` / Laravel récent** :
  `Builder::timeout()` incompatible avec le noyau. → `composer update
  mongodb/laravel-mongodb`.

- **`permission_type_id` codé en dur à `1`** dans `rightsmanagement
  --action=create-permissions`, table `permission_types` vide sur base
  fraîche. → Créer la ligne manuellement (`forceCreate`, le modèle n'a pas
  de `$fillable`) :
  ```php
  \LumePack\Foundation\Data\Models\Dictionaries\Types\PermissionType::forceCreate([
      'uid' => 'ENDPOINT', 'name' => 'Endpoint',
  ]);
  ```

## 5. Pièges qui viennent du package (déjà corrigés dans cette version)

- Les routes chargées via `loadRoutesFrom()` n'héritent pas automatiquement
  du préfixe `/api` ni du groupe `api`. → reproduits explicitement
  (`api_prefix` / `api_middleware`).
- Format de réponse à plat par défaut. → `response_formatter` configurable.

## 6. Mode obligatoire (`USER_TOTP_ENFORCED=true`)

Rappel : chaque méthode reste propre à l'utilisateur (secret TOTP unique, ou
accès à sa propre boîte mail) — "obligatoire" force l'enrôlement, pas une
activation automatique.

```env
USER_TOTP_ENFORCED=true
USER_TOTP_EXEMPTION_RESOLVER=App\Support\LumePackTotpExemptionResolver
```

Flux pour un utilisateur pas encore enrôlé :

1. `POST /api/auth/login` → `{ requires_totp_setup: true, available_methods: [...], pending_token }`
2. Le front propose le choix de méthode (si plusieurs configurées sur le
   projet), puis `POST /api/totp/enroll/{method}/setup` avec `{ pending_token }`
   (routes `enroll/*` sans middleware d'auth : identité portée par le
   `pending_token`)
3. `POST /api/totp/enroll/{method}/enable` avec `{ pending_token, code }` →
   confirme, ne renvoie pas de token
4. `POST /api/auth/totp/verify` avec `{ pending_token, method, code }`
   (même code, encore valide) → token final

## 7. Choix entre plusieurs méthodes actives

Si un utilisateur a activé TOTP **et** email :

1. `POST /api/auth/login` → `{ requires_totp: true, available_methods: ['totp', 'email'], pending_token }`
2. Le front affiche le choix, l'utilisateur clique "email" par exemple
3. `POST /api/totp/challenge` avec `{ pending_token, method: 'email' }` →
   déclenche l'envoi (no-op si `method: 'totp'`, l'utilisateur consulte
   simplement son app)
4. `POST /api/auth/totp/verify` avec `{ pending_token, method: 'email', code }`

Avec une seule méthode active, l'étape 3 est automatique (déclenchée par
`login()` lui-même) — le front peut aller direct à la saisie du code.

## 8. Traçabilité des emails OTP (table `sendmails` du vendor)

Par défaut, l'email OTP est envoyé via une Mailable générique du package, qui
ne connaît rien à `lume-pack/foundation` — il n'apparaît donc **pas** dans la
table `sendmails` du vendor (ses listeners `MessageSendingListener`/
`MessageSentListener` n'agissent que sur les mails portant un header
`x-metadata-sendmail-token`, posé uniquement par `LumePack\Foundation\Mail\BaseMail`).

Pour en profiter, fournissez votre propre Mailable étendant `BaseMail` :

```php
<?php

namespace App\Mail;

use LumePack\Foundation\Mail\BaseMail;

class TotpEmailOtpMail extends BaseMail
{
    public function __construct(string $code)
    {
        parent::__construct('emails.totp-otp-code', [
            'code' => $code,
            'subject' => config('user-totp.email_otp_subject', 'Votre code de connexion'),
        ]);
    }
}
```

```blade
{{-- resources/views/emails/totp-otp-code.blade.php --}}
<p>Voici votre code de connexion :</p>
<p style="font-size: 28px; font-weight: bold;">{{ $code }}</p>
```

```env
USER_TOTP_EMAIL_OTP_MAILABLE=App\Mail\TotpEmailOtpMail
```

> ⚠️ La classe fournie doit accepter un unique argument `string $code` au
> constructeur, comme la Mailable par défaut du package.
