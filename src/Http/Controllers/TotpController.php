<?php

namespace Up2Dev\UserTotp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Up2Dev\UserTotp\Models\TwoFactorMethod;
use Up2Dev\UserTotp\Services\EmailOtpService;
use Up2Dev\UserTotp\Services\PendingLoginTokenService;
use Up2Dev\UserTotp\Services\TotpService;
use Up2Dev\UserTotp\Services\TwoFactorManager;

class TotpController extends Controller
{
    public function __construct(
        protected TotpService $totp,
        protected EmailOtpService $emailOtp,
        protected PendingLoginTokenService $pendingLogin,
        protected TwoFactorManager $manager,
    ) {
    }

    protected function respond(mixed $body, int $status = 200): JsonResponse
    {
        $formatterClass = config('user-totp.response_formatter');

        if ($formatterClass && class_exists($formatterClass)) {
            return app($formatterClass)->format($body, $status);
        }

        return response()->json($body, $status);
    }

    protected function isKnownMethod(string $method): bool
    {
        return in_array($method, config('user-totp.enabled_methods', ['totp', 'email']), true);
    }

    /**
     * -------------------------------------------------------------------
     * Self-service (utilisateur déjà authentifié)
     * -------------------------------------------------------------------
     */

    /**
     * GET /{prefix}/methods
     * Liste les méthodes disponibles sur le projet et celles déjà actives
     * pour l'utilisateur courant — utile pour un écran de paramètres.
     */
    public function methods(Request $request): JsonResponse
    {
        return $this->respond([
            'available' => config('user-totp.enabled_methods', ['totp', 'email']),
            'enabled'   => $request->user()->enabledTwoFactorMethods(),
        ]);
    }

    /**
     * POST /{prefix}/{method}/setup
     * totp  : génère un secret (pas encore actif), renvoie secret + otpauth_uri
     * email : envoie un code de confirmation à l'email de l'utilisateur
     */
    public function setup(Request $request, string $method): JsonResponse
    {
        if (!$this->isKnownMethod($method)) {
            return $this->respond(['message' => 'Méthode inconnue.'], 422);
        }

        $user = $request->user();
        $record = TwoFactorMethod::firstOrNew(['user_id' => $user->id, 'method' => $method]);

        if ($record->exists && $record->enabled) {
            return $this->respond(['message' => 'Cette méthode est déjà activée.'], 409);
        }

        if ($method === 'totp') {
            $secret = $this->totp->generateSecret();
            $record->fill([
                'user_id' => $user->id,
                'method' => 'totp',
                'secret' => $secret,
                'enabled' => false,
                'confirmed_at' => null,
            ])->save();

            return $this->respond([
                'secret' => $secret,
                'otpauth_uri' => $this->totp->otpAuthUri($secret, $user->email ?? (string) $user->id),
            ]);
        }

        // method === 'email'
        $record->fill([
            'user_id' => $user->id,
            'method' => 'email',
            'secret' => null,
            'enabled' => false,
            'confirmed_at' => null,
        ])->save();

        $this->manager->challenge($user->id, 'email', $user->email);

        return $this->respond(['message' => "Un code a été envoyé par email à {$user->email}."]);
    }

    /**
     * POST /{prefix}/{method}/enable
     * Body: { code }
     */
    public function enable(Request $request, string $method): JsonResponse
    {
        if (!$this->isKnownMethod($method)) {
            return $this->respond(['message' => 'Méthode inconnue.'], 422);
        }

        $request->validate(['code' => 'required|string']);

        $user = $request->user();
        $record = TwoFactorMethod::where('user_id', $user->id)->where('method', $method)->first();

        if (!$record) {
            return $this->respond(
                ['message' => "Aucune procédure d'activation en cours pour cette méthode, relancez /{$method}/setup."],
                422
            );
        }

        $valid = match ($method) {
            'totp' => $this->totp->verify($record->secret, $request->input('code')),
            'email' => $this->emailOtp->verify($user->id, $request->input('code')),
            default => false,
        };

        if (!$valid) {
            return $this->respond(['message' => 'Code invalide.'], 422);
        }

        $record->enabled = true;
        $record->confirmed_at = now();
        $record->save();

        return $this->respond(['message' => 'Méthode activée.']);
    }

    /**
     * POST /{prefix}/{method}/disable
     * Refusé si retirer cette méthode laisserait le compte sans aucune
     * double authentification alors qu'elle est obligatoire.
     */
    public function disable(Request $request, string $method): JsonResponse
    {
        if (!$this->isKnownMethod($method)) {
            return $this->respond(['message' => 'Méthode inconnue.'], 422);
        }

        $user = $request->user();

        if (method_exists($user, 'canDisableTwoFactorMethod') && !$user->canDisableTwoFactorMethod($method)) {
            return $this->respond(
                ['message' => 'Vous devez garder au moins une méthode de double authentification active.'],
                403
            );
        }

        TwoFactorMethod::where('user_id', $user->id)->where('method', $method)->delete();

        return $this->respond(['message' => 'Méthode désactivée.']);
    }

