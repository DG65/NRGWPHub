<?php

declare(strict_types=1);

// ===========================================================================
// Uebersetzung fuer WPHub (Cloud-Clients, Modul, Formular). Quelltexte stehen auf
// Englisch im Code und in der form.json, die deutsche Fassung in WPHub/locale.json
// (Symcons uebliches Muster). Die Quelle ist .tools/locale-de.json, php
// .tools/locale-bauen.php erzeugt daraus WPHub/locale.json (Symcon liest locale.json
// nur im Modulordner).
//
// Die Cloud-Clients haben kein $this->Translate(); das Modul setzt deshalb im
// Konstruktor den Uebersetzer. Ohne Uebersetzer bleibt der englische Text stehen.
//
// Symcons Translate() liest bei JEDEM Aufruf die locale.json (Automat 0.2.0:
// hunderte Aufrufe je Timer-Durchlauf -> Tick 2,5 s, SUITE.md Stolperstein 24).
// Hier fragt jeder Text Symcon deshalb hoechstens EINMAL je Prozess und merkt
// sich die Antwort; Timerlauf, Vertragsabfrage und Formular kosten danach nur
// noch einen Array-Zugriff je Text.
// ===========================================================================

final class WPHUB_Text
{
    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;
    /** @var array<string,string> gemerkte Uebersetzungen */
    private static $gemerkt = [];
    /** @var int Zaehler fuer den Pruefstand: wie oft wurde Symcon wirklich gefragt */
    public static $anfragen = 0;

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
        self::$gemerkt = [];
    }

    /** Uebersetzer fuer ein Modul: Symcons Translate(), aber je Text nur einmal je Prozess. */
    public static function mitMerkliste(callable $translate): callable
    {
        return function (string $text) use ($translate): string {
            if (!isset(self::$gemerkt[$text])) {
                self::$anfragen++;
                try {
                    self::$gemerkt[$text] = (string)$translate($text);
                } catch (\Throwable $e) {
                    // z. B. Instanz waehrend des Aufrufs geloescht: Quelltext zeigen, nichts merken.
                    return $text;
                }
            }
            return self::$gemerkt[$text];
        };
    }

    /** Text uebersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $aus === false ? $uebersetzt : $aus;
    }

    /**
     * Uebersetzt in einem Formular (form.json) alle Anzeigetexte: caption, confirm, label sowie die
     * Beschriftungen in options/popup/items/status. Kommandos (onClick, onChange) und Namen bleiben.
     */
    public static function formular($knoten)
    {
        if (!is_array($knoten)) {
            return $knoten;
        }
        foreach ($knoten as $schluessel => $wert) {
            if (is_string($schluessel) && in_array($schluessel, ['caption', 'confirm', 'label'], true) && is_string($wert)) {
                $knoten[$schluessel] = $wert === '' ? $wert : self::t($wert);
            } elseif (is_array($wert)) {
                $knoten[$schluessel] = self::formular($wert);
            }
        }
        return $knoten;
    }
}
