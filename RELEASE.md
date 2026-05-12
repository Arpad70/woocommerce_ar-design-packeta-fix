# Release

## 1.0.0

Prvni samostatny release modulu AR Design Packeta Fix.

### Kontrola pred vydanim

- `php scripts/verify-version-consistency.php`
- `find . -path './build' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`
- `scripts/build-plugin.sh`

### GitHub release

Workflow `.github/workflows/release.yml` publikuje release asset `ar-design-packeta-fix.zip`.
