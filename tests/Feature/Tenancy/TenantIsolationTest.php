<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantNotResolved;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The most important test in the application.
 *
 * It does not check a list someone has to remember to extend: it walks every
 * model on disk. Add a model with a tenant_id and forget the trait, and this
 * fails on the next run rather than in production (SPEC §25.1).
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every model whose table has a tenant_id must be scoped by it.
     */
    #[Test]
    #[DataProvider('models')]
    public function tenant_scoped_tables_use_the_trait(string $class): void
    {
        /** @var Model $model */
        $model = new $class;

        if (! Schema::hasColumn($model->getTable(), 'tenant_id')) {
            $this->assertTrue(true, "{$class} is not tenant-scoped.");

            return;
        }

        $this->assertContains(
            BelongsToTenant::class,
            class_uses_recursive($class),
            "{$class} has a tenant_id column but does not use the BelongsToTenant trait. "
            .'Without it every query returns every venue\'s rows.'
        );
    }

    /**
     * And the scoping has to actually work, not merely be declared.
     */
    #[Test]
    #[DataProvider('models')]
    public function one_tenant_cannot_read_another_tenants_rows(string $class): void
    {
        /** @var Model $model */
        $model = new $class;

        if (! Schema::hasColumn($model->getTable(), 'tenant_id')) {
            $this->assertTrue(true, "{$class} is not tenant-scoped.");

            return;
        }

        if (! in_array(HasFactory::class, class_uses_recursive($class), true)) {
            $this->fail(
                "{$class} is tenant-scoped but has no factory, so its isolation "
                .'cannot be proven. Give it one.'
            );
        }

        $context = app(TenantContext::class);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $record = $context->asTenant(
            $tenantA,
            fn (): Model => $class::factory()->create(['tenant_id' => $tenantA->id]),
        );

        $context->asTenant($tenantB, function () use ($class, $record): void {
            $this->assertNull(
                $class::find($record->getKey()),
                "{$class} leaked a row from another tenant through find()."
            );

            $this->assertSame(
                0,
                $class::query()->count(),
                "{$class} leaked rows from another tenant through a plain query."
            );
        });

        $context->asTenant($tenantA, function () use ($class, $record): void {
            $this->assertNotNull(
                $class::find($record->getKey()),
                "{$class} hid a row from its own tenant."
            );
        });
    }

    /**
     * With no tenant bound and no explicit bypass, a scoped query must refuse.
     *
     * Returning everything would be the quiet failure that leaks data, so the
     * scope throws instead.
     */
    #[Test]
    #[DataProvider('models')]
    public function querying_without_a_tenant_refuses(string $class): void
    {
        /** @var Model $model */
        $model = new $class;

        if (! Schema::hasColumn($model->getTable(), 'tenant_id')) {
            $this->assertTrue(true, "{$class} is not tenant-scoped.");

            return;
        }

        app(TenantContext::class)->forget();

        $this->expectException(TenantNotResolved::class);

        $class::query()->count();
    }

    /**
     * Writes are stamped with the current tenant without the caller asking.
     */
    #[Test]
    #[DataProvider('models')]
    public function creating_stamps_the_current_tenant(string $class): void
    {
        /** @var Model $model */
        $model = new $class;

        if (! Schema::hasColumn($model->getTable(), 'tenant_id')) {
            $this->assertTrue(true, "{$class} is not tenant-scoped.");

            return;
        }

        if (! in_array(HasFactory::class, class_uses_recursive($class), true)) {
            $this->markTestSkipped("{$class} has no factory.");
        }

        $tenant = Tenant::factory()->create();

        $record = app(TenantContext::class)->asTenant(
            $tenant,
            fn (): Model => $class::factory()->create(['tenant_id' => null]),
        );

        $this->assertSame(
            $tenant->id,
            $record->getAttribute('tenant_id'),
            "{$class} was created without being stamped with the current tenant."
        );
    }

    /**
     * Every concrete model under app/Models.
     *
     * @return array<string, array{class-string<Model>}>
     */
    public static function models(): array
    {
        $directory = app_path('Models');

        if (! is_dir($directory)) {
            return [];
        }

        $cases = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $class = self::classFromFile($file);

            if ($class === null) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $cases[class_basename($class)] = [$class];
        }

        return $cases;
    }

    /**
     * @return class-string|null
     */
    private static function classFromFile(SplFileInfo $file): ?string
    {
        $relative = Str::of($file->getRealPath())
            ->after(realpath(app_path()).DIRECTORY_SEPARATOR)
            ->replace(DIRECTORY_SEPARATOR, '\\')
            ->replace('.php', '')
            ->value();

        $class = 'App\\'.$relative;

        return class_exists($class) ? $class : null;
    }
}
