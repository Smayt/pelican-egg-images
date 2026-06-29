<?php
namespace Smayt\EggImages\Services;

use App\Models\Egg;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IgdbImageService
{
    private function getAccessToken(): ?string
    {
        $clientId = config('egg-images.igdb_client_id');
        $clientSecret = config('egg-images.igdb_client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        return Cache::remember('egg-images.igdb_token', 3600 * 24 * 30, function () use ($clientId, $clientSecret) {
            $response = Http::timeout(10)->post('https://id.twitch.tv/oauth2/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            if (!$response->successful()) {
                return null;
            }

            return $response->json('access_token');
        });
    }

    public function fetchByName(Egg $egg): bool
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $clientId = config('egg-images.igdb_client_id');
        $name = addslashes($egg->name);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Client-ID' => $clientId,
                    'Authorization' => "Bearer {$token}",
                    'Content-Type' => 'text/plain',
                ])
                ->withBody("search \"{$name}\"; fields name,cover.image_id; limit 1;", 'text/plain')
                ->post('https://api.igdb.com/v4/games');

            if (!$response->successful()) {
                return false;
            }

            $games = $response->json();
            if (empty($games)) {
                return false;
            }

            $imageId = $games[0]['cover']['image_id'] ?? null;
            if (!$imageId) {
                return false;
            }

            $imageUrl = "https://images.igdb.com/igdb/image/upload/t_cover_big/{$imageId}.jpg";
            $imageResponse = Http::timeout(10)->get($imageUrl);

            if (!$imageResponse->successful()) {
                return false;
            }

            $egg->writeIcon('jpg', $imageResponse->body());

            return true;
        } catch (Exception $e) {
            Log::warning("egg-images: IGDB fetch failed for egg {$egg->id}: " . $e->getMessage());
            return false;
        }
    }

    public function isConfigured(): bool
    {
        return !empty(config('egg-images.igdb_client_id')) && !empty(config('egg-images.igdb_client_secret'));
    }
}
