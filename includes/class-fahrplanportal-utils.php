<?php
/**
 * FahrplanPortal Utils Class
 * Helper-Funktionen, Formatierungen und Konvertierungen
 * 
 * ✅ AKTUALISIERT: Flexibles Mapping-System für alle Formate
 * ✅ NEU: Intelligentes Route-Splitting für deutsche Ortsnamen
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Utils {
    
    /**
     * ✅ NEU: Intelligentes Route-Splitting für deutsche Ortsnamen
     * Erkennt zusammengehörige Ortsnamen-Muster und splittet korrekt
     * 
     * @param string $route Die zu splittende Route (z.B. "st-michael-ob-der-gurk-tainach-voelkermarkt")
     * @return array Array mit korrekt gesplitteten Ortsteilen
     */
    public function smart_split_route($route) {
            error_log("FAHRPLANPORTAL: ============= SMART ROUTE SPLITTING =============");
            error_log("FAHRPLANPORTAL: Input Route: '$route'");
            
            $result = array();
            $route_lower = strtolower($route);
            
            // WICHTIG: Patterns nach LÄNGE sortiert (längste zuerst!)
            $compound_patterns = array(
                // LÄNGSTE Muster zuerst (5 Teile)
                'st-michael-ob-der-gurk' => '§ST_MICHAEL_OB_DER_GURK§',
                'feistritz-an-der-drau' => '§FEISTRITZ_AN_DER_DRAU§',
                'feistritz-an-der-gail' => '§FEISTRITZ_AN_DER_GAIL§',
                'st-veit-an-der-glan' => '§ST_VEIT_AN_DER_GLAN§',
                'spittal-an-der-drau' => '§SPITTAL_AN_DER_DRAU§',
                
                // 4-teilige Muster
                'st-georgen-ob-bleiburg' => '§ST_GEORGEN_OB_BLEIBURG§',
                'st-michael-ob-bleiburg' => '§ST_MICHAEL_OB_BLEIBURG§',
                'st-georgen-ob-murau' => '§ST_GEORGEN_OB_MURAU§',
                'st-paul-im-lavanttal' => '§ST_PAUL_IM_LAVANTTAL§',
                
                // 3-teilige Muster  
                'lind-ob-velden' => '§LIND_OB_VELDEN§',
                'stein-im-jauntal' => '§STEIN_IM_JAUNTAL§',
                'an-der-glan' => '§AN_DER_GLAN§',
                'an-der-drau' => '§AN_DER_DRAU§',
                'ob-der-gurk' => '§OB_DER_GURK§',
                'im-jauntal' => '§IM_JAUNTAL§',
                
                // 2-teilige Muster (MÜSSEN NACH den längeren kommen!)
                'maria-saal' => '§MARIA_SAAL§',
                'st-michael' => '§ST_MICHAEL§',
                'st-veit' => '§ST_VEIT§',
                'st-georgen' => '§ST_GEORGEN§',
                'st-kanzian' => '§ST_KANZIAN§',
                'st-donat' => '§ST_DONAT§',
                'st-paul' => '§ST_PAUL§',
                'st-andrä' => '§ST_ANDRA§',
                'st-jakob' => '§ST_JAKOB§',
                'st-stefan' => '§ST_STEFAN§',
                'st-marein' => '§ST_MAREIN§',
            );
            
            // Schritt 1: Ersetze Muster SEQUENZIELL (nicht alle auf einmal!)
            $processed_route = $route_lower;
            $replacements = array();
            
            foreach ($compound_patterns as $pattern => $placeholder) {
                if (strpos($processed_route, $pattern) !== false) {
                    // Speichere was ersetzt wurde
                    $replacements[$placeholder] = $pattern;
                    // Ersetze NUR EINMAL pro Durchlauf
                    $processed_route = str_replace($pattern, $placeholder, $processed_route);
                    error_log("FAHRPLANPORTAL: Muster ersetzt: '$pattern' → '$placeholder'");
                    error_log("FAHRPLANPORTAL: Zwischenstand: '$processed_route'");
                }
            }
            
            error_log("FAHRPLANPORTAL: Nach Muster-Ersetzung: '$processed_route'");
            
            // Schritt 2: Splitte am Bindestrich
            $parts = explode('-', $processed_route);
            
            // 🔍 DEBUG: Was kommt aus explode() raus?
            error_log("FAHRPLANPORTAL: DEBUG - Parts nach explode(): [" . implode(', ', $parts) . "]");
            error_log("FAHRPLANPORTAL: DEBUG - Replacements Array:");
            foreach ($replacements as $placeholder => $original) {
                error_log("FAHRPLANPORTAL: DEBUG - '$placeholder' => '$original'");
            }
            
            // Schritt 3: Verarbeite jeden Teil
            foreach ($parts as $part) {
                $part = trim($part);
                
                if (empty($part)) {
                    continue;
                }
                
                error_log("FAHRPLANPORTAL: DEBUG - Prüfe Teil: '$part'");
                
                // Prüfe ob es ein Platzhalter ist
                if (strpos($part, '§') !== false && isset($replacements[$part])) {
                    error_log("FAHRPLANPORTAL: DEBUG - Platzhalter erkannt und gefunden in replacements!");
                    $original = $replacements[$part];
                    
                    // Zerlege den Original-String in seine Bestandteile
                    $sub_parts = explode('-', $original);
                    foreach ($sub_parts as $sub) {
                        if (!empty($sub)) {
                            $result[] = $sub;
                        }
                    }
                } else {
                    error_log("FAHRPLANPORTAL: DEBUG - Kein Platzhalter oder nicht in replacements gefunden");
                    // Normaler Teil ohne Platzhalter
                    $result[] = $part;
                }
            }
            
            error_log("FAHRPLANPORTAL: Smart Split Ergebnis: [" . implode(', ', $result) . "]");
            error_log("FAHRPLANPORTAL: ===========================================");
            
            return $result;
        }
    
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
            'michael', 'michaelerberg', 'michaelsberg', 'michaelbeuern', 'steuerberg',
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
     * ✅ KOMPLETT UMSTRUKTURIERT: process_abbreviations mit absoluter Priorität für "ob/bei" Muster
     * ✅ KRITISCHER FIX: "[Ort] ob [Ort]" und "[Ort] bei [Ort]" haben ABSOLUTE PRIORITÄT
     */
    public function process_abbreviations($orte_array) {
        $processed_orte = array();
        $i = 0;
        
        error_log("FAHRPLANPORTAL: Abkürzungs-Verarbeitung Start: " . implode(', ', $orte_array));
        
        while ($i < count($orte_array)) {
            $current = strtolower(trim($orte_array[$i]));
            
            // =================================================================
            // ABSOLUTE PRIORITÄT: "ob" und "bei" Muster MÜSSEN ZUERST KOMMEN!
            // =================================================================
            
            // ✅ HÖCHSTE PRIORITÄT: "[Ort] ob [Ort]" (z.B. lind, ob, velden)
            if (isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'ob' &&
                isset($orte_array[$i + 2])) {
                
                $ort1 = $this->ucfirst_german($current);
                $ort2 = $this->ucfirst_german(trim($orte_array[$i + 2]));
                $processed_orte[] = $ort1 . ' ob ' . $ort2;
                $i += 3;
                error_log("FAHRPLANPORTAL: ABSOLUTE PRIORITÄT - $ort1 ob $ort2 als Einheit erkannt");
                continue;
            }
            
            // ✅ HÖCHSTE PRIORITÄT: "[Ort] bei [Ort]" (z.B. edling, bei, mittlern)
            if (isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'bei' &&
                isset($orte_array[$i + 2])) {
                
                $ort1 = $this->ucfirst_german($current);
                $ort2 = $this->ucfirst_german(trim($orte_array[$i + 2]));
                $processed_orte[] = $ort1 . ' bei ' . $ort2;
                $i += 3;
                error_log("FAHRPLANPORTAL: ABSOLUTE PRIORITÄT - $ort1 bei $ort2 als Einheit erkannt");
                continue;
            }
            
            // =================================================================
            // ZWEITE PRIORITÄT: 5-TEILIGE ST.-MUSTER
            // =================================================================
            
            // ✅ St.[Stadt] an der [Fluss] (z.B. st, veit, an, der, glan)
            if ($current === 'st' && isset($orte_array[$i + 1]) &&
                isset($orte_array[$i + 2]) && strtolower(trim($orte_array[$i + 2])) === 'an' &&
                isset($orte_array[$i + 3]) && strtolower(trim($orte_array[$i + 3])) === 'der' &&
                isset($orte_array[$i + 4])) {
                
                $stadtname = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $fluss = $this->ucfirst_german(trim($orte_array[$i + 4]));
                $processed_orte[] = 'St.' . $stadtname . ' an der ' . $fluss;
                $i += 5;
                error_log("FAHRPLANPORTAL: St.$stadtname an der $fluss als 5-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ St.[Stadt] ob der [Ort] (z.B. st, michael, ob, der, gurk)
            if ($current === 'st' && isset($orte_array[$i + 1]) &&
                isset($orte_array[$i + 2]) && strtolower(trim($orte_array[$i + 2])) === 'ob' &&
                isset($orte_array[$i + 3]) && strtolower(trim($orte_array[$i + 3])) === 'der' &&
                isset($orte_array[$i + 4])) {
                
                $stadtname = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $ort = $this->ucfirst_german(trim($orte_array[$i + 4]));
                $processed_orte[] = 'St.' . $stadtname . ' ob der ' . $ort;
                $i += 5;
                error_log("FAHRPLANPORTAL: St.$stadtname ob der $ort als 5-teilige Einheit erkannt");
                continue;
            }
            
            // =================================================================
            // DRITTE PRIORITÄT: 4-TEILIGE ST.-MUSTER
            // =================================================================
            
            // ✅ St.[Name] im [Tal] (z.B. st, andrä, im, lavanttal)
            if ($current === 'st' && isset($orte_array[$i + 1]) &&
                isset($orte_array[$i + 2]) && strtolower(trim($orte_array[$i + 2])) === 'im' &&
                isset($orte_array[$i + 3])) {
                
                $name = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $tal = $this->ucfirst_german(trim($orte_array[$i + 3]));
                $processed_orte[] = 'St.' . $name . ' im ' . $tal;
                $i += 4;
                error_log("FAHRPLANPORTAL: St.$name im $tal als 4-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ St.[Name] am [Ort] (z.B. st, margarethen, am, töllerberg)
            if ($current === 'st' && isset($orte_array[$i + 1]) &&
                isset($orte_array[$i + 2]) && strtolower(trim($orte_array[$i + 2])) === 'am' &&
                isset($orte_array[$i + 3])) {
                
                $name = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $ort = $this->ucfirst_german(trim($orte_array[$i + 3]));
                $processed_orte[] = 'St.' . $name . ' am ' . $ort;
                $i += 4;
                error_log("FAHRPLANPORTAL: St.$name am $ort als 4-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ St.[Name] ob [Ort] (z.B. st, michael, ob, bleiburg)
            if ($current === 'st' && isset($orte_array[$i + 1]) &&
                isset($orte_array[$i + 2]) && strtolower(trim($orte_array[$i + 2])) === 'ob' &&
                isset($orte_array[$i + 3])) {
                
                $name = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $ort = $this->ucfirst_german(trim($orte_array[$i + 3]));
                $processed_orte[] = 'St.' . $name . ' ob ' . $ort;
                $i += 4;
                error_log("FAHRPLANPORTAL: St.$name ob $ort als 4-teilige Einheit erkannt");
                continue;
            }
            
            // =================================================================
            // VIERTE PRIORITÄT: 4-TEILIGE GENERISCHE MUSTER
            // =================================================================
            
            // ✅ GENERISCHE REGEL für ALLE Städte mit "an der [fluss]"
            if (isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'an' &&
                isset($orte_array[$i + 2]) && strtolower(trim($orte_array[$i + 2])) === 'der' &&
                isset($orte_array[$i + 3])) {
                
                $stadtname = $this->ucfirst_german($current);
                $fluss = $this->ucfirst_german(trim($orte_array[$i + 3]));
                $processed_orte[] = $stadtname . ' an der ' . $fluss;
                $i += 4;
                error_log("FAHRPLANPORTAL: $stadtname an der $fluss als 4-teilige Einheit erkannt (generisch)");
                continue;
            }
            
            // =================================================================
            // FÜNFTE PRIORITÄT: 3-TEILIGE MUSTER
            // =================================================================
            
            // ✅ "klein" + "st" + Name = "Klein St.Name"
            if ($current === 'klein' && 
                isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'st' &&
                isset($orte_array[$i + 2])) {
                
                $name = $this->ucfirst_german(trim($orte_array[$i + 2]));
                $processed_orte[] = 'Klein St.' . $name;
                $i += 3;
                error_log("FAHRPLANPORTAL: Klein St.$name als 3-teilige Einheit erkannt");
                continue;
            }

            // ✅ "groß" + "st" + Name
            if ($current === 'groß' && 
                isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'st' &&
                isset($orte_array[$i + 2])) {
                
                $name = $this->ucfirst_german(trim($orte_array[$i + 2]));
                $processed_orte[] = 'Groß St.' . $name;
                $i += 3;
                error_log("FAHRPLANPORTAL: Groß St.$name als 3-teilige Einheit erkannt");
                continue;
            }

            // ✅ "alt" + "st" + Name
            if ($current === 'alt' && 
                isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'st' &&
                isset($orte_array[$i + 2])) {
                
                $name = $this->ucfirst_german(trim($orte_array[$i + 2]));
                $processed_orte[] = 'Alt St.' . $name;
                $i += 3;
                error_log("FAHRPLANPORTAL: Alt St.$name als 3-teilige Einheit erkannt");
                continue;
            }

            // ✅ "neu" + "st" + Name
            if ($current === 'neu' && 
                isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'st' &&
                isset($orte_array[$i + 2])) {
                
                $name = $this->ucfirst_german(trim($orte_array[$i + 2]));
                $processed_orte[] = 'Neu St.' . $name;
                $i += 3;
                error_log("FAHRPLANPORTAL: Neu St.$name als 3-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ "ob der" Regel (nur wenn nicht von St.-Regeln erfasst)
            if ($current === 'ob' && 
                isset($orte_array[$i + 1]) && strtolower(trim($orte_array[$i + 1])) === 'der' &&
                isset($orte_array[$i + 2])) {
                
                $place = $this->ucfirst_german(trim($orte_array[$i + 2]));
                $processed_orte[] = 'ob der ' . $place;
                $i += 3;
                error_log("FAHRPLANPORTAL: ob der $place als 3-teilige Einheit erkannt");
                continue;
            }
            
            // =================================================================
            // SECHSTE PRIORITÄT: 2-TEILIGE MUSTER
            // =================================================================
            
            // ✅ Maria + beliebiger Ortsname
            if ($current === 'maria' && isset($orte_array[$i + 1])) {
                $next_ort = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $processed_orte[] = 'Maria ' . $next_ort;
                $i += 2;
                error_log("FAHRPLANPORTAL: Maria $next_ort als 2-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ Bad + Ortsname
            if ($current === 'bad' && isset($orte_array[$i + 1])) {
                $next_ort = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $processed_orte[] = 'Bad ' . $next_ort;
                $i += 2;
                error_log("FAHRPLANPORTAL: Bad $next_ort als 2-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ Firma + Firmenname
            if ($current === 'firma' && isset($orte_array[$i + 1])) {
                $firmenname = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $processed_orte[] = 'Firma ' . $firmenname;
                $i += 2;
                error_log("FAHRPLANPORTAL: Firma $firmenname als 2-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ Standard "st" Verarbeitung (für einfache St.-Namen)
            if ($current === 'st' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                $combined = 'St.' . $this->ucfirst_german($next_ort);
                $processed_orte[] = $combined;
                $i += 2;
                error_log("FAHRPLANPORTAL: St.$next_ort als 2-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ "im" Regel
            if ($current === 'im' && isset($orte_array[$i + 1])) {
                $next_ort = $this->ucfirst_german(trim($orte_array[$i + 1]));
                $processed_orte[] = 'im ' . $next_ort;
                $i += 2;
                error_log("FAHRPLANPORTAL: im $next_ort als 2-teilige Einheit erkannt");
                continue;
            }
            
            // ✅ "bahnhof" Regel
            if ($current === 'bahnhof') {
                $processed_orte[] = 'Bahnhof';
                $i++;
                error_log("FAHRPLANPORTAL: Bahnhof als Einzelwort erkannt");
                continue;
            }
            
            // =================================================================
            // LETZTE PRIORITÄT: STANDARD-FALL
            // =================================================================
            
            // Standard-Fall: Einzelner Ortsteil
            $processed_orte[] = $this->ucfirst_german(trim($orte_array[$i]));
            $i++;
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
    
    /**
     * ✅ KORRIGIERT: Zeigt ALLE Tags vollständig an
     */
    public function format_tags_for_display($tags, $pdf_parsing_enabled = false) {
        if (!$pdf_parsing_enabled || empty($tags)) {
            return '';
        }
        
        // Tags in Array umwandeln und bereinigen
        $tags_array = explode(',', $tags);
        $tags_array = array_map('trim', $tags_array);
        $tags_array = array_filter($tags_array); // Leere Einträge entfernen
        
        // Alle Tags mit Komma getrennt zurückgeben
        return implode(', ', $tags_array);
    }
}