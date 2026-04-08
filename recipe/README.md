# Symfony Flex Recipe

This directory contains the source recipe for `perfbase/symfony`.

It exists so the package configuration and the future Symfony Flex recipe stay
in sync during development and release preparation.

Current state:

- the package exposes Symfony bundle metadata in `composer.json`
- the recipe manifest lives in [`manifest.json`](manifest.json)
- the default config file lives in [`config/packages/perfbase.yaml`](config/packages/perfbase.yaml)

Important:

- Symfony Flex does not consume this directory directly from the package repo
- automatic installation depends on publishing the recipe to the Symfony recipes
  ecosystem or another configured recipe source

The current recipe installs:

- bundle registration for `Perfbase\\Symfony\\PerfbaseBundle`
- a default `config/packages/perfbase.yaml` file with safe disabled defaults
