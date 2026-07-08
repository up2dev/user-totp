<?php

namespace Up2Dev\UserTotp\Services;

use Up2Dev\UserTotp\Models\TwoFactorMethod;

/**
 * Point d'entrée unique pour le login : liste les méthodes actives d'un
 * utilisateur, déclenche le "challenge" (envoi d'email si besoin — no-op
 * pour TOTP), et vérifie un code quelle que soit la méthode. L'application
 * hôte n'a jamais besoin de savoir si une méthode est TOTP, email, ou autre.
 */
class TwoFactorManager
{
    public function __construct(
        protected TotpService $totp,
        protected EmailOtpService $emailOtp,
    ) {
    }

    /**
     * @return string[] Méthodes actives (ex: ['totp'], ['email'], ['totp', 'email'])
     */
    public function enabledMethods(int $userId): array
    {
        return TwoFactorMethod::where('user_id', $userId)
            ->where('enabled', true)
            ->pluck('method')
            ->all();
    }

    /**
     * Déclenche ce qu'il faut pour que l'utilisateur puisse saisir un code :
     * envoie un email pour 'email', ne fait rien pour 'totp' (l'utilisateur
     * consulte simplement son app).
     */
    public function challenge(int $userId, string $method, ?string $emailAddress = null): void
    {
        if ($method === 'email' && $emailAddress) {
            $this->emailOtp->sendChallenge($userId, $emailAddress);
        }
    }

    /**
     * Vérifie un code pour une méthode ACTIVE (enabled=true) de cet
     * utilisateur. Pour la confirmation d'activation d'une méthode pas
     * encore active, utilisez directement TotpService/EmailOtpService.
     */
    public function verify(int $userId, string $method, string $code): bool
    {
        $record = TwoFactorMethod::where('user_id', $userId)
            ->where('method', $method)
            ->where('enabled', true)
            ->first();

        if (!$record) {
            return false;
        }

        return match ($method) {
            'totp' => $this->totp->verify($record->secret, $code),
            'email' => $this->emailOtp->verify($userId, $code),
            default => false,
        };
    }
}