    /**
     * -------------------------------------------------------------------
     * Enrôlement forcé pendant le login (config('user-totp.enforced'))
     * -------------------------------------------------------------------
     * Volontairement hors middleware d'auth : pas de session à ce stade,
     * l'identité passe par le pending_token émis lors du login.
     */

    protected function resolveEnrollingUserId(Request $request): ?int
    {
        $request->validate(['pending_token' => 'required|string']);

        return $this->pendingLogin->peek($request->input('pending_token'));
    }

    public function enrollSetup(Request $request, string $method): JsonResponse
    {
        if (!$this->isKnownMethod($method)) {
            return $this->respond(['message' => 'Méthode inconnue.'], 422);
        }

        $userId = $this->resolveEnrollingUserId($request);

        if ($userId === null) {
            return $this->respond(['message' => 'Session de connexion expirée, reconnectez-vous.'], 401);
        }

        $record = TwoFactorMethod::firstOrNew(['user_id' => $userId, 'method' => $method]);

        if ($record->exists && $record->enabled) {
            return $this->respond(['message' => 'Cette méthode est déjà activée.'], 409);
        }

        $label = $this->pendingLogin->peekLabel($request->input('pending_token')) ?? (string) $userId;

        if ($method === 'totp') {
            $secret = $this->totp->generateSecret();
            $record->fill([
                'user_id' => $userId,
                'method' => 'totp',
                'secret' => $secret,
                'enabled' => false,
                'confirmed_at' => null,
            ])->save();

            return $this->respond([
                'secret' => $secret,
                'otpauth_uri' => $this->totp->otpAuthUri($secret, $label),
            ]);
        }

        // method === 'email'
        $record->fill([
            'user_id' => $userId,
            'method' => 'email',
            'secret' => null,
            'enabled' => false,
            'confirmed_at' => null,
        ])->save();

        $this->manager->challenge($userId, 'email', $label);

        return $this->respond(['message' => "Un code a été envoyé par email à {$label}."]);
    }

    public function enrollEnable(Request $request, string $method): JsonResponse
    {
        if (!$this->isKnownMethod($method)) {
            return $this->respond(['message' => 'Méthode inconnue.'], 422);
        }

        $request->validate(['code' => 'required|string']);

        $pendingToken = $request->input('pending_token');
        $userId = $this->resolveEnrollingUserId($request);

        if ($userId === null) {
            return $this->respond(['message' => 'Session de connexion expirée, reconnectez-vous.'], 401);
        }

        $record = TwoFactorMethod::where('user_id', $userId)->where('method', $method)->first();

        if (!$record) {
            return $this->respond(
                ['message' => "Aucune procédure d'activation en cours, relancez l'enrôlement."],
                422
            );
        }

        $valid = match ($method) {
            'totp' => $this->totp->verify($record->secret, $request->input('code')),
            'email' => $this->emailOtp->verify($userId, $request->input('code')),
            default => false,
        };

        if (!$valid) {
            $this->pendingLogin->registerFailedAttempt($pendingToken);
            return $this->respond(['message' => 'Code invalide.'], 422);
        }

        $record->enabled = true;
        $record->confirmed_at = now();
        $record->save();

        return $this->respond([
            'message' => 'Méthode activée, terminez la connexion via la vérification de login.',
        ]);
    }

    /**
     * POST /{prefix}/challenge
     * Body: { pending_token, method }
     *
     * Déclenche le challenge d'une méthode déjà active pendant le login :
     * utile quand l'utilisateur a plusieurs méthodes actives et en choisit
     * une (email a besoin qu'on lui envoie un code ; totp n'a besoin de
     * rien), ou pour renvoyer un email si le premier n'est pas arrivé.
     */
    public function challenge(Request $request): JsonResponse
    {
        $request->validate([
            'pending_token' => 'required|string',
            'method' => 'required|string',
        ]);

        $userId = $this->pendingLogin->peek($request->input('pending_token'));

        if ($userId === null) {
            return $this->respond(['message' => 'Session de connexion expirée, reconnectez-vous.'], 401);
        }

        $method = $request->input('method');

        if (!in_array($method, $this->manager->enabledMethods($userId), true)) {
            return $this->respond(['message' => 'Méthode non activée pour ce compte.'], 422);
        }

        $label = $this->pendingLogin->peekLabel($request->input('pending_token'));
        $this->manager->challenge($userId, $method, $label);

        return $this->respond([
            'message' => $method === 'email'
                ? 'Code envoyé par email.'
                : "Consultez votre application d'authentification.",
        ]);
    }
}
