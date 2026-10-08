<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class BuildAdminSwaggerCommand extends Command
{
    protected $signature = 'swagger:admin {--check : Only report drift, write nothing}';

    protected $description = 'Regenerate resources/swagger/admin.json from openapi.json, keeping the endpoints an admin can call';

    /**
     * The IoT gateway endpoints, left out on purpose.
     *
     * They are the device talking to us, not a person using the admin screens,
     * and listing them next to the dashboard invites someone to wire them up.
     */
    private const GATEWAY_PATHS = [
        '/api/iot/logs',
        '/api/iot/sync',
    ];

    public function handle(): int
    {
        $source = resource_path('swagger/openapi.json');

        if (! is_file($source)) {
            $this->error("openapi.json not found at {$source}");

            return self::FAILURE;
        }

        $spec = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);

        $reachable = $this->adminReachablePaths();

        $missing = array_values(array_diff($reachable, array_keys($spec['paths'])));
        if ($missing !== []) {
            $this->warn('Routes with no entry in openapi.json, so they cannot be copied:');
            foreach ($missing as $path) {
                $this->line("  - {$path}");
            }
            $this->newLine();
        }

        $dropped = [];

        foreach (array_keys($spec['paths']) as $path) {
            if (in_array($path, self::GATEWAY_PATHS, true) || ! in_array($path, $reachable, true)) {
                unset($spec['paths'][$path]);
                $dropped[] = $path;
            }
        }

        $spec['components']['schemas'] = $this->referencedSchemas($spec);

        $spec['info']['title'] = 'Ice Factory API — Admin';
        $spec['info']['description'] = $this->description();

        if ($this->option('check')) {
            $target = resource_path('swagger/admin.json');

            if (! is_file($target)) {
                $this->error('admin.json does not exist yet. Run without --check.');

                return self::FAILURE;
            }

            $current = trim(file_get_contents($target));
            $rebuilt = $this->encode($spec);

            if ($current === trim($rebuilt)) {
                $this->info('admin.json is up to date.');

                return self::SUCCESS;
            }

            $this->error('admin.json is stale. Run: php artisan swagger:admin');

            return self::FAILURE;
        }

        file_put_contents(resource_path('swagger/admin.json'), $this->encode($spec));

        $this->info(sprintf(
            'admin.json written: %d endpoints, %d of %d schemas.',
            count($spec['paths']),
            count($spec['components']['schemas']),
            count(json_decode(file_get_contents($source), true)['components']['schemas'] ?? []),
        ));

        foreach ($dropped as $path) {
            $this->line("  - {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * Every path the role middleware lets an ADMIN reach, taken from the live
     * route table so the generated document cannot claim access the code denies.
     *
     * The auth routes are added by hand: they carry no role because they are what
     * you call before you have a role.
     *
     * @return list<string>
     */
    private function adminReachablePaths(): array
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods()) && ! in_array('POST', $route->methods())) {
                continue;
            }

            $roles = $this->rolesOn($route->gatherMiddleware());

            if ($roles === null || ! in_array('ADMIN', $roles, true)) {
                continue;
            }

            $paths[] = '/'.ltrim($route->uri(), '/');
        }

        foreach (['login', 'logout', 'logout-all', 'me', 'refresh'] as $auth) {
            $paths[] = "/api/auth/{$auth}";
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int, mixed>  $middleware
     * @return list<string>|null null when the route has no role at all
     */
    private function rolesOn(array $middleware): ?array
    {
        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (preg_match('/^role:(.+)$/', $entry, $matches)) {
                return array_map('trim', explode(',', $matches[1]));
            }
        }

        return null;
    }

    /**
     * Keep only the schemas something in the document still points at.
     *
     * Without this the file drags along every driver-only object, which is noise
     * for whoever reads the admin contract.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function referencedSchemas(array $spec): array
    {
        $names = [];

        $walk = function ($node) use (&$walk, &$names): void {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $names[] = basename($value);
                }

                $walk($value);
            }
        };

        $walk($spec);

        $schemas = $spec['components']['schemas'] ?? [];

        return array_intersect_key($schemas, array_flip(array_unique($names)));
    }

    private function encode(array $spec): string
    {
        return json_encode(
            $spec,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }

    private function description(): string
    {
        return implode("\n", [
            'Kontrak API untuk peran **ADMIN** saja. Dokumen ini diturunkan dari',
            '`openapi.json` yang sama, jadi tidak ada nilai atau contoh yang berbeda',
            'antara keduanya. Regenerasi dengan `php artisan swagger:admin`.',
            '',
            'Yang tidak ada di sini memang tidak bisa dipanggil admin, jadi tidak perlu',
            'dikerjakan di layar admin. Sisanya dua kelompok:',
            '',
            '- Hanya admin (`role:ADMIN`), misalnya dashboard, sales, settlement, user.',
            '- Dipakai bersama (`role:ADMIN,WAREHOUSE,DRIVER`), jadi datanya sama untuk semua peran.',
            '',
            '`/api/iot/logs` dan `/api/iot/sync` sengaja tidak disertakan: keduanya untuk',
            'perangkat gateway, bukan layar admin.',
            '',
            'Semua response dibungkus `{ "success": bool, "data": ... }`. Kesalahan: 401 untuk',
            'token salah atau habis masa berlaku, 422 untuk validasi gagal, 403 untuk role',
            'yang tidak punya akses.',
        ]);
    }
}
