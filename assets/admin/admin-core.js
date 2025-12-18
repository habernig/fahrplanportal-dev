/**
 * FahrplanPortal Admin - Core Module
 * 
 * Enthält:
 * - Namespace-Objekt (FahrplanAdmin)
 * - Kontext-Prüfung
 * - AJAX-Helper (fahrplanAdminCall)
 * - Sync-Nachrichten-System
 * - Auto-Status-Check
 * - Initialisierung
 * 
 * @version 2.0.0
 * @requires jQuery
 */

// ========================================
// NAMESPACE UND GLOBALE VARIABLEN
// ========================================

var FahrplanAdmin = FahrplanAdmin || {};

jQuery(document).ready(function($) {
    
    // ✅ SOFORT-FIX: Strikte Admin-Kontext-Prüfung
    console.log('FAHRPLANPORTAL: JavaScript geladen - Admin-Kontext-Prüfung...');

    // FIX: jQuery Selektor-Sicherheit - NUR für ungültige Text-Listen
    var originalFind = $.fn.find;
    $.fn.find = function(selector) {
        // Prüfe ob der Selektor verdächtig aussieht (Kommas mit Wörtern ohne CSS-Syntax)
        if (typeof selector === 'string' && selector.indexOf(',') > -1) {
            // Prüfe ob es wie eine Wortliste aussieht (keine CSS-Zeichen)
            // Erlaubt normale CSS-Selektoren wie "th, td" oder ".class1, .class2"
            if (!selector.match(/[#\.\[\]:>\+~\*=\(\)]/)) {
                // Weitere Prüfung: Enthält der Selektor deutsche Städtenamen oder ähnliches?
                var parts = selector.split(',');
                var suspicious = false;
                for (var i = 0; i < parts.length; i++) {
                    var part = parts[i].trim();
                    // Wenn ein Teil länger als 20 Zeichen ist oder Sonderzeichen enthält, ist es verdächtig
                    if (part.length > 20 || part.match(/[äöüß]/i)) {
                        suspicious = true;
                        break;
                    }
                }
                if (suspicious) {
                    console.warn('FAHRPLANPORTAL: Ungültiger Selektor abgefangen:', selector);
                    return $(); // Leeres jQuery-Objekt zurückgeben
                }
            }
        }
        return originalFind.apply(this, arguments);
    };

    
    // ✅ SOFORT-FIX: Admin-Kontext validieren
    if (typeof fahrplanportal_unified === 'undefined') {
        console.log('❌ FAHRPLANPORTAL: Admin-Kontext nicht erkannt - Script beendet');
        return; // Sofort beenden wenn kein Admin-Kontext
    }
    
    // ✅ SOFORT-FIX: Admin-Kontext bestätigen (alle Admin-Context-Namen)
    if (fahrplanportal_unified.context !== 'admin_fahrplanportal' && 
        fahrplanportal_unified.context !== 'admin_fahrplanportal_fixed' &&
        fahrplanportal_unified.context !== 'admin_fahrplanportal_ajax_fixed' &&
        fahrplanportal_unified.context !== 'admin_fahrplanportal_chunked') {
        console.log('❌ FAHRPLANPORTAL: Falscher Kontext (' + (fahrplanportal_unified.context || 'unbekannt') + ') - Script beendet');
        return; // Sofort beenden wenn falscher Kontext
    }
    
    console.log('✅ FAHRPLANPORTAL: Admin-Kontext bestätigt:', fahrplanportal_unified.context);
    console.log('✅ FAHRPLANPORTAL: PDF-Parsing verfügbar:', fahrplanportal_unified.pdf_parsing_enabled);
    
    // ========================================
    // NAMESPACE SETUP
    // ========================================
    
    // Globale Variablen im Namespace speichern
    FahrplanAdmin.pdfParsingEnabled = fahrplanportal_unified.pdf_parsing_enabled || false;
    FahrplanAdmin.context = fahrplanportal_unified.context;
    
    // ✅ NEU: Config-Objekt für Settings aus PHP
    FahrplanAdmin.config = {
        scan_chunk_size: fahrplanportal_unified.scan_chunk_size || 10
    };
    console.log('✅ FAHRPLANPORTAL: Config geladen - Chunk-Size:', FahrplanAdmin.config.scan_chunk_size);
    FahrplanAdmin.initialized = false;
    
    // ========================================
    // AJAX HELPER
    // ========================================
    
    /**
     * ✅ GEFIXTER AJAX Helper für Admin-Funktionen
     * Verfügbar als FahrplanAdmin.ajaxCall()
     */
    FahrplanAdmin.ajaxCall = function(action, data, options) {
        var defaults = {
            success: function(response) { 
                console.log("✅ Fahrplan Admin AJAX Success (" + action + "):", response); 
            },
            error: function(error) { 
                console.error("❌ Fahrplan Admin AJAX Error (" + action + "):", error); 
            },
            beforeSend: function() {
                console.log("🔄 Fahrplan Admin AJAX Start:", action);
            }
        };
        
        var settings = Object.assign({}, defaults, options || {});
        
        // ✅ KRITISCHER FIX: Prüfung ob Unified System verfügbar
        if (typeof UnifiedAjaxAPI !== 'undefined' && UnifiedAjaxAPI && typeof UnifiedAjaxAPI.call === 'function') {
            console.log("✅ FAHRPLANPORTAL: Verwende Unified AJAX System");
            return UnifiedAjaxAPI.call('fahrplanportal', action, data, settings);
        } else {
            console.warn("⚠️ FAHRPLANPORTAL: Unified AJAX nicht verfügbar, verwende WordPress AJAX Fallback");
            
            // ✅ FALLBACK: Standard WordPress AJAX
            var ajaxData = {
                action: 'unified_ajax',
                module: 'fahrplanportal',
                module_action: action,
                nonce: fahrplanportal_unified.nonce
            };
            
            // Data-Parameter hinzufügen
            if (data && typeof data === 'object') {
                for (var key in data) {
                    if (data.hasOwnProperty(key)) {
                        ajaxData[key] = data[key];
                    }
                }
            }
            
            return jQuery.ajax({
                url: fahrplanportal_unified.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: ajaxData,
                beforeSend: settings.beforeSend,
                success: function(response) {
                    if (response && response.success) {
                        settings.success(response.data);
                    } else {
                        var errorMsg = (response && response.data) ? response.data : 'Unbekannter Fehler';
                        settings.error({ message: errorMsg });
                    }
                },
                error: function(xhr, status, error) {
                    console.error("❌ FAHRPLANPORTAL: WordPress AJAX Fehler:", xhr.responseText);
                    settings.error({ 
                        message: error || 'AJAX-Verbindungsfehler',
                        xhr: xhr,
                        status: status
                    });
                }
            });
        }
    };
    
    // Alias für Rückwärtskompatibilität (wird von anderen Modulen verwendet)
    window.fahrplanAdminCall = FahrplanAdmin.ajaxCall;

    // ========================================
    // SYNC-NACHRICHTEN SYSTEM
    // ========================================

    /**
     * ✅ Sync-Nachricht persistent speichern und anzeigen
     */
    FahrplanAdmin.showPersistentSyncMessage = function(message, type) {
        type = type || 'success';
        
        // In sessionStorage speichern (überlebt Page-Reload)
        sessionStorage.setItem('fahrplan_sync_message', message);
        sessionStorage.setItem('fahrplan_sync_type', type);
        
        // Sofort anzeigen
        FahrplanAdmin.displaySyncMessage(message, type);
    };
    
    /**
     * Sync-Nachricht anzeigen
     */
    FahrplanAdmin.displaySyncMessage = function(message, type) {
        var $status = $('#status-update-info');
        var color = type === 'success' ? 'green' : (type === 'error' ? 'red' : 'orange');
        
        $status.html('<span style="color: ' + color + ';">' + message + '</span>');
        
        // Nach 10 Sekunden ausblenden (nur die aktuelle Anzeige, nicht aus sessionStorage)
        setTimeout(function() {
            $status.fadeOut(2000);
        }, 10000);
    };
    
    /**
     * Gespeicherte Sync-Nachricht prüfen und anzeigen
     */
    FahrplanAdmin.checkForPersistedSyncMessage = function() {
        var message = sessionStorage.getItem('fahrplan_sync_message');
        var type = sessionStorage.getItem('fahrplan_sync_type');
        
        if (message) {
            console.log('✅ FAHRPLANPORTAL: Gespeicherte Sync-Nachricht gefunden');
            FahrplanAdmin.displaySyncMessage(message, type);
            
            // Nach Anzeige aus sessionStorage entfernen
            setTimeout(function() {
                sessionStorage.removeItem('fahrplan_sync_message');
                sessionStorage.removeItem('fahrplan_sync_type');
            }, 15000);
        }
    };

    // ========================================
    // AUTO-STATUS-CHECK
    // ========================================

    /**
     * ✅ Automatische Status-Prüfung beim Seitenladen
     */
    FahrplanAdmin.autoCheckTableStatus = function() {
        console.log('🔄 FAHRPLANPORTAL: Auto-Status-Check gestartet');
        
        // Status-Daten per AJAX laden
        FahrplanAdmin.ajaxCall('get_all_status_updates', {}, {
            success: function(response) {
                console.log('✅ FAHRPLANPORTAL: Status-Daten beim Laden erhalten:', response);
                
                // Alle Status-Zellen aktualisieren
                $('#fahrplaene-table tbody tr').each(function() {
                    var $row = $(this);
                    var rowId = $row.data('id');
                    var $statusCell = $row.find('[id^="status-"]');
                    
                    if (rowId && $statusCell.length > 0 && response.status_data) {
                        var status = response.status_data[rowId] || 'OK';
                        
                        // Status entsprechend setzen
                        if (status === 'MISSING') {
                            $statusCell.html('<span class="status-missing">❌ Fehlt</span>');
                            $row.addClass('missing-pdf-row');
                        } else if (status === 'IMPORT') {
                            var pdfPath = $row.data('pdf-path') || '';
                            $statusCell.html('<span class="status-import" data-pdf-path="' + pdfPath + '">🆕 Import</span>');
                        } else {
                            $statusCell.html('<span class="status-ok">✅ OK</span>');
                            $row.removeClass('missing-pdf-row');
                        }
                        
                        $row.attr('data-pdf-status', status);
                    }
                });
                
                // Fehlende PDFs Button anzeigen falls nötig
                var missingCount = 0;
                Object.values(response.status_data || {}).forEach(function(status) {
                    if (status === 'MISSING') missingCount++;
                });
                
                if (missingCount > 0) {
                    $('#delete-missing-pdfs').show();
                    $('#show-missing-details').show();
                    $('#delete-missing-pdfs').html('<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>Fehlende PDFs löschen (' + missingCount + ')');
                } else {
                    $('#delete-missing-pdfs').hide();
                    $('#show-missing-details').hide();
                }
                
                console.log('✅ FAHRPLANPORTAL: Auto-Status-Check abgeschlossen');
            },
            error: function(error) {
                console.error('❌ FAHRPLANPORTAL: Auto-Status-Check Fehler:', error);
                
                // Fallback: Einfacher Check basierend auf PDF-Links
                $('[id^="status-"]').each(function() {
                    var $statusCell = $(this);
                    var $loadingSpan = $statusCell.find('.status-loading');
                    
                    if ($loadingSpan.length > 0) {
                        var $row = $statusCell.closest('tr');
                        var $pdfLink = $row.find('a[href*=".pdf"]');
                        
                        if ($pdfLink.length > 0) {
                            $statusCell.html('<span class="status-ok">✅ OK</span>');
                        } else {
                            $statusCell.html('<span class="status-checking">🔍 Prüfen</span>');
                        }
                    }
                });
                
                console.log('✅ FAHRPLANPORTAL: Auto-Status-Check Fallback durchgeführt');
            }
        });
    };

    // ========================================
    // HELPER FUNKTIONEN
    // ========================================

    /**
     * HTML escaping
     */
    FahrplanAdmin.escapeHtml = function(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text || '').replace(/[&<>"']/g, function(m) { return map[m]; });
    };

    /**
     * Dauer formatieren
     */
    FahrplanAdmin.formatDuration = function(seconds) {
        if (seconds < 60) {
            return seconds + ' Sek';
        } else if (seconds < 3600) {
            var minutes = Math.floor(seconds / 60);
            var remainingSeconds = seconds % 60;
            return minutes + ' Min' + (remainingSeconds > 0 ? ' ' + remainingSeconds + ' Sek' : '');
        } else {
            var hours = Math.floor(seconds / 3600);
            var remainingMinutes = Math.floor((seconds % 3600) / 60);
            return hours + ' Std' + (remainingMinutes > 0 ? ' ' + remainingMinutes + ' Min' : '');
        }
    };

    /**
     * ✅ System-Status prüfen (Debug)
     */
    FahrplanAdmin.checkSystemStatus = function() {
        console.log('🔍 FAHRPLANPORTAL: System-Status Check');
        console.log('  - fahrplanportal_unified verfügbar:', typeof fahrplanportal_unified !== 'undefined');
        console.log('  - UnifiedAjaxAPI verfügbar:', typeof UnifiedAjaxAPI !== 'undefined');
        console.log('  - jQuery verfügbar:', typeof jQuery !== 'undefined');
        
        if (typeof fahrplanportal_unified !== 'undefined') {
            console.log('  - AJAX URL:', fahrplanportal_unified.ajax_url);
            console.log('  - Nonce:', fahrplanportal_unified.nonce);
            console.log('  - Context:', fahrplanportal_unified.context);
        }
        
        if (typeof UnifiedAjaxAPI !== 'undefined') {
            console.log('  - UnifiedAjaxAPI.call verfügbar:', typeof UnifiedAjaxAPI.call === 'function');
        }
    };

    // ========================================
    // INITIALISIERUNG
    // ========================================

    /**
     * Warten auf Unified System und dann initialisieren
     */
    function waitForUnifiedSystem() {
        if (typeof UnifiedAjaxAPI === 'undefined') {
            console.log('🔄 FAHRPLANPORTAL: Warte auf Unified AJAX API (Admin)...');
            
            var adminWaitAttempts = 0;
            var maxAdminWaitAttempts = 30; // 3 Sekunden Maximum
            
            var adminWaitInterval = setInterval(function() {
                adminWaitAttempts++;
                
                if (typeof UnifiedAjaxAPI !== 'undefined') {
                    console.log('✅ FAHRPLANPORTAL: Unified AJAX API verfügbar nach ' + adminWaitAttempts + ' Versuchen');
                    clearInterval(adminWaitInterval);
                    initializeAllModules();
                    return;
                }
                
                if (adminWaitAttempts >= maxAdminWaitAttempts) {
                    console.error('❌ FAHRPLANPORTAL: Unified AJAX API nach ' + maxAdminWaitAttempts + ' Versuchen nicht verfügbar');
                    clearInterval(adminWaitInterval);
                    initializeFallbackMode();
                    return;
                }
            }, 100);
            
            return;
        }
        
        // Sofort initialisieren falls verfügbar
        console.log('✅ FAHRPLANPORTAL: Unified AJAX API sofort verfügbar');
        initializeAllModules();
    }

    /**
     * Alle Module initialisieren
     */
    function initializeAllModules() {
        console.log('🚀 FAHRPLANPORTAL: Initialisiere Admin-Interface...');
        
        FahrplanAdmin.initialized = true;
        
        
        // Event auslösen damit andere Module starten können
        $(document).trigger('fahrplanAdmin:ready');
        
        // Status-Checks nach kurzer Verzögerung
        setTimeout(function() {
            FahrplanAdmin.checkForPersistedSyncMessage();
            
            if ($('#fahrplaene-table').length > 0) {
                FahrplanAdmin.autoCheckTableStatus();
            }
            
            FahrplanAdmin.checkSystemStatus();
        }, 1000);
        
        console.log('✅ FAHRPLANPORTAL: Core-Modul initialisiert');
    }

    /**
     * Fallback-Modus initialisieren
     */
    function initializeFallbackMode() {
        console.log('⚠️ FAHRPLANPORTAL: Initialisiere Fallback-Modus (ohne Unified System)');
        
        // Basis-Admin-Interface ohne AJAX-Funktionalität
        $('#scan-directory').on('click', function() {
            $(this).prop('disabled', true);
            $('#scan-status').html('<span style="color: red;">⚠️ Unified AJAX System nicht verfügbar - Seite neu laden</span>');
            
            setTimeout(function() {
                location.reload();
            }, 2000);
        });
        
        // Warnung für alle anderen AJAX-abhängigen Buttons
        $('.button').not('#scan-directory').on('click', function(e) {
            if ($(this).attr('id') && ($(this).attr('id').indexOf('save') >= 0 || $(this).attr('id').indexOf('load') >= 0 || $(this).attr('id').indexOf('db') >= 0)) {
                e.preventDefault();
                alert('⚠️ Unified AJAX System nicht verfügbar. Bitte Seite neu laden.');
            }
        });
        
        console.log('⚠️ FAHRPLANPORTAL: Fallback-Modus aktiv');
    }

    // Initialisierung starten
    waitForUnifiedSystem();

}); // Ende jQuery ready
