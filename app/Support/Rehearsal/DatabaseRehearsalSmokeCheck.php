<?php

namespace App\Support\Rehearsal;

use App\Models\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use JsonException;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

class DatabaseRehearsalSmokeCheck
{
    public const TOKEN_NAME = 'learning-os-rehearsal-smoke';

    /**
     * @var list<array{name: string, uri: string, required: list<string>}>
     *
     * These endpoints must stay stateless. The smoke harness dispatches them through the HTTP kernel without
     * running the terminate phase, so session-dependent or throttle-protected endpoints can leak state across
     * loop iterations.
     */
    private const ENDPOINTS = [
        [
            'name' => 'current user',
            'uri' => '/api/me',
            'required' => ['data.id', 'data.email'],
        ],
        [
            'name' => 'study settings',
            'uri' => '/api/study/settings',
            'required' => ['data.new_cards_per_day', 'data.created_at', 'data.updated_at'],
        ],
        [
            'name' => 'study overview',
            'uri' => '/api/study/overview',
            'required' => [
                'data.due_count',
                'data.failed_count',
                'data.new_count',
                'data.total_cards',
                'data.latest_import',
            ],
        ],
        [
            'name' => 'study browser',
            'uri' => '/api/study/browser?limit=1',
            'required' => ['total', 'limit', 'rows', 'filterOptions'],
        ],
        [
            'name' => 'study new queue',
            'uri' => '/api/study/new-queue?limit=1',
            'required' => ['total', 'limit', 'items'],
        ],
        [
            'name' => 'study imports',
            'uri' => '/api/study/imports?per_page=1',
            'required' => ['data', 'links', 'meta'],
        ],
        [
            'name' => 'current study import',
            'uri' => '/api/study/imports/current',
            'required' => ['data'],
        ],
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Migrator $migrator,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     connection: array{name: string, database: string|null},
     *     checks: list<array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}>
     * }
     */
    public function run(?string $userEmail = null): array
    {
        $checks = [];

        $check = $this->checkDatabaseConnection();
        $checks[] = $check;
        if (! $check['ok']) {
            return $this->report($checks);
        }

        $check = $this->checkMigrationsAreCurrent();
        $checks[] = $check;
        if (! $check['ok']) {
            return $this->report($checks);
        }

        [$userCheck, $user] = $this->resolveUser($userEmail);
        $checks[] = $userCheck;
        if ($user === null) {
            return $this->report($checks);
        }

        $user->tokens()
            ->where('name', self::TOKEN_NAME)
            ->delete();

        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addMinutes(15));

        try {
            foreach (self::ENDPOINTS as $endpoint) {
                $checks[] = $this->checkEndpoint($endpoint, $token);
            }
        } finally {
            try {
                $token->accessToken->delete();
            } catch (Throwable $exception) {
                $checks[] = $this->fail(
                    'token cleanup',
                    'Unable to delete the temporary smoke-check token; it will expire automatically.',
                    [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ],
                    softFail: true,
                );
            }
        }

        return $this->report($checks);
    }

    /**
     * @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}
     */
    private function checkDatabaseConnection(): array
    {
        try {
            DB::selectOne('select 1 as ok');
        } catch (Throwable $exception) {
            return $this->fail('database connection', 'Unable to query the configured database.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->pass('database connection', 'Connected to the configured database.');
    }

    /**
     * @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}
     */
    private function checkMigrationsAreCurrent(): array
    {
        if (! $this->migrator->repositoryExists()) {
            return $this->fail('migrations', 'The migrations table does not exist. Run php artisan migrate first.');
        }

        $migrationFiles = $this->migrator->getMigrationFiles(array_merge(
            [database_path('migrations')],
            $this->migrator->paths(),
        ));
        $migrationNames = array_keys($migrationFiles);
        $ran = $this->migrator->getRepository()->getRan();
        $pending = array_values(array_diff($migrationNames, $ran));

        if ($pending !== []) {
            return $this->fail('migrations', 'Pending migrations were found. Run php artisan migrate first.', [
                'pending' => $pending,
            ]);
        }

        $orphaned = array_values(array_diff($ran, $migrationNames));

        if ($orphaned !== []) {
            return $this->fail('migrations', 'Migration records were found without matching migration files.', [
                'orphaned' => $orphaned,
            ]);
        }

        return $this->pass('migrations', 'All database migrations are current.');
    }

    /**
     * @return array{0: array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}, 1: User|null}
     */
    private function resolveUser(?string $userEmail): array
    {
        $query = User::query()->orderBy('id');

        if ($userEmail !== null && $userEmail !== '') {
            $user = $query->where('email', $userEmail)->first();

            if ($user === null) {
                return [
                    $this->fail('auth user', "No user exists with email [{$userEmail}]."),
                    null,
                ];
            }

            return [
                $this->pass('auth user', "Selected rehearsal user [{$userEmail}]."),
                $user,
            ];
        }

        $user = $query->first();

        if ($user === null) {
            return [
                $this->fail('auth user', 'No users exist in the configured database. Pass --user-email after restoring real data.'),
                null,
            ];
        }

        return [
            $this->pass('auth user', "Selected first available user [{$user->email}]."),
            $user,
        ];
    }

    /**
     * @param  array{name: string, uri: string, required: list<string>}  $endpoint
     * @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}
     */
    private function checkEndpoint(array $endpoint, NewAccessToken $token): array
    {
        $request = Request::create($endpoint['uri'], 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token->plainTextToken,
        ]);

        try {
            Auth::forgetGuards();

            $response = $this->app->handle($request);
        } catch (Throwable $exception) {
            return $this->fail($endpoint['name'], 'Endpoint threw before returning a response.', [
                'uri' => $endpoint['uri'],
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        $status = $response->getStatusCode();
        $content = $response->getContent();

        if ($status !== 200) {
            return $this->fail($endpoint['name'], "Expected HTTP 200, received HTTP {$status}.", [
                'uri' => $endpoint['uri'],
                'body' => $this->preview($content),
            ]);
        }

        if ($content === false || $content === '') {
            return $this->fail($endpoint['name'], 'Endpoint returned an empty body.', [
                'uri' => $endpoint['uri'],
            ]);
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->fail($endpoint['name'], 'Endpoint returned invalid JSON.', [
                'uri' => $endpoint['uri'],
                'message' => $exception->getMessage(),
                'body' => $this->preview($content),
            ]);
        }

        if (! is_array($payload)) {
            return $this->fail($endpoint['name'], 'Endpoint returned a non-object JSON payload.', [
                'uri' => $endpoint['uri'],
            ]);
        }

        $missingKeys = array_values(array_filter(
            $endpoint['required'],
            fn (string $key): bool => ! Arr::has($payload, $key),
        ));

        if ($missingKeys !== []) {
            return $this->fail($endpoint['name'], 'Endpoint response is missing required keys.', [
                'uri' => $endpoint['uri'],
                'missing' => $missingKeys,
            ]);
        }

        return $this->pass($endpoint['name'], "GET {$endpoint['uri']} returned the expected response shape.");
    }

    /**
     * @param  list<array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}>  $checks
     * @return array{
     *     ok: bool,
     *     connection: array{name: string, database: string|null},
     *     checks: list<array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}>
     * }
     */
    private function report(array $checks): array
    {
        return [
            'ok' => collect($checks)->every(fn (array $check): bool => $check['ok'] || ($check['soft_fail'] ?? false)),
            'connection' => [
                'name' => config('database.default'),
                'database' => config('database.connections.'.config('database.default').'.database'),
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}
     */
    private function pass(string $name, string $message): array
    {
        return [
            'name' => $name,
            'ok' => true,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{name: string, ok: bool, message: string, meta?: array<string, mixed>, soft_fail?: bool}
     */
    private function fail(string $name, string $message, array $meta = [], bool $softFail = false): array
    {
        $check = [
            'name' => $name,
            'ok' => false,
            'message' => $message,
        ];

        if ($meta !== []) {
            $check['meta'] = $meta;
        }

        if ($softFail) {
            $check['soft_fail'] = true;
        }

        return $check;
    }

    private function preview(string|false $content): string
    {
        if ($content === false || $content === '') {
            return '';
        }

        return mb_substr($content, 0, 500);
    }
}
