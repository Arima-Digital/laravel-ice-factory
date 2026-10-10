<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class BuildAdminSwaggerCommand extends Command
{
    protected $signature = 'swagger:admin {--check : Only report drift, write nothing}';

    protected $description = 'Regenerate resources/swagger/admin.json from openapi.json, keeping only the endpoints an admin screen actually uses';

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

    /**
     * The admin screens we have agreed on, module by module.
     *
     * This is not every endpoint an ADMIN can reach. A driver checks the freezer
     * and records the sale, warehouse inputs production; admin may call those
     * too, but they are not the admin's job, so they stay out of this document.
     * Entries are "METHOD /api/...". Agree a module, add its lines, regenerate.
     *
     * @var array<string, list<string>>
     */
    private const ADMIN_TASKS = [
        'Autentikasi' => [
            'POST /api/auth/login',
            'POST /api/auth/logout',
            'POST /api/auth/refresh',
            'GET /api/auth/me',
            'POST /api/auth/logout-all',
        ],
        'Produksi' => [
            'GET /api/productions',
            'GET /api/productions/{productionId}',
            'POST /api/productions/{productionId}/post',
        ],
        'Rencana Pengiriman' => [
            'GET /api/deliveries',
            'POST /api/deliveries',
            'PUT /api/deliveries/{deliveryId}',
            'DELETE /api/deliveries/{deliveryId}',
            'GET /api/deliveries/suggestions',
            'GET /api/deliveries/{deliveryId}',
            'GET /api/deliveries/{deliveryId}/summary',
            'GET /api/deliveries/{deliveryId}/route',
            'GET /api/deliveries/{deliveryId}/items',
            'POST /api/deliveries/{deliveryId}/post',
        ],
        'Penjualan' => [
            'GET /api/sales',
            'GET /api/sales/{saleId}',
            'GET /api/sales/summary/all',
            'GET /api/sales/date/{date}',
            'GET /api/sales/status/{status}',
            'GET /api/stores/{storeId}/sales',
            'GET /api/freezers/{freezerId}/sales',
            'POST /api/sales/{saleId}/approve',
            'POST /api/sales/{saleId}/reject',
        ],
    ];

    private const HTTP_METHODS = ['get', 'post', 'put', 'delete', 'patch', 'options', 'head'];

    /**
     * The order the admin tags read in the document. Any tag left with no path
     * after pruning is dropped, so the admin page never shows an empty section.
     *
     * @var list<string>
     */
    private const TAG_ORDER = ['Autentikasi', 'Produksi', 'Pengiriman', 'Sales'];

    /**
     * Admin-facing tag blurbs. The full spec describes each tag for every role
     * at once (driver, warehouse, admin), which reads wrong on a page that only
     * shows the admin's own endpoints. Tags not listed here keep their wording.
     *
     * @var array<string, string>
     */
    private const TAG_DESCRIPTIONS = [
        'Autentikasi' => 'Login, profil, token, dan logout. Dipanggil lebih dulu sebelum endpoint lain.',
        'Produksi' => 'Warehouse membuat produksi DRAFT; admin mengunci lewat `POST /api/productions/{productionId}/post` supaya stok good tersedia untuk pengiriman.',
        'Pengiriman' => 'Admin membuat rencana (DRAFT), menyetujuinya lewat `POST /api/deliveries/{deliveryId}/post`, dan memantau yang sedang jalan. Driver yang menekan start dan menutup run.',
        'Sales' => 'Admin meninjau penjualan yang dicatat driver: `POST /api/sales/{saleId}/approve` (CONFIRMED, masuk piutang) atau `POST /api/sales/{saleId}/reject` (VOID). Hanya CONFIRMED yang masuk piutang.',
    ];

    public function handle(): int
    {
        $source = resource_path('swagger/openapi.json');

        if (! is_file($source)) {
            $this->error("openapi.json not found at {$source}");

            return self::FAILURE;
        }

        $spec = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);

        $problems = $this->mismatches($this->approvedEndpoints());
        if ($problems !== []) {
            $this->error('Daftar tugas admin tidak cocok dengan route table:');
            foreach ($problems as $line) {
                $this->line("  - {$line}");
            }
            $this->newLine();

            return self::FAILURE;
        }

        $spec = $this->keepApproved($spec);
        $spec = $this->pruneTags($spec);

        $spec['components']['schemas'] = $this->referencedSchemas($spec);
        $spec['info']['title'] = 'Ice Factory API — Admin';
        $spec['info']['description'] = $this->description();

        if ($this->option('check')) {
            return $this->reportDrift($spec);
        }

        file_put_contents(resource_path('swagger/admin.json'), $this->encode($spec));

        $this->info(sprintf(
            'admin.json written: %d endpoints, %d modul, %d of %d schemas.',
            count($spec['paths']),
            count(self::ADMIN_TASKS),
            count($spec['components']['schemas']),
            count(json_decode(file_get_contents(resource_path('swagger/openapi.json')), true)['components']['schemas'] ?? []),
        ));

        $this->reportPending();

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> "METHOD /api/..." => module name
     */
    private function approvedEndpoints(): array
    {
        $map = [];

        foreach (self::ADMIN_TASKS as $module => $endpoints) {
            foreach ($endpoints as $endpoint) {
                $map[$endpoint] = $module;
            }
        }

        return $map;
    }

    /**
     * The approved list must match the live route table: every entry has to
     * exist and the route has to let ADMIN through. A typo would silently drop
     * a screen from the contract, so we stop instead of writing a wrong file.
     *
     * @param  array<string, string>  $approved
     * @return list<string>
     */
    private function mismatches(array $approved): array
    {
        $live = $this->liveEndpoints();
        $problems = [];

        foreach ($approved as $endpoint => $module) {
            [$method, $path] = explode(' ', $endpoint, 2);

            if (! array_key_exists($method, $live[$path] ?? [])) {
                $problems[] = "{$endpoint} (modul {$module}) tidak ada di route table";

                continue;
            }

            $roles = $live[$path][$method];

            if ($roles !== null && ! in_array('ADMIN', $roles, true)) {
                $problems[] = "{$endpoint} (modul {$module}) tidak diizinkan role ADMIN";
            }
        }

        return $problems;
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function liveEndpoints(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $roles = $this->rolesOn($route->gatherMiddleware());
            $path = '/'.ltrim($route->uri(), '/');

            foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
                if (in_array($method, $route->methods(), true)) {
                    $map[$path][$method] = $roles;
                }
            }
        }

        return $map;
    }

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

    private function keepApproved(array $spec): array
    {
        $allowed = [];

        foreach ($this->approvedEndpoints() as $endpoint => $module) {
            [$method, $path] = explode(' ', $endpoint, 2);
            $allowed[$path][$method] = true;
        }

        foreach (array_keys($spec['paths']) as $path) {
            if (in_array($path, self::GATEWAY_PATHS, true) || ! isset($allowed[$path])) {
                unset($spec['paths'][$path]);

                continue;
            }

            foreach (array_keys($spec['paths'][$path]) as $method) {
                if (in_array($method, self::HTTP_METHODS, true)
                    && ! array_key_exists(strtoupper($method), $allowed[$path])) {
                    unset($spec['paths'][$path][$method]);
                }
            }
        }

        return $spec;
    }

    /**
     * Keep only the tags that still have an endpoint, and put them in the order
     * an admin reads them. Without this the document declares every tag in the
     * full spec, so Swagger UI renders empty sections for the modules not in
     * the admin's list.
     */
    private function pruneTags(array $spec): array
    {
        $used = [];

        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['tags'] ?? [] as $tag) {
                    $used[$tag] = true;
                }
            }
        }

        $order = array_flip(self::TAG_ORDER);

        $tags = [];

        foreach ($spec['tags'] ?? [] as $tag) {
            if (! isset($used[$tag['name']])) {
                continue;
            }

            $tag['description'] = self::TAG_DESCRIPTIONS[$tag['name']] ?? $tag['description'] ?? '';

            $tags[] = $tag;
        }

        usort($tags, fn (array $a, array $b): int => ($order[$a['name']] ?? PHP_INT_MAX) <=> ($order[$b['name']] ?? PHP_INT_MAX));

        $spec['tags'] = $tags;

        return $spec;
    }

    private function reportDrift(array $spec): int
    {
        $target = resource_path('swagger/admin.json');

        if (! is_file($target)) {
            $this->error('admin.json does not exist yet. Run without --check.');

            return self::FAILURE;
        }

        if (trim(file_get_contents($target)) === trim($this->encode($spec))) {
            $this->info('admin.json is up to date.');

            return self::SUCCESS;
        }

        $this->error('admin.json is stale. Run: php artisan swagger:admin');

        return self::FAILURE;
    }

    /**
     * Show the admin-reachable endpoints we have not agreed on yet, so the next
     * module to discuss cannot be forgotten.
     */
    private function reportPending(): void
    {
        $approved = [];
        foreach (array_keys($this->approvedEndpoints()) as $endpoint) {
            [$method, $path] = explode(' ', $endpoint, 2);
            $approved[$path][$method] = true;
        }

        $pending = [];

        foreach (Route::getRoutes() as $route) {
            $roles = $this->rolesOn($route->gatherMiddleware());

            if (! is_array($roles) || ! in_array('ADMIN', $roles, true)) {
                continue;
            }

            $path = '/'.ltrim($route->uri(), '/');

            if (in_array($path, self::GATEWAY_PATHS, true)) {
                continue;
            }

            foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
                if (in_array($method, $route->methods(), true) && ! isset($approved[$path][$method])) {
                    $pending[] = "{$method} {$path}";
                }
            }
        }

        sort($pending);

        if ($pending === []) {
            return;
        }

        $this->newLine();
        $this->line('Belum disepakati (admin bisa akses, belum masuk dokumen):');
        foreach ($pending as $line) {
            $this->line("  - {$line}");
        }
    }

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
            'Kontrak API untuk **tugas utama peran ADMIN**. Dokumen ini sengaja tidak',
            'memuat semua endpoint yang bisa dipanggil admin, hanya yang benar-benar',
            'dipakai layar admin: persetujuan, perencanaan, dan laporan. Regenerasi',
            'dengan `php artisan swagger:admin`.',
            '',
            'Alur persetujuan admin:',
            '',
            '- Produksi: warehouse input DRAFT, admin kunci lewat',
            '  `POST /api/productions/{productionId}/post` (DRAFT -> POSTED).',
            '- Rencana pengiriman: admin buat DRAFT, admin approve lewat',
            '  `POST /api/deliveries/{deliveryId}/post` (DRAFT -> POSTED). Driver yang',
            '  `start` dan `complete`.',
            '- Penjualan: driver catat PENDING, admin setujui lewat',
            '  `POST /api/sales/{saleId}/approve` (CONFIRMED) atau tolak lewat',
            '  `POST /api/sales/{saleId}/reject` (VOID).',
            '',
            'Tugas driver & warehouse (cek toko, konfirmasi freezer, catat penjualan,',
            'input produksi) tidak ada di sini walaupun role admin diizinkan memanggilnya.',
            '',
            'Semua response dibungkus `{ "success": bool, "data": ... }`. Kesalahan: 401',
            'token salah atau habis masa berlaku, 422 validasi gagal, 403 role tidak punya',
            'akses.',
        ]);
    }
}
