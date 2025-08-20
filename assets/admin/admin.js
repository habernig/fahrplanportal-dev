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
    
    // ✅ SOFORT-FIX: Sanftes Warten auf Unified System (Admin-spezifisch)
    if (typeof UnifiedAjaxAPI === 'undefined') {
        console.log('🔄 FAHRPLANPORTAL: Warte auf Unified AJAX API (Admin)...');
        
        var adminWaitAttempts = 0;
        var maxAdminWaitAttempts = 30; // 3 Sekunden Maximum
        
        var adminWaitInterval = setInterval(function() {
            adminWaitAttempts++;
            
            if (typeof UnifiedAjaxAPI !== 'undefined') {
                console.log('✅ FAHRPLANPORTAL: Unified AJAX API verfügbar nach ' + adminWaitAttempts + ' Versuchen');
                clearInterval(adminWaitInterval);
                initializeFahrplanportalAdmin();
                return;
            }
            
            if (adminWaitAttempts >= maxAdminWaitAttempts) {
                console.error('❌ FAHRPLANPORTAL: Unified AJAX API nach ' + maxAdminWaitAttempts + ' Versuchen nicht verfügbar');
                clearInterval(adminWaitInterval);
                initializeFahrplanportalFallback();
                return;
            }
        }, 100);
        
        return;
    }
    
    // ✅ Sofort initialisieren falls verfügbar
    console.log('✅ FAHRPLANPORTAL: Unified AJAX API sofort verfügbar');
    initializeFahrplanportalAdmin();
    
    // ========================================
