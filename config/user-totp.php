<?php

return [

    'issuer' => env('USER_TOTP_ISSUER', env('APP_NAME', 'Laravel')),

    /*
    |--------------------------------------------------------------------------
    | Méthodes de double authentification proposées
    |--------------------------------------------------------------------------
    | Retirez 'email' si aucun mailer n'est configuré côté host, ou 'totp'
    | si vous ne voulez proposer que l'email. Les routes /{method}/... sont
    | contraintes à cette liste (whereIn).
    */
    'enabled_methods' => array_filter(explode(',', env('USER_TOTP_ENABLED_METHODS', 'totp,email'))),

    /*
    |--------------------------------------------------------------------------
    | Paramètres RFC 6238 (méthode 'totp')
    |--------------------------------------------------------------------------
    | Ne pas changer sans savoir pourquoi : ce sont les valeurs par défaut
    | universellement supportées par toutes les apps TOTP (Google Authenticator,
    | Authy, Duo, Microsoft Authenticator, FreeOTP, Bitwarden, 1Password, Aegis...).
    */
    'digits' => 6,
    'period' => 30,
    'window' => 1, // tolérance ±30s

    /*
    |--------------------------------------------------------------------------
    | Paramètres de l'OTP par email (méthode 'email')
    |--------------------------------------------------------------------------
    */
    'email_otp_length' => (int) env('USER_TOTP_EMAIL_OTP_LENGTH', 6),
    'email_otp_ttl' => (int) env('USER_TOTP_EMAIL_OTP_TTL', 600), // secondes, 10 min
    'email_otp_max_attempts' => (int) env('USER_TOTP_EMAIL_OTP_MAX_ATTEMPTS', 5),
    'email_otp_subject' => env('USER_TOTP_EMAIL_OTP_SUBJECT', 'Votre code de connexion'),

    /*
    | Classe Mailable utilisée pour l'envoi du code. Doit accepter un unique
    | argument string $code au constructeur. Par défaut, une Mailable
    | générique du package (n'importe quel mailer Laravel). Fournissez la
    | vôtre pour profiter d'un mécanisme de traçabilité maison (ex: table
    | "sendmails" de lume-pack/foundation) — voir INTEGRATION.md.
    */
    'email_otp_mailable' => env('USER_TOTP_EMAIL_OTP_MAILABLE', \Up2Dev\UserTotp\Mail\EmailOtpCodeMail::class),

    /*
    |--------------------------------------------------------------------------
    | Token temporaire post-mot de passe (avant validation du 2FA)
    |--------------------------------------------------------------------------
    | Pas un token Sanctum : une valeur opaque en cache, incapable de passer
    | un middleware d'authentification API ailleurs dans l'app.
    */
    'pending_token_ttl' => (int) env('USER_TOTP_PENDING_TTL', 300),
    'pending_max_attempts' => (int) env('USER_TOTP_PENDING_MAX_ATTEMPTS', 5),
    'cache_store' => env('USER_TOTP_CACHE_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Routes exposées par le package
    |--------------------------------------------------------------------------
    | Préfixe et middleware configurables : le package ne fait aucune
    | supposition sur le système d'auth de l'host (guard Sanctum standard,
    | middleware custom type "lpfauth:sanctum", etc.)
    */
    'route_prefix' => env('USER_TOTP_ROUTE_PREFIX', 'totp'),
    'middleware' => array_filter(explode(',', env('USER_TOTP_MIDDLEWARE', 'auth:sanctum'))),

    /*
    | loadRoutesFrom() (utilisé par le ServiceProvider) n'ajoute PAS
    | automatiquement le préfixe "/api" ni le groupe de middleware "api" que
    | Laravel applique via bootstrap/app.php -> withRouting(). On les
    | reproduit ici explicitement.
    */
    'api_prefix' => trim(env('USER_TOTP_API_PREFIX', 'api'), '/'),
    'api_middleware' => array_filter(explode(',', env('USER_TOTP_API_MIDDLEWARE', 'api'))),

    /*
    |--------------------------------------------------------------------------
    | Format de réponse
    |--------------------------------------------------------------------------
    | Par défaut, les routes du package renvoient du JSON à plat. Si votre
    | application a sa propre enveloppe de réponse (ex: { meta, data }),
    | fournissez ici une classe implémentant
    | Up2Dev\UserTotp\Contracts\ResponseFormatter.
    */
    'response_formatter' => env('USER_TOTP_RESPONSE_FORMATTER'),

    /*
    |--------------------------------------------------------------------------
    | TOTP obligatoire par défaut
    |--------------------------------------------------------------------------
    | Si true, tout utilisateur sans aucune méthode active est forcé d'en
    | enrôler une au prochain login (voir routes /enroll/*), SAUF s'il est
    | exempté (voir 'exemption_resolver').
    |
    | Important : ceci force l'ENRÔLEMENT, pas une activation magique.
    | Chaque méthode (secret TOTP ou email de réception) est propre à
    | l'utilisateur — il n'existe aucun moyen de l'activer "pour tout le
    | monde" sans que chacun passe par sa propre étape de confirmation.
    */
    'enforced' => filter_var(env('USER_TOTP_ENFORCED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Classe implémentant Up2Dev\UserTotp\Contracts\ExemptionResolver,
    | pour exempter certains utilisateurs (par rôle, permission, etc.) de
    | l'obligation ci-dessus. Si null, personne n'est exempté quand
    | 'enforced' est actif.
    */
    'exemption_resolver' => env('USER_TOTP_EXEMPTION_RESOLVER'),

];
