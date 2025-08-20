<?php
/**
 * FahrplanPortal Utils Class
 * Helper-Funktionen, Formatierungen und Konvertierungen
 * 
 * ✅ AKTUALISIERT: Flexibles Mapping-System für alle Formate
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
     * ✅ KOMPLETT NEU: Flexibles Linien-Mapping für alle Formate
     * 
     * Unterstützt jetzt:
     * - Reine Zahlen: 100:5108
     * - Buchstaben-Zahlen: X1:SB3 (5912)
     * - Text mit Leerzeichen: 1:Lin 1
     * - Zahlen mit Suffix: 122:8101 GV
     * - Mehrere Zahlen: 180:5140/5144
     * - Spezialwerte: 106:keine
     * - Komplexe Formate: X4:SB4 (8574/8575)
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
            
            // ✅ NEUES FLEXIBLES PATTERN: Akzeptiert ALLES vor und nach dem Doppelpunkt
            // Bedingung: Es muss genau EIN Doppelpunkt vorhanden sein
            $colon_count = substr_count($line, ':');
            
            if ($colon_count !== 1) {
                error_log("FAHRPLANPORTAL: ⚠️ Zeile $line_num hat $colon_count Doppelpunkte (erwartet: 1): '$line'");
                continue;
            }
            
            // Teile am Doppelpunkt
            $parts = explode(':', $line, 2);
            
            if (count($parts) === 2) {
                $linie_neu = trim($parts[0]);
                $linie_alt = trim($parts[1]);
                
                // Validierung: Beide Teile müssen nicht-leer sein
                if (empty($linie_neu) || empty($linie_alt)) {
                    error_log("FAHRPLANPORTAL: ⚠️ Zeile $line_num hat leere Teile: neu='$linie_neu', alt='$linie_alt'");
                    continue;
                }
                
                // Normalisierung für besseres Matching
                $linie_neu_normalized = $this->normalize_line_key($linie_neu);
                
                // Haupteintrag mit normalisiertem Key
                $mapping_array[$linie_neu_normalized] = $linie_alt;
                
                // Zusätzlich Original-Key speichern falls unterschiedlich
                if ($linie_neu_normalized !== $linie_neu) {
                    $mapping_array[$linie_neu] = $linie_alt;
                }
                
                // Für Buchstaben-Zahlen-Kombinationen auch lowercase Version
                if (preg_match('/^[A-Z]\d+$/i', $linie_neu)) {
                    $linie_neu_lower = strtolower($linie_neu);
                    $mapping_array[$linie_neu_lower] = $linie_alt;
                }
                
                error_log("FAHRPLANPORTAL: ✅ Mapping geladen (Zeile " . ($line_num + 1) . "): '$linie_neu' → '$linie_alt'");
                
            } else {
                error_log("FAHRPLANPORTAL: ⚠️ Zeile $line_num konnte nicht geparst werden: '$line'");
            }
        }
        
        error_log("FAHRPLANPORTAL: ✅ " . count($mapping_array) . " Mapping-Einträge erfolgreich geladen");
        
        // Debug: Spezielle Einträge anzeigen
        $special_entries = array();
        foreach ($mapping_array as $neu => $alt) {
            if (strpos($alt, 'keine') !== false || 
                strpos($alt, '/') !== false || 
                strpos($alt, '(') !== false ||
                strpos($alt, ' ') !== false) {
                $special_entries[] = "$neu → $alt";
            }
        }
        
        if (!empty($special_entries)) {
            error_log("FAHRPLANPORTAL: 📋 Spezielle Mapping-Einträge:");
            foreach (array_slice($special_entries, 0, 10) as $entry) {
                error_log("FAHRPLANPORTAL:    $entry");
            }
        }
        
        return $mapping_array;
    }
    
    /**
     * ✅ NEU: Helper-Funktion für Key-Normalisierung
     * 
     * @param string $key Der zu normalisierende Schlüssel
     * @return string Der normalisierte Schlüssel
     */
    private function normalize_line_key($key) {
        // Entferne führende/nachfolgende Leerzeichen
        $key = trim($key);
        
        // Für Buchstaben-Zahlen-Kombinationen: Großbuchstaben
        if (preg_match('/^[A-Z]\d+$/i', $key)) {
            return strtoupper($key);
        }
        
        // Numerische Keys bleiben unverändert
        if (is_numeric($key)) {
            return $key;
        }
        
        // Alle anderen bleiben wie sie sind
        return $key;
    }
    
    /**
     * ✅ ERWEITERT: Intelligentes Mapping-Lookup mit Fallbacks
     * 
     * @param string $key Der zu suchende Schlüssel
     * @param array $mapping_array Das Mapping-Array
     * @return string|null Der gemappte Wert oder null
     */
    public function lookup_mapping($key, $mapping_array) {
        // 1. Direkte Suche
        if (isset($mapping_array[$key])) {
            error_log("FAHRPLANPORTAL: Mapping gefunden (direkt): '$key' → '" . $mapping_array[$key] . "'");
            return $mapping_array[$key];
        }
        
        // 2. Normalisierte Suche
        $normalized_key = $this->normalize_line_key($key);
        if ($normalized_key !== $key && isset($mapping_array[$normalized_key])) {
            error_log("FAHRPLANPORTAL: Mapping gefunden (normalisiert): '$key' → '" . $mapping_array[$normalized_key] . "'");
            return $mapping_array[$normalized_key];
        }
        
        // 3. Case-insensitive Suche
        $key_upper = strtoupper($key);
        if (isset($mapping_array[$key_upper])) {
            error_log("FAHRPLANPORTAL: Mapping gefunden (uppercase): '$key' → '" . $mapping_array[$key_upper] . "'");
            return $mapping_array[$key_upper];
        }
        
        $key_lower = strtolower($key);
        if (isset($mapping_array[$key_lower])) {
            error_log("FAHRPLANPORTAL: Mapping gefunden (lowercase): '$key' → '" . $mapping_array[$key_lower] . "'");
            return $mapping_array[$key_lower];
        }
        
        // 4. Vollständige Case-insensitive Iteration (letzter Versuch)
        foreach ($mapping_array as $map_key => $map_value) {
            if (strcasecmp($map_key, $key) === 0) {
                error_log("FAHRPLANPORTAL: Mapping gefunden (case-insensitive): '$key' → '$map_value'");
                return $map_value;
            }
        }
        
        // Kein Mapping gefunden
        error_log("FAHRPLANPORTAL: ❌ Kein Mapping gefunden für: '$key'");
        return null;
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
                    error_log("FAHRPLANPORTAL: a-d-Abkürzung: 'a + d + $third' → '$combined'");
                } else {
                    $processed_orte[] = trim($orte_array[$i]);
                    $i++;
                }
            }
            // ✅ REGEL 3: "-a-w-" → "am" (mit Leerzeichen) - für "am Wörthersee"
            elseif ($current === 'a' && isset($orte_array[$i + 1]) && isset($orte_array[$i + 2])) {
                $second = strtolower(trim($orte_array[$i + 1]));
                $third = trim($orte_array[$i + 2]);
                
                if ($second === 'w') {
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = 'am ' . $this->ucfirst_german($third);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile
                    error_log("FAHRPLANPORTAL: a-w-Abkürzung: 'a + w + $third' → '$combined'");
                } else {
                    $processed_orte[] = trim($orte_array[$i]);
                    $i++;
                }
            }
            // ✅ REGEL 4: "-ob-" → "ob" (mit Leerzeichen)
            elseif ($current === 'ob' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // ✅ GEFIXT: Korrekte Großschreibung
                $combined = 'ob ' . $this->ucfirst_german($next_ort);
                $processed_orte[] = $combined;
                $i += 2; // Überspringe beide Teile
                error_log("FAHRPLANPORTAL: ob-Abkürzung: 'ob + $next_ort' → '$combined'");
            }
            // ✅ REGEL 5: "-ob-der-" → "ob der" (mit Leerzeichen)
            elseif ($current === 'ob' && isset($orte_array[$i + 1]) && isset($orte_array[$i + 2])) {
                $second = strtolower(trim($orte_array[$i + 1]));
                $third = trim($orte_array[$i + 2]);
                
                if ($second === 'der') {
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = 'ob der ' . $this->ucfirst_german($third);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile
                    error_log("FAHRPLANPORTAL: ob-der-Abkürzung: 'ob + der + $third' → '$combined'");
                } else {
                    $processed_orte[] = trim($orte_array[$i]);
                    $i++;
                }
            }
            // ✅ REGEL 6: "-bei-" → "bei" (mit Leerzeichen)
            elseif ($current === 'bei' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // ✅ GEFIXT: Korrekte Großschreibung
                $combined = 'bei ' . $this->ucfirst_german($next_ort);
                $processed_orte[] = $combined;
                $i += 2; // Überspringe beide Teile
                error_log("FAHRPLANPORTAL: bei-Abkürzung: 'bei + $next_ort' → '$combined'");
            }
            // ✅ REGEL 7: "-unter-" → "unter" (mit Leerzeichen)
            elseif ($current === 'unter' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // ✅ GEFIXT: Korrekte Großschreibung
                $combined = 'unter ' . $this->ucfirst_german($next_ort);
                $processed_orte[] = $combined;
                $i += 2; // Überspringe beide Teile
                error_log("FAHRPLANPORTAL: unter-Abkürzung: 'unter + $next_ort' → '$combined'");
            }
            // ✅ Keine Regel trifft zu
            else {
                $processed_orte[] = trim($orte_array[$i]);
                $i++;
            }
        }
        
        error_log("FAHRPLANPORTAL: Abkürzungs-Verarbeitung Ende: " . implode(', ', $processed_orte));
        
        return $processed_orte;
    }
    
    /**
     * Hilfsfunktionen für verschiedene Aufgaben
     */
    public function get_pdf_url($pdf_pfad) {
        return home_url('/fahrplaene/' . $pdf_pfad);
    }
    
    public function format_german_date($date) {
        if (empty($date) || $date === '0000-00-00') {
            return '';
        }
        
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }
        
        return date('d.m.Y', $timestamp);
    }
    
    public function format_tags_for_display($tags, $pdf_parsing_enabled = false) {
        if (!$pdf_parsing_enabled || empty($tags)) {
            return '';
        }
        
        $tags_array = explode(',', $tags);
        $tags_array = array_map('trim', $tags_array);
        $tags_array = array_slice($tags_array, 0, 5); // Zeige max 5 Tags
        
        return implode(', ', $tags_array) . (count(explode(',', $tags)) > 5 ? '...' : '');
    }
}