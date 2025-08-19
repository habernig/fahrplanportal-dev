<?php
/**
 * FahrplanPortal Utils Class
 * Helper-Funktionen, Formatierungen und Konvertierungen
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Utils {
    
    /**
     * ✅ NEU: Exklusionsliste aus WordPress Options laden (erweitert für Tag-Analyse)
     * ✅ ÜBERSCHREIBT: Die bestehende get_exclusion_words() Funktion für bessere Performance
     */
    public function get_exclusion_words() {
        $exclusion_text = get_option('fahrplanportal_exclusion_words', '');
        
        if (empty($exclusion_text)) {
            return array();
        }
        
        // Wörter können durch Leerzeichen, Kommas, Tabs oder Zeilenumbrüche getrennt sein
        $exclusion_words_array = preg_split('/[\s,\t\n\r]+/', $exclusion_text, -1, PREG_SPLIT_NO_EMPTY);
        $exclusion_words_array = array_map('trim', $exclusion_words_array);
        $exclusion_words_array = array_map('mb_strtolower', $exclusion_words_array);
        
        // Performance-Optimierung: array_flip für O(1) Lookups
        return array_flip($exclusion_words_array);
    }
    
    /**
     * ✅ BUG-FIX: Linien-Mapping aus WordPress Options laden 
     * ✅ ERWEITERT: Unterstützt jetzt auch Buchstaben-Zahl-Kombinationen (X1:SB1, X2:SB2)
     * ✅ PROBLEM: Das alte Regex war nur auf reine Zahlen ausgelegt
     */
    public function get_line_mapping() {
        $mapping_text = get_option('fahrplanportal_line_mapping', '');
        
        if (empty($mapping_text)) {
            error_log("FAHRPLANPORTAL: Mapping-Text ist leer");
            return array();
        }
        
        $mapping_array = array();
        $lines = preg_split('/[\n\r]+/', $mapping_text, -1, PREG_SPLIT_NO_EMPTY);
        
        error_log("FAHRPLANPORTAL: Verarbeite " . count($lines) . " Mapping-Zeilen");
        
        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            
            // Überspringe leere Zeilen und Kommentare
            if (empty($line) || strpos($line, '//') === 0 || strpos($line, '#') === 0) {
                continue;
            }
            
            // ✅ BUG-FIX: ERWEITERTE REGEX für Buchstaben-Zahl-Kombinationen
            // Altes Pattern: /^(\d+)\s*:\s*(\d+)$/ (nur Zahlen)
            // Neues Pattern: Unterstützt auch X1:SB1, A2:SA2, etc.
            if (preg_match('/^([A-Za-z]*\d+)\s*:\s*([A-Za-z]*\d+)$/', $line, $matches)) {
                $linie_neu = strtoupper(trim($matches[1]));  // X2, 100, A1 → X2, 100, A1
                $linie_alt = strtoupper(trim($matches[2]));  // SB2, 5000, SA1 → SB2, 5000, SA1
                
                $mapping_array[$linie_neu] = $linie_alt;
                
                error_log("FAHRPLANPORTAL: Mapping geladen (Zeile " . ($line_num + 1) . ") - Neue: '$linie_neu' → Alte: '$linie_alt'");
            } else {
                error_log("FAHRPLANPORTAL: ⚠️ Mapping-Zeile " . ($line_num + 1) . " ungültig: '$line'");
                error_log("FAHRPLANPORTAL: 🔍 Erwartet Format: 'neue_bezeichnung:alte_bezeichnung' (z.B. X2:SB2 oder 100:5000)");
            }
        }
        
        error_log("FAHRPLANPORTAL: ✅ " . count($mapping_array) . " Mapping-Einträge erfolgreich geladen");
        
        // Debug: Alle geladenen Mappings ausgeben
        if (!empty($mapping_array)) {
            error_log("FAHRPLANPORTAL: 📋 Geladene Mappings:");
            foreach ($mapping_array as $neu => $alt) {
                error_log("FAHRPLANPORTAL:    $neu → $alt");
            }
        }
        
        return $mapping_array;
    }
    
    /**
     * ✅ KORRIGIERT: Regionsnamen formatieren (Stadt- und Vororteverkehr + normale Regionen)
     */
    public function format_region_name($region_raw) {
        // Leer? Dann unverändert zurück
        if (empty($region_raw)) {
            return $region_raw;
        }
        
        // Schon formatiert (hat Großbuchstaben)? Dann unverändert
        if (preg_match('/[A-ZÄÖÜ]/', $region_raw)) {
            return $region_raw;
        }
        
        error_log("FAHRPLANPORTAL: Format Region Raw Input: '$region_raw'");
        
        // ✅ NEU: Spezielle Behandlung für Stadt- und Vororteverkehr
        if (preg_match('/^([a-z])-stadt-und-vororteverkehr-(.+)$/', $region_raw, $matches)) {
            $buchstabe = strtoupper($matches[1]);  // b → B, c → C, etc.
            $stadt_name = $matches[2];             // villach, spittal-a-d-drau, etc.
            
            error_log("FAHRPLANPORTAL: Stadt- und Vororteverkehr erkannt - Buchstabe: '$buchstabe', Stadt: '$stadt_name'");
            
            // ✅ Stadt-Name formatieren (mit Abkürzungen und Umlauten)
            $formatted_city = $this->format_city_name_with_abbreviations($stadt_name);
            
            $result = $buchstabe . ' Stadt- u. Vororteverkehr ' . $formatted_city;
            
            error_log("FAHRPLANPORTAL: Stadt- und Vororteverkehr formatiert: '$region_raw' → '$result'");
            return $result;
        }
        
        // ✅ NEU: Spezielle Behandlung für Nummern-Regionen (01-moelltal, 02-liesertal, etc.)
        if (preg_match('/^(\d{2})-(.+)$/', $region_raw, $matches)) {
            $nummer = $matches[1];           // 01, 02, etc.
            $region_name = $matches[2];      // moelltal, liesertal, etc.
            
            error_log("FAHRPLANPORTAL: Nummern-Region erkannt - Nummer: '$nummer', Name: '$region_name'");
            
            // ✅ Region-Name formatieren (mit Abkürzungen und Umlauten)
            $formatted_region = $this->format_city_name_with_abbreviations($region_name);
            
            $result = $nummer . ' ' . $formatted_region;
            
            error_log("FAHRPLANPORTAL: Nummern-Region formatiert: '$region_raw' → '$result'");
            return $result;
        }
        
        // ✅ FALLBACK: Normale Region-Formatierung (wie bisher)
        return $this->format_normal_region_name($region_raw);
    }

    /**
     * ✅ NEU: Stadt-Namen mit Abkürzungen formatieren (für Stadt- und Vororteverkehr)
     */
    private function format_city_name_with_abbreviations($city_name_raw) {
        // ✅ Spezielle Stadt-Abkürzungen behandeln
        $special_cities = array(
            'spittal-a-d-drau' => 'Spittal an der Drau',
            'villach-a-d-drau' => 'Villach an der Drau',
            'klagenfurt-a-w-see' => 'Klagenfurt am Wörthersee',
            'st-veit-a-d-glan' => 'St.Veit an der Glan',
            'wolfsberg-a-d-lavant' => 'Wolfsberg an der Lavant',
            'feldkirchen-a-d-drau' => 'Feldkirchen an der Drau',
            'st-georgen-ob-bleiburg' => 'St.Georgen ob Bleiburg',
            'st-michael-ob-bleiburg' => 'St.Michael ob Bleiburg'
        );
        
        // ✅ Direkte Zuordnung prüfen
        if (isset($special_cities[$city_name_raw])) {
            $result = $special_cities[$city_name_raw];
            error_log("FAHRPLANPORTAL: Spezielle Stadt-Zuordnung: '$city_name_raw' → '$result'");
            return $result;
        }
        
        // ✅ Normale Formatierung für andere Städte
        $city_parts = explode('-', $city_name_raw);
        
        // ✅ Array-basierte Abkürzungs-Verarbeitung
        $city_parts = $this->process_abbreviations($city_parts);
        
        // ✅ Umlaute konvertieren
        $formatted_parts = array();
        foreach ($city_parts as $part) {
            $part_with_umlauts = $this->convert_german_umlauts($part);
            
            // ✅ Falls noch nicht formatiert: Ersten Buchstaben groß
            if (!preg_match('/^(St\.|an der |ob der |am |bei |unter )/', $part_with_umlauts)) {
                $part_with_umlauts = $this->ucfirst_german($part_with_umlauts);
            }
            
            $formatted_parts[] = $part_with_umlauts;
        }
        
        $result = implode(' ', $formatted_parts);
        error_log("FAHRPLANPORTAL: Stadt-Name formatiert: '$city_name_raw' → '$result'");
        
        return $result;
    }

    /**
     * ✅ NEU: Normale Region-Formatierung (bisherige Logik)
     */
    private function format_normal_region_name($region_raw) {
        // ✅ Deutsche Umlaute konvertieren
        $region_with_umlauts = $this->convert_german_umlauts_in_region($region_raw);
        
        // Bindestriche durch Leerzeichen ersetzen
        $region_spaced = str_replace('-', ' ', $region_with_umlauts);
        
        // In Kleinbuchstaben und dann in Wörter aufteilen
        $words = explode(' ', strtolower(trim($region_spaced)));
        
        // Wörter die klein bleiben sollen
        $lowercase_words = array('an', 'der', 'am', 'von', 'im', 'auf', 'bei', 'zu', 'zur', 'ob');
        
        $formatted_words = array();
        
        foreach ($words as $index => $word) {
            $word = trim($word);
            
            if (empty($word)) {
                continue; // Leere Wörter überspringen
            }
            
            // Erstes Wort oder nicht in Ausnahmeliste: groß schreiben
            if ($index === 0 || !in_array($word, $lowercase_words)) {
                $formatted_words[] = $this->ucfirst_german($word);
            } else {
                // Ausnahmewort: klein lassen
                $formatted_words[] = $word;
            }
        }
        
        $result = implode(' ', $formatted_words);
        
        error_log("FAHRPLANPORTAL: Normale Region formatiert: '$region_raw' → '$result'");
        
        return $result;
    }
    
    /**
     * ✅ VEREINFACHT: Deutsche Umlaute für Regionsnamen konvertieren
     */
    private function convert_german_umlauts_in_region($text) {
        return $this->convert_german_umlauts($text);
    }
    
    /**
     * ✅ NEU: Deutsche Groß-/Kleinschreibung mit Umlauten
     */
    public function ucfirst_german($word) {
        // Deutsche Umlaute und Sonderzeichen berücksichtigen
        $word = trim($word);
        
        if (empty($word)) {
            return $word;
        }
        
        // Ersten Buchstaben groß, Rest klein
        return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . 
               mb_strtolower(mb_substr($word, 1, null, 'UTF-8'), 'UTF-8');
    }
    
    /**
     * ✅ ERWEITERT: Deutsche Umlaute konvertieren - SÜDKÄRNTEN FIX
     */
    public function convert_german_umlauts($text) {
        // Bestehende Ausnahmeliste...
        $exceptions = array(
            'auen', 'auenwald', 'auental', 'auendorf', 'auenbach',
            'michael', 'michaelerberg', 'michaelsberg', 'michaelbeuern',
        );
        
        $original_text = $text;
        $text_lower = mb_strtolower($text, 'UTF-8');
        
        // Ausnahmen-Prüfung
        if (in_array($text_lower, $exceptions)) {
            error_log("FAHRPLANPORTAL: Titel-Ausnahme gefunden für '$original_text' - keine Umlaut-Konvertierung");
            return $text;
        }
        
        // Spezielle Michael-Prüfung
        if (stripos($text_lower, 'michael') !== false) {
            error_log("FAHRPLANPORTAL: 'Michael' in Text '$original_text' erkannt - keine Konvertierung");
            return $text;
        }
        
        // ✅ GEZIELTE ÖSTERREICHISCHE KONVERTIERUNGEN - SÜDKÄRNTEN HINZUGEFÜGT
        $priority_conversions = array(
            'woerthersee' => 'wörthersee',
            'woerth' => 'wörth', 
            'moell' => 'möll',
            'oesterreich' => 'österreich',
            'kaernten' => 'kärnten',
            'voelkermarkt' => 'völkermarkt',
            'goeriach' => 'göriach',
            'pusarnitz' => 'pusarnitz',
            
            // ✅ NEU: SÜDKÄRNTEN FIX
            'suedkaernten' => 'südkärnten',
            'suedkärnten' => 'südkärnten',
            'suedoesterreich' => 'südösterreich',
            'suedtirol' => 'südtirol',
            'westkaernten' => 'westkärnten',
            'ostkaernten' => 'ostkärnten',
            'nordkaernten' => 'nordkärnten',
            
            // Bestehende Brücken-Konvertierungen...
            'bruecke' => 'brücke',
            'bruecken' => 'brücken',
            'moellbruecke' => 'möllbrücke',
            
            // Bestehende weitere Konvertierungen...
            'muehle' => 'mühle',
            'muehlen' => 'mühlen',
            'gruenberg' => 'grünberg',
            'gruendorf' => 'gründorf',
        );
        
        // Prioritäts-Konvertierungen durchführen (CASE-INSENSITIVE)
        foreach ($priority_conversions as $search => $replace) {
            $text = str_ireplace($search, $replace, $text);
        }
        
        // Überprüfung ob sich etwas geändert hat
        if ($text !== $original_text) {
            error_log("FAHRPLANPORTAL: Prioritäts-Konvertierung durchgeführt: '$original_text' → '$text'");
            return $text;
        }
        
        // Standard Umlaut-Konvertierung (wie bisher)
        $conversions = array(
            'ae' => 'ä', 'Ae' => 'Ä', 'AE' => 'Ä',
            'oe' => 'ö', 'Oe' => 'Ö', 'OE' => 'Ö',
            'ue' => 'ü', 'Ue' => 'Ü', 'UE' => 'Ü'
        );
        
        $converted_text = $text;
        foreach ($conversions as $search => $replace) {
            $test_conversion = str_replace($search, $replace, $converted_text);
            $test_lower = mb_strtolower($test_conversion, 'UTF-8');
            
            if (in_array($test_lower, $exceptions)) {
                error_log("FAHRPLANPORTAL: Konvertierung '$search' → '$replace' übersprungen für '$converted_text' (würde Ausnahme '$test_lower' erzeugen)");
                continue;
            }
            
            $converted_text = str_replace($search, $replace, $converted_text);
        }
        
        if ($converted_text !== $text) {
            error_log("FAHRPLANPORTAL: Standard Umlaut-Konvertierung durchgeführt: '$original_text' → '$converted_text'");
        }
        
        return $converted_text;
    }
    
    /**
     * ✅ BUG-FIX: Verarbeitet Abkürzungen schrittweise (Array-basiert)
     */
    public function process_abbreviations($orte_array) {
        $processed_orte = array();
        $i = 0;
        
        error_log("FAHRPLANPORTAL: Abkürzungs-Verarbeitung Start: " . implode(', ', $orte_array));
        
        while ($i < count($orte_array)) {
            $current = strtolower(trim($orte_array[$i]));
            
            // ✅ REGEL 1: "-st-" → "St." (ohne Leerzeichen zum nächsten Wort)
            if ($current === 'st' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // ✅ GEFIXT: Korrekte Großschreibung mit ucfirst_german()
                $combined = 'St.' . $this->ucfirst_german($next_ort);
                $processed_orte[] = $combined;
                $i += 2; // Überspringe beide Teile
                error_log("FAHRPLANPORTAL: St-Abkürzung: 'st + $next_ort' → '$combined'");
            }
            // ✅ REGEL 2: "-a-d-" → "an der" (mit Leerzeichen)
            elseif ($current === 'a' && isset($orte_array[$i + 1]) && isset($orte_array[$i + 2])) {
                $second = strtolower(trim($orte_array[$i + 1]));
                $third = trim($orte_array[$i + 2]);
                
                if ($second === 'd') {
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = 'an der ' . $this->ucfirst_german($third);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile
                    error_log("FAHRPLANPORTAL: A-D-Abkürzung: 'a + d + $third' → '$combined'");
                } else {
                    // Kein "a-d-" Muster, normal verarbeiten
                    $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                    $i += 1;
                }
            }
            // ✅ BUG-FIX: REGEL 2.5: "-o-d-" → "ob der" NUR wenn wirklich 3 Teile vorhanden
            elseif ($current === 'o' && isset($orte_array[$i + 1]) && isset($orte_array[$i + 2])) {
                $second = strtolower(trim($orte_array[$i + 1]));
                $third = trim($orte_array[$i + 2]);
                
                if ($second === 'd') {
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = 'ob der ' . $this->ucfirst_german($third);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile
                    error_log("FAHRPLANPORTAL: O-D-Abkürzung: 'o + d + $third' → '$combined'");
                } else {
                    // Kein "o-d-" Muster, normal verarbeiten
                    $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                    $i += 1;
                }
            }
            // ✅ REGEL 3: "-am-" → " am " (Präposition zwischen Orten)
            elseif ($current === 'am' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // Prüfen ob vorheriger Ort existiert um ihn zu erweitern
                if (!empty($processed_orte)) {
                    $last_index = count($processed_orte) - 1;
                    $last_ort = $processed_orte[$last_index];
                    
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = $last_ort . ' am ' . $this->ucfirst_german($next_ort);
                    $processed_orte[$last_index] = $combined;
                    $i += 2; // Überspringe beide Teile
                    error_log("FAHRPLANPORTAL: Am-Erweiterung: '$last_ort + am + $next_ort' → '$combined'");
                } else {
                    // Kein vorheriger Ort, normal verarbeiten
                    $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                    $i += 1;
                }
            }
            // Weitere Regeln...
            else {
                // ✅ GEFIXT: Verwende ucfirst_german() statt ucfirst()
                $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                $i += 1;
            }
        }
        
        error_log("FAHRPLANPORTAL: Abkürzungs-Verarbeitung Ergebnis: " . implode(' | ', $processed_orte));
        return $processed_orte;
    }
    
    /**
     * ✅ HILFSMETHODE: Datum in deutsches Format umwandeln (für Admin-Interface)
     */
    public function format_german_date($date) {
        if (empty($date) || $date === '0000-00-00') {
            return '';
        }
        
        $timestamp = strtotime($date);
        if ($timestamp) {
            return date('d.m.Y', $timestamp);
        }
        
        return $date;
    }
    
    /**
     * Tags für Anzeige formatieren - Nur wenn PDF-Parsing aktiv
     */
    public function format_tags_for_display($tags, $pdf_parsing_enabled) {
        if (!$pdf_parsing_enabled || empty($tags)) {
            return '<span class="no-tags">Keine Tags</span>';
        }
        
        // Tags sind als kommagetrennte Liste gespeichert
        $tag_array = explode(',', $tags);
        $tag_array = array_map('trim', $tag_array);
        $tag_array = array_filter($tag_array); // Leere entfernen
        
        if (empty($tag_array)) {
            return '<span class="no-tags">Keine Tags</span>';
        }
        
        // EINFACH: Nur die kommagetrennte Liste zurückgeben
        return '<span class="simple-tags">' . esc_html(implode(', ', $tag_array)) . '</span>';
    }
    
    /**
     * PDF-URL generieren
     */
    public function get_pdf_url($pdf_pfad) {
        return site_url('fahrplaene/' . $pdf_pfad);
    }
    
    /**
     * ✅ HILFSMETHODE: Verarbeitungszeit schätzen
     */
    public function estimate_processing_time($total_files, $pdf_parsing_enabled) {
        // Basis-Zeit pro Datei
        $base_time_per_file = 0.2; // 200ms pro Datei ohne PDF-Parsing
        
        if ($pdf_parsing_enabled) {
            $base_time_per_file = 0.8; // 800ms pro Datei mit PDF-Parsing
        }
        
        $total_seconds = $total_files * $base_time_per_file;
        
        // Auf 5-Sekunden-Schritte runden
        $rounded_seconds = ceil($total_seconds / 5) * 5;
        
        return array(
            'seconds' => $rounded_seconds,
            'formatted' => $this->format_duration($rounded_seconds)
        );
    }
    
    /**
     * ✅ HILFSMETHODE: Dauer formatieren
     */
    public function format_duration($seconds) {
        if ($seconds < 60) {
            return $seconds . ' Sekunden';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remaining_seconds = $seconds % 60;
            return $minutes . ' Min' . ($remaining_seconds > 0 ? ' ' . $remaining_seconds . ' Sek' : '');
        } else {
            $hours = floor($seconds / 3600);
            $remaining_minutes = floor(($seconds % 3600) / 60);
            return $hours . ' Std' . ($remaining_minutes > 0 ? ' ' . $remaining_minutes . ' Min' : '');
        }
    }
}