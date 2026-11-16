<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\{Cache, Http};
use RuntimeException;

class FirebaseGateway
{
    public function customToken(string $uid, array $claims): string
    {
        $account = $this->account();
        $now = time();
        return JWT::encode([
            'iss' => $account['client_email'], 'sub' => $account['client_email'],
            'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            'iat' => $now, 'exp' => $now + 3600, 'uid' => $uid,
            'claims' => $claims + ['access_until' => $now + 3600],
        ], $account['private_key'], 'RS256');
    }

    public function publish(array $documents): void
    {
        $base = 'projects/'.config('firebase.project_id').'/databases/(default)/documents';
        foreach (array_chunk($documents, 400, true) as $chunk) {
            $writes = [];
            foreach ($chunk as $path => $data) {
                $fields = [];
                foreach ($data as $key => $value) {
                    $fields[$key] = match (true) {
                        is_null($value) => ['nullValue' => null],
                        is_bool($value) => ['booleanValue' => $value],
                        is_int($value) => ['integerValue' => (string) $value],
                        default => ['stringValue' => (string) $value],
                    };
                }
                $writes[] = ['update' => ['name' => "$base/$path", 'fields' => $fields]];
            }
            Http::withToken($this->accessToken())->timeout(20)->post("https://firestore.googleapis.com/v1/{$base}:commit", ['writes' => $writes])->throw();
        }
    }

    public function notify(string $token, string $eventId, array $data): bool
    {
        $response = Http::withToken($this->accessToken())->timeout(15)->post(
            'https://fcm.googleapis.com/v1/projects/'.config('firebase.project_id').'/messages:send',
            ['message' => [
                'token' => $token,
                'notification' => ['title' => 'دورك الآن', 'body' => 'رقم '.$data['number'].'، توجه إلى '.$data['counter']],
                'data' => ['event_id' => $eventId],
                'webpush' => ['headers' => ['TTL' => '60'], 'notification' => ['tag' => 'queue-'.$eventId]],
            ]],
        );
        if ($response->json('error.details.0.errorCode') === 'UNREGISTERED') { return false; }
        $response->throw();
        return true;
    }

    public function customToken(string $uid, array $claims): string
    {
        $account = $this->account();
        $now = time();
        return \Firebase\JWT\JWT::encode([
            'iss' => $account['client_email'], 'sub' => $account['client_email'],
            'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            'iat' => $now, 'exp' => $now + 3600, 'uid' => $uid,
            'claims' => [...$claims, 'access_until' => $now + 3600],
        ], $account['private_key'], 'RS256');
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase-access-'.config('firebase.project_id'), 3000, function () {
            $credentials = new ServiceAccountCredentials(['https://www.googleapis.com/auth/cloud-platform', 'https://www.googleapis.com/auth/firebase.messaging'], $this->account());
            return $credentials->fetchAuthToken()['access_token'] ?? throw new RuntimeException('Firebase access token missing.');
        });
    }

    private function account(): array
    {
        $path = config('firebase.credentials');
        if (! config('firebase.enabled') || ! is_file($path)) {
            throw new RuntimeException('Firebase is not configured.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
