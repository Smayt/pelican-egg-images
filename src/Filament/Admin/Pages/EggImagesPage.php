<?php
namespace Smayt\EggImages\Filament\Admin\Pages;

use App\Models\Egg;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Smayt\EggImages\Services\IgdbImageService;
use Smayt\EggImages\Services\SteamImageService;

class EggImagesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'egg-images';
    protected static ?string $title = 'Egg Images';
    protected string $view = 'egg-images::egg-images-page';

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'tabler-photo-search';
    }

    public static function getNavigationSort(): ?int
    {
        return 50;
    }

    public function schema(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $defaultIcon = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(public_path('pelican.svg')));

        return $table
            ->query(Egg::query())
            ->searchable(true)
            ->defaultPaginationPageOption(25)
            ->columns([
                ImageColumn::make('icon')
                    ->label('')
                    ->alignCenter()
                    ->circular()
                    ->getStateUsing(fn (Egg $record) => $record->icon ?: $defaultIcon),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('steam_app_id')
                    ->label('Steam App ID')
                    ->getStateUsing(fn (Egg $record) => app(SteamImageService::class)->getSteamAppId($record) ?? '—'),
                TextColumn::make('protected')
                    ->label('Protected')
                    ->badge()
                    ->getStateUsing(fn (Egg $record) => app(SteamImageService::class)->isProtected($record) ? 'Yes' : 'No')
                    ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'gray'),
                TextColumn::make('has_image')
                    ->label('Has Image')
                    ->badge()
                    ->getStateUsing(fn (Egg $record) => $record->icon ? 'Yes' : 'No')
                    ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'danger'),
            ])
            ->recordActions([
                Action::make('fetch_steam')
                    ->label('Fetch Steam')
                    ->icon('tabler-brand-steam')
                    ->color('gray')
                    ->form([
                        TextInput::make('steam_app_id')
                            ->label('Steam App ID')
                            ->numeric()
                            ->required()
                            ->placeholder('e.g. 892970 for Valheim')
                            ->default(fn (Egg $record) => app(SteamImageService::class)->getSteamAppId($record)),
                    ])
                    ->action(function (Egg $record, array $data) {
                        $steamService = app(SteamImageService::class);
                        $appId = (int) $data['steam_app_id'];
                        if ($steamService->fetchByAppId($record, $appId)) {
                            Notification::make()->title('Image fetched from Steam')->success()->send();
                        } else {
                            Notification::make()->title('Failed to fetch from Steam')->body('Check the App ID and try again.')->danger()->send();
                        }
                    }),

                Action::make('fetch_igdb')
                    ->label('Fetch IGDB')
                    ->icon('tabler-device-gamepad-2')
                    ->color('gray')
                    ->visible(fn () => app(IgdbImageService::class)->isConfigured())
                    ->form([
                        TextInput::make('search')
                            ->label('Search term')
                            ->required()
                            ->default(fn (Egg $record) => $record->name),
                    ])
                    ->action(function (Egg $record, array $data) {
                        $igdbService = app(IgdbImageService::class);
                        $steamService = app(SteamImageService::class);
                        $searchEgg = clone $record;
                        $searchEgg->name = $data['search'];
                        if ($igdbService->fetchByName($searchEgg)) {
                            $steamService->setProtected($record);
                            Notification::make()->title('Image fetched from IGDB')->success()->send();
                        } else {
                            Notification::make()->title('Failed to fetch from IGDB')->danger()->send();
                        }
                    }),

                Action::make('toggle_protection')
                    ->label(fn (Egg $record) => app(SteamImageService::class)->isProtected($record) ? 'Unprotect' : 'Protect')
                    ->icon(fn (Egg $record) => app(SteamImageService::class)->isProtected($record) ? 'tabler-lock-open' : 'tabler-lock')
                    ->color(fn (Egg $record) => app(SteamImageService::class)->isProtected($record) ? 'warning' : 'success')
                    ->action(function (Egg $record) {
                        $steamService = app(SteamImageService::class);
                        if ($steamService->isProtected($record)) {
                            $steamService->removeProtected($record);
                            Notification::make()->title('Protection removed')->success()->send();
                        } else {
                            $steamService->setProtected($record);
                            Notification::make()->title('Image protected')->success()->send();
                        }
                    }),

                Action::make('clear_icon')
                    ->label('Clear')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->closeModalByEscaping(false)
                    ->action(function (Egg $record) {
                        $steamService = app(SteamImageService::class);
                        foreach (['png', 'jpg', 'webp'] as $ext) {
                            $path = Egg::getIconStoragePath() . "/{$record->uuid}.{$ext}";
                            if (Storage::disk('public')->exists($path)) {
                                Storage::disk('public')->delete($path);
                            }
                        }
                        $steamService->removeProtected($record);
                        Notification::make()->title('Image cleared')->success()->send();
                    }),
            ])
            ->toolbarActions([
                Action::make('bulk_steam')
                    ->label('Auto-fetch all from Steam')
                    ->icon('tabler-brand-steam')
                    ->requiresConfirmation()
                    ->closeModalByEscaping(false)
                    ->modalDescription('Fetches Steam artwork for all unprotected eggs without an image.')
                    ->action(function () {
                        $steamService = app(SteamImageService::class);
                        $eggs = Egg::all();
                        $fetched = 0; $skipped = 0; $failed = 0;
                        foreach ($eggs as $egg) {
                            if ($steamService->isProtected($egg) || $egg->icon) { $skipped++; continue; }
                            $steamService->fetchByName($egg) ? $fetched++ : $failed++;
                            usleep(200000);
                        }
                        Notification::make()->title("Steam bulk: {$fetched} fetched, {$skipped} skipped, {$failed} failed")->success()->send();
                        $this->redirect(request()->header('Referer'));
                    }),

                Action::make('bulk_igdb')
                    ->label('Auto-fetch missing from IGDB')
                    ->icon('tabler-device-gamepad-2')
                    ->visible(fn () => app(IgdbImageService::class)->isConfigured())
                    ->requiresConfirmation()
                    ->closeModalByEscaping(false)
                    ->modalDescription('Fetches IGDB artwork for all unprotected eggs still missing an image.')
                    ->action(function () {
                        $steamService = app(SteamImageService::class);
                        $igdbService = app(IgdbImageService::class);
                        $eggs = Egg::all();
                        $fetched = 0; $skipped = 0; $failed = 0;
                        foreach ($eggs as $egg) {
                            if ($steamService->isProtected($egg) || $egg->icon) { $skipped++; continue; }
                            if ($igdbService->fetchByName($egg)) { $steamService->setProtected($egg); $fetched++; } else { $failed++; }
                            usleep(250000);
                        }
                        Notification::make()->title("IGDB bulk: {$fetched} fetched, {$skipped} skipped, {$failed} failed")->success()->send();
                    }),
            ]);
    }
}
