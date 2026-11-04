<?php
/** konstanth → Anleitung: step-by-step help for institution accounts; administrators also see the platform chapters. */
defined( 'ABSPATH' ) || exit;

class KN_Guide {
    public static function render() {
        if ( ! current_user_can( 'kn_access' ) ) wp_die( 'Keine Berechtigung.', '', array( 'response' => 403 ) );
        $admin = KN_Core::is_admin();
        $new = function ( $type ) { return esc_url( admin_url( 'post-new.php?post_type=' . $type ) ); };
        $page = function ( $slug ) { return esc_url( admin_url( 'admin.php?page=' . $slug ) ); };
        $org = $admin ? 0 : KN_Core::org_id();
        $profile = $admin ? esc_url( admin_url( 'edit.php?post_type=kn_institution' ) ) : ( $org ? esc_url( (string) get_edit_post_link( $org, 'raw' ) ) : '' );
        $chapters = array(
            'start' => 'So arbeitest du mit konstanth',
            'veranstaltung' => 'Veranstaltung anlegen',
            'bilder' => 'Bilder, Flyer und Kopfbild',
            'rundmail' => 'Rundmail versenden',
            'aendern' => 'Absagen, duplizieren, planen',
            'stelle' => 'Stelle anlegen',
            'nachricht' => 'Nachricht anlegen',
            'profil' => 'Institutionsprofil und Förderung',
            'import' => 'Alte Mailtexte importieren',
            'fragen' => 'Wenn etwas nicht klappt',
        );
        if ( $admin ) $chapters += array(
            'bausteine' => 'Bausteine auf der Website',
            'darstellung' => 'Darstellung und Standard-Kopfbild',
            'kategorien' => 'Kategorien & Farben',
            'konten' => 'Institutionen und Konten',
            'versand' => 'Brevo und Rundmails',
        );
        ?>
<div class="wrap kn-admin kn-guide">
<h1>Anleitung</h1>
<p class="kn-admin-intro">Hier steht Schritt für Schritt, wie du Veranstaltungen, Stellen, Nachrichten und dein Institutionsprofil pflegst. <?php echo $admin ? 'Als Administrator siehst du am Ende zusätzlich die Kapitel zur Einrichtung der Plattform.' : 'Fragen zur Website selbst klärt der Administrator der Plattform.'; ?></p>
<nav class="kn-guide-toc" aria-label="Inhalt der Anleitung"><?php foreach ( $chapters as $id => $title ) echo '<a href="#kn-' . esc_attr( $id ) . '">' . esc_html( $title ) . '</a>'; ?></nav>

<section class="kn-guide-section" id="kn-start">
<h2>So arbeitest du mit konstanth</h2>
<p>Im Menü links unter <strong>konstanth</strong> findest du alles, was deine Einrichtung betrifft:</p>
<table class="kn-guide-table"><tbody>
<tr><th>Veranstaltungen</th><td>Vorträge, Kurse, Feste und alles mit Datum. Erscheinen in der Veranstaltungsliste, im Kalender und auf der Startseite.</td></tr>
<tr><th>Stellen</th><td>Stellenangebote für die Stellenbörse. Sie verschwinden nach der Bewerbungsfrist von selbst.</td></tr>
<tr><th>Nachrichten</th><td>Neuigkeiten aus deiner Einrichtung.</td></tr>
<tr><th>Institutionen</th><td>Das Profil deiner Einrichtung mit Logo, Kontakt und Förderangaben.</td></tr>
<tr><th>Rundmails</th><td>Welche Rundmails geplant, verschickt oder angehalten sind.</td></tr>
<tr><th>Mailtexte importieren</th><td>Alte Mailtexte als Entwürfe übernehmen.</td></tr>
</tbody></table>
<p><strong>Die Bearbeitungsansicht:</strong> Jeder Eintrag öffnet sich in einer aufgeräumten Ansicht. Oben steht eine Leiste mit <em>Abbrechen</em>, <em>Entwurf sichern</em> und <em>Veröffentlichen</em>. Links füllst du die Angaben aus, rechts siehst du auf großen Bildschirmen sofort eine Vorschau. Die Vorschau ist vereinfacht; auf der Website kommt die Schrift von deren Gestaltung. Die genaue Ansicht öffnet der Knopf <em>Vorschau</em> im Kasten „Veröffentlichen“.</p>
<p>Lieber die gewohnte WordPress-Ansicht? Oben auf <em>Klassische Ansicht</em> klicken. Zurück geht es mit <em>Fokus-Ansicht</em>. Dein Browser merkt sich die Wahl.</p>
<p class="kn-guide-tip">Du siehst und bearbeitest nur die Inhalte deiner eigenen Einrichtung. Auf der Website steht als Veranstalter immer der Name der Einrichtung, nicht dein Benutzername.</p>
</section>

<section class="kn-guide-section" id="kn-veranstaltung">
<h2>Veranstaltung anlegen</h2>
<ol class="kn-guide-steps">
<li><strong>Öffnen:</strong> konstanth → Veranstaltungen → <em>Neu hinzufügen</em>. <a href="<?php echo $new( 'kn_event' ); ?>">Neue Veranstaltung jetzt anlegen</a></li>
<li><strong>Titel:</strong> ganz oben eintragen, zum Beispiel „Licht und Schwere – Seminar mit Abendvortrag“.</li>
<li><strong>Bild der Veranstaltung:</strong> direkt unter dem Titel auf <em>Bild der Veranstaltung festlegen</em> klicken, ein Bild hochladen oder aus der Mediathek wählen (Format siehe <a href="#kn-bilder">Bilder</a>).</li>
<li><strong>Wann:</strong> Beginn und Ende wählen. Ein Klick öffnet Kalender und Uhrzeit. Das Ende muss nach dem Beginn liegen; die Zeit gilt in der Zeitzone der Website.</li>
<li><strong>Wo:</strong> Falls der Ort einen Namen hat, ihn eintragen (zum Beispiel „Atelier im Zweighaus“). Darunter die Adresse: Straße und Hausnummer, Postleitzahl, Ort, dann Land und Bundesland bzw. Kanton aus der Liste. Bei neuen Veranstaltungen sind Land und Bundesland deiner Einrichtung schon vorausgewählt.</li>
<li><strong>Eintritt:</strong> <em>Keine Angabe</em>, <em>Kostenlos</em> oder <em>Preis</em> wählen. Bei „Preis“ trägst du ihn als Text ein, zum Beispiel „15 €, ermäßigt 10 €“ oder „20 CHF“.</li>
<li><strong>Themen:</strong> mindestens eines und höchstens drei anklicken. Nach den Themen filtern Besucher die Liste, und Abonnenten eines Themas bekommen deine Rundmail.</li>
<li><strong>Beschreibung:</strong> in das große Textfeld schreiben. Ablauf, Preise und Hinweise gehören hierher.</li>
<li><strong>Weitere Angaben</strong> (alle freiwillig): <em>Link zur Anmeldung</em> (wird zum Knopf „Zur Anmeldung“), <em>Flyer</em>, eigenes <em>Kopfbild</em>, <em>Informationen für Besucher</em>.</li>
<li><strong>Interne Hinweise:</strong> nur für deine Einrichtung, zum Beispiel „Raum ab 13 Uhr offen“. Sie erscheinen nie auf der Website, in Kalenderdateien oder in Rundmails.</li>
<li><strong>Rundmail:</strong> <em>Keine</em>, <em>Sofort</em> oder <em>Nach 24 h</em> wählen (siehe <a href="#kn-rundmail">Rundmail</a>).</li>
<li><strong>Speichern:</strong> erst <em>Entwurf sichern</em> und die Vorschau ansehen, dann <em>Veröffentlichen</em>.</li>
</ol>
<p><strong>So sieht die Veranstaltungsseite aus:</strong> Oben das Kopfbild mit einer Karte für Datum und Titel. Darunter links das Bild und der Flyer mit dem Knopf „Flyer herunterladen“, rechts die Beschreibung mit Ort, Eintritt, Veranstalter und den Knöpfen „Zur Anmeldung“ und „In den Kalender“. Ganz unten führt „Alle Veranstaltungen“ zurück zur Liste. „In den Kalender“ lädt eine Kalenderdatei; Handys öffnen sie direkt im Kalender, am Computer reicht ein Klick auf die Datei.</p>
<p class="kn-guide-tip">Ohne Beginn, Ende, Themen und zugeordnete Einrichtung lässt sich nichts veröffentlichen. Fehlt etwas, bleibt der Eintrag ein Entwurf und oben steht, was fehlt.</p>
</section>

<section class="kn-guide-section" id="kn-bilder">
<h2>Bilder, Flyer und Kopfbild</h2>
<p>Unter jedem Bildfeld steht das passende Format. Hier alles auf einen Blick:</p>
<div class="kn-guide-scroll"><table class="kn-guide-table kn-guide-formats"><thead><tr><th>Bild</th><th>Format</th><th>Am besten</th><th>Mindestens</th><th>Dateityp</th></tr></thead><tbody>
<tr><th>Bild der Veranstaltung</th><td>Querformat 16:9</td><td>1600 × 900 Pixel</td><td>1200 × 675 Pixel</td><td>JPG oder WebP</td></tr>
<tr><th>Bild zur Stelle</th><td>Querformat 16:9</td><td>1600 × 900 Pixel</td><td>1200 × 675 Pixel</td><td>JPG oder WebP</td></tr>
<tr><th>Bild zur Nachricht</th><td>Querformat 16:9</td><td>1600 × 900 Pixel</td><td>1200 × 675 Pixel</td><td>JPG oder WebP</td></tr>
<tr><th>Logo der Institution</th><td>Quadratisch 1:1</td><td>800 × 800 Pixel</td><td>400 × 400 Pixel</td><td>PNG mit transparentem Hintergrund oder JPG</td></tr>
<tr><th>Kopfbild einer Veranstaltung</th><td>Querformat 3:1</td><td>2400 × 800 Pixel</td><td>1600 × 533 Pixel</td><td>JPG oder WebP</td></tr>
<tr><th>Flyer</th><td>zum Beispiel A4</td><td>2480 × 3508 Pixel oder PDF</td><td>–</td><td>PDF, JPG, PNG, WebP oder GIF</td></tr>
</tbody></table></div>
<ul class="kn-guide-list">
<li><strong>Logo:</strong> Es wird klein und rund angezeigt. Lass rund um das Motiv etwas Rand, sonst werden Ecken abgeschnitten.</li>
<li><strong>Kopfbild:</strong> Wichtiges in die Mitte setzen; je nach Bildschirm werden oben und unten oder links und rechts etwas abgeschnitten. Ohne eigenes Kopfbild erscheint das Standardbild der Plattform.</li>
<li><strong>Flyer:</strong> Er erscheint auf der Veranstaltungsseite neben der Beschreibung: ein PDF im PDF-Betrachter des Browsers, ein Bild als Bild. Darunter steht der Knopf „Flyer herunterladen“ mit der Datei genau so, wie du sie hochlädst. Handys ohne PDF-Betrachter zeigen nur den Knopf. Entfernen: im Feld auf <em>Entfernen</em> klicken und speichern.</li>
<li><strong>Bild in der Rundmail:</strong> Das Bild der Veranstaltung, Stelle oder Nachricht steht auch in der Mail. WebP-Bilder wandelt konstanth dafür selbst um.</li>
<li>Verwende nur eigene Bilder oder Bilder, die du verwenden darfst.</li>
</ul>
</section>

<section class="kn-guide-section" id="kn-rundmail">
<h2>Rundmail versenden</h2>
<p>Zu jeder Veranstaltung, Stelle und Nachricht kann konstanth eine Rundmail verschicken. Titel, Bild und Text kommen automatisch aus dem Eintrag.</p>
<ol class="kn-guide-steps">
<li>Den Eintrag fertig schreiben.</li>
<li>Im Kasten <em>Website &amp; Rundmail</em> wählen: <strong>Keine</strong>, <strong>Sofort</strong> (beim Veröffentlichen) oder <strong>Nach 24 h</strong> (einen Tag nach der ersten Veröffentlichung).</li>
<li><em>Entwurf sichern</em> und dann <em>Gespeicherte Mailvorschau</em> öffnen. So sieht die Mail aus.</li>
<li><em>Veröffentlichen</em>. Den Stand siehst du unter <a href="<?php echo $page( 'kn-mail' ); ?>">konstanth → Rundmails</a>.</li>
</ol>
<ul class="kn-guide-list">
<li><strong>Wer sie bekommt:</strong> alle, die deine Einrichtung abonniert und das Abo bestätigt haben. Bei Veranstaltungen zusätzlich alle, die eines der gewählten Themen abonniert haben.</li>
<li><strong>Eine pro Tag:</strong> Je Einrichtung geht höchstens eine Rundmail pro Tag hinaus. Weitere folgen am nächsten Morgen um 8 Uhr.</li>
<li><strong>Freigabe:</strong> Je nach Einstellung der Plattform prüft ein Administrator die Rundmail vorher. Dann steht unter Rundmails „Wartet auf Freigabe“.</li>
<li><strong>Nachträgliche Änderungen</strong> lösen keine zweite Rundmail aus. Stellst du vor dem Versand auf <em>Keine</em> zurück, wird die geplante Mail gestoppt.</li>
<li><strong>Nicht verschickt</strong> werden Mails zu abgesagten oder bereits begonnenen Veranstaltungen und zu abgelaufenen Stellen.</li>
<li><strong>Nach 24 h</strong> passt nicht, wenn die Veranstaltung schon vorher beginnt. Dann weist der Kasten darauf hin; wähle in dem Fall <em>Sofort</em>.</li>
</ul>
</section>

<section class="kn-guide-section" id="kn-aendern">
<h2>Absagen, duplizieren, planen</h2>
<h3>Veranstaltung absagen</h3>
<p>Die Veranstaltung öffnen, <em>Veranstaltung ist abgesagt</em> anhaken und speichern. Sie bleibt sichtbar, ist aber in Liste, Kalender und auf ihrer Seite deutlich als abgesagt markiert. Auf der Startseite erscheint sie nicht mehr. Eine geplante Rundmail dazu geht nicht hinaus.</p>
<h3>Duplizieren, zum Beispiel für Kurse</h3>
<ol class="kn-guide-steps">
<li>Die Veranstaltung öffnen und im Kasten <em>Website &amp; Rundmail</em> auf <em>Veranstaltung duplizieren</em> klicken. In der Liste geht das auch über den Link <em>Duplizieren</em>.</li>
<li>konstanth legt einen Entwurf mit dem Zusatz „(Kopie)“ an, mit Text, Angaben, Adresse, Eintritt, Themen, Bild, Flyer und Kopfbild.</li>
<li>Datum und Titel anpassen, prüfen, veröffentlichen. Stellen und Nachrichten kopierst du genauso mit <em>Als Kopie neu anlegen</em>.</li>
</ol>
<h3>Später veröffentlichen</h3>
<p>Im Kasten <em>Veröffentlichen</em> bei „Sofort veröffentlichen“ auf <em>Bearbeiten</em> klicken, Datum und Uhrzeit wählen und <em>Planen</em> klicken. WordPress veröffentlicht den Eintrag dann von selbst. Fehlt zu diesem Zeitpunkt eine Pflichtangabe, bleibt er ein Entwurf und in der Liste steht „Geplante Veröffentlichung abgebrochen“.</p>
<h3>Löschen</h3>
<p>In der Liste mit der Maus über den Eintrag fahren und <em>Papierkorb</em> wählen. Aus dem Papierkorb lässt er sich wiederherstellen. Vergangene Veranstaltungen musst du nicht löschen: Sie verschwinden von selbst aus Liste und Startseite.</p>
</section>

<section class="kn-guide-section" id="kn-stelle">
<h2>Stelle anlegen</h2>
<ol class="kn-guide-steps">
<li>konstanth → Stellen → <em>Neu hinzufügen</em>. <a href="<?php echo $new( 'kn_job' ); ?>">Neue Stelle jetzt anlegen</a></li>
<li>Titel der Stelle eintragen, zum Beispiel „Klassenlehrer:in Unterstufe (60–80 %)“.</li>
<li><em>Bild zur Stelle</em> festlegen (16:9, 1600 × 900 Pixel). Freiwillig, aber es macht die Stelle sichtbarer.</li>
<li>Die Stelle beschreiben: Aufgaben, Profil, Angebot.</li>
<li><em>Arbeitsort</em>, <em>Stellenumfang</em>, <em>Bewerbungsfrist</em> und <em>Bewerbungsweg / Kontakt / Link</em> ausfüllen. Eine Internetadresse (https://…) wird zum Knopf „Zur Bewerbung“; anderer Text, etwa eine E-Mail-Adresse oder ein Ansprechpartner, erscheint so, wie du ihn schreibst.</li>
<li>Rundmail wählen, Entwurf sichern, prüfen, veröffentlichen.</li>
</ol>
<p class="kn-guide-tip">Nach der Bewerbungsfrist verschwindet die Stelle von selbst aus der Stellenbörse. Ohne Frist bleibt sie, bis du sie löschst.</p>
</section>

<section class="kn-guide-section" id="kn-nachricht">
<h2>Nachricht anlegen</h2>
<ol class="kn-guide-steps">
<li>konstanth → Nachrichten → <em>Neu hinzufügen</em>. <a href="<?php echo $new( 'kn_news' ); ?>">Neue Nachricht jetzt anlegen</a></li>
<li>Überschrift, <em>Bild zur Nachricht</em> (16:9, 1600 × 900 Pixel) und Text eintragen.</li>
<li>Rundmail wählen, Entwurf sichern, prüfen, veröffentlichen. Über der Nachricht steht auf der Website der Name deiner Einrichtung.</li>
</ol>
</section>

<section class="kn-guide-section" id="kn-profil">
<h2>Institutionsprofil und Förderung</h2>
<p>Das Profil stellt deine Einrichtung im Verzeichnis vor. <?php if ( $profile ) echo '<a href="' . $profile . '">' . ( $admin ? 'Institutionen öffnen' : 'Dein Profil jetzt bearbeiten' ) . '</a>'; ?></p>
<ol class="kn-guide-steps">
<li><strong>Name</strong> der Einrichtung als Titel.</li>
<li><strong>Logo:</strong> quadratisch, 800 × 800 Pixel, am besten als PNG mit transparentem Hintergrund. Ohne Logo erscheinen die Anfangsbuchstaben in der Farbe deiner Kategorie.</li>
<li><strong>Beschreibung:</strong> wer ihr seid und was ihr anbietet.</li>
<li><strong>Kategorien</strong> (zum Beispiel Schule, Gesundheit) anklicken.</li>
<li><strong>Adresse und Kontakt:</strong> Straße und Hausnummer, Postleitzahl, Ort, Land, Bundesland bzw. Kanton, dann Kontakt-E-Mail, Telefon und Website. Antworten auf eure Rundmails gehen an die Kontakt-E-Mail.</li>
<li><strong>Förderung und Spenden:</strong> Unter <em>Eigene Förderinformationen / Spendenkonto</em> trägst du ein, wie man euch unterstützen kann, etwa Spendenkonto und Verwendungszweck. Unter <em>Förder- oder Spendenlink</em> kommt die Adresse eurer Spendenseite. Beides erscheint im Profil und im Bereich „Fördern“ der Website. konstanth wickelt selbst keine Zahlungen ab.</li>
<li><em>Aktualisieren</em> klicken.</li>
</ol>
</section>

<section class="kn-guide-section" id="kn-import">
<h2>Alte Mailtexte importieren</h2>
<p>Frühere Rundmails lassen sich als Nachrichtenentwürfe übernehmen. <a href="<?php echo $page( 'kn-import' ); ?>">Zum Import</a></p>
<ol class="kn-guide-steps">
<li>Eine CSV-Datei mit den Spalten <code>title,content,date</code> vorbereiten (UTF-8, Komma oder Semikolon, höchstens 1 MB und 100 Einträge). Am einfachsten mit einer Tabellenkalkulation „Als CSV speichern“.</li>
<li>Erst eine kleine Testdatei hochladen.</li>
<li>Die Entwürfe unter konstanth → Nachrichten prüfen und einzeln veröffentlichen.</li>
</ol>
<p class="kn-guide-tip">Der Import übernimmt keine Empfängeradressen und verschickt keine Rundmails. Gleiche Texte werden nicht doppelt angelegt.</p>
</section>

<section class="kn-guide-section" id="kn-fragen">
<h2>Wenn etwas nicht klappt</h2>
<dl class="kn-guide-faq">
<dt>Nach „Veröffentlichen“ bleibt der Eintrag ein Entwurf.</dt><dd>Oben steht ein Hinweis, was fehlt. Meist sind es Beginn und Ende oder die Themen (eins bis drei).</dd>
<dt>Meine Veranstaltung fehlt auf der Startseite.</dt><dd>Dort stehen nur die nächsten Veranstaltungen, abgesagte nicht. Prüfe Datum, Status (veröffentlicht?) und das Häkchen „abgesagt“.</dd>
<dt>Das Bild ist abgeschnitten.</dt><dd>Prüfe das Format in der Tabelle unter <a href="#kn-bilder">Bilder</a>. Querbilder 16:9 und quadratische Logos passen ohne Beschnitt.</dd>
<dt>Die Rundmail ist nicht angekommen.</dt><dd>Unter Rundmails steht der Stand: geplant, wartet auf Freigabe, übergeben oder beendet mit Grund. Die Plattform kann sich außerdem noch im Testbetrieb befinden; dann geht keine Mail hinaus.</dd>
<dt>Ich sehe die Vorschau rechts nicht.</dt><dd>Sie erscheint erst auf breiten Bildschirmen. Die genaue Ansicht zeigt immer der Knopf <em>Vorschau</em> im Kasten „Veröffentlichen“.</dd>
<dt>Das Hochladen bricht ab.</dt><dd>Die Datei ist vermutlich größer als erlaubt. Die Grenze steht im Fenster der Mediathek; Bilder vorher verkleinern, zum Beispiel auf 1600 Pixel Breite.</dd>
</dl>
</section>
<?php if ( $admin ) : ?>

<p class="kn-guide-divider">Nur für Administratoren</p>

<section class="kn-guide-section" id="kn-bausteine">
<h2>Bausteine auf der Website</h2>
<p>Die Inhalte erscheinen über Bausteine, die du in deine eigenen Seiten setzt. Kopf, Menü, Fuß und Schrift bleiben beim Theme.</p>
<ol class="kn-guide-steps">
<li>Eine Seite öffnen, auf <strong>+</strong> klicken und nach „konstanth“ suchen.</li>
<li>Den Baustein wählen, zum Beispiel <em>konstanth: Veranstaltungen</em> für die Veranstaltungsseite oder <em>konstanth: Aktuelle Veranstaltungen</em> für die Startseite.</li>
<li>Rechts in der Seitenleiste einstellen: Einrichtung, Anzahl, Anordnung, Überschrift. Beim Startseiten-Baustein auch Knopftext und Ziel.</li>
<li>Seite speichern.</li>
</ol>
<p>Kurzzeilen (Shortcodes) zum Kopieren und alle Einstellungen stehen unter <a href="<?php echo $page( 'konstanth' ); ?>">Übersicht &amp; Shortcodes</a>. Diese Seite sehen nur Administratoren.</p>
<p class="kn-guide-tip">Der Knopf „Alle Veranstaltungen“ auf der Startseite und unten auf jeder Veranstaltungsseite führt automatisch zur ersten veröffentlichten Seite mit dem Baustein „konstanth: Veranstaltungen“. Wie die Seite heißt, spielt keine Rolle.</p>
</section>

<section class="kn-guide-section" id="kn-darstellung">
<h2>Darstellung und Standard-Kopfbild</h2>
<ol class="kn-guide-steps">
<li><a href="<?php echo $page( 'kn-display' ); ?>">konstanth → Darstellung</a> öffnen.</li>
<li>Ein breites Bild wählen (3:1, am besten 2400 × 800 Pixel). Es steht oben auf jeder Veranstaltungsseite ohne eigenes Kopfbild.</li>
<li>Speichern.</li>
</ol>
<p>Bausteine, Veranstaltungsseiten und Rundmails sind in den Farben „Goldlicht“ gehalten (Gold, Creme, dunkles Braun), passend zu hellbeigen Seiten; die Farben der Themen bleiben. Die Schrift kommt von deinem Theme. Andere Farben lassen sich bei Bedarf per CSS über die Variablen der Bausteine setzen.</p>
</section>

<section class="kn-guide-section" id="kn-kategorien">
<h2>Kategorien &amp; Farben</h2>
<p>Unter <a href="<?php echo $page( 'kn-categories' ); ?>">Kategorien &amp; Farben</a> pflegst du die Themen der Veranstaltungen und die Einrichtungsarten: hinzufügen, umbenennen, Reihenfolge, Farbe. Die Schriftfarbe berechnet konstanth selbst, damit der Kontrast stimmt. Beim Löschen nennt konstanth vorher, wie viele Einträge und Abos betroffen sind.</p>
</section>

<section class="kn-guide-section" id="kn-konten">
<h2>Institutionen und Konten</h2>
<ol class="kn-guide-steps">
<li>Unter <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=kn_institution' ) ); ?>">Institutionen</a> die Einrichtung anlegen und veröffentlichen.</li>
<li>Unter <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">Benutzer → Neu hinzufügen</a> ein Konto anlegen und die Rolle <strong>Institution</strong> wählen.</li>
<li>Im Abschnitt „konstanth · Institutionszugang“ die Einrichtung zuordnen. Mehrere Konten pro Einrichtung sind möglich.</li>
</ol>
<p>Institutionskonten bearbeiten nur die Inhalte ihrer eigenen Einrichtung. Sie sehen weder die Übersicht der Shortcodes noch Darstellung, Kategorien oder „Brevo & Versand“. Im Profil einer Einrichtung kannst du deren Rundmails sperren.</p>
</section>

<section class="kn-guide-section" id="kn-versand">
<h2>Brevo und Rundmails</h2>
<p>konstanth verschickt Rundmails und Bestätigungsmails über dein Brevo-Konto. Alles Nötige trägst du unter <a href="<?php echo $page( 'kn-settings' ); ?>">Brevo &amp; Versand</a> ein; dort steht jeder Schritt ausführlich.</p>
<ol class="kn-guide-steps">
<li>Bei Brevo ein Konto anlegen, am besten nur für konstanth.</li>
<li>Bei Brevo die Absenderadresse bestätigen (besser: die ganze Domain authentifizieren).</li>
<li>Bei Brevo einen API-Schlüssel erzeugen und einen Ordner anlegen, zum Beispiel „konstanth“.</li>
<li>Unter „Brevo &amp; Versand“ API-Schlüssel, Absenderadresse, Absendername, Ordner-ID und Postanschrift eintragen und speichern.</li>
<li><em>Verbindung prüfen</em> klicken. Der Kasten „Stand“ zeigt, was noch fehlt.</li>
<li>Eine Seite „Rundmail“ mit dem Baustein „konstanth: Rundmail-Anmeldung“ anlegen.</li>
<li>Live schalten: „Newsletter grundsätzlich aktivieren“ an, „Testmodus“ aus, speichern. Dann mit einer eigenen Adresse testen.</li>
</ol>
<p class="kn-guide-tip">In Brevo brauchst du keine Formulare, Listen oder Kategorien. Leser melden sich auf deiner Website an und wählen dort Themen und Einrichtungen; konstanth legt die passenden Listen bei Brevo selbst an.</p>
<ul class="kn-guide-list">
<li><strong>Freigabe:</strong> Ist unter <a href="<?php echo $page( 'kn-settings' ); ?>">Brevo &amp; Versand</a> „Rundmails von Einrichtungen brauchen meine Freigabe“ an, warten Rundmails der Einrichtungen unter <a href="<?php echo $page( 'kn-mail' ); ?>">Rundmails</a> auf dein <em>Freigeben</em> oder <em>Abbrechen</em>.</li>
<li><strong>Testbetrieb:</strong> Nach der Installation geht keine Mail hinaus. Den Livebetrieb schaltest du erst ein, wenn Brevo, Absenderadresse und Rechtliches fertig sind. Offene Aufträge aus dem Testbetrieb werden dabei nicht verschickt.</li>
<li><strong>Warteschlange:</strong> Sie läuft etwa alle fünf Minuten. Unter Rundmails siehst du den letzten Lauf und kannst sie von Hand starten.</li>
<li>Die ausführliche Einrichtung (Brevo, Server-Cron, Updates, Datenschutz) steht in der separaten Betreiber-Anleitung (ANLEITUNG.md).</li>
</ul>
</section>
<?php endif; ?>
</div>
        <?php
    }
}
