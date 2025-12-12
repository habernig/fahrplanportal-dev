# FahrplanPortal Admin - Modulare JavaScript-Struktur

## Übersicht der Module

| Modul | Größe | Funktion |
|-------|-------|----------|
| `admin-core.js` | ~18 KB | Namespace, AJAX-Helper, Sync-Nachrichten, Initialisierung |
| `admin-datatable.js` | ~13 KB | DataTables, Region-Filter mit sessionStorage-Persistenz |
| `admin-modal.js` | ~9 KB | Edit-Modal, Speichern, Löschen |
| `admin-db-maintenance.js` | ~20 KB | Exklusionsliste, Linien-Mapping, DB-Wartung |
| `admin-sync.js` | ~28 KB | Tabellen-Sync, fehlende PDFs, Einzel-Import |
| `admin-tags.js` | ~14 KB | Tag-Analyse und -Anzeige |
| `admin-scanning.js` | ~45 KB | Chunked Scanning, Progress Bar, Fehlerprotokoll |

**Gesamt: ~147 KB** (Original admin.js: ~140 KB)

---

## Einbindung in WordPress

### PHP-Code für wp_enqueue_scripts

Ersetze die bisherige Einbindung der `admin.js` durch folgendes in deiner Plugin-Hauptdatei:

```php
/**
 * Admin Scripts modular einbinden
 */
function fahrplanportal_enqueue_admin_scripts($hook) {
    // Nur auf Plugin-Seiten laden
    if (strpos($hook, 'fahrplanportal') === false) {
        return;
    }
    
    $plugin_url = plugin_dir_url(__FILE__);
    $version = '2.0.0'; // Oder deine aktuelle Version
    
    // 1. Core-Modul (MUSS zuerst geladen werden)
    wp_enqueue_script(
        'fahrplanportal-admin-core',
        $plugin_url . 'js/admin-core.js',
        array('jquery'),
        $version,
        true // Im Footer laden
    );
    
    // 2. DataTable-Modul
    wp_enqueue_script(
        'fahrplanportal-admin-datatable',
        $plugin_url . 'js/admin-datatable.js',
        array('jquery', 'fahrplanportal-admin-core'),
        $version,
        true
    );
    
    // 3. Modal-Modul
    wp_enqueue_script(
        'fahrplanportal-admin-modal',
        $plugin_url . 'js/admin-modal.js',
        array('jquery', 'fahrplanportal-admin-core'),
        $version,
        true
    );
    
    // 4. DB-Maintenance-Modul
    wp_enqueue_script(
        'fahrplanportal-admin-db-maintenance',
        $plugin_url . 'js/admin-db-maintenance.js',
        array('jquery', 'fahrplanportal-admin-core'),
        $version,
        true
    );
    
    // 5. Sync-Modul
    wp_enqueue_script(
        'fahrplanportal-admin-sync',
        $plugin_url . 'js/admin-sync.js',
        array('jquery', 'fahrplanportal-admin-core'),
        $version,
        true
    );
    
    // 6. Tags-Modul
    wp_enqueue_script(
        'fahrplanportal-admin-tags',
        $plugin_url . 'js/admin-tags.js',
        array('jquery', 'fahrplanportal-admin-core'),
        $version,
        true
    );
    
    // 7. Scanning-Modul
    wp_enqueue_script(
        'fahrplanportal-admin-scanning',
        $plugin_url . 'js/admin-scanning.js',
        array('jquery', 'fahrplanportal-admin-core'),
        $version,
        true
    );
    
    // Lokalisierte Daten für alle Module (wie bisher)
    wp_localize_script('fahrplanportal-admin-core', 'fahrplanportal_unified', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fahrplanportal_nonce'),
        'context' => 'admin_fahrplanportal_chunked',
        'pdf_parsing_enabled' => get_option('fahrplanportal_pdf_parsing_enabled', false),
        // ... weitere Optionen wie bisher
    ));
}
add_action('admin_enqueue_scripts', 'fahrplanportal_enqueue_admin_scripts');
```

---

## Dateistruktur im Plugin

```
fahrplanportal/
├── fahrplanportal.php
├── js/
│   ├── admin-core.js          ← NEU
│   ├── admin-datatable.js     ← NEU
│   ├── admin-modal.js         ← NEU
│   ├── admin-db-maintenance.js← NEU
│   ├── admin-sync.js          ← NEU
│   ├── admin-tags.js          ← NEU
│   ├── admin-scanning.js      ← NEU
│   ├── admin.js               ← ALT (kann gelöscht werden nach Test)
│   └── unified-ajax.js        ← Bestehend
├── ...
```

---

## Architektur

### Event-basierte Kommunikation

Die Module kommunizieren über jQuery Events:

```javascript
// Core löst aus wenn bereit:
$(document).trigger('fahrplanAdmin:ready');

// Andere Module reagieren:
$(document).on('fahrplanAdmin:ready', function() {
    // Modul initialisieren
});
```

### Namespace

Alle Module nutzen den globalen Namespace `FahrplanAdmin`:

```javascript
// Verfügbare Eigenschaften/Methoden:
FahrplanAdmin.pdfParsingEnabled  // Boolean
FahrplanAdmin.initialized        // Boolean
FahrplanAdmin.dataTable          // DataTables-Instanz
FahrplanAdmin.scanState          // Scan-Status-Objekt

FahrplanAdmin.ajaxCall()         // AJAX-Helper
FahrplanAdmin.escapeHtml()       // HTML-Escape
FahrplanAdmin.formatDuration()   // Zeit formatieren
FahrplanAdmin.showPersistentSyncMessage()  // Sync-Nachrichten
```

---

## Wichtige Änderungen

### Region-Filter Persistenz (admin-datatable.js)

Der Region-Filter wird jetzt in `sessionStorage` gespeichert und nach Page-Reload wiederhergestellt:

```javascript
// Bei Änderung speichern
sessionStorage.setItem('fahrplan_region_filter', selectedRegion);

// Nach DataTable-Init wiederherstellen
var savedRegion = sessionStorage.getItem('fahrplan_region_filter');
```

---

## Test-Checkliste

Nach der Einbindung prüfen:

- [ ] Admin-Seite lädt ohne JavaScript-Fehler (Browser-Konsole)
- [ ] DataTable wird korrekt angezeigt
- [ ] Region-Filter funktioniert
- [ ] Region-Filter bleibt nach Modal-Speichern erhalten
- [ ] Edit-Modal öffnet und speichert
- [ ] Verzeichnis scannen funktioniert
- [ ] Tag-Analyse funktioniert
- [ ] Exklusionsliste speichern/laden funktioniert
- [ ] Linien-Mapping speichern/laden funktioniert
- [ ] Tabelle aktualisieren funktioniert

---

## Rollback

Falls Probleme auftreten, einfach die alte `admin.js` wieder einbinden und die modularen Scripts entfernen.
