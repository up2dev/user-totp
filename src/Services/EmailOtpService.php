<?php

namespace Up2Dev\UserTotp\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Up2Dev\UserTotp\Mail\EmailOtpCodeMail;

/**
 * Génère, envoie (via le mailer déjà configuré de l'host — aucun service
 * tiers) et vérifie un code à usage unique par email. Contrairement au TOTP,
 * il n'y a pas de secret partagé permanent : un nouveau code est généré à
 * chaque envoi et stocké (haché) en cache avec un TTL court.
 */
class EmailOtpService
{
    protected function store(): CacheRepository
    {
        return Cache::store(config('user-totp.cache_store'));
    }

    protected function codeKey(int $userId): string
    {
        return "user-totp:email-otp:code:{$userId}";
    }

    protected function attemptsKey(int $userId): string
    {
        return "user-totp:email-otp:attempts:{$userId}";
    }

    /**
     * Classe Mailable utilisée pour l'envoi. Configurable
     * (config('user-totp.email_otp_mailable')) pour brancher une Mailable
     * maison — ex: une classe qui étend LumePack\Foundation\Mail\BaseMail
     * pour profiter de sa traçabilité (table "sendmails" du vendor), que la
     * Mailable par défaut du package n'a pas puisqu'elle ne connaît rien à
     * LumePack. Doit accepter un unique argument string $code au
     * constructeur, comme la Mailable par défaut.
     */
    protected function mailableClass(): string
    {
        return config('user-totp.email_otp_mailable', EmailOtpCodeMail::class);
    }

    /**
     * Génère un nouveau code, l'envoie par email, et invalide tout code
     * précédent + son compteur de tentatives.
     */
    public function sendChallenge(int $userId, string $emailAddress): void
    {
        $length = (int) config('user-totp.email_otp_length', 6);
        $ttl = (int) config('user-totp.email_otp_ttl', 600);

        $code = (string) random_int(
            (int) (10 ** ($length - 1)),
            (int) (10 ** $length) - 1
        );

        $this->store()->put($this->codeKey($userId), Hash::make($code), $ttl);
        $this->store()->forget($this->attemptsKey($userId));

        $mailableClass = $this->mailableClass();
        Mail::to($emailAddress)->send(new $mailableClass($code));
    }

    /**
     * Vérifie le code soumis. Consomme le code dès succès. Invalide le code
     * après "email_otp_max_attempts" échecs pour empêcher le bruteforce.
     */
    public function verify(int $userId, string $submittedCode): bool
    {
        $hashed = $this->store()->get($this->codeKey($userId));

        if ($hashed === null) {
            return false;
        }

        $maxAttempts = (int) config('user-totp.email_otp_max_attempts', 5);
        $attempts = (int) $this->store()->get($this->attemptsKey($userId), 0);

        if ($attempts >= $maxAttempts) {
            $this->store()->forget($this->codeKey($userId));
            return false;
        }

        if (!Hash::check($submittedCode, $hashed)) {
            $ttl = (int) config('user-totp.email_otp_ttl', 600);
            $this->store()->put($this->attemptsKey($userId), $attempts + 1, $ttl);
            return false;
        }

        $this->store()->forget($this->codeKey($userId));
        $this->store()->forget($this->attemptsKey($userId));

        return true;
    }
}
