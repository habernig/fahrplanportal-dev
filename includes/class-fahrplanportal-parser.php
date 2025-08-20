<?php
/**
 * FahrplanPortal Parser Class
 * PDF-Parsing, Dateiname-Parsing und Tag-Extraktion
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Parser {
    
    private $pdf_base_path;
    private $pdf_parsing_enabled;
    private $utils;
    
    public function __construct($pdf_base_path, $pdf_parsing_enabled, $utils) {
        $this->pdf_base_path = $pdf_base_path;
        $this->pdf_parsing_enabled = $pdf_parsing_enabled;
        $this->utils = $utils;
    }
    
    /**
     * ✅ HILFSMETHODE: Alle Scan-Dateien sammeln
     */
    public function collect_all_scan_files($base_scan_path, $folder) {
        $all_files = array();
        
        // Direkte PDFs im Hauptordner
        $direct_pdfs = glob($base_scan_path . '*.pdf');
        foreach ($direct_pdfs as $file) {
            $all_files[] = array(
                'full_path' => $file,
                'filename' => basename($file),
                'folder' => $folder,
                'region' => ''
            );
        }
        
        // Regionen-Unterordner
        $region_dirs = glob($base_scan_path . '*', GLOB_ONLYDIR);
        foreach ($region_dirs as $region_dir) {
            $region_name = basename($region_dir);
            
            // Versteckte Ordner überspringen
            if (substr($region_name, 0, 1) === '.') {
                continue;
            }
            
            $region_pdfs = glob($region_dir . '/*.pdf');
            foreach ($region_pdfs as $file) {
                $all_files[] = array(
                    'full_path' => $file,
                    'filename' => basename($file),
                    'folder' => $folder,
                    'region' => $region_name
                );
            }
        }
        
        return $all_files;
    }
    
    /**
     * ✅ HILFSMETHODE: Einzelne PDF-Datei verarbeiten
     * ✅ GEÄNDERT: Gültigkeitsdaten aus Ordnernamen ableiten (14.12. Vorjahr bis 13.12. aktuelles Jahr)
     * ✅ NEUE NUMMERNLOGIK: 2-3 stellige Nummern als neue Hauptnummern
     */
    public function process_single_pdf_file($file_info) {
        $filename = $file_info['filename'];
        $folder = $file_info['folder'];
        $region = $file_info['region'];
        
        // Dateiname parsen
        $parsed = $this->parse_filename($filename);
        
        if (!$parsed) {
            throw new Exception("Dateiname-Parsing fehlgeschlagen für: " . $filename);
        }
        
        // ✅ NEU: Gültigkeitsdaten aus Ordnernamen ableiten
        // Fahrplanwechsel erfolgt am 14. Dezember, nicht zum Kalenderjahr
        // Jahr X gilt vom 14.12.(X-1) bis 13.12.X
        if (preg_match('/^(\d{4})/', $folder, $matches)) {
            $jahr = intval($matches[1]);  // Extrahiert z.B. "2026" aus "2026-dev"
            $vorjahr = $jahr - 1;
            
            // Gültig vom 14. Dezember des Vorjahres
            $parsed['gueltig_von'] = $vorjahr . '-12-14';
            // Gültig bis 13. Dezember des aktuellen Jahres
            $parsed['gueltig_bis'] = $jahr . '-12-13';
            
            error_log("FAHRPLANPORTAL: Gültigkeitsdaten aus Ordner '$folder' abgeleitet: {$vorjahr}-12-14 bis {$jahr}-12-13");
        } else {
            // Fallback: Aktuelles Jahr verwenden mit gleicher Logik
            $jahr = intval(date('Y'));
            $vorjahr = $jahr - 1;
            
            $parsed['gueltig_von'] = $vorjahr . '-12-14';
            $parsed['gueltig_bis'] = $jahr . '-12-13';
            
            error_log("FAHRPLANPORTAL: Ordner '$folder' enthält kein Jahr, verwende aktuelles Jahr: {$vorjahr}-12-14 bis {$jahr}-12-13");
        }
        
        // PDF-Pfad erstellen
        $pdf_pfad = $folder . '/';
        if (!empty($region)) {
            $pdf_pfad .= $region . '/';
        }
        $pdf_pfad .= $filename;
        
        // PDF parsen für Tags (falls verfügbar)
        $tags = '';
        if ($this->pdf_parsing_enabled) {
            $full_pdf_path = $this->pdf_base_path . $pdf_pfad;
            $tags = $this->extract_pdf_tags($full_pdf_path);
        }
        
        // Daten-Array vorbereiten
        $data = array(
            'titel' => $parsed['titel'],
            'linie_alt' => $parsed['linie_alt'],
            'linie_neu' => $parsed['linie_neu'],
            'kurzbeschreibung' => '',
            'gueltig_von' => $parsed['gueltig_von'],
            'gueltig_bis' => $parsed['gueltig_bis'],
            'pdf_pfad' => $pdf_pfad,
            'dateiname' => $filename,
            'jahr' => $folder,
            'region' => $this->utils->format_region_name($region)
        );
        
        // Tags nur hinzufügen wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled) {
            $data['tags'] = $tags;
        }
        
        return array('success' => true, 'data' => $data);
    }
    
    /**
     * PDF parsen und Tags extrahieren - ANGEPASST für Backend-Exklusionsliste
     */
    public function extract_pdf_tags($pdf_file_path) {
        if (!$this->pdf_parsing_enabled) {
            error_log('FAHRPLANPORTAL: PDF-Parsing übersprungen (nicht verfügbar)');
            return '';
        }
        
        error_log('FAHRPLANPORTAL: Beginne PDF-Parsing für: ' . $pdf_file_path);
        
        // Prüfen ob Datei existiert
        if (!file_exists($pdf_file_path)) {
            error_log('FAHRPLANPORTAL: PDF-Datei nicht gefunden: ' . $pdf_file_path);
            return '';
        }
        
        try {
            // Exklusionswörter aus Backend laden
            $exclusion_words = $this->utils->get_exclusion_words();
            
            // Direkte Verwendung der aktualisierten hd_process_pdf_for_words Funktion
            if (function_exists('hd_process_pdf_for_words')) {
                $words_array = hd_process_pdf_for_words($pdf_file_path, $exclusion_words);
                
                if (!empty($words_array)) {
                    // Array zu kommagetrennte Liste konvertieren
                    $tags_string = implode(', ', $words_array);
                    error_log('FAHRPLANPORTAL: PDF-Parsing erfolgreich - ' . count($words_array) . ' Wörter extrahiert (nach Exklusion)');
                    return $tags_string;
                } else {
                    error_log('FAHRPLANPORTAL: PDF-Parsing - keine Wörter extrahiert');
                    return '';
                }
            } else {
                error_log('FAHRPLANPORTAL: hd_process_pdf_for_words Funktion nicht verfügbar');
                return '';
            }
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: PDF-Parsing Fehler: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * 🔍 DEBUG-VERSION: parse_filename() mit detailliertem Mapping-Logging
     * ✅ Zeigt genau an warum Mappings nicht gefunden werden
     */
    public function parse_filename($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $linie_alt = '';
        $linie_neu = '';
        
        error_log("FAHRPLANPORTAL: 🔍 DEBUG Parse Dateiname: " . $name);
        
        // ✅ Mapping laden mit Debug-Info
        $line_mapping = $this->utils->get_line_mapping();
        error_log("FAHRPLANPORTAL: 🔍 Mapping-Array geladen: " . count($line_mapping) . " Einträge");
        
        // Buchstaben-Zahl-Kombinationen (X2-, X3-, etc.)
        if (preg_match('/^([A-Za-z]\d+)-(.+)$/', $name, $matches)) {
            $buchstaben_bezeichnung = strtoupper($matches[1]);  // X2, X3, X1 etc.
            $rest_route = $matches[2];
            
            error_log("FAHRPLANPORTAL: 🔍 Buchstaben-Bezeichnung erkannt: '$buchstaben_bezeichnung'");
            error_log("FAHRPLANPORTAL: 🔍 Rest-Route: '$rest_route'");
            
            // Erste Bezeichnung speichern
            $alle_bezeichnungen = array($buchstaben_bezeichnung);
            
            // Rest-Route analysieren: Weitere Nummern?
            if (preg_match('/^(\d{2,4}(?:-\d{2,4})*)-(.+)$/', $rest_route, $nummer_matches)) {
                $nummern_string = $nummer_matches[1];
                $final_route = $nummer_matches[2];
                
                $zusatz_nummern = explode('-', $nummern_string);
                $alle_bezeichnungen = array_merge($alle_bezeichnungen, $zusatz_nummern);
                
                error_log("FAHRPLANPORTAL: 🔍 Zusatz-Nummern gefunden: " . implode(', ', $zusatz_nummern));
            } else {
                $final_route = $rest_route;
                error_log("FAHRPLANPORTAL: 🔍 Keine Zusatz-Nummern, nur Route");
            }
            
            $linie_neu = implode(', ', $alle_bezeichnungen);
            error_log("FAHRPLANPORTAL: 🔍 Alle neue Bezeichnungen: [$linie_neu]");
            
            // ✅ DETAILLIERTES MAPPING-LOOKUP
            $alte_bezeichnungen = array();
            foreach ($alle_bezeichnungen as $bezeichnung) {
                $bezeichnung = strtoupper(trim($bezeichnung));  // Normalisierung
                
                error_log("FAHRPLANPORTAL: 🔍 Suche Mapping für: '$bezeichnung'");
                
                if (isset($line_mapping[$bezeichnung])) {
                    $gemappte_alte = $line_mapping[$bezeichnung];
                    $alte_bezeichnungen[] = $gemappte_alte;
                    error_log("FAHRPLANPORTAL: ✅ Mapping GEFUNDEN: '$bezeichnung' → '$gemappte_alte'");
                } else {
                    error_log("FAHRPLANPORTAL: ❌ Mapping NICHT gefunden für: '$bezeichnung'");
                    error_log("FAHRPLANPORTAL: 🔍 Verfügbare Mapping-Keys: " . implode(', ', array_keys($line_mapping)));
                    
                    // Fuzzy-Search für mögliche Tippfehler
                    $similar_keys = array();
                    foreach (array_keys($line_mapping) as $key) {
                        if (strcasecmp($key, $bezeichnung) === 0) {
                            $similar_keys[] = $key . " (case-insensitive match)";
                        } elseif (levenshtein($key, $bezeichnung) <= 2) {
                            $similar_keys[] = $key . " (ähnlich)";
                        }
                    }
                    
                    if (!empty($similar_keys)) {
                        error_log("FAHRPLANPORTAL: 💡 Ähnliche Keys gefunden: " . implode(', ', $similar_keys));
                    }
                }
            }
            
            if (!empty($alte_bezeichnungen)) {
                $linie_alt = implode(', ', $alte_bezeichnungen);
                error_log("FAHRPLANPORTAL: ✅ Finale alte Bezeichnungen: [$linie_alt]");
            } else {
                error_log("FAHRPLANPORTAL: ⚠️ KEINE Mappings gefunden - linie_alt bleibt leer");
            }
        }
        // Standard-Nummern (561-, 82-, etc.)
        elseif (preg_match('/^(\d{2,3}(?:-\d{2,3})*)-(.+)$/', $name, $matches)) {
            $nummern_string = $matches[1];
            $final_route = $matches[2];
            
            $nummern_array = explode('-', $nummern_string);
            $linie_neu = implode(', ', $nummern_array);
            
            error_log("FAHRPLANPORTAL: 🔍 Standard-Nummern: [$linie_neu]");
            
            // Mapping für Nummern
            $alte_nummern = array();
            foreach ($nummern_array as $nummer) {
                $nummer = trim($nummer);
                
                if (isset($line_mapping[$nummer])) {
                    $alte_nummern[] = $line_mapping[$nummer];
                    error_log("FAHRPLANPORTAL: ✅ Nummern-Mapping: '$nummer' → '" . $line_mapping[$nummer] . "'");
                } else {
                    error_log("FAHRPLANPORTAL: ❌ Nummern-Mapping nicht gefunden für: '$nummer'");
                }
            }
            
            if (!empty($alte_nummern)) {
                $linie_alt = implode(', ', $alte_nummern);
            }
        }
        // 4-stellige Nummern (Fallback)
        elseif (preg_match('/^(\d{4}(?:-\d{4})*)-(.+)$/', $name, $matches)) {
            $alte_nummern_string = $matches[1];
            $final_route = $matches[2];
            
            $alte_nummern_array = explode('-', $alte_nummern_string);
            $linie_alt = implode(', ', $alte_nummern_array);
            
            error_log("FAHRPLANPORTAL: 🔍 4-stellige Nummern (Fallback): [$linie_alt]");
            
            // Reverse Mapping
            $reverse_mapping = array_flip($line_mapping);
            $neue_nummern = array();
            foreach ($alte_nummern_array as $alte_nummer) {
                if (isset($reverse_mapping[$alte_nummer])) {
                    $neue_nummern[] = $reverse_mapping[$alte_nummer];
                    error_log("FAHRPLANPORTAL: ✅ Reverse-Mapping: '$alte_nummer' → '" . $reverse_mapping[$alte_nummer] . "'");
                }
            }
            
            if (!empty($neue_nummern)) {
                $linie_neu = implode(', ', $neue_nummern);
            }
        }
        else {
            error_log("FAHRPLANPORTAL: ❌ Kein Muster erkannt für: " . $name);
            return false;
        }
        
        // Route verarbeiten
        if (isset($final_route)) {
            $orte = explode('-', $final_route);
            $orte = $this->utils->process_abbreviations($orte);
            
            $orte_formatted = array();
            foreach ($orte as $ort) {
                $ort_mit_umlauten = $this->utils->convert_german_umlauts($ort);
                
                if (strpos($ort_mit_umlauten, 'St.') === 0 || 
                    strpos($ort_mit_umlauten, 'an der ') === 0 ||
                    strpos($ort_mit_umlauten, ' am ') !== false ||
                    strpos($ort_mit_umlauten, ' bei ') !== false ||
                    strpos($ort_mit_umlauten, ' ob ') !== false ||
                    strpos($ort_mit_umlauten, ' ob der ') !== false ||
                    strpos($ort_mit_umlauten, ' unter ') !== false) {
                    $ort_formatted = $ort_mit_umlauten;
                } else {
                    $ort_formatted = ucfirst($ort_mit_umlauten);
                }
                
                $orte_formatted[] = $ort_formatted;
            }
            
            $titel = implode(' — ', $orte_formatted);
            
            $result = array(
                'titel' => $titel,
                'linie_alt' => $linie_alt,
                'linie_neu' => $linie_neu,
                'kurzbeschreibung' => '',
                'gueltig_von' => '',
                'gueltig_bis' => ''
            );
            
            error_log("FAHRPLANPORTAL: 🎯 FINALES ERGEBNIS:");
            error_log("FAHRPLANPORTAL:    Titel: $titel");
            error_log("FAHRPLANPORTAL:    Linie Neu: '$linie_neu'");
            error_log("FAHRPLANPORTAL:    Linie Alt: '$linie_alt'");
            
            return $result;
        }
        
        error_log("FAHRPLANPORTAL: ❌ Parse fehlgeschlagen - keine finale Route");
        return false;
    }
}