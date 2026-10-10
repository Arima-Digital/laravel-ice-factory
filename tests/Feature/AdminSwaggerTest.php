<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSwaggerTest extends TestCase
{
    public function test_admin_document_is_valid_json_and_titled_as_admin(): void
    {
        $spec = json_decode(file_get_contents(resource_path('swagger/admin.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Ice Factory API — Admin', $spec['info']['title']);
        $this->assertNotEmpty($spec['paths']);
    }

    public function test_admin_document_holds_no_endpoint_an_admin_cannot_call(): void
    {
        $spec = json_decode(file_get_contents(resource_path('swagger/admin.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (array_keys($spec['paths']) as $path) {
            $route = collect($this->app['router']->getRoutes()->getRoutes())
                ->first(fn ($candidate) => '/'.$candidate->uri() === $path);

            if ($route === null) {
                // A route the package only uses for the docs itself.
                continue;
            }

            if (str_starts_with($path, '/api/auth/')) {
                // Auth carries no role: it is what you call before you have one.
                continue;
            }

            $middleware = implode(' ', array_map('strval', $route->gatherMiddleware()));

            $this->assertStringContainsString(
                'role:ADMIN',
                $middleware,
                "{$path} is listed for admin but the middleware does not allow ADMIN",
            );
        }
    }

    public function test_gateway_endpoints_are_left_out(): void
    {
        $spec = json_decode(file_get_contents(resource_path('swagger/admin.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('/api/iot/logs', $spec['paths']);
        $this->assertArrayNotHasKey('/api/iot/sync', $spec['paths']);
    }

    public function test_admin_document_declares_no_empty_tag_sections(): void
    {
        $spec = json_decode(file_get_contents(resource_path('swagger/admin.json')), true, 512, JSON_THROW_ON_ERROR);

        $used = [];
        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['tags'] ?? [] as $tag) {
                    $used[$tag] = true;
                }
            }
        }

        $declared = array_column($spec['tags'] ?? [], 'name');

        $this->assertSame(
            [],
            array_values(array_diff($declared, array_keys($used))),
            'admin.json declares tags that have no endpoint, so Swagger UI would render empty sections',
        );
    }

    public function test_admin_document_agrees_with_the_full_document_on_shared_endpoints(): void
    {
        $admin = json_decode(file_get_contents(resource_path('swagger/admin.json')), true, 512, JSON_THROW_ON_ERROR);
        $full = json_decode(file_get_contents(resource_path('swagger/openapi.json')), true, 512, JSON_THROW_ON_ERROR);

        // admin.json keeps only the operations an admin owns, so a path can
        // carry fewer methods than in openapi.json. Each retained operation,
        // though, has to be identical to its source.
        foreach ($admin['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertSame(
                    $full['paths'][$path][$method] ?? null,
                    $operation,
                    strtoupper($method)." {$path} differs between the two documents, so one of them is stale",
                );
            }
        }
    }

    public function test_generated_file_is_up_to_date(): void
    {
        $this->artisan('swagger:admin --check')->assertSuccessful();
    }
}
