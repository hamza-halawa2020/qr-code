<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
                    $fields[$key] = $this->field($value);
                }
                $writes[] = ['update' => ['name' => "$base/$path", 'fields' => $fields]];
            }
            Http::withToken($this->accessToken())->timeout(20)->post("https://firestore.googleapis.com/v1/{$base}:commit", ['writes' => $writes])->throw();
        }
    }

    public function notify(#[\SensitiveParameter] string $token, string $eventId, array $data): bool
    {
        $response = Http::withToken($this->accessToken())->timeout(15)->post(
            'https://fcm.googleapis.com/v1/projects/'.config('firebase.project_id').'/messages:send',
            ['message' => [
                'token' => $token,
                'notification' => ['title' => ($data['notification_kind'] ?? '') === 'near_turn' ? __('queue.notif_near_turn') : __('queue.notif_called_now'), 'body' => ($data['notification_kind'] ?? '') === 'near_turn' ? __('queue.notif_near_body', ['number' => $data['number']]) : __('queue.notif_called_body', ['number' => $data['number'], 'counter' => $data['counter']])],
                'data' => ['event_id' => $eventId, 'ticket_id' => $data['id'], 'kind' => $data['notification_kind'] ?? 'called', 'ticket_url' => $data['ticket_url'] ?? ''],
                'webpush' => ['headers' => ['TTL' => '60'], 'notification' => ['tag' => 'queue-'.$eventId]],
            ]],
        );
        if (collect($response->json('error.details', []))->contains(fn ($detail) => ($detail['errorCode'] ?? null) === 'UNREGISTERED')) {
            return false;
        }
        if ($response->failed()) {
            // Provider errors can echo the device token; do not put their raw
            // body into failed_jobs or application logs.
            throw new RuntimeException('Firebase messaging request failed (HTTP '.$response->status().').');
        }

        return true;
    }

    public function deleteDocuments(array $paths): void
    {
        $base = 'projects/'.config('firebase.project_id').'/databases/(default)/documents';
        foreach (array_chunk($paths, 400) as $chunk) {
            $writes = array_map(fn ($path) => ['delete' => $base.'/'.$path], $chunk);
            Http::withToken($this->accessToken())->timeout(20)->post("https://firestore.googleapis.com/v1/{$base}:commit", ['writes' => $writes])->throw();
        }
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase-access-'.config('firebase.project_id'), 3000, function () {
            $credentials = new ServiceAccountCredentials(['https://www.googleapis.com/auth/cloud-platform', 'https://www.googleapis.com/auth/firebase.messaging'], $this->account());

            return $credentials->fetchAuthToken()['access_token'] ?? throw new RuntimeException('Firebase access token missing.');
        });
    }

    private function field(mixed $value): array
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? ['arrayValue' => ['values' => array_map(fn ($item) => $this->field($item), $value)]]
                : ['mapValue' => ['fields' => array_map(fn ($item) => $this->field($item), $value)]];
        }

        return match (true) {
            is_null($value) => ['nullValue' => null],
            is_bool($value) => ['booleanValue' => $value],
            is_int($value) => ['integerValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            default => ['stringValue' => (string) $value],
        };
    }

    private function account(): array
    {
        if (! config('firebase.enabled')) {
            throw new RuntimeException('Firebase is disabled. Set FIREBASE_ENABLED=true in .env.');
        }

        $path = (string) config('firebase.credentials');
        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Firebase service account file is missing or unreadable: '.$path);
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
