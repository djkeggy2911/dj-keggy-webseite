# Automatische DJ-Anfragen per WhatsApp

Die Implementierung ist lokal vorbereitet. Ohne Meta-Zugangsdaten und freigegebene
Vorlage wird nur die E-Mail versendet und ein Konfigurationsfehler protokolliert.

## 1. Meta einrichten

- Meta Business Portfolio, Entwickler-App mit WhatsApp und WhatsApp Business
  Account (WABA) einrichten.
- Eine Cloud-API-Absendernummer registrieren. Verwende eine eigene Absendernummer,
  getrennt von der serverseitig konfigurierten Empfängernummer. Zum Einstieg ist die von Meta
  bereitgestellte Testnummer geeignet; den Empfänger im Testbereich hinzufügen
  und verifizieren.
- Die **Phone Number ID der Absendernummer** aus dem WhatsApp-API-Setup kopieren.
  Das ist weder die Telefonnummer noch die WABA-ID.
- Für den Betrieb einen System-User-Access-Token mit Zugriff auf die WhatsApp-Assets
  und `whatsapp_business_messaging` erzeugen. Für die Verwaltung von Vorlagen und
  Assets wird außerdem `whatsapp_business_management` benötigt. Temporäre
  Dashboard-Tokens sind nur zum Testen geeignet. Tokenablauf überwachen.
- Die von Meta für dein Konto verlangten Registrierungs-, Zahlungs- und ggf.
  Verifizierungsschritte abschließen. Empfang der Benachrichtigungen auf der
  Zielnummer ausdrücklich freigeben.

## 2. Vorlage anlegen

Im WhatsApp Manager eine Vorlage namens `neue_dj_anfrage`, Sprache Deutsch (`de`),
mit **positionellen** Variablen anlegen. Nur Body, keine Header-/Button-Variablen.
Als nicht werbliche Benachrichtigung zur Prüfung einreichen; Meta entscheidet
über Kategorie und Freigabe. Erst nach Freigabe mit echten Anfragen verwenden.

Exakter Body mit **12 Parametern**:

```text
Neue DJ-Anfrage 🎧

Name: {{1}}
Event: {{2}}
Datum: {{3}}
Beginn: {{4}}
Ort: {{5}}
Land: {{6}}
Gäste: {{7}}
Telefon: {{8}}
E-Mail: {{9}}
Musik: {{10}}
Leistungen: {{11}}
Nachricht: {{12}}
```

Für die Prüfung fiktive Beispieldaten für alle Variablen hinterlegen, etwa Max
Mustermann, Hochzeit, 2027-06-12, 18:00, Berlin, Deutschland, 80, +491234567890,
max@example.com, Balkan und House, DJ und Licht, Empfang ab 18 Uhr.
Ein Website-Formular eröffnet kein WhatsApp-Servicefenster. Die freigegebene
Vorlage ermöglicht die proaktive Benachrichtigung auch ohne laufenden Chat.

## 3. Zugangsdaten sicher konfigurieren

Im Hosting-Control-Panel **serverseitige Umgebungsvariablen für PHP** setzen:

| Variable | Wert |
| --- | --- |
| `WHATSAPP_ACCESS_TOKEN` | Dein Meta Access Token |
| `WHATSAPP_PHONE_NUMBER_ID` | ID der sendenden Cloud-API-Nummer |
| `WHATSAPP_GRAPH_VERSION` | Unterstützte Version aus deinem Meta-API-Setup, Format `vNN.0` |
| `WHATSAPP_RECIPIENT` | Erforderliche Empfängernummer mit Landesvorwahl; nur auf dem Server setzen |
| `WHATSAPP_TEMPLATE_NAME` | `neue_dj_anfrage` |
| `WHATSAPP_TEMPLATE_LANGUAGE` | `de` |

