<?php

namespace Tests\Feature\Achievements;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AchievementCatalogApiTest extends TestCase
{
    public function test_public_catalog_describes_versioned_client_assets(): void
    {
        $response = $this->getJson('/api/achievements/catalog');

        $response
            ->assertOk()
            ->assertJsonPath('revision', 'achievement-collection-v1')
            ->assertJsonPath('status', 'production')
            ->assertJsonPath('assetBaseUrl', 'http://localhost')
            ->assertJsonPath('design.canvas', 256)
            ->assertJsonPath('design.standardAssetSize', 256)
            ->assertJsonPath('design.retinaAssetSize', 512)
            ->assertJsonPath('design.pixelDensityVariants.1x', 256)
            ->assertJsonPath('design.pixelDensityVariants.2x', 512)
            ->assertJsonPath('design.exportSizes', [64, 128, 256, 512])
            ->assertJsonPath('presentation.targetVisibleBadgeCount', 3)
            ->assertJsonPath('presentation.fillWithLockedCandidates', true)
            ->assertJsonPath('presentation.noDataFallbackTierIds.0', 'card-muncher.first-nibble')
            ->assertJsonCount(3, 'families')
            ->assertJsonCount(7, 'families.0.tiers')
            ->assertJsonPath('families.0.key', 'yearfire')
            ->assertJsonPath('families.0.metricKey', 'cards.stability_365d.count')
            ->assertJsonPath('families.0.tiers.0.earnedDescription', 'Kept 25 cards stable for a year')
            ->assertJsonPath('families.1.tiers.6.earnedDescription', 'Completed 25,000 reviews')
            ->assertJsonPath('families.2.tiers.4.earnedDescription', 'Logged 1,000 conversation minutes')
            ->assertJsonPath('families.2.metricKey', 'study.conversation.minutes')
            ->assertJsonPath('families.2.unit', 'minutes')
            ->assertJsonPath('families.0.tiers.0.assets.earned.png.128.width', 128);

        $asset = $response->json('families.0.tiers.0.assets.earned.png.128');

        $this->assertSame(
            '/achievement-assets/matsuri-light-series-v1/first-ember/earned-128.png',
            $asset['path'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $asset['checksumSha256']);
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));

        $path = public_path(ltrim($asset['path'], '/'));
        $this->assertFileExists($path);
        $this->assertSame([128, 128], array_slice(getimagesize($path), 0, 2));

        $catalog = $response->json();
        $publishedTierIds = [];

        foreach ($catalog['families'] as $family) {
            $this->assertCount(7, $family['tiers']);
            $thresholds = array_column($family['tiers'], 'threshold');
            $sortedThresholds = $thresholds;
            sort($sortedThresholds, SORT_NUMERIC);
            $this->assertSame($sortedThresholds, $thresholds, "{$family['key']} thresholds must increase.");

            foreach ($family['tiers'] as $tier) {
                $publishedTierIds[] = "{$family['key']}.{$tier['key']}";
                $this->assertNotSame('', $tier['earnedDescription']);

                foreach (['earned', 'locked'] as $state) {
                    foreach ([64, 128, 256, 512] as $size) {
                        $publishedAsset = $tier['assets'][$state]['png'][(string) $size];
                        $publishedPath = public_path(ltrim($publishedAsset['path'], '/'));

                        $this->assertSame($size, $publishedAsset['width']);
                        $this->assertSame($size, $publishedAsset['height']);
                        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $publishedAsset['checksumSha256']);
                        $this->assertFileExists($publishedPath);
                        $this->assertSame(
                            $publishedAsset['checksumSha256'],
                            hash_file('sha256', $publishedPath),
                            "{$family['key']}.{$tier['key']} {$state}-{$size} checksum must match its file.",
                        );
                        $this->assertSame([$size, $size], array_slice(getimagesize($publishedPath), 0, 2));
                    }
                }
            }
        }

        foreach ($catalog['presentation']['noDataFallbackTierIds'] as $fallbackTierId) {
            $this->assertContains($fallbackTierId, $publishedTierIds);
        }
    }

    public function test_catalog_route_is_public_and_uses_the_expected_controller(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $route = $routes
            ->first(static fn (LaravelRoute $route): bool => $route->uri() === 'api/achievements/catalog');

        $this->assertInstanceOf(LaravelRoute::class, $route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(
            'App\\Http\\Controllers\\Api\\Achievements\\ListAchievementCatalogController',
            $route->getActionName(),
        );
        $this->assertSame(['api'], $route->gatherMiddleware());

        $routeOrder = $routes
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();
        $sanctumIndex = $routeOrder->search('GET|HEAD sanctum/csrf-cookie', strict: true);
        $catalogIndex = $routeOrder->search('GET|HEAD api/achievements/catalog', strict: true);

        $this->assertIsInt($sanctumIndex);
        $this->assertIsInt($catalogIndex);
        $this->assertSame($sanctumIndex + 1, $catalogIndex);
    }
}