// HAUPT-INITIALISIERUNG (ADMIN-ONLY)
// ========================================
function initializeFahrplanportalAdmin() {
    console.log('🚀 FAHRPLANPORTAL: Initialisiere Admin-Interface...');
    
    var pdfParsingEnabled = fahrplanportal_unified.pdf_parsing_enabled || false;
    
    // ✅ GEFIXTER AJAX Helper für Admin-Funktionen
    function fahrplanAdminCall(action, data, options) {
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
    }
    
    // ========================================
    // ✅ ERWEITERT: CHUNKED SCANNING MIT DB-BEREINIGUNG
    // ========================================
    
    // Globalen chunkingScanState erweitern um detaillierte Fehlersammlung
    var chunkingScanState = {
        active: false,
        folder: '',
        totalChunks: 0,
        currentChunk: 0,
        totalFiles: 0,
        totalStats: {
            imported: 0,
            skipped: 0,
            errors: 0,
            processed: 0
        },
        regionStats: {},
        startTime: null,
        cancelled: false,
        databaseCleared: false,
        
        // ✅ NEU: Detaillierte Fehlersammlung
        errorDetails: [],           // Array für alle Fehler
        errorsByType: {},          // Gruppierung nach Fehlertyp
        errorsByRegion: {},        // Gruppierung nach Region
        currentChunkErrors: []     // Fehler des aktuellen Chunks
    };


    // ✅ NEU: Tag-Cleanup in bestehender Datenbank
    $('#cleanup-existing-tags').on('click', function() {
        var $btn = $(this);
        var $status = $('#cleanup-status');
        
        // Sicherheitsabfrage
        var confirmed = confirm(
            '🧹 Tag-Bereinigung starten?\n\n' +
            'Diese Funktion entfernt alle Exklusionswörter aus den bereits gespeicherten Tags in der Datenbank.\n\n' +
            '⚠️ Wichtig: Stellen Sie sicher, dass Ihre Exklusionsliste aktuell ist.\n' +
            'Die Änderungen sind nicht rückgängig zu machen!\n\n' +
            'Fortfahren?'
        );
        
        if (!confirmed) {
            return;
        }
        
        // UI für Loading-State vorbereiten
        $btn.prop('disabled', true);
        $status.html('<span style="color: orange;">🔄 Bereinige Tags in Datenbank...</span>');
        
        // Detaillierte Progress-Anzeige
        var startTime = Date.now();
        var progressInterval = setInterval(function() {
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            $status.html('<span style="color: orange;">🔄 Bereinige Tags... (' + elapsed + 's)</span>');
        }, 1000);
        
        // AJAX-Call zur Tag-Bereinigung
        fahrplanAdminCall('cleanup_existing_tags', {}, {
            success: function(response) {
                clearInterval(progressInterval);
                
                // Detaillierte Erfolgs-Statistiken anzeigen
                var stats = response;
                var message = '';
                
                if (stats.updated_fahrplaene === 0) {
                    message = '✅ Keine Bereinigung nötig - alle Tags sind bereits sauber!';
                } else {
                    message = '✅ Tag-Bereinigung erfolgreich abgeschlossen!\n\n';
                    message += '📊 Statistiken:\n';
                    message += '• ' + stats.updated_fahrplaene + ' Fahrpläne aktualisiert\n';
                    message += '• ' + stats.removed_words + ' Wörter entfernt\n';
                    message += '• ' + stats.total_fahrplaene + ' Fahrpläne insgesamt geprüft\n';
                    message += '• ' + stats.exclusion_count + ' Wörter in Exklusionsliste\n';
                    
                    if (stats.efficiency) {
                        message += '• ⌀ ' + stats.efficiency + ' Wörter pro Fahrplan entfernt\n';
                    }
                    
                    message += '• ⏱️ Verarbeitungszeit: ' + stats.processing_time + 's';
                }
                
                // Status-Anzeige aktualisieren
                $status.html('<span style="color: green;">' + 
                    stats.updated_fahrplaene + ' Fahrpläne bereinigt, ' + 
                    stats.removed_words + ' Wörter entfernt (' + 
                    stats.processing_time + 's)</span>');
                
                // Alert mit Details
                alert(message);
                
                // Status nach 5 Sekunden ausblenden
                setTimeout(function() {
                    $status.html('');
                }, 5000);
                
                console.log('✅ FAHRPLANPORTAL: Tag-Cleanup abgeschlossen', stats);
            },
            error: function(error) {
                clearInterval(progressInterval);
                
                var errorMsg = error.message || 'Unbekannter Fehler';
                $status.html('<span style="color: red;">✗ Fehler: ' + errorMsg + '</span>');
                
                // Detaillierte Fehlermeldung
                alert('❌ Fehler bei Tag-Bereinigung:\n\n' + errorMsg + '\n\nBitte prüfen Sie:\n' +
                      '• Ist die Exklusionsliste gespeichert?\n' +
                      '• Ist PDF-Parsing aktiviert?\n' +
                      '• Haben Sie die nötigen Berechtigungen?');
                
                console.error('❌ FAHRPLANPORTAL: Tag-Cleanup Fehler:', error);
            },
            complete: function() {
                clearInterval(progressInterval);
                $btn.prop('disabled', false);
            }
        });
    });

    // ✅ NEU: Hilfs-Tooltip für Tag-Cleanup Button
    $('#cleanup-existing-tags').on('mouseenter', function() {
        $(this).attr('title', 
            'Entfernt alle Exklusionswörter aus bereits gespeicherten Tags in der Datenbank. ' +
            'Nützlich nach Änderungen an der Exklusionsliste.'
        );
    });


    /**
     * ✅ SICHERER START: Fehlersammlung initialisieren
     */
    function initializeErrorCollection() {
        if (!chunkingScanState) {
            console.error('❌ FAHRPLANPORTAL: chunkingScanState nicht definiert!');
            return;
        }
        
        // Alle Fehler-Arrays sicher initialisieren
        chunkingScanState.errorDetails = chunkingScanState.errorDetails || [];
        chunkingScanState.errorsByRegion = chunkingScanState.errorsByRegion || {};
        chunkingScanState.errorsByType = chunkingScanState.errorsByType || {};
        chunkingScanState.currentChunkErrors = chunkingScanState.currentChunkErrors || [];
        
        console.log('✅ FAHRPLANPORTAL: Fehlersammlung initialisiert');
    }

    /**
     * ✅ NEU: Fehler zu Sammlung hinzufügen
     */
    function addErrorToCollection(error, file, region) {
        // ✅ KRITISCHER FIX: Sicherstellen dass Arrays existieren
        if (!chunkingScanState.errorDetails) {
            chunkingScanState.errorDetails = [];
        }
        if (!chunkingScanState.errorsByRegion) {
            chunkingScanState.errorsByRegion = {};
        }
        if (!chunkingScanState.errorsByType) {
            chunkingScanState.errorsByType = {};
        }
        
        var errorEntry = {
            timestamp: new Date().toISOString(),
            file: file || 'Unbekannte Datei',
            error: error || 'Unbekannter Fehler',
            region: region || 'Unbekannte Region',
            chunk: chunkingScanState.currentChunk || 0
        };
        
        try {
            // Zu globaler Fehlersammlung hinzufügen
            chunkingScanState.errorDetails.push(errorEntry);
            
            // Nach Region gruppieren
            if (!chunkingScanState.errorsByRegion[errorEntry.region]) {
                chunkingScanState.errorsByRegion[errorEntry.region] = 0;
            }
            chunkingScanState.errorsByRegion[errorEntry.region]++;
            
            // Nach Fehlertyp gruppieren
            var errorType = categorizeError(error);
            if (!chunkingScanState.errorsByType[errorType]) {
                chunkingScanState.errorsByType[errorType] = 0;
            }
            chunkingScanState.errorsByType[errorType]++;
            
            console.log('✅ FAHRPLANPORTAL: Fehler erfolgreich gesammelt:', errorEntry);
            
        } catch (collectionError) {
            console.error('❌ FAHRPLANPORTAL: Fehler beim Sammeln von Fehlern:', collectionError);
            console.error('❌ Original-Fehler war:', error, 'für Datei:', file);
        }
    }

    /**
     * ✅ NEU: Fehler kategorisieren
     */
    function categorizeError(errorMessage) {
        if (!errorMessage) return 'Unbekannter Fehler';
        
        var errorLower = errorMessage.toLowerCase();
        
        if (errorLower.includes('dateiname') || errorLower.includes('filename')) {
            return 'Dateiname-Parsing Fehler';
        } else if (errorLower.includes('database') || errorLower.includes('db') || errorLower.includes('insert')) {
            return 'Datenbank Fehler';
        } else if (errorLower.includes('pdf') && errorLower.includes('parsing')) {
            return 'PDF-Parsing Fehler';
        } else if (errorLower.includes('nicht gefunden') || errorLower.includes('not found')) {
            return 'Datei nicht gefunden';
        } else if (errorLower.includes('permission') || errorLower.includes('berechtigung')) {
            return 'Berechtigung Fehler';
        } else if (errorLower.includes('format') || errorLower.includes('invalid')) {
            return 'Format Fehler';
        } else {
            return 'Allgemeiner Fehler';
        }
    }
    
    /**
     * ✅ ERWEITERT: Chunked Scanning mit DB-Bereinigung starten
     */
    function startChunkedScanning() {
        var folder = $('#scan-year').val();
        var button = $('#scan-directory');
        var status = $('#scan-status');
        
        if (!folder) {
            status.html('<span style="color: red;">✗ Bitte einen Ordner auswählen</span>');
            return;
        }
        
        console.log('🚀 FAHRPLANPORTAL: Starte Chunked Scanning für Ordner:', folder);
        
        // ✅ NEU: Bestätigungsdialog vor DB-Bereinigung
        showDatabaseClearConfirmation(folder);
    }
    
    /**
     * ✅ NEU: Bestätigungsdialog für Datenbank-Bereinigung
     */
    function showDatabaseClearConfirmation(folder) {
        var confirmationHtml = `
            <div id="db-clear-confirmation" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 999999;
                animation: fadeIn 0.3s ease;
            ">
                <div style="
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    max-width: 500px;
                    width: 90%;
                    max-height: 90vh;
                    overflow: hidden;
                    animation: slideIn 0.3s ease;
                ">
                    <!-- Header -->
                    <div style="
                        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                        color: white;
                        padding: 20px 25px;
                        text-align: center;
                    ">
                        <div style="font-size: 48px; margin-bottom: 10px;">⚠️</div>
                        <h2 style="margin: 0; font-size: 20px; font-weight: 600;">
                            Datenbank wird bereinigt!
                        </h2>
                    </div>
                    
                    <!-- Body -->
                    <div style="padding: 30px 25px;">
                        <div style="
                            background: #fff3cd;
                            border: 2px solid #ffeaa7;
                            border-radius: 8px;
                            padding: 20px;
                            margin-bottom: 25px;
                            text-align: center;
                        ">
                            <strong style="color: #856404; font-size: 16px; display: block; margin-bottom: 10px;">
                                ACHTUNG: Alle bestehenden Fahrpläne werden gelöscht!
                            </strong>
                            <p style="color: #856404; margin: 0; line-height: 1.5;">
                                Vor dem Scannen des Ordners <strong>"${folder}"</strong> wird die komplette 
                                Fahrplan-Datenbank geleert. Alle vorhandenen Einträge gehen unwiderruflich verloren.
                            </p>
                        </div>
                        
                        <div style="
                            background: #e3f2fd;
                            border: 2px solid #90caf9;
                            border-radius: 8px;
                            padding: 15px;
                            margin-bottom: 25px;
                        ">
                            <p style="color: #1565c0; margin: 0; font-size: 14px; line-height: 1.4;">
                                <strong>📊 Ablauf:</strong><br>
                                1. Alle bestehenden Fahrplan-Einträge löschen<br>
                                2. Ordner "${folder}" scannen und neue Daten importieren<br>
                                3. Datenbank enthält nur noch die neuen Scan-Ergebnisse
                            </p>
                        </div>
                        
                        <p style="
                            text-align: center;
                            font-size: 16px;
                            color: #333;
                            margin: 0 0 20px 0;
                            font-weight: 500;
                        ">
                            Möchten Sie wirklich fortfahren?
                        </p>
                    </div>
                    
                    <!-- Footer -->
                    <div style="
                        padding: 20px 25px;
                        background: #f8f9fa;
                        border-top: 1px solid #dee2e6;
                        display: flex;
                        justify-content: center;
                        gap: 15px;
                    ">
                        <button id="confirm-db-clear-cancel" style="
                            background: #6c757d;
                            color: white;
                            border: none;
                            padding: 12px 25px;
                            border-radius: 6px;
                            font-size: 14px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s ease;
                            min-width: 120px;
                        ">
                            ❌ Abbrechen
                        </button>
                        <button id="confirm-db-clear-proceed" style="
                            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                            color: white;
                            border: none;
                            padding: 12px 25px;
                            border-radius: 6px;
                            font-size: 14px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s ease;
                            min-width: 120px;
                            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
                        ">
                            🗑️ Löschen & Scannen
                        </button>
                    </div>
                </div>
            </div>
            
            <style>
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideIn {
                from { 
                    opacity: 0; 
                    transform: translateY(-50px) scale(0.9); 
                }
                to { 
                    opacity: 1; 
                    transform: translateY(0) scale(1); 
                }
            }
            #confirm-db-clear-cancel:hover {
                background: #5a6268 !important;
                transform: translateY(-2px);
            }
            #confirm-db-clear-proceed:hover {
                background: linear-gradient(135deg, #c82333 0%, #a02622 100%) !important;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(220, 53, 69, 0.4) !important;
            }
            </style>
        `;
        
        // Dialog zum Body hinzufügen
        $('body').append(confirmationHtml);
        
        // Event Handler für Buttons
        $('#confirm-db-clear-cancel').on('click', function() {
            hideDatabaseClearConfirmation();
            resetScanButton();
            console.log('🚫 FAHRPLANPORTAL: DB-Bereinigung abgebrochen vom Benutzer');
        });
        
        $('#confirm-db-clear-proceed').on('click', function() {
            hideDatabaseClearConfirmation();
            confirmAndClearDatabase(folder);
            console.log('✅ FAHRPLANPORTAL: DB-Bereinigung bestätigt, starte Prozess');
        });
        
        // ESC-Taste zum Abbrechen
        $(document).on('keydown.db-clear-confirmation', function(e) {
            if (e.key === 'Escape') {
                $('#confirm-db-clear-cancel').click();
            }
        });
    }
    
    /**
     * ✅ NEU: Bestätigungsdialog verstecken
     */
    function hideDatabaseClearConfirmation() {
        $('#db-clear-confirmation').fadeOut(300, function() {
            $(this).remove();
        });
        $(document).off('keydown.db-clear-confirmation');
    }
    
    /**
     * ✅ NEU: Datenbank bereinigen und dann scannen
     */
    function confirmAndClearDatabase(folder) {
        var button = $('#scan-directory');
        var status = $('#scan-status');
        
        // Button deaktivieren und Status anzeigen
        button.prop('disabled', true).text('Bereinige Datenbank...');
        status.html('<span style="color: orange;">🗑️ Lösche alle bestehenden Fahrpläne...</span>');
        
        console.log('🗑️ FAHRPLANPORTAL: Starte Datenbank-Bereinigung vor Scan');
        
        // Datenbank leeren
        fahrplanAdminCall('clear_db', {}, {
            success: function(response) {
                console.log('✅ FAHRPLANPORTAL: Datenbank erfolgreich bereinigt');
                status.html('<span style="color: green;">✅ Datenbank bereinigt - starte Scanning...</span>');
                
                // Kurze Pause für Benutzer-Feedback, dann Scanning starten
                setTimeout(function() {
                    proceedWithScanning(folder);
                }, 1000);
            },
            error: function(error) {
                console.error('❌ FAHRPLANPORTAL: Fehler beim Bereinigen der Datenbank:', error);
                status.html('<span style="color: red;">❌ Fehler beim Bereinigen: ' + error.message + '</span>');
                resetScanButton();
                
                // Fehler-Dialog anzeigen
                alert('Fehler beim Bereinigen der Datenbank:\n\n' + error.message + '\n\nScannen wurde abgebrochen.');
            }
        });
    }
    
    /**
     * ✅ ERWEITERT: Procced with Scanning mit sicherer Initialisierung
     */
    function proceedWithScanning(folder) {
        var button = $('#scan-directory');
        var status = $('#scan-status');
        
        console.log('🚀 FAHRPLANPORTAL: Beginne Chunked Scanning nach DB-Bereinigung für Ordner:', folder);
        
        // Button-Text aktualisieren
        button.text('Bereite Scanning vor...');
        status.html('<span style="color: blue;">📊 Sammle Scan-Informationen...</span>');
        
        // ✅ State komplett neu initialisieren
        chunkingScanState = {
            active: true,
            folder: folder,
            totalChunks: 0,
            currentChunk: 0,
            totalFiles: 0,
            totalStats: {
                imported: 0,
                skipped: 0,
                errors: 0,
                processed: 0
            },
            regionStats: {},
            startTime: Date.now(),
            cancelled: false,
            databaseCleared: true,
            
            // ✅ SICHER: Arrays direkt initialisieren
            errorDetails: [],
            errorsByRegion: {},
            errorsByType: {},
            currentChunkErrors: []
        };
        
        // ✅ ZUSÄTZLICHE SICHERHEIT: Explizit initialisieren
        initializeErrorCollection();
        
        // Scan-Informationen laden
        fahrplanAdminCall('get_scan_info', {folder: folder}, {
            success: function(scanInfo) {
                console.log('✅ FAHRPLANPORTAL: Scan-Info erhalten nach DB-Bereinigung:', scanInfo);
                
                chunkingScanState.totalChunks = scanInfo.total_chunks;
                chunkingScanState.totalFiles = scanInfo.total_files;
                
                // Status zurücksetzen
                status.html('');
                
                // Progress Bar anzeigen
                showProgressBar(scanInfo);
                
                // Zusätzliche Info dass DB bereinigt wurde
                updateScanActivity('🗑️ Datenbank erfolgreich bereinigt - alle alten Einträge entfernt');
                updateScanActivity('🚀 Starte Chunked Scanning für bereinigte Datenbank...');
                
                
                // Chunked Scanning starten
                processNextChunk();
            },
            error: function(error) {
                console.error('❌ FAHRPLANPORTAL: Fehler beim Laden der Scan-Info nach DB-Bereinigung:', error);
                status.html('<span style="color: red;">❌ Fehler beim Vorbereiten: ' + error.message + '</span>');
                resetScanButton();
            }
        });
    }
    
    /**
     * ✅ SICHER: Chunk-Verarbeitung mit Fehlerbehandlung
     */
    function processNextChunk() {
        if (chunkingScanState.cancelled) {
            console.log('🛑 FAHRPLANPORTAL: Chunked Scanning abgebrochen');
            return;
        }
        
        if (chunkingScanState.currentChunk >= chunkingScanState.totalChunks) {
            console.log('✅ FAHRPLANPORTAL: Alle Chunks verarbeitet');
            completeScan();
            return;
        }


        
        // ✅ SICHERHEIT: Fehlersammlung vor jedem Chunk prüfen
        initializeErrorCollection();
        
        var chunkIndex = chunkingScanState.currentChunk;
        var chunkSize = 10;
        
        console.log('🔄 FAHRPLANPORTAL: Verarbeite Chunk', chunkIndex + 1, 'von', chunkingScanState.totalChunks);
        
        // Chunk-Info aktualisieren
        $('#scan-current-chunk').text((chunkIndex + 1) + '/' + chunkingScanState.totalChunks);
        updateScanActivity('🔄 Verarbeite Chunk ' + (chunkIndex + 1) + '/' + chunkingScanState.totalChunks + '...');
        
        fahrplanAdminCall('scan_chunk', {
            folder: chunkingScanState.folder,
            chunk_index: chunkIndex,
            chunk_size: chunkSize
        }, {
            success: function(chunkResult) {
                console.log('✅ FAHRPLANPORTAL: Chunk', chunkIndex + 1, 'erfolgreich verarbeitet:', chunkResult);
                
                // Statistiken aktualisieren
                chunkingScanState.totalStats.imported += chunkResult.stats.imported || 0;
                chunkingScanState.totalStats.skipped += chunkResult.stats.skipped || 0;
                chunkingScanState.totalStats.errors += chunkResult.stats.errors || 0;
                chunkingScanState.totalStats.processed += chunkResult.stats.processed || 0;
                
                // ✅ SICHER: Fehler aus Server-Response sammeln (mit Try-Catch)
                try {
                    // Variante 1: Fehler direkt in Response
                    if (chunkResult.errors && Array.isArray(chunkResult.errors)) {
                        chunkResult.errors.forEach(function(error) {
                            addErrorToCollection(
                                error.message || error.error || 'Unbekannter Server-Fehler',
                                error.file || error.filename || 'Unbekannte Datei',
                                error.region || 'Unbekannte Region'
                            );
                        });
                    }
                    
                    // Variante 2: Fehler in Stats
                    if (chunkResult.stats && chunkResult.stats.error_details && Array.isArray(chunkResult.stats.error_details)) {
                        chunkResult.stats.error_details.forEach(function(errorDetail) {
                            addErrorToCollection(
                                errorDetail.error || 'Unbekannter Fehler',
                                errorDetail.file || 'Unbekannte Datei',
                                errorDetail.region || 'Unbekannte Region'
                            );
                        });
                    }
                    
                    // ✅ FALLBACK: Wenn Errors > 0 aber keine Details, generische Fehler erstellen
                    var reportedErrors = chunkResult.stats.errors || 0;
                    var collectedErrors = chunkingScanState.errorDetails.length;
                    
                    if (reportedErrors > 0 && collectedErrors === 0) {
                        console.warn('⚠️ FAHRPLANPORTAL: Server meldet ' + reportedErrors + ' Fehler, aber keine Details - erstelle generische Einträge');
                        
                        for (var i = 0; i < reportedErrors; i++) {
                            addErrorToCollection(
                                'Fehler beim Verarbeiten (Server-Details nicht verfügbar)',
                                'Chunk ' + (chunkIndex + 1) + ' - Datei #' + (i + 1),
                                'Unbekannte Region'
                            );
                        }
                    }
                    
                } catch (errorProcessingError) {
                    console.error('❌ FAHRPLANPORTAL: Fehler beim Verarbeiten der Server-Fehler:', errorProcessingError);
                    addErrorToCollection(
                        'Fehler beim Verarbeiten der Chunk-Antwort: ' + errorProcessingError.message,
                        'Chunk ' + (chunkIndex + 1),
                        'JavaScript-Fehler'
                    );
                }
                
                // Regionen-Statistiken zusammenführen
                if (chunkResult.stats.region_stats) {
                    for (var region in chunkResult.stats.region_stats) {
                        if (!chunkingScanState.regionStats[region]) {
                            chunkingScanState.regionStats[region] = 0;
                        }
                        chunkingScanState.regionStats[region] += chunkResult.stats.region_stats[region];
                    }
                }
                
                // Progress Bar aktualisieren
                updateProgressBar();
                
                // Aktivität aktualisieren
                if (chunkResult.stats.processed > 0) {
                    var regionList = [];
                    if (chunkResult.stats.region_stats) {
                        for (var region in chunkResult.stats.region_stats) {
                            regionList.push(region + ': ' + chunkResult.stats.region_stats[region] + ' PDFs');
                        }
                    }
                    
                    var activityMessage = '✅ Chunk ' + (chunkIndex + 1) + ' fertig';
                    if (regionList.length > 0) {
                        activityMessage += ': ' + regionList.join(', ');
                    }
                    updateScanActivity(activityMessage);
                    
                    // Fehler-Info für Chunk anzeigen
                    if (chunkResult.stats.errors > 0) {
                        updateScanActivity('⚠️ Chunk ' + (chunkIndex + 1) + ' hatte ' + chunkResult.stats.errors + ' Fehler');
                    }
                }
                
                // Nächsten Chunk verarbeiten
                chunkingScanState.currentChunk++;
                setTimeout(processNextChunk, 500);
            },
            error: function(error) {
                console.error('❌ FAHRPLANPORTAL: Chunk', chunkIndex + 1, 'fehlgeschlagen:', error);
                
                // ✅ SICHER: AJAX-Fehler sammeln
                try {
                    addErrorToCollection(
                        'Chunk-Verarbeitung fehlgeschlagen: ' + (error.message || error.responseText || 'Unbekannter AJAX-Fehler'),
                        'Chunk ' + (chunkIndex + 1),
                        'Server-Fehler'
                    );
                } catch (ajaxErrorCollectionError) {
                    console.error('❌ Konnte AJAX-Fehler nicht sammeln:', ajaxErrorCollectionError);
                }
                
                chunkingScanState.totalStats.errors++;
                updateProgressBar();
                updateScanActivity('❌ Chunk ' + (chunkIndex + 1) + ' fehlgeschlagen: ' + (error.message || 'Unbekannter Fehler'));
                
                // Trotzdem weitermachen mit nächstem Chunk
                chunkingScanState.currentChunk++;
                setTimeout(processNextChunk, 1000);
            }
        });
    }
    
    /**
     * ✅ ERWEITERT: Progress Bar anzeigen mit DB-Bereinigung Info
     */
    function showProgressBar(scanInfo) {
        // Normale Controls verstecken
        $('.fahrplan-controls').hide();
        
        // Progress Container anzeigen
        $('#scan-progress-container').show();
        
        // Initial-Werte setzen
        $('#scan-progress-text').text('0% (0/' + scanInfo.total_files + ' PDFs)');
        $('#scan-time-remaining').text('Geschätzte Zeit: ' + scanInfo.estimated_time.formatted);
        $('#scan-current-file').text('Bereite vor...');
        $('#scan-current-chunk').text('0/' + scanInfo.total_chunks);
        
        // ✅ ERWEITERT: Bereinigung-Info in Header
        var headerText = 'PDF-Scanning läuft...';
        if (chunkingScanState.databaseCleared) {
            headerText = 'PDF-Scanning läuft (Datenbank bereinigt)...';
        }
        $('#scan-progress-container h4').html(
            '<i class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite;"></i>' +
            headerText
        );
        
        // Regionen-Info hinzufügen
        var regionInfo = 'Gefundene Regionen: ';
        var regionList = [];
        for (var region in scanInfo.regions) {
            regionList.push(region + ' (' + scanInfo.regions[region] + ' PDFs)');
        }
        regionInfo += regionList.join(', ');
        
        updateScanActivity('📊 ' + regionInfo);
        
        if (scanInfo.parsing_enabled) {
            updateScanActivity('🔧 PDF-Parsing aktiviert - Tags werden automatisch extrahiert');
        } else {
            updateScanActivity('⚠️ PDF-Parsing nicht verfügbar - nur Metadaten werden gespeichert');
        }
        
        // ✅ NEU: Hinweis dass alle Daten neu sind
        if (chunkingScanState.databaseCleared) {
            updateScanActivity('💾 Alle Einträge werden als neue Daten importiert (keine Duplikat-Prüfung nötig)');
        }
    }
    
    /**
     * ✅ NEU: Progress Bar aktualisieren
     */
    function updateProgressBar() {
        var processed = chunkingScanState.totalStats.processed;
        var total = chunkingScanState.totalFiles;
        var percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
        
        // Progress Bar
        $('#scan-progress-bar').css('width', percentage + '%');
        
        // Progress Text
        $('#scan-progress-text').text(percentage + '% (' + processed + '/' + total + ' PDFs)');
        
        // Zeit-Schätzung
        var elapsed = Date.now() - chunkingScanState.startTime;
        var remaining = 0;
        
        if (processed > 0) {
            var avgTimePerFile = elapsed / processed;
            var remainingFiles = total - processed;
            remaining = Math.round(avgTimePerFile * remainingFiles / 1000);
        }
        
        if (remaining > 0) {
            $('#scan-time-remaining').text('Noch ca. ' + formatDuration(remaining));
        } else {
            $('#scan-time-remaining').text('Berechne...');
        }
        
        // Statistiken aktualisieren
        $('#scan-imported').text(chunkingScanState.totalStats.imported);
        $('#scan-skipped').text(chunkingScanState.totalStats.skipped);
        $('#scan-errors').text(chunkingScanState.totalStats.errors);
    }
    
    /**
     * ✅ NEU: Aktivität aktualisieren
     */
    function updateScanActivity(message) {
        var activityContainer = $('#scan-region-activity');
        var timestamp = new Date().toLocaleTimeString();
        
        var newEntry = '<div style="margin-bottom: 2px;">' +
                       '<span style="color: #666;">' + timestamp + '</span> ' +
                       '<span>' + message + '</span>' +
                       '</div>';
        
        activityContainer.prepend(newEntry);
        
        // Nur die letzten 20 Einträge behalten
        var entries = activityContainer.find('div');
        if (entries.length > 20) {
            entries.slice(20).remove();
        }
    }
    

    /**
     * ✅ ERWEITERT: Scan abschließen mit persistentem Fehlerprotokoll
     */
    function completeScan() {
        var stats = chunkingScanState.totalStats;
        var duration = Math.round((Date.now() - chunkingScanState.startTime) / 1000);
        
        console.log('🎉 FAHRPLANPORTAL: Chunked Scanning abgeschlossen:', stats);
        
        // Progress Bar auf 100%
        $('#scan-progress-bar').css('width', '100%');
        $('#scan-progress-text').text('100% (' + stats.processed + '/' + chunkingScanState.totalFiles + ' PDFs)');
        $('#scan-time-remaining').text('Abgeschlossen in ' + formatDuration(duration));
        
        // Erfolgs-Meldung
        var successMessage = '🎉 Chunked Scanning abgeschlossen! ';
        if (chunkingScanState.databaseCleared) {
            successMessage += '(Datenbank bereinigt) ';
        }
        successMessage += 'Importiert: ' + stats.imported + ', ' +
                         'Übersprungen: ' + stats.skipped + ', ' +
                         'Fehler: ' + stats.errors + ' ' +
                         '(Dauer: ' + formatDuration(duration) + ')';
        
        updateScanActivity(successMessage);
        
        if (chunkingScanState.databaseCleared) {
            updateScanActivity('💾 Datenbank-Zustand: Komplett neu aufgebaut mit ' + stats.imported + ' Einträgen');
        }
        
        // Regionen-Zusammenfassung
        var regionSummary = 'Regionen-Übersicht: ';
        var regionList = [];
        for (var region in chunkingScanState.regionStats) {
            regionList.push(region + ' (' + chunkingScanState.regionStats[region] + ')');
        }
        regionSummary += regionList.join(', ');
        updateScanActivity('📊 ' + regionSummary);
        
        // ✅ NEU: Fehlerprotokoll sammeln und anzeigen
        var errorSummary = collectErrorSummary();
        if (errorSummary.totalErrors > 0) {
            showErrorProtocol(errorSummary);
        }
        
        // Progress Bar zu Erfolg ändern
        $('#scan-progress-bar').removeClass('progress-bar-striped progress-bar-animated')
                               .css('background', 'linear-gradient(90deg, #46b450, #2e7d32)');
        
        // Cancel-Button zu "Fertig" ändern
        $('#scan-cancel').removeClass('button-secondary')
                         .addClass('button-primary')
                         .css({
                             'background': '#46b450',
                             'border-color': '#46b450',
                             'color': 'white'
                         })
                         .text('✅ Fertig');
        
        chunkingScanState.active = false;
        
        // ✅ GEÄNDERT: Nur bei Erfolg ohne Fehler automatisch neu laden
        if (stats.imported > 0 && stats.errors === 0) {
            setTimeout(function() {
                updateScanActivity('🔄 Seite wird in 5 Sekunden neu geladen...');
                setTimeout(function() {
                    location.reload();
                }, 5000); // ✅ LÄNGERE WARTEZEIT für Fehlerprotokoll
            }, 3000);
        } else if (stats.errors > 0) {
            // ✅ NEU: Bei Fehlern NICHT automatisch neu laden
            updateScanActivity('⚠️ Import mit Fehlern abgeschlossen - Seite wird NICHT automatisch neu geladen');
            updateScanActivity('📋 Überprüfen Sie das Fehlerprotokoll und laden Sie die Seite manuell neu');
        }
    }
    
    /**
     * ✅ NEU: Scanning abbrechen
     */
    function cancelScanning() {
        if (!chunkingScanState.active) {
            // Wenn nicht aktiv, Progress Bar verstecken
            hideProgressBar();
            return;
        }
        
        console.log('🛑 FAHRPLANPORTAL: Benutzer bricht Chunked Scanning ab');
        
        chunkingScanState.cancelled = true;
        chunkingScanState.active = false;
        
        // Progress Bar zu Abbruch ändern
        $('#scan-progress-bar').removeClass('progress-bar-striped progress-bar-animated')
                               .css('background', 'linear-gradient(90deg, #dc3232, #a02622)');
        
        updateScanActivity('🛑 Scanning vom Benutzer abgebrochen');
        
        // Cancel-Button zu "Schließen" ändern
        $('#scan-cancel').text('❌ Schließen');
        
        // Nach 2 Sekunden Progress Bar verstecken
        setTimeout(hideProgressBar, 2000);
    }
    
    /**
     * ✅ NEU: Progress Bar verstecken
     */
    function hideProgressBar() {
        $('#scan-progress-container').hide();
        $('.fahrplan-controls').show();
        resetScanButton();
    }
    
    /**
     * ✅ NEU: Scan-Button zurücksetzen
     */
    function resetScanButton() {
        $('#scan-directory').prop('disabled', false).text('Verzeichnis scannen');
        $('#scan-status').html('');
    }
    
    /**
     * ✅ NEU: Dauer formatieren
     */
    function formatDuration(seconds) {
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
    }
    
    // ========================================
    // DATATABLES INITIALISIERUNG (ADMIN)
    // ========================================
    initAdminDataTables();
    
    function initAdminDataTables() {
        if (!$('#fahrplaene-table').length) {
            console.log('FAHRPLANPORTAL: Keine Admin-Tabelle gefunden');
            return;
        }
        
        var hasData = $('#fahrplaene-table tbody tr[data-id]').length > 0;
        
        if (!hasData) {
            console.log('FAHRPLANPORTAL: Keine Daten vorhanden, DataTables übersprungen');
            return;
        }
        
        // Custom Sorting für deutsches Datumsformat
        $.fn.dataTable.ext.type.order['date-de-pre'] = function(data) {
            if (!data || data === '') return 0;
            
            var parts = data.split('.');
            if (parts.length === 3) {
                return parts[2] + parts[1].padStart(2, '0') + parts[0].padStart(2, '0');
            }
            return 0;
        };
        
        try {
            var tagsColumnIndex = pdfParsingEnabled ? 10 : -1;
            var actionsColumnIndex = pdfParsingEnabled ? 11 : 10;
            
            console.log('FAHRPLANPORTAL: Admin DataTables - Tags-Index:', tagsColumnIndex, 'Aktionen-Index:', actionsColumnIndex);
            
            var datatableConfig = {
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/German.json"
                },
                "responsive": true,
                "lengthMenu": [ 10, 25, 50, 200, -1 ],
                "pageLength": 200,
                "order": [[ 7, "asc" ]],
                "scrollX": false,
                "columnDefs": [
                    { "orderable": false, "targets": [actionsColumnIndex] },
                    { "width": "50px", "targets": [0] },
                    { "width": "80px", "targets": [1, 2] },
                    { "width": "auto", "targets": [3] },
                    { 
                        "width": "90px", 
                        "targets": [4, 5],
                        "type": "date-de"
                    },
                    { "width": "70px", "targets": [6] },
                    { "width": "100px", "targets": [7] },
                    { "width": "50px", "targets": [8] },
                    { "width": "150px", "targets": [9] },
                    { "width": "120px", "targets": [actionsColumnIndex] }
                ],
                "initComplete": function() {
                    populateAdminRegionFilter();
                    
                    if (pdfParsingEnabled) {
                        addAdminTagTooltips();
                    }
                    
                    console.log('✅ FAHRPLANPORTAL: Admin DataTables initialisiert');
                },
                "drawCallback": function() {
                    if (pdfParsingEnabled) {
                        addAdminTagTooltips();
                    }
                }
            };
            
            // Tags-Spalten-Konfiguration für Admin
            if (pdfParsingEnabled && tagsColumnIndex >= 0) {
                datatableConfig.columnDefs.push({
                    "width": "200px", 
                    "targets": [tagsColumnIndex],
                    "orderable": true,
                    "searchable": true,
                    "render": function(data, type, row) {
                        if (type === 'display') {
                            return data;
                        }
                        // FIX: Verwende jQuery-Objekt mit HTML-String korrekt
                        try {
                            var tempDiv = document.createElement('div');
                            tempDiv.innerHTML = data;
                            return tempDiv.textContent || tempDiv.innerText || '';
                        } catch(e) {
                            return data;
                        }
                    }
                });
            }
            
            var fahrplaeneTable = $('#fahrplaene-table').DataTable(datatableConfig);
            
            setupAdminRegionFilter(fahrplaeneTable);
            
        } catch (error) {
            console.error('FAHRPLANPORTAL: Admin DataTables Fehler:', error);
        }
    }
    
    function addAdminTagTooltips() {
        if (!pdfParsingEnabled) return;
        
        $('.simple-tags').each(function() {
            var $tag = $(this);
            // FIX: Sichere Text-Extraktion ohne jQuery-Parser
            var tagText = (this.textContent || this.innerText || '').trim();
            
            if (this.scrollWidth > this.clientWidth) {
                $tag.attr('title', tagText);
            }
        });
    }
    
    function populateAdminRegionFilter() {
        var regions = new Set();
        var regionFilter = $('#region-filter');
        
        $('#fahrplaene-table tbody tr').each(function() {
            var regionCell = $(this).find('td:nth-child(8)');
            var regionText = '';
            
            // FIX: Sichere Text-Extraktion
            if (regionCell.length > 0) {
                regionText = regionCell[0].textContent || regionCell[0].innerText || '';
                regionText = regionText.trim();
            }
            
            if (regionText && regionText !== '') {
                regions.add(regionText);
            }
        });
        
        var sortedRegions = Array.from(regions).sort();
        regionFilter.find('option:not(:first)').remove();
        
        sortedRegions.forEach(function(region) {
            regionFilter.append('<option value="' + region + '">' + region + '</option>');
        });
        
        console.log('✅ FAHRPLANPORTAL: Admin Region-Filter gefüllt mit:', sortedRegions);
    }
    
    function setupAdminRegionFilter(fahrplaeneTable) {
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            var selectedRegion = $('#region-filter').val();
            var regionColumn = data[7];
            
            if (!selectedRegion || selectedRegion === '') {
                return true;
            }
            
            return regionColumn === selectedRegion;
        });
        
        $('#region-filter').on('change', function() {
            var selectedRegion = $(this).val();
            console.log('FAHRPLANPORTAL: Admin Region-Filter geändert zu:', selectedRegion);
            
            if (fahrplaeneTable) {
                fahrplaeneTable.draw();
            }
            
            updateAdminFilterStatus(selectedRegion, fahrplaeneTable);
        });
        
        $('#clear-filter').on('click', function() {
            $('#region-filter').val('');
            if (fahrplaeneTable) {
                fahrplaeneTable.draw();
            }
            updateAdminFilterStatus('', fahrplaeneTable);
            console.log('FAHRPLANPORTAL: Admin Region-Filter zurückgesetzt');
        });
    }
    
    function updateAdminFilterStatus(selectedRegion, fahrplaeneTable) {
        var statusText = '';
        if (selectedRegion) {
            var filteredCount = fahrplaeneTable ? fahrplaeneTable.rows({search: 'applied'}).count() : 0;
            statusText = ' (gefiltert nach: ' + selectedRegion + ' - ' + filteredCount + ' Einträge)';
        }
        
        $('#filter-status').remove();
        $('.dataTables_info').append('<span id="filter-status">' + statusText + '</span>');
    }
    
    // ========================================
    // ADMIN SCAN-FUNKTIONALITÄT (ERWEITERT)
    // ========================================
    $('#scan-directory').on('click', function() {
        console.log('FAHRPLANPORTAL: Admin Scan-Button geklickt');
        
        // ✅ ERWEITERT: Chunked Scanning mit DB-Bereinigung verwenden
        startChunkedScanning();
    });
    
    // ✅ NEU: Cancel-Button für Chunked Scanning
    $('#scan-cancel').on('click', function() {
        console.log('FAHRPLANPORTAL: Cancel-Button geklickt');
        cancelScanning();
    });
    
    // ========================================
    // ADMIN MODAL-FUNKTIONALITÄT
    // ========================================
    function openAdminEditModal(fahrplanId) {
        console.log('FAHRPLANPORTAL: Öffne Admin Modal für ID:', fahrplanId);
        
        $('#fahrplan-edit-modal').fadeIn(300);
        
        fahrplanAdminCall('get_fahrplan', {id: fahrplanId}, {
            success: function(fahrplan) {
                $('#edit-id').val(fahrplan.id);
                $('#edit-titel').val(fahrplan.titel);
                $('#edit-linie-alt').val(fahrplan.linie_alt);
                $('#edit-linie-neu').val(fahrplan.linie_neu);
                $('#edit-kurzbeschreibung').val(fahrplan.kurzbeschreibung);
                $('#edit-gueltig-von').val(fahrplan.gueltig_von);
                $('#edit-gueltig-bis').val(fahrplan.gueltig_bis);
                $('#edit-region').val(fahrplan.region);
                
                if (pdfParsingEnabled && $('#edit-tags').length) {
                    $('#edit-tags').val(fahrplan.tags || '');
                }
                
                setTimeout(function() {
                    $('#edit-titel').focus();
                }, 100);
                
                console.log('FAHRPLANPORTAL: Admin Modal-Daten geladen');
            },
            error: function(error) {
                console.error('FAHRPLANPORTAL: Admin Fehler beim Laden:', error);
                alert('Admin Fehler beim Laden der Fahrplan-Daten: ' + error.message);
                closeAdminEditModal();
            }
        });
    }
    
    function closeAdminEditModal() {
        $('#fahrplan-edit-modal').fadeOut(300);
        $('#fahrplan-edit-form')[0].reset();
        $('#edit-id').val('');
        console.log('FAHRPLANPORTAL: Admin Modal geschlossen');
    }
    
    function saveAdminModalChanges() {
        var id = $('#edit-id').val();
        var saveButton = $('#save-edit-btn');
        
        if (!id) {
            alert('Admin Fehler: Keine ID gefunden');
            return;
        }
        
        saveButton.prop('disabled', true).text('Speichern...');
        
        var formData = {
            id: id,
            titel: $('#edit-titel').val(),
            linie_alt: $('#edit-linie-alt').val(),
            linie_neu: $('#edit-linie-neu').val(),
            kurzbeschreibung: $('#edit-kurzbeschreibung').val(),
            gueltig_von: $('#edit-gueltig-von').val(),
            gueltig_bis: $('#edit-gueltig-bis').val(),
            region: $('#edit-region').val()
        };
        
        if (pdfParsingEnabled && $('#edit-tags').length) {
            formData.tags = $('#edit-tags').val();
        }
        
        console.log('FAHRPLANPORTAL: Admin speichere Änderungen für ID:', id);
        
        fahrplanAdminCall('update_fahrplan', formData, {
            success: function(response) {
                console.log('FAHRPLANPORTAL: Admin Speichern erfolgreich');
                
                saveButton.text('✓ Gespeichert').addClass('success');
                
                setTimeout(function() {
                    closeAdminEditModal();
                    location.reload();
                }, 1000);
            },
            error: function(error) {
                console.error('FAHRPLANPORTAL: Admin Speichern fehlgeschlagen:', error);
                alert('Admin Fehler beim Speichern: ' + error.message);
                saveButton.prop('disabled', false).text('Speichern');
            }
        });
    }
    
    
    // ========================================
    // ✅ TAG-ANALYSE EVENT-HANDLER (GEFIXT)
    // ========================================
    $('#analyze-all-tags').on('click', function() {
        var $btn = $(this);
        var $status = $('#tag-analysis-status');
        
        // Button deaktivieren und Status anzeigen
        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite; vertical-align: middle; margin-right: 5px;"></span>' +
            'Analysiere Tags...'
        );
        $status.html('<span style="color: orange;">🔄 Sammle alle Tags aus der Datenbank...</span>');
        
        // Ergebnisse-Container verstecken
        $('#tag-analysis-results').hide();
        
        console.log('🔍 FAHRPLANPORTAL: Starte Tag-Analyse');
        
        fahrplanAdminCall('analyze_all_tags', {}, {
            success: function(response) {
                console.log('✅ FAHRPLANPORTAL: Tag-Analyse erfolgreich:', response);
                
                // Button zurücksetzen
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-search" style="vertical-align: middle; margin-right: 5px;"></span>' +
                    'Alle Tags analysieren'
                );
                
                // ✅ GEFIXT: Response ist bereits im korrekten Format
                if (!response || (!response.statistics && !response.analysis)) {
                    console.error('❌ FAHRPLANPORTAL: Ungültige Response-Struktur:', response);
                    $status.html('<span style="color: red;">❌ Fehler: Ungültige Datenstruktur</span>');
                    return;
                }
                
                // Erfolgs-Status anzeigen
                var totalTags = (response.statistics && response.statistics.total_unique_tags) || 0;
                $status.html('<span style="color: green;">✅ Analyse abgeschlossen (' + totalTags + ' eindeutige Tags)</span>');
                
                // ✅ GEFIXT: Direkt response übergeben, NICHT in data wrappen
                displayTagAnalysisResults({ data: response });
                
                // Nach 5 Sekunden Status leeren
                setTimeout(function() {
                    $status.html('');
                }, 5000);
            },
            error: function(error) {
                console.error('❌ FAHRPLANPORTAL: Tag-Analyse fehlgeschlagen:', error);
                
                // Button zurücksetzen
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-search" style="vertical-align: middle; margin-right: 5px;"></span>' +
                    'Alle Tags analysieren'
                );
                
                // Detaillierte Fehlerbehandlung
                var errorMessage = 'Unbekannter Fehler';
                if (error && error.message) {
                    errorMessage = error.message;
                } else if (typeof error === 'string') {
                    errorMessage = error;
                }
                
                // Fehler-Status anzeigen
                $status.html('<span style="color: red;">❌ Fehler: ' + errorMessage + '</span>');
                
                // Benutzerfreundliche Fehlermeldung
                alert('Fehler bei der Tag-Analyse:\n\n' + errorMessage + '\n\nPrüfen Sie die Browser-Konsole für weitere Details.');
            }
        });
    });

    // ========================================
    // ✅ GEÄNDERT: ADMIN EVENT-HANDLER - LINIE NEU EDITIERBAR
    // ========================================
    
    // Bearbeiten-Button in Admin-Tabelle
    $('#fahrplaene-table').on('click', '.edit-fahrplan', function() {
        var fahrplanId = $(this).data('id');
        openAdminEditModal(fahrplanId);
    });
    
    // ✅ GEÄNDERT: Klickbare Linie NEU (Spalte 3) statt Linie ALT (Spalte 2) in Admin
    $('#fahrplaene-table').on('click', 'td:nth-child(3)', function() {
        var lineText = $(this).text().trim();
        if (lineText && lineText !== '') {
            var fahrplanId = $(this).closest('tr').data('id');
            
            if (fahrplanId) {
                console.log('FAHRPLANPORTAL: Admin Linie NEU geklickt - ID:', fahrplanId, 'Linie:', lineText);
                openAdminEditModal(fahrplanId);
            } else {
                console.warn('FAHRPLANPORTAL: Admin - Keine ID in Tabellenzeile gefunden');
            }
        }
    });
    
    // ✅ GEÄNDERT: Hover-Effekt für Linie NEU (Spalte 3) statt Linie ALT (Spalte 2) in Admin
    $('#fahrplaene-table').on('mouseenter', 'td:nth-child(3)', function() {
        var lineText = $(this).text().trim();
        if (lineText && lineText !== '') {
            $(this).attr('title', 'Klicken um Fahrplan zu bearbeiten');
            $(this).css('cursor', 'pointer');
        }
    });
    
    // ✅ NEU: Linie ALT (Spalte 2) nicht mehr klickbar - normaler Cursor
    $('#fahrplaene-table').on('mouseenter', 'td:nth-child(2)', function() {
        $(this).css('cursor', 'default');
        $(this).removeAttr('title');
    });
    
    // Admin Modal Event-Handler
    $('#close-modal-btn').on('click', function() {
        closeAdminEditModal();
    });
    
    $('#cancel-edit-btn').on('click', function() {
        closeAdminEditModal();
    });
    
    $('#save-edit-btn').on('click', function() {
        saveAdminModalChanges();
    });
    
    $('#fahrplan-edit-modal').on('click', function(e) {
        if (e.target === this) {
            closeAdminEditModal();
        }
    });
    
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            if ($('#fahrplan-edit-modal').is(':visible')) {
                closeAdminEditModal();
            } else if (chunkingScanState.active) {
                // ESC-Taste auch für Scan-Abbruch
                cancelScanning();
            }
        }
    });
    
    // Admin Fahrplan löschen
    $('#fahrplaene-table').on('click', '.delete-fahrplan', function() {
        if (!confirm('Admin: Fahrplan wirklich löschen?')) {
            return;
        }
        
        var row = $(this).closest('tr');
        var id = $(this).data('id');
        
                        fahrplanAdminCall('delete_fahrplan', {id: id}, {
            success: function(response) {
                var fahrplaeneTable = $('#fahrplaene-table').DataTable();
                if (fahrplaeneTable) {
                    fahrplaeneTable.row(row).remove().draw();
                } else {
                    row.remove();
                }
                console.log('FAHRPLANPORTAL: Admin Fahrplan gelöscht, ID:', id);
            },
            error: function(error) {
                alert('Admin Fehler beim Löschen: ' + error.message);
            }
        });
    });
    
    // ========================================
    // ADMIN DB-WARTUNG FUNKTIONALITÄT
    // ========================================
    
    // Standard-Exklusionsliste
    var defaultExclusions = 'aber alle allem allen aller alles also auch auf aus bei bin bis bist dass den der des die dies doch dort durch ein eine einem einen einer eines für hab hat hier ich ihr ihre ihrem ihren ihrer ihres ist mit nach nicht noch nur oder sich sie sind über und uns von war wird wir zu zum zur wie was wo wer wann warum welche welcher welches wenn schon noch sehr mehr weniger groß klein gut schlecht neu alt lang kurz hoch niedrig\n\nfahrplan fahrpläne fahrt fahrten zug züge bus busse bahn bahnen haltestelle haltestellen bahnhof bahnhöfe station stationen linie linien route routen verkehr abfahrt abfahrten ankunft ankünfte uhrzeit uhrzeiten\n\nmontag dienstag mittwoch donnerstag freitag samstag sonntag wochentag wochentage wochenende\n\nheute gestern morgen übermorgen vorgestern täglich wöchentlich monatlich jährlich\n\njahr jahre jahren zeit zeiten mal male heute gestern morgen immer nie oft selten manchmal immer wieder\n\nauto autos wagen fahrzeug fahrzeuge transport transporte reise reisen weg wege straße straßen';
    
    // Beispiel-Mapping
    var exampleMapping = '// Beispiel Linien-Mapping: linie_alt:linie_neu\n// Kärnten Linien Beispiele:\n5000:100\n5001:101\n5002:102\n5003:103\n5004:104\n5005:105\n5006:106\n5007:107\n5008:108\n5009:109\n5010:110\n\n// Regionale Linien:\n5020:120\n5021:121\n5022:122\n5025:125\n\n// Stadtlinien:\n5100:200\n5101:201\n5102:202\n\n// Weitere Zuordnungen:\n5402:502\n5403:503\n5404:504';
    
    // Admin Exklusionsliste speichern
    $('#save-exclusion-words').on('click', function() {
        var $btn = $(this);
        var $status = $('#exclusion-status');
        var exclusionText = $('#exclusion-words').val();
        
        $btn.prop('disabled', true);
        $status.html('<span style="color: orange;">Admin speichert...</span>');
        
        fahrplanAdminCall('save_exclusion_words', {exclusion_words: exclusionText}, {
            success: function(response) {
                $status.html('<span style="color: green;">✓ Admin gespeichert (' + response.word_count + ' Wörter)</span>');
                setTimeout(function() {
                    $status.html('');
                }, 3000);
            },
            error: function(error) {
                $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });
    
    // Admin Exklusionsliste laden
    $('#load-exclusion-words').on('click', function() {
        var $btn = $(this);
        var $status = $('#exclusion-status');
        
        $btn.prop('disabled', true);
        $status.html('<span style="color: orange;">Admin lädt...</span>');
        
        fahrplanAdminCall('load_exclusion_words', {}, {
            success: function(response) {
                $('#exclusion-words').val(response.exclusion_words);
                $status.html('<span style="color: green;">✓ Admin geladen (' + response.word_count + ' Wörter)</span>');
                setTimeout(function() {
                    $status.html('');
                }, 3000);
            },
            error: function(error) {
                $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });
    
    // Standard-Exklusionsliste laden
    $('#load-default-exclusions').on('click', function() {
        var currentText = $('#exclusion-words').val();
        var newText = currentText.trim();
        
        if (newText.length > 0) {
            newText += '\n\n';
        }
        newText += defaultExclusions;
        
        $('#exclusion-words').val(newText);
        
        $('#exclusion-status').html('<span style="color: blue;">ℹ Admin Standard-Wörter hinzugefügt. Klicken Sie "Speichern" um zu übernehmen.</span>');
        setTimeout(function() {
            $('#exclusion-status').html('');
        }, 5000);
    });
    
    // Admin Linien-Mapping speichern
    $('#save-line-mapping').on('click', function() {
        var $btn = $(this);
        var $status = $('#mapping-status');
        var mappingText = $('#line-mapping').val();
        
        $btn.prop('disabled', true);
        $status.html('<span style="color: orange;">Admin speichert...</span>');
        
        fahrplanAdminCall('save_line_mapping', {line_mapping: mappingText}, {
            success: function(response) {
                $status.html('<span style="color: green;">✓ Admin gespeichert (' + response.mapping_count + ' Zuordnungen)</span>');
                setTimeout(function() {
                    $status.html('');
                }, 3000);
            },
            error: function(error) {
                $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });
    
    // Admin Linien-Mapping laden
    $('#load-line-mapping').on('click', function() {
        var $btn = $(this);
        var $status = $('#mapping-status');
        
        $btn.prop('disabled', true);
        $status.html('<span style="color: orange;">Admin lädt...</span>');
        
        fahrplanAdminCall('load_line_mapping', {}, {
            success: function(response) {
                $('#line-mapping').val(response.line_mapping);
                $status.html('<span style="color: green;">✓ Admin geladen (' + response.mapping_count + ' Zuordnungen)</span>');
                setTimeout(function() {
                    $status.html('');
                }, 3000);
            },
            error: function(error) {
                $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });
    
    // Beispiel-Mapping laden
    $('#load-example-mapping').on('click', function() {
        var currentText = $('#line-mapping').val();
        var newText = currentText.trim();
        
        if (newText.length > 0) {
            newText += '\n\n';
        }
        newText += exampleMapping;
        
        $('#line-mapping').val(newText);
        
        $('#mapping-status').html('<span style="color: blue;">ℹ Admin Beispiel-Zuordnungen hinzugefügt. Klicken Sie "Speichern" um zu übernehmen.</span>');
        setTimeout(function() {
            $('#mapping-status').html('');
        }, 5000);
    });
    
    // Admin DB-Wartung Buttons
    $('#recreate-db').on('click', function() {
        if (!confirm('Admin: Wirklich die komplette Datenbank neu erstellen? Alle Daten gehen verloren!')) {
            return;
        }
        
        fahrplanAdminCall('recreate_db', {}, {
            success: function(response) {
                alert('Admin: Datenbank erfolgreich neu erstellt');
                location.reload();
            },
            error: function(error) {
                alert('Admin Fehler: ' + error.message);
            }
        });
    });
    
    $('#clear-db').on('click', function() {
        if (!confirm('Admin: Wirklich alle Fahrpläne löschen?')) {
            return;
        }
        
        fahrplanAdminCall('clear_db', {}, {
            success: function(response) {
                alert('Admin: Alle Fahrpläne erfolgreich gelöscht');
                location.reload();
            },
            error: function(error) {
                alert('Admin Fehler: ' + error.message);
            }
        });
    });

    /**
     * ✅ NEU: Fehlerprotokoll sammeln
     */
    function collectErrorSummary() {
        console.log('📊 FAHRPLANPORTAL: Sammle Fehlerprotokoll:', chunkingScanState.errorDetails);
        
        var errorSummary = {
            totalErrors: chunkingScanState.errorDetails.length,
            errorDetails: chunkingScanState.errorDetails,
            errorsByRegion: chunkingScanState.errorsByRegion,
            errorsByType: chunkingScanState.errorsByType
        };
        
        // Fallback: Falls keine detaillierten Fehler, aber Fehler-Counter > 0
        if (errorSummary.totalErrors === 0 && chunkingScanState.totalStats.errors > 0) {
            console.warn('⚠️ FAHRPLANPORTAL: Fehler-Counter zeigt Fehler, aber keine Details gesammelt');
            
            // Generische Fehler-Einträge erstellen
            for (var i = 0; i < chunkingScanState.totalStats.errors; i++) {
                errorSummary.errorDetails.push({
                    timestamp: new Date().toISOString(),
                    file: 'Unbekannte Datei #' + (i + 1),
                    error: 'Fehler beim Verarbeiten (Details nicht verfügbar)',
                    region: 'Unbekannte Region',
                    chunk: 'Unbekannt'
                });
            }
            
            errorSummary.totalErrors = chunkingScanState.totalStats.errors;
            errorSummary.errorsByType['Unbekannte Fehler'] = chunkingScanState.totalStats.errors;
            errorSummary.errorsByRegion['Unbekannte Region'] = chunkingScanState.totalStats.errors;
        }
        
        console.log('📋 FAHRPLANPORTAL: Finales Fehlerprotokoll:', errorSummary);
        return errorSummary;
    }

    /**
     * ✅ NEU: Fehlerprotokoll-Dialog anzeigen
     */
    function showErrorProtocol(errorSummary) {
        console.log('🚨 FAHRPLANPORTAL: Zeige Fehlerprotokoll an:', errorSummary);
        
        // Sicherstellen dass wir Daten haben
        if (!errorSummary.errorDetails || errorSummary.errorDetails.length === 0) {
            console.warn('⚠️ FAHRPLANPORTAL: Keine Fehler-Details zum Anzeigen');
            return;
        }
        
        var errorTypesHtml = '';
        if (Object.keys(errorSummary.errorsByType).length > 0) {
            errorTypesHtml = Object.entries(errorSummary.errorsByType)
                .map(([type, count]) => `<li><strong>${type}:</strong> ${count}</li>`)
                .join('');
        } else {
            errorTypesHtml = '<li>Keine Kategorisierung verfügbar</li>';
        }
        
        var errorRegionsHtml = '';
        if (Object.keys(errorSummary.errorsByRegion).length > 0) {
            errorRegionsHtml = Object.entries(errorSummary.errorsByRegion)
                .map(([region, count]) => `<li><strong>${region}:</strong> ${count}</li>`)
                .join('');
        } else {
            errorRegionsHtml = '<li>Keine Regions-Zuordnung verfügbar</li>';
        }
        
        var errorDetailsHtml = '';
        if (errorSummary.errorDetails.length > 0) {
            errorDetailsHtml = errorSummary.errorDetails
                .map((error, index) => `
                    <div style="
                        padding: 10px 15px;
                        border-bottom: 1px solid #e9ecef;
                        ${index % 2 === 0 ? 'background: white;' : 'background: #f8f9fa;'}
                    ">
                        <div style="margin-bottom: 5px;">
                            <strong style="color: #dc3545;">📄 Datei:</strong> 
                            <span style="font-family: monospace; background: #f1f3f4; padding: 2px 4px; border-radius: 3px;">
                                ${error.file || 'Unbekannte Datei'}
                            </span>
                        </div>
                        <div style="margin-bottom: 5px;">
                            <strong style="color: #856404;">⚠️ Fehler:</strong> 
                            <span style="color: #721c24;">${error.error || 'Unbekannter Fehler'}</span>
                        </div>
                        ${error.region ? `
                            <div style="margin-bottom: 5px;">
                                <strong style="color: #0c5460;">🗺️ Region:</strong> 
                                <span style="color: #155724;">${error.region}</span>
                            </div>
                        ` : ''}
                        ${error.timestamp ? `
                            <div style="font-size: 11px; color: #6c757d;">
                                🕒 ${new Date(error.timestamp).toLocaleString('de-DE')}
                            </div>
                        ` : ''}
                    </div>
                `)
                .join('');
        } else {
            errorDetailsHtml = '<div style="padding: 20px; text-align: center; color: #6c757d;">Keine detaillierten Fehlerinformationen verfügbar</div>';
        }
        
        var errorDialogHtml = `
            <div id="error-protocol-dialog" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000000;
                animation: fadeIn 0.3s ease;
            ">
                <div style="
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
                    max-width: 900px;
                    width: 95%;
                    max-height: 90vh;
                    overflow: hidden;
                    animation: slideIn 0.4s ease;
                    border: 3px solid #dc3545;
                ">
                    <!-- Header -->
                    <div style="
                        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                        color: white;
                        padding: 20px 25px;
                        text-align: center;
                    ">
                        <div style="font-size: 42px; margin-bottom: 10px;">⚠️</div>
                        <h2 style="margin: 0; font-size: 20px; font-weight: 600;">
                            Import-Fehlerprotokoll
                        </h2>
                        <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;">
                            ${errorSummary.totalErrors} Fehler beim PDF-Import aufgetreten
                        </p>
                    </div>
                    
                    <!-- Body -->
                    <div style="padding: 25px; max-height: 60vh; overflow-y: auto;">
                        <!-- Fehler-Statistik -->
                        <div style="
                            background: #fff3cd;
                            border: 2px solid #ffc107;
                            border-radius: 8px;
                            padding: 15px;
                            margin-bottom: 20px;
                        ">
                            <h4 style="margin: 0 0 15px 0; color: #856404;">📊 Fehler-Übersicht:</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div>
                                    <strong style="color: #856404; display: block; margin-bottom: 8px;">Nach Fehlertyp:</strong>
                                    <ul style="margin: 0; padding-left: 20px; color: #856404; line-height: 1.4;">
                                        ${errorTypesHtml}
                                    </ul>
                                </div>
                                <div>
                                    <strong style="color: #856404; display: block; margin-bottom: 8px;">Nach Region:</strong>
                                    <ul style="margin: 0; padding-left: 20px; color: #856404; line-height: 1.4;">
                                        ${errorRegionsHtml}
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detaillierte Fehlerliste -->
                        <h4 style="margin: 0 0 15px 0; color: #dc3545;">🔍 Detaillierte Fehlerliste:</h4>
                        <div style="
                            background: #f8f9fa;
                            border: 2px solid #dee2e6;
                            border-radius: 8px;
                            max-height: 350px;
                            overflow-y: auto;
                            font-size: 13px;
                            line-height: 1.4;
                        ">
                            ${errorDetailsHtml}
                        </div>
                        
                        <!-- Hilfetext -->
                        <div style="
                            background: #e3f2fd;
                            border: 2px solid #2196f3;
                            border-radius: 8px;
                            padding: 15px;
                            margin-top: 20px;
                        ">
                            <h5 style="margin: 0 0 10px 0; color: #1565c0;">💡 Nächste Schritte:</h5>
                            <ul style="margin: 0; padding-left: 20px; color: #1565c0; line-height: 1.5;">
                                <li>Überprüfen Sie die fehlerhaften PDF-Dateien</li>
                                <li>Korrigieren Sie Dateinamen falls nötig</li>
                                <li>Prüfen Sie die Linien-Mapping Konfiguration</li>
                                <li>Führen Sie den Import erneut aus</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Footer -->
                    <div style="
                        padding: 20px 25px;
                        background: #f8f9fa;
                        border-top: 1px solid #dee2e6;
                        text-align: center;
                    ">
                        <button id="close-error-protocol" style="
                            background: #007bff;
                            color: white;
                            border: none;
                            padding: 12px 25px;
                            border-radius: 6px;
                            font-size: 14px;
                            font-weight: 600;
                            cursor: pointer;
                            min-width: 120px;
                            transition: all 0.2s ease;
                        ">
                            ✅ Verstanden
                        </button>
                    </div>
                </div>
            </div>
            
            <style>
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideIn {
                from { 
                    opacity: 0; 
                    transform: translateY(-50px) scale(0.9); 
                }
                to { 
                    opacity: 1; 
                    transform: translateY(0) scale(1); 
                }
            }
            #close-error-protocol:hover {
                background: #0056b3 !important;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
            }
            </style>
        `;
        
        // Dialog zum Body hinzufügen
        $('body').append(errorDialogHtml);
        
        // Close-Handler
        $('#close-error-protocol').on('click', function() {
            $('#error-protocol-dialog').fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // ESC-Taste
        $(document).on('keydown.error-protocol', function(e) {
            if (e.key === 'Escape') {
                $('#close-error-protocol').click();
                $(document).off('keydown.error-protocol');
            }
        });
    }

    // Bei Scan-Start Fehlersammlung zurücksetzen
    function resetErrorCollection() {
        chunkingScanState.errorDetails = [];
        chunkingScanState.errorsByType = {};
        chunkingScanState.errorsByRegion = {};
        chunkingScanState.currentChunkErrors = [];
        
        console.log('🔄 FAHRPLANPORTAL: Fehlersammlung zurückgesetzt');
    }
    
    // ✅ NEU: CSS für Editierbarkeit
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            /* Linie NEU editierbar - Hover-Effekt */
            #fahrplaene-table tbody td:nth-child(3) {
                cursor: pointer !important;
                transition: background-color 0.2s ease;
            }
            
            #fahrplaene-table tbody td:nth-child(3):hover {
                background-color: #e3f2fd !important;
                color: #1565c0 !important;
            }
            
            /* Linie ALT nicht editierbar - normaler Stil */
            #fahrplaene-table tbody td:nth-child(2) {
                cursor: default !important;
                color: #666 !important;
            }
            
            #fahrplaene-table tbody td:nth-child(2):hover {
                background-color: inherit !important;
                color: #666 !important;
            }
        `)
        .appendTo('head');
    
    console.log('✅ FAHRPLANPORTAL: Admin-Interface vollständig initialisiert (Chunked Scanning mit DB-Bereinigung + Linie NEU editierbar)');


    /**
     * ✅ HAUPTFUNKTION: Tag-Analyse Ergebnisse anzeigen (KOMPLETT GEFIXT)
     */
    function displayTagAnalysisResults(response) {
        console.log('📊 FAHRPLANPORTAL: Zeige Tag-Analyse Ergebnisse an:', response);
        
        // ✅ KRITISCHER FIX: Korrekte Datenstruktur prüfen
        if (!response || !response.data) {
            console.error('❌ FAHRPLANPORTAL: Ungültige Response-Struktur:', response);
            return;
        }
        
        var data = response.data;
        
        // ✅ GEFIXT: Sichere Navigation zur statistics und analysis
        var stats = data.statistics || {};
        var analysis = data.analysis || {};
        
        console.log('📊 FAHRPLANPORTAL: Extrahierte Stats:', stats);
        console.log('📊 FAHRPLANPORTAL: Extrahierte Analysis:', analysis);
        
        // ✅ Container anzeigen
        $('#tag-analysis-results').show();
        
        // ✅ Statistiken füllen (mit Fallback-Werten)
        var totalFahrplaene = stats.total_fahrplaene || 0;
        var totalUniqueTags = stats.total_unique_tags || 0;
        var excludedCount = stats.excluded_count || 0;
        var notExcludedCount = stats.not_excluded_count || 0;
        var exclusionPercentage = stats.exclusion_percentage || 0;
        var exclusionListSize = stats.exclusion_list_size || 0;
        var processingTime = data.processing_time || 0;
        
        var statsHtml = `
            <div style="text-align: center;">
                <strong style="font-size: 18px; color: #856404;">
                    📊 ${totalUniqueTags} eindeutige Tags aus ${totalFahrplaene} Fahrplänen
                </strong>
            </div>
            <div style="margin-top: 15px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <div style="text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #28a745;">
                        ${excludedCount}
                    </div>
                    <div style="font-size: 12px; color: #856404;">
                        Bereits ausgeschlossen (${exclusionPercentage}%)
                    </div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #dc3545;">
                        ${notExcludedCount}
                    </div>
                    <div style="font-size: 12px; color: #856404;">
                        Noch nicht ausgeschlossen
                    </div>
                </div>
            </div>
            <div style="margin-top: 15px; text-align: center; font-size: 12px; color: #6c757d;">
                Exklusionsliste: ${exclusionListSize} Wörter | 
                Verarbeitung: ${Math.round(processingTime * 1000)}ms
            </div>
        `;
        $('#tag-stats-content').html(statsHtml);
        
        // ✅ Bereits ausgeschlossene Tags (GRÜN) - aus analysis.excluded_tags
        var excludedHtml = '';
        var excludedTags = analysis.excluded_tags || [];
        var excludedTagsTotal = analysis.excluded_tags_total || excludedTags.length;
        
        if (excludedTags && excludedTags.length > 0) {
            // Einfache kommagetrennte Liste
            excludedHtml = '<div style="padding: 5px; line-height: 1.6; word-wrap: break-word;">' + 
                excludedTags.map(function(tag) {
                    return escapeHtml(tag);
                }).join(', ') + '</div>';
        } else if (excludedTagsTotal === 0) {
            excludedHtml = '<div style="text-align: center; color: #666; font-style: italic;">Keine Tags in der Exklusionsliste gefunden</div>';
        }
        $('#excluded-tags-list').html(excludedHtml);
        $('#excluded-tags-count').text(excludedTagsTotal);
        
        // ✅ Noch nicht ausgeschlossene Tags (ROT) - aus analysis.not_excluded_tags
        var notExcludedHtml = '';
        var notExcludedTags = analysis.not_excluded_tags || [];
        var notExcludedTagsTotal = analysis.not_excluded_tags_total || notExcludedTags.length;
        
        if (notExcludedTags && notExcludedTags.length > 0) {
            // Einfache kommagetrennte Liste
            notExcludedHtml = '<div style="padding: 5px; line-height: 1.6; word-wrap: break-word;">' + 
                notExcludedTags.map(function(tag) {
                    return escapeHtml(tag);
                }).join(', ') + '</div>';
        } else if (notExcludedTagsTotal === 0) {
            notExcludedHtml = '<div style="text-align: center; color: #666; font-style: italic;">Alle Tags sind bereits ausgeschlossen! 🎉</div>';
        }
        $('#not-excluded-tags-list').html(notExcludedHtml);
        $('#not-excluded-tags-count').text(notExcludedTagsTotal);
        
        // ✅ Variablen für Event-Handler verfügbar machen
        var _notExcludedTags = notExcludedTags;
        var _notExcludedTagsTotal = notExcludedTagsTotal;
        
        // ✅ Zusätzliche Analysen vorbereiten (mit sicherer Prüfung)
        if (analysis && analysis.top_frequent_tags) {
            // Top häufige Tags
            var frequentHtml = '';
            var counter = 1;
            for (var tag in analysis.top_frequent_tags) {
                var count = analysis.top_frequent_tags[tag];
                frequentHtml += `<div>${counter}. <strong>${escapeHtml(tag)}</strong> (${count}x)</div>`;
                counter++;
            }
            $('#frequent-tags-list').html(frequentHtml || '<div>Keine häufigen Tags gefunden</div>');
            
            // Kurze Tags
            var shortTags = analysis.short_tags || [];
            var shortHtml = shortTags.length > 0 ? 
                shortTags.map(tag => '<code>' + escapeHtml(tag) + '</code>').join(', ') : 
                'Keine kurzen Tags gefunden';
            $('#short-tags-list').html(shortHtml);
            
            // Lange Tags  
            var longTags = analysis.long_tags || [];
            var longHtml = longTags.length > 0 ? 
                longTags.map(tag => '<code>' + escapeHtml(tag) + '</code>').join(', ') : 
                'Keine langen Tags gefunden';
            $('#long-tags-list').html(longHtml);
            
            // Event-Handler für zusätzliche Analysen
            $('#show-analysis-extras').off('click').on('click', function() {
                var $extras = $('#tag-analysis-extras');
                if ($extras.is(':visible')) {
                    $extras.hide();
                    $(this).text('📊 Zusätzliche Analysen anzeigen');
                } else {
                    $extras.show();
                    $(this).text('📊 Zusätzliche Analysen ausblenden');
                }
            });
        }
        
        // ✅ Event-Handler für Aktions-Buttons
        $('#copy-red-tags').off('click').on('click', function() {
            // Nutze ALLE angezeigten Tags für die Kopier-Funktion
            if (_notExcludedTags && _notExcludedTags.length > 0) {
                // Für Kopieren nutzen wir Leerzeichen statt Komma als Trenner
                var tagsText = _notExcludedTags.join(', ');
                
                // Versuche in Zwischenablage zu kopieren
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(tagsText).then(function() {
                        var message = '✅ ' + _notExcludedTags.length + ' rote Tags in Zwischenablage kopiert!';
                        message += '\n\nSie können diese nun in die Exklusionsliste einfügen.';
                        alert(message);
                    }).catch(function(err) {
                        // Fallback
                        promptCopyText(tagsText, _notExcludedTags.length, _notExcludedTagsTotal);
                    });
                } else {
                    // Fallback für ältere Browser
                    promptCopyText(tagsText, _notExcludedTags.length, _notExcludedTagsTotal);
                }
            } else {
                alert('🎉 Keine roten Tags zum Kopieren - alle Tags sind bereits ausgeschlossen!');
            }
        });
        
        console.log('✅ FAHRPLANPORTAL: Tag-Analyse Ergebnisse vollständig angezeigt');
    }

    // Helper-Funktion für HTML-Escaping
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Fallback für Kopieren in Zwischenablage
    function promptCopyText(text, count, totalCount) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            var message = '✅ ' + count + ' rote Tags in Zwischenablage kopiert!';
            alert(message);
        } catch (err) {
            prompt('Bitte manuell kopieren (Strg+C):', text);
        }
        document.body.removeChild(textarea);
    }

}

    // ========================================
    // FALLBACK-FUNKTIONALITÄT (OHNE UNIFIED SYSTEM)
    // ========================================
    function initializeFahrplanportalFallback() {
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

    /**
     * ✅ ZUSÄTZLICHE DEBUG-FUNKTION: System-Status prüfen
     */
    function checkFahrplanSystemStatus() {
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
    }
    
    // ✅ Status beim Laden prüfen
    setTimeout(function() {
        checkFahrplanSystemStatus();
    }, 1000);

});