Die Werte werden in `whatsapp-notify.php` mit `getenv()` gelesen. Die Zielnummer
hat keinen Standardwert. Fehlt sie oder ist sie ungültig, wird WhatsApp kontrolliert
abgebrochen und nur ein allgemeiner Fehlercode protokolliert. Die Anfrage-E-Mail bleibt erhalten.
Kein Token und keine private Empfängernummer gehören in HTML, JavaScript,
Git oder eine herunterladbare Konfigurationsdatei. Eine `.env`-Datei wird nicht
automatisch eingelesen. Wenn das Hosting keine PHP-Umgebungsvariablen anbietet,
den Hoster bitten, diese für den PHP-Prozess einzurichten. Bei PHP-FPM müssen sie
auch im Worker verfügbar sein. Eventuell PHP-Prozesse neu starten lassen.

PHP ab 7.3 mit cURL und aktuellem CA-Zertifikatsspeicher erforderlich; eine aktuell
unterstützte PHP-Version verwenden. Ausgehendes HTTPS zu `graph.facebook.com:443`
muss erlaubt sein. `display_errors` im Produktivbetrieb deaktivieren und
`log_errors` aktivieren. Logs dürfen nicht öffentlich abrufbar sein.

## 4. Bereitstellung und Test

1. `index.html`, `script.js`, `send-offer.php` und `whatsapp-notify.php` zusammen
   hochladen. Die Anleitung muss nicht öffentlich bereitgestellt werden.
2. PHP-Syntax mit `php -l send-offer.php` und `php -l whatsapp-notify.php` prüfen.
3. Meta-Vorlage freigeben lassen und Umgebungsvariablen setzen.
4. Eine Formularanfrage mit eigenen Test-Kontaktdaten, Umlauten, Emoji, mehreren
   Musikrichtungen/Leistungen und einer mehrzeiligen Nachricht absenden.
5. Prüfen: Anfrage-E-Mail und Kundenbestätigung kommen an; WhatsApp erreicht
   die konfigurierte Empfängernummer; der Browser zeigt nur die bisherige Erfolgsmeldung, ohne Popup
   oder zusätzlichen Formular-Button. Sprachwechsel DE/HR/EN/IT testen.
6. In einer Testumgebung den Token vorübergehend ungültig setzen und erneut
   absenden: E-Mail und Formularerfolg bleiben erhalten. Im PHP-Fehlerlog steht
   `[whatsapp] failed` mit HTTP-, cURL- und Meta-Fehlercodes. Danach Token korrigieren.
7. Fehlende Konfiguration, fehlendes cURL und Zeitüberschreitungen prüfen.
   Ungültige Pflichtfelder dürfen weder E-Mail noch WhatsApp auslösen.

## Ablauf und Grenzen

Nach Validierung und erfolgreicher Übergabe der Anfrage-E-Mail an `mail()` wird
ein WhatsApp-Versuch ausgeführt (maximal zehn Sekunden). Anschließend wird die
bestehende Kundenbestätigung versendet. Ein Fehler der Kundenbestätigung wird
protokolliert, ohne die bereits empfangene Anfrage als fehlgeschlagen darzustellen.

Die WhatsApp-Nachricht enthält alle zwölf Felder. Leere optionale Werte werden
zu „Keine Angabe“, Zeilenumbrüche in Variablen zu Leerzeichen. UTF-8 bleibt erhalten;
es findet keine stille Kürzung statt. Überschreitet ein Text Metas Größenlimits,
wird der Fehler protokolliert und die vollständige E-Mail bleibt erhalten.

`[whatsapp] accepted_by_meta` bedeutet API-Annahme, keine Zustellgarantie.
Asynchrone Zustellfehler lassen sich über Metas Status-Webhooks überwachen;
ein Webhook-Empfänger und eine dauerhafte Warteschlange mit Wiederholungsversuchen
sind hier nicht implementiert. Netzwerk-/API-Fehler werden ohne Kundendaten,
Tokens oder rohe API-Antworten im PHP-Fehlerlog erfasst. Für einen erneuten
WhatsApp-Versuch das Kundenformular nicht erneut absenden (doppelte E-Mail).

Quellen:
- Meta Cloud API: https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api
- WhatsApp Business Messaging Policy: https://business.whatsapp.com/policy
