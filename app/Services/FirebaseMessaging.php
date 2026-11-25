<?php

namespace App\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

#[Singleton]
class FirebaseMessaging
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private ?string $accessToken = null;

    private int $expiresAt = 0;

    private string $projectId;

    public function authorize(): void
    {
        if ($this->accessToken !== null && $this->expiresAt > now()->timestamp + 60) {
            return;
        }

        $path = config('services.fcm.credentials');
        $realPath = is_string($path) && $path !== '' ? realpath($path) : false;
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException('Fichier de compte de service Firebase indisponible.');
        }
        $credentials = json_decode(file_get_contents($realPath), true);
        if (($credentials['type'] ?? null) !== 'service_account'
            || ! is_string($credentials['project_id'] ?? null)
            || ! preg_match('/\A[a-z0-9-]+\z/', $credentials['project_id'])
            || ! filter_var($credentials['client_email'] ?? null, FILTER_VALIDATE_EMAIL)
            || ! is_string($credentials['private_key'] ?? null)) {
            throw new RuntimeException('Fichier de compte de service Firebase invalide.');
        }

        $now = now()->timestamp;
        $claims = [
            'iss' => $credentials['client_email'],
            'aud' => self::TOKEN_URL,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $unsigned = $this->base64Url('{"alg":"RS256","typ":"JWT"}').'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private($credentials['private_key']);
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature du compte de service Firebase indisponible.');
        }

        try {
            $response = $this->http()->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$this->base64Url($signature),
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Connexion au service OAuth Google indisponible.');
        }

        $token = $response->json('access_token');
        $ttl = $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_numeric($ttl) || $ttl <= 0) {
            throw new RuntimeException('Authentification Firebase refusée (HTTP '.$response->status().').');
        }

        $this->projectId = $credentials['project_id'];
        $this->accessToken = $token;
        $this->expiresAt = $now + min(3600, (int) $ttl);
    }

    public function send(#[SensitiveParameter] string $deviceToken, string $notificationId): Response
    {
        try {
            $response = $this->http()->withToken($this->accessToken)->post(
                'https://fcm.googleapis.com/v1/projects/'.$this->projectId.'/messages:send',
                ['message' => [
                    'token' => $deviceToken,
                    'notification' => ['title' => 'ANINF', 'body' => 'Vous avez une nouvelle notification.'],
                    'data' => ['notification_id' => $notificationId],
                    'android' => ['notification' => ['tag' => $notificationId]],
                    'apns' => ['headers' => ['apns-collapse-id' => $notificationId]],
                    'webpush' => ['notification' => ['tag' => $notificationId]],
                ]],
            );
        } catch (ConnectionException) {
            throw new RuntimeException('Connexion au service de notifications push indisponible.');
        }
        if ($response->unauthorized()) {
            $this->accessToken = null;
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
    }

    private function base64Url(#[SensitiveParameter] string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
