<?php

namespace Up2Dev\UserTotp\Services;

use PragmaRX\Google2FA\Google2FA;
use Up2Dev\UserTotp\Models\UserTotp;

class TotpService
{
    protected Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA();
    }

    /**
     * Génère un secret TOTP. Calcul 100% local (aucun appel réseau).
     */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * URI otpauth://totp/... standard, à transformer en QR code CÔTÉ FRONT
     * (ex: qrcode.vue, en JS pur dans le navigateur). Ce n'est qu'une chaîne
     * de texte : getQRCodeUrl() ne contacte aucun service externe malgré son
     * nom, elle ne fait que construire cette URI selon le format standard.
     */
    public function otpAuthUri(string $secret, string $label): string
    {
        return $this->engine->getQRCodeUrl(
            config('user-totp.issuer', config('app.name')),
            $label,
            $secret
        );
    }

    /**
     * Vérifie un code à 6 chiffres saisi par l'utilisateur contre le secret
     * stocké, avec la fenêtre de tolérance configurée.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey(
            $secret,
            $code,
            (int) config('user-totp.window', 1)
        );
    }

    public function findOrInitFor(int $userId): UserTotp
    {
        return UserTotp::firstOrNew(['user_id' => $userId]);
    }
}
