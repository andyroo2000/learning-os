# Achievement collection v1

This collection is the client-facing Learning OS manifest for the first 21 raster achievement badges.

- `Roarer` uses Friendly Kaiju metaphors for conversation milestones.
- `Card Muncher` uses Friendly Kaiju metaphors for completed reviews.
- `Matsuri Light` uses non-character festival-light metaphors for cards with one year of memory stability.
- Every family has seven earned images and seven deterministic grayscale locked images at 64, 128, 256, and 512 pixels.
- Clients display the standard 256-pixel asset at 256 × 256. High-density displays use the 512-pixel asset at the same visual size.
- The 64- and 128-pixel files remain available only as optional compact-layout fallbacks.
- All production badge images share the same handmade frame and contain no text. Web and iOS clients render the flexible caption plate below the image.
- `resources/achievements/achievement-collection-v1/catalog.source.json` owns names, thresholds, descriptions, fallback candidates, and ranking behavior.
- The three family source directories own complete generated interior scenes, exact prompt logs, and deterministic shared-frame build configuration.

Build everything with `npm run build:achievements`. Verify committed outputs with `npm run check:achievements`.

The public API reads `public/achievement-assets/achievement-collection-v1/catalog.json`; asset entries point at immutable family revision directories.
