<?php

namespace Up2Dev\UserTotp\Contracts;

use Illuminate\Http\JsonResponse;

/**
 * Implémentez cette interface côté application hôte si vous voulez que les
 * réponses des routes du package (setup/enable/disable) suivent le même
 * format d'enveloppe que le reste de votre API (ex: { meta, data } côté
 * lume-pack/foundation), au lieu du JSON à plat par défaut.
 *
 * Voir config/user-totp.php -> 'response_formatter'.
 */
interface ResponseFormatter
{
    public function format(mixed $body, int $status = 200): JsonResponse;
}
