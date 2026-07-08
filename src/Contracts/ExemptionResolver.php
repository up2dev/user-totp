<?php

namespace Up2Dev\UserTotp\Contracts;

/**
 * Implémentez cette interface côté application hôte pour exempter certains
 * utilisateurs (par rôle, permission, type de compte...) de l'obligation
 * TOTP quand config('user-totp.enforced') est actif.
 *
 * Voir config/user-totp.php -> 'exemption_resolver'.
 */
interface ExemptionResolver
{
    public function isExempt(mixed $user): bool;
}
