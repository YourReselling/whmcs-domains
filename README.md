# Your-Reselling WHMCS · Domains

Dieses Repository enthält genau **ein eigenständig installierbares Modul**. PHP **8.1+**, cURL, JSON, OpenSSL und eine damit kompatible WHMCS-Version mit MySQL/MariaDB werden benötigt. Alle Modulklassen und API-Routendaten liegen im eigenen Modulordner. Kein anderes Your-Reselling-Modul, zentrales Common-Verzeichnis, SDK oder Composer-Installationsschritt ist erforderlich.

## Installation

1. Nur `modules/registrars/yourresellingdomains/` in das gleichnamige Verzeichnis der WHMCS-Installation kopieren. Alternativ das mit `python3 tools/package.py` erzeugte ZIP entpacken und dessen `modules/` in das WHMCS-Hauptverzeichnis kopieren.
2. Den Registrar **Your-Reselling Domains** aktivieren, API-Key und HTTPS-Basis-URL inklusive `/api/v1` eintragen und den TLDs zuordnen.


Den Registrar aktivieren, API-Key und Basis-URL eintragen und den gewünschten TLDs zuordnen. Bei der ersten Domainaktion wird `mod_yourreselling_domains` für die Zuordnung von WHMCS-ID zu Anbieter-UUID angelegt. Die Datenbank benötigt dafür einmalig CREATE-Rechte. Kontakte werden aus den WHMCS-Registrantendaten erstellt; die Telefonnummer muss international mit `+` verfügbar sein.

Unterstützt: Registrierung, Transfer, Verfügbarkeit in 50er-Batches, Nameserver, Basis-DNS, Auth-Code und Domain-/Transfer-Sync. Premium-Domains werden nicht automatisch angeboten. Der Domain-Sync kann vorhandene Domains über eine exakte, paginierte Namenssuche wieder zuordnen. DNS-Speichern ersetzt die ganze Zone; bei erweiterten Record-Typen verhindert das Modul ein Überschreiben durch die eingeschränkte WHMCS-DNS-Oberfläche.

**Kein `RenewDomain`:** Der aktuelle öffentliche API-Vertrag besitzt keinen expliziten Verlängerungs-Endpunkt. Anbieter-AutoRenew und die Rechnungsläufe müssen administrativ abgestimmt werden. Ebenfalls fehlen öffentliche Setter für Registrar-Lock und Domaininhaberwechsel sowie eine allgemeine Domain-Delete-Funktion. Diese Funktionen werden nicht mit falschem Erfolg implementiert.

## Unabhängig entwickeln, testen und veröffentlichen

Dieses Verzeichnis ist die Repository-Wurzel. Es kann separat geklont, verschoben und versioniert werden. Es gibt keine Symlinks oder Pfadabhängigkeiten zu Nachbar-Repositories. Der private Namespace `YourReselling\Whmcs\Domains` verhindert Klassenkonflikte mit anderen Produktmodulen und deren Versionen.

```sh
php tests/run.php
python3 tools/package.py
```

Die Tests verwenden einen lokalen cURL-Testadapter und lösen keine API-Bestellungen aus. Sie benötigen keine WHMCS-Installation und keinen Composer-Vendor. Das Build-Skript verwendet nur die Python-Standardbibliothek und Dateien dieses Repositories. Es erzeugt `dist/whmcs-domains-3.0.0.zip` sowie eine SHA-256-Prüfsumme. Die Version für eigene Releases in `module.json` ändern. Für Updates ausschließlich den eigenen Modulordner ersetzen; Anbieter-IDs und Status liegen in der WHMCS-Datenbank.

[API-Vertrag](docs/api.json) und [Produktkonfiguration](docs/product.md) sind Bestandteil dieses Repositories. Bei API-Änderungen dieses Modul separat anpassen, testen und veröffentlichen. Andere Module benötigen kein gleichzeitiges Update.

Die lokalen Tests ersetzen keine Abnahme in einer lizenzierten WHMCS-Testinstallation. Dort insbesondere Theme, Service Properties, CSRF, Berechtigungen und Modulqueue prüfen.

Referenzen: [WHMCS Provisioning](https://developers.whmcs.com/provisioning-modules/), [Registrar-Module](https://developers.whmcs.com/domain-registrars/).
