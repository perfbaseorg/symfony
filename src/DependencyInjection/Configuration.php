<?php

declare(strict_types=1);

namespace Perfbase\Symfony\DependencyInjection;

use Perfbase\SDK\FeatureFlags;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('perfbase');

        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->validate()
                ->ifTrue(static function (array $config): bool {
                    return (bool) ($config['enabled'] ?? false) && trim((string) ($config['api_key'] ?? '')) === '';
                })
                ->thenInvalid('The "perfbase.api_key" option must be configured when "perfbase.enabled" is true.')
            ->end();

        $children = $rootNode->children();

        $children
            ->booleanNode('enabled')->defaultFalse()->end()
            ->booleanNode('debug')->defaultFalse()->end()
            ->booleanNode('log_errors')->defaultTrue()->end()
            ->scalarNode('api_key')->defaultValue('')->end()
            ->scalarNode('api_url')
                ->cannotBeEmpty()
                ->defaultValue('https://ingress.perfbase.cloud')
                ->validate()
                    ->ifTrue(static function ($value): bool {
                        return !is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false;
                    })
                    ->thenInvalid('The "perfbase.api_url" option must be a valid URL.')
                ->end()
            ->end()
            ->floatNode('sample_rate')
                ->min(0.0)
                ->max(1.0)
                ->defaultValue(0.1)
            ->end()
            ->arrayNode('profile_http_status_codes')
                ->integerPrototype()
                    ->min(100)
                    ->max(599)
                ->end()
                ->defaultValue(array_merge(range(200, 299), range(500, 599)))
            ->end()
            ->integerNode('timeout')
                ->min(1)
                ->defaultValue(10)
            ->end()
            ->scalarNode('proxy')
                ->defaultNull()
                ->validate()
                    ->ifTrue(static function ($value): bool {
                        return $value !== null
                            && (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_URL) === false);
                    })
                    ->thenInvalid('The "perfbase.proxy" option must be null or a valid URL.')
                ->end()
            ->end()
            ->integerNode('flags')
                ->min(0)
                ->defaultValue(FeatureFlags::DefaultFlags)
            ->end()
            ->scalarNode('app_version')->defaultValue('')->end();

        $children
            ->arrayNode('include')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('http')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                        ->defaultValue(['*'])
                    ->end()
                    ->arrayNode('console')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                        ->defaultValue(['*'])
                    ->end()
                ->end()
            ->end();

        $children
            ->arrayNode('exclude')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('http')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                        ->defaultValue([])
                    ->end()
                    // Long-running workers never finish, so a command-level
                    // trace would grow until the process exits.
                    ->arrayNode('console')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                        ->defaultValue(['messenger:consume'])
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
