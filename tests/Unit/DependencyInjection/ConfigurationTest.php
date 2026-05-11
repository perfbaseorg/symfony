<?php

declare(strict_types=1);

namespace Perfbase\Symfony\Tests\Unit\DependencyInjection;

use Perfbase\Symfony\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class ConfigurationTest extends TestCase
{
    public function test_configuration_exposes_perfbase_tree_builder(): void
    {
        $configuration = new Configuration();

        self::assertInstanceOf(TreeBuilder::class, $configuration->getConfigTreeBuilder());
    }

    public function test_configuration_defaults_are_applied(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['enabled']);
        self::assertSame(0.1, $config['sample_rate']);
        self::assertSame(array_merge(range(200, 299), range(500, 599)), $config['profile_http_status_codes']);
        self::assertSame(['*'], $config['include']['http']);
        self::assertSame([], $config['exclude']['console']);
    }

    public function test_configuration_accepts_custom_values(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'enabled' => true,
            'api_key' => 'test-key',
            'sample_rate' => 1.0,
            'profile_http_status_codes' => [200, 404],
            'include' => [
                'http' => ['GET /health'],
                'console' => ['app:*'],
            ],
        ]]);

        self::assertTrue($config['enabled']);
        self::assertSame('test-key', $config['api_key']);
        self::assertSame([200, 404], $config['profile_http_status_codes']);
        self::assertSame(['GET /health'], $config['include']['http']);
        self::assertSame(['app:*'], $config['include']['console']);
    }

    public function test_enabled_configuration_requires_api_key(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [[
            'enabled' => true,
            'api_key' => '',
        ]]);
    }

    public function test_sample_rate_must_be_between_zero_and_one(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [[
            'sample_rate' => 1.5,
        ]]);
    }

    public function test_timeout_must_be_positive(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [[
            'timeout' => 0,
        ]]);
    }

    public function test_api_url_must_be_valid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [[
            'api_url' => 'not-a-url',
        ]]);
    }

    public function test_proxy_must_be_null_or_valid_url(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [[
            'proxy' => '',
        ]]);
    }

    public function test_filter_entries_must_not_be_empty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [[
            'include' => [
                'http' => [''],
            ],
        ]]);
    }

    public function test_profile_http_status_codes_must_be_valid_http_status_codes(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $processor = new Processor();
        $processor->processConfiguration(new Configuration(), [[
            'profile_http_status_codes' => [99],
        ]]);
    }
}
