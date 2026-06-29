<?php
namespace Smayt\EggImages\Services;

use App\Models\Egg;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SteamImageService
{
    public function fetchByAppId(Egg $egg, int $appId): bool
    {
        $url = "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appId}/header.jpg";

        try {
            $response = Http::timeout(10)->get($url);

            if (!$response->successful()) {
                return false;
            }

            $egg->writeIcon('jpg', $response->body());
            $this->setSteamAppId($egg, $appId);
            $this->setProtected($egg);

            return true;
        } catch (Exception $e) {
            Log::warning("egg-images: Steam fetch failed for egg {$egg->id}: " . $e->getMessage());
            return false;
        }
    }

    public function fetchByName(Egg $egg): bool
    {
        if ($this->isProtected($egg)) {
            return false;
        }

        $appId = $this->searchAppId($egg->name);
        if (!$appId) {
            return false;
        }

        $url = "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appId}/header.jpg";

        try {
            $response = Http::timeout(10)->get($url);

            if (!$response->successful()) {
                return false;
            }

            $egg->writeIcon('jpg', $response->body());
            $this->setSteamAppId($egg, $appId);

            return true;
        } catch (Exception $e) {
            Log::warning("egg-images: Steam auto-fetch failed for egg {$egg->id}: " . $e->getMessage());
            return false;
        }
    }

    private function searchAppId(string $name): ?int
    {
        try {
            $response = Http::timeout(10)->get('https://store.steampowered.com/api/storesearch/', [
                'term' => $name,
                'l' => 'english',
                'cc' => 'US',
            ]);

            if (!$response->successful()) {
                return null;
            }

            $items = $response->json('items', []);
            if (empty($items)) {
                return null;
            }

            return $items[0]['id'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function getSteamAppId(Egg $egg): ?int
    {
        foreach ($egg->tags as $tag) {
            if (str_starts_with($tag, 'steam:')) {
                return (int) str_replace('steam:', '', $tag);
            }
        }
        return null;
    }

    public function setSteamAppId(Egg $egg, int $appId): void
    {
        $tags = array_filter($egg->tags, fn ($t) => !str_starts_with($t, 'steam:'));
        $tags[] = "steam:{$appId}";
        $egg->tags = array_values($tags);
        $egg->save();
    }

    public function isProtected(Egg $egg): bool
    {
        return in_array('icon:protected', $egg->tags);
    }

    public function setProtected(Egg $egg): void
    {
        if (!$this->isProtected($egg)) {
            $tags = $egg->tags;
            $tags[] = 'icon:protected';
            $egg->tags = $tags;
            $egg->save();
        }
    }

    public function removeProtected(Egg $egg): void
    {
        $tags = array_filter($egg->tags, fn ($t) => $t !== 'icon:protected');
        $egg->tags = array_values($tags);
        $egg->save();
    }
}
