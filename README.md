# Your-Reselling WHMCS · Domains

Dieses Repository enthält genau **ein eigenständig installierbares Modul**. PHP **8.1+**, cURL, JSON, OpenSSL und eine damit kompatible WHMCS-Version mit MySQL/MariaDB werden benötigt. Alle Modulklassen und API-Routendaten liegen im eigenen Modulordner. Kein anderes Your-Reselling-Modul, zentrales Common-Verzeichnis, SDK oder Composer-Installationsschritt ist erforderlich.

## Installation

1. Nur `modules/registrars/yourresellingdomains/` in das gleichnamige Verzeichnis der WHMCS-Installation kopieren. Alternativ das mit `python3 tools/package.py` erzeugte ZIP entpacken und dessen `modules/` in das WHMCS-Hauptverzeichnis kopieren.
2. Den Registrar **Your-Reselling Domains** aktivieren, API-Key und HTTPS-Basis-URL inklusive `/api/v1` eintragen und den TLDs zuordnen.


Den Registrar aktivieren, API-Key und Basis-URL eintragen und den gewünschten TLDs zuordnen. Bei der ersten Domainaktion wird `mod_yourreselling_domains` für die Zuordnung von WHMCS-ID zu Anbieter-UUID angelegt. Die Datenbank benötigt dafür einmalig CREATE-Rechte. Kontakte werden aus den WHMCS-Registrantendaten erstellt; die Telefonnummer muss international mit `+` verfügbar sein.

Unterstützt: Registrierung, Transfer, Verfügbarkeit in 50er-Batches, Nameserver, Basis-DNS, Auth-Code und Domain-/Transfer-Sync. Premium-Domains werden nicht automatisch angeboten. Der Domain-Sync kann vorhandene Domains über eine exakte, paginierte Namenssuche wieder zuordnen. DNS-Speichern ersetzt die ganze Zone; bei erweiterten Record-Typen verhindert das Modul ein Überschreiben durch die eingeschränkte WHMCS-DNS-Oberfläche.

**Verlängerung über Kündigungsstatus:** `RenewDomain` merkt die in WHMCS gekaufte Laufzeit und entfernt die Kündigung mit `enableAutoRenew`. Die API verlängert später automatisch; ein sofortiger Registry-Renew wird nicht behauptet. Ohne bezahlte Folgeperiode setzt der tägliche Abgleich `disableAutoRenew`. Die Domain bleibt bis zum Ende der gedeckten Laufzeit bestehen. Mehrjährige Zahlungen bleiben auch über mehrere jährliche API-Verlängerungen gedeckt.

**Einrichtung:** WHMCS-System-Cron, Domain-Synchronisierung und automatische Verlängerung nach Zahlung aktivieren. Die enthaltene `hooks.php` wird mit dem Registrar geladen; nach dem Update die Registrar-Konfiguration speichern, damit WHMCS neue Hooks erkennt. `DailyCronJob` prüft alle zugeordneten Domains in 100er-Blöcken, unabhängig von den 50er-Batches des normalen Domain-Sync. API-Key benötigt `domains:read` und `domains:update` sowie die bisherigen Rechte für Bestellungen/DNS. Fehler stehen im WHMCS-Aktivitätslog und werden beim nächsten Lauf erneut geprüft.

**Rechnungsfrist:** Standardmäßig wird eine unbezahlte Folgeperiode **14 Tage vor Ablauf** gekündigt, einstellbar auf 7–90 Tage. Die API kann bereits fünf Tage vor Ablauf verlängern; ein Abgleich erst am Ablaufdatum wäre zu spät. Rechnungserstellung, Fälligkeit und Erinnerungen in WHMCS vor diesen Stichtag legen. Eine spätere Zahlung kann die Kündigung aufheben, solange die Domain noch aktiv und nicht abgelaufen ist. Für bereits gelöschte, abgelaufene oder gesperrte Domains gibt es keinen vermeintlichen Wiederherstellungserfolg.

