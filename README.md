# Learning OS

Laravel backend/API platform for ConvoLab, Currio, and future learning products.

Learning OS owns authentication, content generation, study cards and FSRS review
scheduling, incremental sync, media, Daily Audio, knowledge profiles, and compatibility
contracts used by the ConvoLab web and iOS clients.

## Requirements

- PHP 8.3 or newer (CI and deployment currently use PHP 8.5)
- Composer 2
- Node.js 24
- npm 11

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
```

## Development

```bash
composer run dev
```

## Verification

```bash
composer run lint
composer run test
npm run build
```

## Architecture Direction

See [docs/architecture.md](docs/architecture.md) for the current platform architecture notes.

- Keep controllers focused on HTTP concerns.
- Put business operations in actions or services.
- Keep persistence in models and migrations.
- Use policies for authorization.
- Use resources or transformers for API response shape.
- Build shared flashcard, review, media, and sync behavior in explicit domains as the need appears.
- Keep ConvoLab and Currio product-specific behavior out of shared domains unless the sharing pressure is real.

## ConvoLab study compatibility

- `POST /api/study/offline-reserve` returns the authenticated user's scheduled cards due
  within five days plus five days of their configured new-card target.
- `GET /api/study/cards/{cardId}` returns a canonical card by server or client-generated
  identifier for incremental client sync.
- Daily Audio produces drill, dialogue, and story tracks. Provider failures fall back to
  deterministic card-based scripts so a practice remains usable.
- Manual card drafts receive generated reading, meaning, pitch-accent, and media
  enrichment without overwriting concurrent user edits.
- Content generation has no monthly entitlement or cooldown quota. Short-window endpoint
  rate limits remain as operational abuse protection.
