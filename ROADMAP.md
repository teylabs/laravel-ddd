# Roadmap

Laravel-DDD brings Laravel's `make:*` commands to domain-driven design: domain and application objects generated where your domains live, discovered without registration. 4.0 rebuilds it on [tey/mod](https://github.com/teylabs/mod). You keep the same commands, folders and config, and if you ever outgrow Laravel-DDD's conventions, moving to plain tey/mod is a single command. Every difference from 3.x will be listed in the upgrade guide.

**Versions are milestones, not dates.**

## Shipped

- [x] **[v1 — Domain-driven generators](https://github.com/teylabs/laravel-ddd/releases/tag/v1.0.0).** `ddd:*` generators for domain and application objects, with auto-discovery.
- [x] **[v2 — Laravel 12](https://github.com/teylabs/laravel-ddd/releases/tag/v2.0.0).** Laravel 12 support, PHP 8.2+.
- [x] **[v3 — Tey Labs maintenance](https://github.com/teylabs/laravel-ddd/releases/tag/v3.0.0).** Renamed to `tey/laravel-ddd`. 3.x added Symfony 8 support, a Laravel Boost guideline and skill, discovery fixes, and `make:*`-style exit codes (3.3).

## The countdown to 4.0

- [ ] **[v4.0 — Built on tey/mod](https://github.com/teylabs/laravel-ddd/milestone/1).** The same commands, folders and config on tey/mod's engine, cached and ordered discovery, custom generators that carry over to tey/mod, and `ddd:upgrade --to=mod`.
- [ ] **[4.x — After 4.0](https://github.com/teylabs/laravel-ddd/milestone/2).** In no particular order.
