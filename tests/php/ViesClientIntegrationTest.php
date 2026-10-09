<?php

namespace Sitesoft\GravityForms\VATChecker\Tests;

use Sitesoft\GravityForms\VATChecker\Tests\Support\TestCase;
use Sitesoft\GravityForms\VATChecker\VAT_Cache;
use Sitesoft\GravityForms\VATChecker\VAT_Logger;
use Sitesoft\GravityForms\VATChecker\VIES_Client;

/**
 * Real PHP SoapClient against a local fake VIES server (tests/fixtures/fake-vies).
 * Proves the timeout/exception configuration works with the actual SOAP extension.
 *
 * @requires extension soap
 */
final class ViesClientIntegrationTest extends TestCase
{
    private static $server;
    private static int $port;
    private static string $state_dir;

    public static function setUpBeforeClass(): void
    {
        self::$state_dir = sys_get_temp_dir() . '/fake-vies-' . bin2hex(random_bytes(4));
        mkdir(self::$state_dir);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $router = dirname(__DIR__) . '/fixtures/fake-vies/router.php';
        $env    = array_merge(getenv(), [
            'FAKE_VIES_STATE_DIR'    => self::$state_dir,
            'PHP_CLI_SERVER_WORKERS' => '4',
        ]);

        self::$server = proc_open(
            [ PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router ],
            [ [ 'pipe', 'r' ], [ 'file', '/dev/null', 'w' ], [ 'file', '/dev/null', 'w' ] ],
            $pipes,
            null,
            $env,
        );

        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($probe) {
                fclose($probe);

                return;
            }
            usleep(100000);
        }

        self::fail('Fake VIES server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        array_map('unlink', glob(self::$state_dir . '/*') ?: []);
        @rmdir(self::$state_dir);
    }

    public function test_valid(): void
    {
        $result = $this->real_client()->check('BE', '0000000097');

        $this->assertTrue($result->is_valid());
        $this->assertSame('NV SITESOFT TEST', $result->name());
        $this->assertSame("Kerkstraat 1\n9000 Gent", $result->address());
    }

    public function test_invalid(): void
    {
        $this->assertTrue($this->real_client()->check('BE', '0000000196')->is_invalid());
        $this->assertSame(1, $this->calls('0000000196'));
    }

    public function test_ms_max_concurrent_req_is_temporary_and_retried_once(): void
    {
        $result = $this->real_client()->check('BE', '9000000001');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('MS_MAX_CONCURRENT_REQ', $result->error_code());
        $this->assertSame(2, $this->calls('9000000001'));
        $this->assertStringContainsString('fault=env:Server', $this->log_lines[0]);
    }

    public function test_ms_unavailable_is_temporary(): void
    {
        $result = $this->real_client()->check('BE', '9000000002');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('MS_UNAVAILABLE', $result->error_code());
    }

    public function test_first_fault_then_successful_retry(): void
    {
        $this->assertTrue($this->real_client()->check('BE', '9000000006')->is_valid());
        $this->assertSame(2, $this->calls('9000000006'));
    }

    public function test_read_timeout_is_enforced(): void
    {
        $started = microtime(true);
        $result  = $this->real_client([ 'read_timeout' => 1 ])->check('BE', '9000000003');
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('HTTP_READ', $result->error_code());
        // Fails within ~1s per attempt instead of PHP's default 60s.
        $this->assertLessThan(3.0, $elapsed);
    }

    public function test_connection_failure_is_temporary(): void
    {
        $result = $this->real_client([ 'location' => 'http://127.0.0.1:1/checkVatService' ])->check('BE', '0000000097');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('HTTP_CONNECT', $result->error_code());
    }

    public function test_html_error_page_is_temporary(): void
    {
        $result = $this->real_client()->check('BE', '9000000004');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('HTTP_ERROR', $result->error_code());
    }

    public function test_html_with_200_status_is_temporary(): void
    {
        $result = $this->real_client()->check('BE', '9000000007');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('BAD_RESPONSE', $result->error_code());
    }

    public function test_unknown_fault_is_temporary_and_not_retried(): void
    {
        $result = $this->real_client()->check('BE', '9000000005');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('UNKNOWN_FAULT', $result->error_code());
        $this->assertSame(1, $this->calls('9000000005'));
    }

    public function test_unreachable_wsdl_is_temporary(): void
    {
        $result = $this->real_client([ 'wsdl' => 'http://127.0.0.1:1/checkVatService.wsdl' ])->check('BE', '0000000097');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('WSDL_ERROR', $result->error_code());
    }

    private function real_client(array $config = []): VIES_Client
    {
        return new VIES_Client(
            array_merge([
                'wsdl'           => dirname(__DIR__) . '/fixtures/fake-vies/checkVatService.wsdl',
                'location'       => 'http://127.0.0.1:' . self::$port . '/checkVatService',
                'retry_delay_ms' => 10,
            ], $config),
            null, // real SoapClient
            new VAT_Logger(new VAT_Cache(), function (string $line): void {
                $this->log_lines[] = $line;
            }),
        );
    }

    private function calls(string $number): int
    {
        return (int) @file_get_contents(self::$state_dir . '/calls-' . $number);
    }
}