**Auto-Renew-Schalter:** WHMCS-Auto-Renew allein ist keine Zahlung. Ohne weitere Zahlung greift immer der Kündigungsvorlauf. „Nicht verlängern“ setzt die Kündigung beim nächsten täglichen Abgleich, bei Admin-Änderungen unmittelbar. Schon bezahlte Folgejahre bleiben erhalten; die Kündigung wird erst gesetzt, sobald die API-Laufzeit diese Jahre abdeckt. Eine manuelle administrative Renew-Aktion erteilt ausdrücklich eine neue Laufzeitfreigabe.

**Zustand und Wiederholungen:** Bezahltes Laufzeitende und Vorgangskennungen liegen zusammen mit der Anbieter-ID in `mod_yourreselling_domains`. Zahlungsaufrufe verwenden die Rechnungs-ID, manuelle Aufrufe das bisherige WHMCS-Ablaufdatum und den Zeitraum. Unbestätigte API-Aufrufe bleiben wiederaufnehmbar und addieren beim Retry keine weiteren Jahre. Domain-Sync liefert die gedeckte Kundenlaufzeit, damit eine früh bezahlte Verlängerung nicht durch das noch unveränderte API-Datum zurückgesetzt wird. Bei Updates/Importen wird einmalig das vorhandene WHMCS-Ablaufdatum übernommen; deshalb dieses Datum vorher mit der bezahlten Laufzeit abgleichen. Ein fehlendes/ungültiges Datum führt zu einem Fehler statt zu einer geratenen Kündigung.

**Anbieter-Vertrag:** Das Modul bestätigt die gespeicherte Kündigungsentscheidung; die API synchronisiert diese asynchron zum Provider. Der API-Betreiber muss sicherstellen, dass Kündigungen bei monatlichem Einkauf die gesamte bezahlte Kundenlaufzeit erhalten, auch wenn Provider- und Kundenablauf auseinanderliegen. Diese Providerdeckung ist über die öffentliche API nicht prüfbar. Im aktuellen Backend sind die Sonderregeln auf `.de` und den letzten Kundenmonat begrenzt; monatliche Nicht-`.de`-Domains und abweichende Monatsabläufe benötigen vor Einsatz einen Backend-Abgleich.

Öffentliche Setter für Registrar-Lock und Domaininhaberwechsel sowie eine allgemeine sofortige Domain-Delete-Funktion fehlen weiterhin. Diese Funktionen werden nicht angeboten.

## Unabhängig entwickeln, testen und veröffentlichen

Dieses Verzeichnis ist die Repository-Wurzel. Es kann separat geklont, verschoben und versioniert werden. Es gibt keine Symlinks oder Pfadabhängigkeiten zu Nachbar-Repositories. Der private Namespace `YourReselling\Whmcs\Domains` verhindert Klassenkonflikte mit anderen Produktmodulen und deren Versionen.

```sh
php tests/run.php
python3 tools/package.py
```

Die Tests verwenden einen lokalen cURL-Testadapter und lösen keine API-Bestellungen aus. Sie benötigen keine WHMCS-Installation und keinen Composer-Vendor. Das Build-Skript verwendet nur die Python-Standardbibliothek und Dateien dieses Repositories. Es erzeugt `dist/whmcs-domains-3.1.0.zip` sowie eine SHA-256-Prüfsumme. Die Version für eigene Releases in `module.json` ändern. Für Updates ausschließlich den eigenen Modulordner ersetzen; Anbieter-IDs und Status liegen in der WHMCS-Datenbank.

[API-Vertrag](docs/api.json) und [Produktkonfiguration](docs/product.md) sind Bestandteil dieses Repositories. Bei API-Änderungen dieses Modul separat anpassen, testen und veröffentlichen. Andere Module benötigen kein gleichzeitiges Update.

Die lokalen Tests prüfen auch die echten Modul-Callbacks und Hooks mit WHMCS-Datenbank-/Registrar-Testadaptern. Sie ersetzen keine Abnahme in einer lizenzierten WHMCS-Testinstallation: dort Zahlung, manuelle Wiederholung, tägliche Cron-Ausführung, Auto-Renew-Schalter, Transferabschluss und Fälligkeits-Synchronisierung prüfen.

Referenzen: [WHMCS Provisioning](https://developers.whmcs.com/provisioning-modules/), [Registrar-Module](https://developers.whmcs.com/domain-registrars/).
