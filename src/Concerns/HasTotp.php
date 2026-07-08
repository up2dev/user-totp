<?php

namespace Up2Dev\UserTotp\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Up2Dev\UserTotp\Models\TwoFactorMethod;

/**
 * À utiliser sur le modèle User de l'application hôte :
 *
 *   use Up2Dev\UserTotp\Concerns\HasTotp;
 *
 *   class User extends ... {
 *       use HasTotp;
 *   }
 */
trait HasTotp
{
    public function twoFactorMethods(): HasMany
    {
        return $this->hasMany(TwoFactorMethod::class, 'user_id');
    }

    /**
     * @return string[] ex: ['totp'], ['email'], ['totp', 'email'], []
     */
    public function enabledTwoFactorMethods(): array
    {
        return $this->twoFactorMethods()->where('enabled', true)->pluck('method')->all();
    }

    public function hasTwoFactorMethodEnabled(string $method): bool
    {
        return in_array($method, $this->enabledTwoFactorMethods(), true);
    }

    public function hasAnyTwoFactorEnabled(): bool
    {
        return !empty($this->enabledTwoFactorMethods());
    }

    /**
     * Conservé pour compatibilité avec le code déjà écrit avant l'ajout du
     * multi-méthodes : équivaut à hasTwoFactorMethodEnabled('totp').
     */
    public function hasTotpEnabled(): bool
    {
        return $this->hasTwoFactorMethodEnabled('totp');
    }

    public function isTotpExempt(): bool
    {
        $resolverClass = config('user-totp.exemption_resolver');

        if ($resolverClass && class_exists($resolverClass)) {
            return (bool) app($resolverClass)->isExempt($this);
        }

        return false;
    }

    /**
     * True si cet utilisateur doit obligatoirement s'enrôler (au moins une
     * méthode) avant de pouvoir continuer sa connexion.
     */
    public function mustEnrollTotp(): bool
    {
        if ($this->hasAnyTwoFactorEnabled()) {
            return false;
        }

        if (!config('user-totp.enforced', false)) {
            return false;
        }

        return !$this->isTotpExempt();
    }

    /**
     * True si l'utilisateur a le droit de désactiver AU MOINS une méthode
     * (utilisé par le contrôleur en dernier recours ; préférez
     * canDisableTwoFactorMethod() qui tient compte du fait qu'il doit en
     * rester au moins une active en mode obligatoire).
     */
    public function canDisableTotp(): bool
    {
        if (!config('user-totp.enforced', false)) {
            return true;
        }

        return $this->isTotpExempt();
    }

    /**
     * True si désactiver CETTE méthode précise est autorisé : toujours vrai
     * en mode opt-in ou si exempté ; en mode obligatoire, seulement s'il
     * reste au moins une autre méthode active après coup.
     */
    public function canDisableTwoFactorMethod(string $method): bool
    {
        if ($this->canDisableTotp()) {
            return true;
        }

        $remaining = array_diff($this->enabledTwoFactorMethods(), [$method]);

        return !empty($remaining);
    }
}
