<?php

namespace Up2Dev\UserTotp\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Gère un token temporaire émis juste après validation du mot de passe,
 * en attente du code TOTP.
 *
 * Volontairement PAS un token Sanctum : c'est une valeur opaque stockée en
 * cache, que seul ce service sait interpréter. Il ne peut donc structurellement
 * pas passer un middleware d'authentification API ailleurs dans l'app, contrairement
 * à un PersonalAccessToken Sanctum à portée restreinte (dont les "abilities" ne
 * sont pas forcément vérifiées par tous les middlewares d'auth d'un host).
 */
class PendingLoginTokenService
{
    protected function store(): CacheRepository
    {
        return Cache::store(config('user-totp.cache_store'));
    }

    protected function key(string $token): string
    {
        return 'user-totp:pending:' . $token;
    }

    protected function attemptsKey(string $token): string
    {
        return 'user-totp:pending-attempts:' . $token;
    }

    /**
     * Émet un nouveau token temporaire pour cet utilisateur.
     *
     * @param string|null $label Ex: l'email de l'utilisateur, pour affichage
     *                           dans le QR code pendant l'enrôlement forcé
     *                           (voir TotpController::enrollSetup), quand le
     *                           package n'a pas accès à l'objet User complet.
     */
    public function issue(int $userId, ?string $label = null): string
    {
        $token = Str::random(64);
        $ttl = (int) config('user-totp.pending_token_ttl', 300);

        $this->store()->put($this->key($token), [
            'user_id' => $userId,
            'label'   => $label,
        ], $ttl);

        return $token;
    }

    /**
     * Retourne l'user_id associé si le token est valide et pas expiré,
     * sans le consommer (utile pour vérifier avant de tenter un code).
     */
    public function peek(string $token): ?int
    {
        $payload = $this->store()->get($this->key($token));

        return $payload === null ? null : (int) $payload['user_id'];
    }

    /**
     * Retourne le label associé au token (peut être null si non fourni à
     * l'émission).
     */
    public function peekLabel(string $token): ?string
    {
        $payload = $this->store()->get($this->key($token));

        return $payload['label'] ?? null;
    }

    /**
     * Invalide définitivement le token (succès du TOTP, ou nombre max de
     * tentatives atteint).
     */
    public function invalidate(string $token): void
    {
        $this->store()->forget($this->key($token));
        $this->store()->forget($this->attemptsKey($token));
    }

    /**
     * Comptabilise une tentative de code TOTP échouée. Invalide le token
     * automatiquement au-delà de "pending_max_attempts" pour empêcher le
     * bruteforce du code sur la durée de vie du token.
     *
     * @return int Nombre de tentatives échouées effectuées jusqu'ici.
     */
    public function registerFailedAttempt(string $token): int
    {
        $maxAttempts = (int) config('user-totp.pending_max_attempts', 5);
        $ttl = (int) config('user-totp.pending_token_ttl', 300);

        $attempts = (int) $this->store()->get($this->attemptsKey($token), 0) + 1;
        $this->store()->put($this->attemptsKey($token), $attempts, $ttl);

        if ($attempts >= $maxAttempts) {
            $this->invalidate($token);
        }

        return $attempts;
    }
}
