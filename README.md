# ompphp packages

Official Composer packages that provide typed PHP APIs for open.mp components through [ompphp](https://github.com/ompphp/ompphp)'s component CAPI.

## Install

Add the repository once, without disabling Packagist:

```bash
composer repo add --global ompphp composer \
    https://packages.justmichael.xyz/ompphp
```

Then install the SDK or a component package normally:

```bash
composer require ompphp/sdk
```

Component wrappers will be published as their CAPI surfaces become available.

## Development

Runtime packages live in `packages/<component>` and are released independently with tags such as `mapandreas-v0.1.0`. Registry tooling lives in `tools/registry`.

```bash
composer install
composer test
php tools/registry/bin/build.php public
```

The registry build discovers every suitable release in `ompphp/ompphp` and this repository.