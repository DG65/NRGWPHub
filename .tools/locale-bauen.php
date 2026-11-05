<?php
/**
 * Schreibt die deutsche Fassung (.tools/locale-de.json) als WPHub/locale.json -- Symcon liest locale.json
 * nur im Ordner des Moduls. Quelltexte stehen englisch im Code und in der form.json.
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$quelle = (string)file_get_contents(__DIR__ . '/locale-de.json');
$tabelle = json_decode($quelle, true, 512, JSON_THROW_ON_ERROR);
file_put_contents(__DIR__ . '/../WPHub/locale.json', json_encode($tabelle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
echo 'locale.json geschrieben (' . count($tabelle['translations']['de']) . " Texte)\n";
