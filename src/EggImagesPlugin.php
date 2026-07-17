<?php
namespace Smayt\EggImages;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;

class EggImagesPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'egg-images';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            $panel->discoverPages(
                plugin_path($this->getId(), 'src/Filament/Admin/Pages'),
                'Smayt\\EggImages\\Filament\\Admin\\Pages'
            );
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return config('egg-images');
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Steam Settings')
                ->schema([
                    Toggle::make('steam_auto_fetch')
                        ->label('Auto-fetch from Steam by default')
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip('When enabled, bulk fetch will try Steam first for all unprotected eggs without images.')
                        ->inline(false)
                        ->default(fn () => config('egg-images.steam_auto_fetch')),
                ]),
            Section::make('IGDB Settings')
                ->description('Get your credentials at https://dev.twitch.tv/console')
                ->columns(2)
                ->schema([
                    TextInput::make('igdb_client_id')
                        ->label('Twitch Client ID')
                        ->password()
                        ->revealable()
                        ->default(fn () => config('egg-images.igdb_client_id')),
                    TextInput::make('igdb_client_secret')
                        ->label('Twitch Client Secret')
                        ->password()
                        ->revealable()
                        ->default(fn () => config('egg-images.igdb_client_secret')),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'EGG_IMAGES_STEAM_AUTO_FETCH' => $data['steam_auto_fetch'] ? 'true' : 'false',
            'EGG_IMAGES_IGDB_CLIENT_ID' => $data['igdb_client_id'] ?? '',
            'EGG_IMAGES_IGDB_CLIENT_SECRET' => $data['igdb_client_secret'] ?? '',
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
