<?php

declare(strict_types=1);

return [
    'export.capacity.details' => 'Grenzen für Export und Wiederherstellung',
    'export.capacity.legacy' => 'Browser-Download (v1): bis zu {records, number, integer} Einträge, {revisions, number, integer} aufbewahrte Versionen, {relationships, number, integer} aktuelle Beziehungen und {media, number, integer} Medieneinträge innerhalb von {mib} MiB JSON.',
    'export.capacity.chunked' => 'Privates Paket in Datenblöcken (v2), erstellt durch die Installationsadministration: bis zu {records, number, integer} Einträge, {revisions, number, integer} aufbewahrte Versionen, {relationships, number, integer} aktuelle Beziehungen und {media, number, integer} Medieneinträge innerhalb von {mib} MiB Inhaltsblöcken.',
    'export.capacity.scope' => 'Die Summen gelten für die ganze Installation, einschließlich Entwürfen, Archiven, Begriffen und Einstellungen. Jede gespeicherte Version enthält den vollständigen Inhalt. Beide Wege erlauben bis zu {derivatives, number, integer} Ableitungen. Alle Mengen- und Bytegrenzen gelten zugleich; zu große Exporte werden abgelehnt, niemals gekürzt oder um Verlauf bereinigt.',
    'export.capacity.restore' => 'Die Wiederherstellung prüft Dateien, Bytes, Laufzeit und Hostfähigkeiten getrennt. V1 benötigt mindestens 64 MiB freien PHP-Speicher oder das Sechzehnfache der JSON-Dateigröße, je nachdem, welcher Wert größer ist. Die gemessene v2-Stufe erfordert einen qualifizierten PHP-CLI-Prozess mit 256 MiB; die Anzahl der Einträge allein qualifiziert keinen Host. Unvollständige Vorbereitung muss fortgesetzt werden und ist keine abgeschlossene Wiederherstellung.',
    'export.capacity.bytes' => 'JSON enthält keine privaten Originale, Ableitungen oder hochgeladenen Schriften: Diese Dateien muss eine befugte Person ebenfalls paketieren. Ein Inhaltspaket enthält keine Konten, Geheimnisse, Sitzungen, alten Auditverläufe, Aufgabenwiederherstellung oder Veröffentlichungsaktivierung. Bewahren Sie eine separat geprüfte Installationssicherung auf.',
    'export.capacity.exceeded' => 'Diese Website überschreitet mindestens eine unterstützte Mengen- oder Bytegrenze. Es wurde nichts gelöscht. Bitten Sie die Installationsadministration, die Kapazität zu prüfen und einen qualifizierten Export durchzuführen, bevor die Website weiter wächst.',
    'preflight.issue.capacity' => 'Diese Website überschreitet eine Größen- oder Eintragsgrenze für Vorschauen. Es wurden keine Inhalte gelöscht. Bitten Sie die Installationsadministration, die Kapazität für Vorschauen und vollständige Exporte vor dem Erstellen zu prüfen.',
    'export.capacity.counts' => 'Diese Website enthält {records} Einträge und {revisions} gespeicherte Versionen, einschließlich Entwürfen und archivierten Inhalten.',
    'export.capacity.warning' => 'Diese Website nähert sich einer unterstützten Grenze für Inhalt, Verlauf oder die Größe aktueller Seiten. Erstellen Sie einen geprüften vollständigen Export und prüfen Sie die Kapazität vor weiteren Inhalten. Der Verlauf folgt der eingestellten Aufbewahrungsgrenze.',
    'export.capacity.package' => 'Nutzen Sie den privaten Export in Datenblöcken für einen vollständigen Export dieser Website. Bitten Sie die Installationsadministration, das mitgelieferte Werkzeug zur Paketerstellung auszuführen. Alle aufbewahrten Versionen bleiben enthalten; kein Verlauf wird verworfen.',
];
