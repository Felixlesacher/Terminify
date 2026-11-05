# konstanth – Einrichtungen, Veranstaltungen & Rundmails

WordPress-Plugin für die Zusammenarbeit anthroposophischer Einrichtungen: Veranstaltungen, Stellen, Einrichtungen und Nachrichten als Gutenberg-Blöcke und Shortcodes, mit Zugängen für Einrichtungen, Kategorien mit eigenen Farben und optionalen Rundmails über Brevo. Oberfläche und öffentliche Texte sind auf Deutsch.

## Installation

1. Unter [Releases](https://github.com/Felixlesacher/Terminify/releases) die Datei `konstanth-X.Y.Z.zip` der neuesten Version herunterladen. Nicht den Knopf „Code → Download ZIP“ verwenden: Dieses Archiv hat eine andere Ordnerstruktur.
2. In WordPress unter **Plugins → Installieren → Plugin hochladen** die ZIP hochladen und aktivieren.
3. Ab Version 1.2.0 erscheinen neue Versionen automatisch unter **Plugins** und lassen sich per Klick aktualisieren. Inhalte, Konten und Einstellungen bleiben dabei erhalten.

Voraussetzungen: WordPress 6.6 oder neuer, PHP 8.0 oder neuer.

## Hinweise

- Nach der Aktivierung läuft das Plugin im Testmodus: Es werden keine E-Mails verschickt und es gibt keine Verbindung zu Brevo.
- Für Updates fragt die Website etwa alle 12 Stunden bei GitHub nach der neuesten Version, häufiger, solange ein Administrator die Seiten „Plugins“ oder „Aktualisierungen“ geöffnet hat. Abschalten mit `define( 'KN_DISABLE_UPDATES', true );` in der `wp-config.php`.
- Ausführliche Beschreibung, externe Dienste und Änderungen: siehe `readme.txt`.

## Lizenz

GPL-2.0-or-later. Enthält die Bibliothek [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) von Jānis Elsts (MIT-Lizenz, siehe `lib/plugin-update-checker/license.txt`). Diese bringt ihrerseits Parsedown von Emanuil Rusev (MIT-Lizenz) und einen vom WordPress-Readme-Parser abgeleiteten Parser mit. Außerdem enthält es [pdf.js](https://github.com/mozilla/pdf.js) 6.4.299 von Mozilla (Apache License 2.0), das PDF-Flyer auf Veranstaltungsseiten zeichnet, samt seinen Bausteinen für JPEG-2000-Bilder (OpenJPEG, BSD-Lizenz), JBIG2-Bilder (PDFium, BSD-Lizenz) und Farbprofile (qcms, MIT-Lizenz) und einem CMYK-Profil (CC0); Lizenzen und Herkunft in `assets/vendor/pdfjs/` (`LICENSE`, `wasm/LICENSE_*`, `iccs/LICENSE`, `HERKUNFT.md`). Die Apache-Lizenz ist mit der GPL ab Version 3 vereinbar; zusammen mit pdf.js wird das Plugin daher unter GPL-3.0-or-later weitergegeben.
