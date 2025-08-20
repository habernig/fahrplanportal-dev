<?php 

/**
 * Parst eine PDF-Datei von einem gegebenen Pfad, extrahiert den Text und verarbeitet ihn.
 *
 * Die Verarbeitung umfasst:
 * 1. Entfernen aller Zahlen.
 * 2. Umwandlung aller Wörter in Kleinbuchstaben.
 * 3. Entfernen von Duplikaten.
 * 4. Entfernen von Wörtern, die kürzer als 4 Buchstaben sind.
 * 5. Entfernen von Wörtern aus der Exklusionsliste.
 *
 * @param string $pdf_file_path Der vollständige Dateipfad zur PDF-Datei.
 * @param array $exclusion_words Optionales Array mit Exklusionswörtern (bereits in Kleinbuchstaben und als array_flip für Performance).
 * @return array Ein Array von einzigartigen, verarbeiteten Wörtern aus der PDF,
 * oder ein leeres Array im Fehlerfall.
 */

use Smalot\PdfParser\Parser;

function hd_process_pdf_for_words( string $pdf_file_path, array $exclusion_words = [] ): array {
    // Temporäre Erhöhung der PHP-Limits für den Parsing-Prozess.
    // Dies ist entscheidend für größere PDFs.
    ini_set('memory_limit', '512M');
    set_time_limit(300);

    // Prüfen, ob die Datei existiert und lesbar ist
    if ( ! file_exists( $pdf_file_path ) ) {
        error_log( 'HD PDF Processor: Datei existiert nicht unter Pfad: ' . $pdf_file_path );
        return [];
    }
    if ( ! is_readable( $pdf_file_path ) ) {
        error_log( 'HD PDF Processor: Datei ist nicht lesbar (Berechtigungsproblem): ' . $pdf_file_path );
        return [];
    }

    $processed_words = []; // Sammelt die verarbeiteten Wörter

    try {
        $parser = new Parser();
        $pdf = $parser->parseFile( $pdf_file_path );
        $raw_text = $pdf->getText();

        // 1. Zahlen entfernen
        $text_without_numbers = preg_replace('/\d/', '', $raw_text);

        // 2. Wörter in Kleinbuchstaben zerlegen
        // Regex findet Wörter, die Buchstaben (inkl. Umlaute), Bindestriche oder Apostrophe enthalten
        preg_match_all('/[a-zA-ZäöüÄÖÜß\-\']+/', $text_without_numbers, $matches);
        $words = $matches[0];

        // 3. Wörter in Kleinbuchstaben konvertieren (nochmal, falls nicht schon in Regex behandelt)
        $words_lower = array_map('mb_strtolower', $words);

        // 4. Wörter kürzer als 4 Buchstaben eliminieren
        $filtered_words_by_length = array_filter($words_lower, function($word) {
            return mb_strlen($word) >= 4; // mb_strlen für korrekte Länge bei Umlauten
        });

        // 5. Exklusionswörter entfernen (falls Exklusionsliste vorhanden)
        if (!empty($exclusion_words)) {
            $filtered_words_by_exclusion = array_filter($filtered_words_by_length, function($word) use ($exclusion_words) {
                return !isset($exclusion_words[$word]); // isset() ist viel schneller als in_array()
            });
        } else {
            $filtered_words_by_exclusion = $filtered_words_by_length;
        }

        // 6. Duplikate entfernen (am Ende der Verarbeitungskette)
        $processed_words = array_unique($filtered_words_by_exclusion);

    } catch ( \Exception $e ) {
        // Logge den Fehler, anstatt ihn direkt auf einer Webseite auszugeben
        error_log( 'HD PDF Processor Fehler beim Parsen von ' . $pdf_file_path . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine() );
        return []; // Im Fehlerfall ein leeres Array zurückgeben
    }

    return array_values($processed_words); // array_values() reindiziert das Array numerisch
}