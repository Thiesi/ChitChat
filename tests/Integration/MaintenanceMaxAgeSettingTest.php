<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\SystemSettingsService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Observability\PrometheusEncoder;
use ChitChat\Observability\SystemStatusService;

/**
 * The maintenance max age is an Operational setting (#312). MAINTENANCE_MAX_AGE_HOURS is the
 * fallback default until a value is saved, and again after it is cleared with null.
 */
final class MaintenanceMaxAgeSettingTest extends DatabaseTestCase
{
    public function testTheEnvironmentValueAppliesUntilASettingIsSaved(): void
    {
        $root = $this->root();
        $settings = new SystemSettingsService($this->pdo, $this->configWith(['maintenanceMaxAgeHours' => 50]));
        self::assertSame(['hours' => 50, 'source' => 'environment'], $this->maintenance($settings->get($root)));

        $status = $this->systemStatus($this->configWith(['maintenanceMaxAgeHours' => 50]));
        self::assertSame(50, $status['maintenance']['maximum_age_hours']);
        self::assertSame('environment', $status['maintenance']['maximum_age_source']);
    }

    public function testWithoutAConfigTheFallbackIs26(): void
    {
        self::assertSame(['hours' => 26, 'source' => 'environment'], $this->maintenance((new SystemSettingsService($this->pdo))->get($this->root())));
    }

    public function testASavedValueWinsAndNullClearsItAgain(): void
    {
        $root = $this->root();
        $config = $this->configWith(['maintenanceMaxAgeHours' => 50]);
        $settings = new SystemSettingsService($this->pdo, $config);

        self::assertSame(['hours' => 72, 'source' => 'setting'], $this->maintenance($this->update($settings, $root, 72)));
        self::assertSame(72, $this->systemStatus($config)['maintenance']['maximum_age_hours'], 'System status follows the setting.');
        self::assertSame(['hours' => 72, 'source' => 'setting'], $this->maintenance($this->update($settings, $root, false)), 'Left unchanged when omitted.');

        // Not a one-way door: null goes back to the environment value.
        self::assertSame(['hours' => 50, 'source' => 'environment'], $this->maintenance($this->update($settings, $root, null)));
        self::assertNull($this->pdo->query('SELECT maintenance_max_age_hours FROM system_settings WHERE id = 1')->fetchColumn());
    }

    public function testOverdueUsesTheEffectiveValue(): void
    {
        $this->pdo->exec("INSERT INTO maintenance_runs (dry_run, status, started_at, finished_at, duration_ms, result_json)
            VALUES (FALSE, 'success', NOW() - INTERVAL '30 hours', NOW() - INTERVAL '30 hours', 10, '{}')");
        $config = $this->configWith(['maintenanceMaxAgeHours' => 26]);
        self::assertTrue($this->systemStatus($config)['maintenance']['overdue'], '30 h is past the 26 h fallback.');

        $this->update(new SystemSettingsService($this->pdo, $config), $this->root(), 48);
        $status = $this->systemStatus($config);
        self::assertFalse($status['maintenance']['overdue'], 'Within the saved 48 h.');
        self::assertStringContainsString("chitchat_maintenance_overdue 0\n", PrometheusEncoder::encode($status));
    }

    public function testOnlyOneTo720HoursAreAccepted(): void
    {
        $root = $this->root();
        $settings = new SystemSettingsService($this->pdo, $this->config);
        foreach ([0, -1, 721] as $invalid) {
            try {
                $this->update($settings, $root, $invalid);
                self::fail("{$invalid} was accepted.");
            } catch (ApiException $exception) {
                self::assertSame([400, 'validation_error'], [$exception->status, $exception->errorCode]);
            }
        }
        self::assertSame(1, $this->maintenance($this->update($settings, $root, 1))['hours']);
        self::assertSame(720, $this->maintenance($this->update($settings, $root, 720))['hours']);
    }

    /** @return array<string, bool|int|string> */
    private function update(SystemSettingsService $settings, AuthenticatedUser $root, int|false|null $hours): array
    {
        return $settings->update($root, true, false, 0, 0, 0, 30, 24, 168, 30, '127.0.0.1', maintenanceMaxAgeHours: $hours);
    }

    /**
     * @param array<string, bool|int|string> $settings
     * @return array{hours: mixed, source: mixed}
     */
    private function maintenance(array $settings): array
    {
        return ['hours' => $settings['maintenance_max_age_hours'], 'source' => $settings['maintenance_max_age_source']];
    }

    /** @return array<string, mixed> */
    private function systemStatus(Config $config): array
    {
        return (new SystemStatusService($this->pdo, $config))->snapshot();
    }

    private function root(): AuthenticatedUser
    {
        return (new AuthService($this->pdo, $this->config))->register('Root', 'Maintenance-Age-Test-Aa1!', '127.0.0.1');
    }
}
