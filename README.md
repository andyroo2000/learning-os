# Learning OS

Laravel backend/API platform for ConvoLab, Currio, and future learning products.

This repository is intentionally starting small. The first slice is only a bootable Laravel application with automated tests, style checks, and frontend asset build verification. Product-specific domains and shared flashcard/review/sync/media behavior should be added in later vertical slices.

## Requirements

- PHP 8.5
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

- Keep controllers focused on HTTP concerns.
- Put business operations in actions or services.
- Keep persistence in models and migrations.
- Use policies for authorization.
- Use resources or transformers for API response shape.
- Build shared flashcard, review, media, and sync behavior in explicit domains as the need appears.
- Keep ConvoLab and Currio product-specific behavior out of shared domains unless the sharing pressure is real.
