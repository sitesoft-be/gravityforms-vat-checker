<?php

namespace Sitesoft\GravityForms\VATChecker\Tests\Support;

use Sitesoft\GravityForms\VATChecker\VAT_Cache;
use Sitesoft\GravityForms\VATChecker\VAT_Logger;
use Sitesoft\GravityForms\VATChecker\VAT_Validation_Service;
use Sitesoft\GravityForms\VATChecker\VIES_Client;
use WP_Test_State;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Fake monotonic clock in seconds, advanced by the fake sleeper. */
    protected float $now = 1000.0;

    /** @var int[] milliseconds passed to the sleeper */
    protected array $sleeps = [];

    /** @var string[] */
    protected array $log_lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        WP_Test_State::reset();
        VAT_Validation_Service::set_instance(null);
        $this->now       = 1000.0;
        $this->sleeps    = [];
        $this->log_lines = [];
    }

    protected function clock(): callable
    {
        return function (): float {
            return $this->now;
        };
    }

    protected function sleeper(): callable
    {
        return function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
            $this->now      += $milliseconds / 1000;
        };
    }

    protected function logger(): VAT_Logger
    {
        return new VAT_Logger(new VAT_Cache(), function (string $line): void {
            $this->log_lines[] = $line;
        });
    }

    protected function client(FakeSoap $soap, array $config = []): VIES_Client
    {
        return new VIES_Client($config, $soap->factory(), $this->logger(), $this->sleeper(), $this->clock());
    }

    protected function service(FakeSoap $soap, array $config = [], ?callable $sleeper = null): VAT_Validation_Service
    {
        $service = new VAT_Validation_Service(
            $this->client($soap, $config),
            new VAT_Cache(),
            $this->logger(),
            $sleeper ?? $this->sleeper(),
            $this->clock(),
        );
        VAT_Validation_Service::set_instance($service);

        return $service;
    }

    /**
     * Step for FakeSoap that takes $seconds before producing $outcome.
     */
    protected function slow(float $seconds, $outcome): callable
    {
        return function () use ($seconds, $outcome) {
            $this->now += $seconds;

            return $outcome;
        };
    }
}
