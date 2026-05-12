# AR Design Packeta Fix for WooCommerce

Samostatny modul pro Packeta automatizaci. Modul navazuje na oficialni plugin Packeta pro WooCommerce a dela pouze provozni fixy, ktere driv omylem zily v `ar-design-dpd`.

## Funkce

- automaticky exportuje Packeta objednavky pri prechodu objednavky do stavu `zabalena`,
- synchronizuje Packeta tracking pri Packeta cron hooku i vlastnim hodinovem WP-Cron hooku,
- uklada normalizovana shipment metadata do stavajicich `_ard_shipping_*` klicu kvuli kompatibilite se starymi objednavkami,
- pri doruceni spousti stejny sdileny workflow hook `ard_shipping_shipment_delivered`,
- umi objednavku po doruceni prepnout na `completed`,
- pripravi fakturu pro COD follow-up, pokud je dostupny plugin PDF Invoices & Packing Slips.

## Pozadavky

- WordPress 5.3+
- WooCommerce 7.0+
- PHP 7.4+
- oficialni plugin Packeta

## Instalace

1. Nahrajte adresar `ar-design-packeta-fix` do `wp-content/plugins`.
2. Aktivujte plugin `AR Design Packeta Fix for WooCommerce`.
3. V administraci WooCommerce otevrette `WooCommerce -> Nastavenia -> Doprava -> Packeta Fix`.
4. Zkontrolujte, ze je zapnuta automatizace a volitelne prepinani objednavek na `completed`.

## Release

Verze je rizena souborem `VERSION` a hlavickou v `ar-design-packeta-fix.php`.

```bash
php scripts/verify-version-consistency.php
scripts/build-plugin.sh
```

GitHub Actions workflow `.github/workflows/release.yml` vytvori zip asset `ar-design-packeta-fix.zip` pri release tagu `vX.Y.Z`.
