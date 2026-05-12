# Release Checklist

- [ ] Upravit `VERSION`.
- [ ] Upravit header `Version` v `ar-design-packeta-fix.php`.
- [ ] Doplnit `CHANGELOG.md`.
- [ ] Spustit `php scripts/verify-version-consistency.php`.
- [ ] Spustit PHP lint vsech souboru.
- [ ] Spustit `scripts/build-plugin.sh`.
- [ ] Commitnout zmeny, pushnout branch a zkontrolovat GitHub release workflow.
