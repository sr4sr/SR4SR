<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Support;

use InvalidArgumentException;
use ManorLedger\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testTypedGetters(): void
    {
        $config = new Config(['auth' => ['idleTimeout' => 1800, 'flag' => '1', 'name' => 'x'], 'list' => [1, 2]]);
        self::assertSame(1800, $config->int('auth.idleTimeout'));
        self::assertTrue($config->bool('auth.flag'));
        self::assertSame('x', $config->string('auth.name'));
        self::assertSame([1, 2], $config->array('list'));
        self::assertSame('fallback', $config->get('auth.missing', 'fallback'));
        self::assertTrue($config->has('auth.name'));
        self::assertFalse($config->has('auth.nope'));
        $this->expectException(InvalidArgumentException::class);
        $config->int('auth.name');
    }

    public function testLoadsRealConfigWithEnvOverride(): void
    {
        putenv('MANOR_DATA_DIR=/var/lib/manor/');
        try {
            $config = Config::load(__DIR__ . '/../../config/app.php');
            self::assertSame('/var/lib/manor/users.json', $config->string('paths.users'));
            self::assertSame('__Host-manor_session', $config->string('auth.cookieName'));
            self::assertSame(1800, $config->int('auth.idleTimeout'));
        } finally {
            putenv('MANOR_DATA_DIR');
        }
    }

    public function testRevenueSharesComeFromTheDataDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/manor-shares-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        putenv('MANOR_DATA_DIR=' . $dir);
        try {
            $defaults = Config::load(__DIR__ . '/../../config/app.php');
            self::assertSame(0.17, $defaults->get('games.locust.royaltyShare'), 'no file: the default');
            self::assertSame(0.0, $defaults->get('games.colorblind.royaltyShare'));

            file_put_contents($dir . '/revenue-shares.json', json_encode(['locust' => 0.25, 'colorblind' => 1.5]));
            $config = Config::load(__DIR__ . '/../../config/app.php');
            self::assertSame(0.25, $config->get('games.locust.royaltyShare'));
            self::assertSame(0.25, $config->get('economics.royaltyShare'), 'the default game drives the legacy key');
            self::assertSame(0.0, $config->get('games.colorblind.royaltyShare'), 'outside [0, 1): ignored');
        } finally {
            putenv('MANOR_DATA_DIR');
            @unlink($dir . '/revenue-shares.json');
            rmdir($dir);
        }
    }

    public function testCaptionRequestsAreAtLeastTwoMinutesApart(): void
    {
        $config = Config::load(__DIR__ . '/../../config/app.php');
        self::assertGreaterThanOrEqual(120.0, (float)$config->get('voices.transcriptInterval'));
    }
}
