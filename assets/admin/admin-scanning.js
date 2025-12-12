/**
 * FahrplanPortal Admin - Scanning Module
 * 
 * Enthält:
 * - Chunked Scanning mit DB-Bereinigung
 * - Progress Bar
 * - Fehlersammlung und -protokoll
 * - Cancel-Funktionalität
 * 
 * @version 2.0.0
 * @requires admin-core.js
 */

jQuery(document).ready(function($) {
    
    // Warte auf Core-Modul
    $(document).on('fahrplanAdmin:ready', function() {
        console.log('📂 FAHRPLANPORTAL: Scanning-Modul wird initialisiert...');
        initScanningModule();
    });
    
    // Falls Core bereits initialisiert ist
    if (typeof FahrplanAdmin !== 'undefined' && FahrplanAdmin.initialized) {
        initScanningModule();
    }
    
    function initScanningModule() {
        
        // ========================================
        // SCAN-STATE
        // ========================================
        
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
            
            // Detaillierte Fehlersammlung
            errorDetails: [],
            errorsByType: {},
            errorsByRegion: {},
            currentChunkErrors: []
        };
        
        // Im Namespace verfügbar machen
        FahrplanAdmin.scanState = chunkingScanState;
        
        // ========================================
        // EVENT LISTENER
        // ========================================
        
        // ✅ DIREKT: Scan-Button Click-Handler
        $('#scan-directory').off('click').on('click', function() {
            console.log('🔘 FAHRPLANPORTAL: Scan-Button geklickt (Scanning-Modul)');
            startChunkedScanning();
        });
        
        // ✅ DIREKT: Cancel-Button Click-Handler
        $('#scan-cancel').off('click').on('click', function() {
            console.log('🔘 FAHRPLANPORTAL: Cancel-Button geklickt');
            cancelScanning();
        });
        
        // Auf Events reagieren (Fallback)
        $(document).on('fahrplanAdmin:startScan', function() {
            startChunkedScanning();
        });
        
        $(document).on('fahrplanAdmin:cancelScan', function() {
            cancelScanning();
        });
        
        // ESC-Taste für Scan-Abbruch
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && chunkingScanState.active) {
                cancelScanning();
            }
        });
        
        console.log('✅ FAHRPLANPORTAL: Scan-Button Handler registriert');
        
        // ========================================
        // FEHLER-HANDLING
        // ========================================
        
        /**
         * Fehlersammlung initialisieren
         */
        function initializeErrorCollection() {
            chunkingScanState.errorDetails = [];
            chunkingScanState.errorsByRegion = {};
            chunkingScanState.errorsByType = {};
            chunkingScanState.currentChunkErrors = [];
            
            console.log('✅ FAHRPLANPORTAL: Fehlersammlung initialisiert');
        }
        
        /**
         * Fehler zu Sammlung hinzufügen
         */
        function addErrorToCollection(error, file, region) {
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
            }
        }
        
        /**
         * Fehler kategorisieren
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
         * Fehlersammlung zurücksetzen
         */
        function resetErrorCollection() {
            chunkingScanState.errorDetails = [];
            chunkingScanState.errorsByType = {};
            chunkingScanState.errorsByRegion = {};
            chunkingScanState.currentChunkErrors = [];
            
            console.log('🔄 FAHRPLANPORTAL: Fehlersammlung zurückgesetzt');
        }
        
        // ========================================
        // SCANNING STARTEN
        // ========================================
        
        /**
         * Chunked Scanning mit DB-Bereinigung starten
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
            
            // Bestätigungsdialog vor DB-Bereinigung
            showDatabaseClearConfirmation(folder);
        }
        
        /**
         * Bestätigungsdialog für Datenbank-Bereinigung
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
         * Bestätigungsdialog verstecken
         */
        function hideDatabaseClearConfirmation() {
            $('#db-clear-confirmation').remove();
            $(document).off('keydown.db-clear-confirmation');
        }
        
        /**
         * Scan-Button zurücksetzen
         */
        function resetScanButton() {
            $('#scan-directory').prop('disabled', false).text('Verzeichnis scannen');
            $('#scan-status').html('');
        }
        
        /**
         * Datenbank bereinigen und Scanning starten
         */
        function confirmAndClearDatabase(folder) {
            var button = $('#scan-directory');
            var status = $('#scan-status');
            
            button.prop('disabled', true);
            status.html('<span style="color: orange;">🗑️ Bereinige Datenbank...</span>');
            
            console.log('🗑️ FAHRPLANPORTAL: Starte Datenbank-Bereinigung vor Scan');
            
            FahrplanAdmin.ajaxCall('clear_db', {}, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Datenbank erfolgreich bereinigt');
                    chunkingScanState.databaseCleared = true;
                    
                    status.html('<span style="color: green;">✅ Datenbank bereinigt - Starte Scan...</span>');
                    
                    // Kurze Verzögerung vor dem eigentlichen Scan
                    setTimeout(function() {
                        executeChunkedScanning(folder);
                    }, 500);
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Fehler beim Bereinigen der Datenbank:', error);
                    
                    status.html('<span style="color: red;">❌ Fehler beim Bereinigen: ' + error.message + '</span>');
                    resetScanButton();
                    
                    // Dialog mit Optionen zeigen
                    var retry = confirm('Fehler beim Bereinigen der Datenbank:\n' + error.message + '\n\nErneut versuchen?');
                    if (retry) {
                        confirmAndClearDatabase(folder);
                    }
                }
            });
        }
        
        /**
         * Chunked Scanning ausführen
         */
        function executeChunkedScanning(folder) {
            console.log('🎯🎯🎯 FAHRPLANPORTAL: executeChunkedScanning() AUFGERUFEN! 🎯🎯🎯');
            console.log('🚀 FAHRPLANPORTAL: Führe Chunked Scanning für Ordner aus:', folder);
            
            // State zurücksetzen
            chunkingScanState.active = true;
            chunkingScanState.folder = folder;
            chunkingScanState.currentChunk = 0;
            chunkingScanState.totalStats = { imported: 0, skipped: 0, errors: 0, processed: 0 };
            chunkingScanState.regionStats = {};
            chunkingScanState.startTime = Date.now();
            chunkingScanState.cancelled = false;
            
            // Fehlersammlung zurücksetzen
            resetErrorCollection();
            
            // Progress Bar vorbereiten
            showProgressBar();
            
            // Scan-Info abrufen (Dateien sammeln)
            FahrplanAdmin.ajaxCall('get_scan_info', { folder: folder }, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Scan-Info erhalten:', response);
                    
                    chunkingScanState.totalChunks = response.total_chunks;
                    chunkingScanState.totalFiles = response.total_files;
                    
                    updateScanActivity('📊 Gefunden: ' + response.total_files + ' PDFs in ' + response.total_chunks + ' Chunks');
                    
                    // Regionen-Info anzeigen
                    if (response.regions) {
                        var regionInfo = [];
                        for (var region in response.regions) {
                            regionInfo.push(region + ': ' + response.regions[region]);
                        }
                        updateScanActivity('🗺️ Regionen: ' + regionInfo.join(', '));
                    }
                    
                    // Ersten Chunk verarbeiten (0-basiert)
                    processChunk(0);
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Fehler beim Starten des Scans:', error);
                    hideProgressBar();
                    resetScanButton();
                    $('#scan-status').html('<span style="color: red;">❌ Fehler: ' + error.message + '</span>');
                }
            });
        }
        
        /**
         * Einzelnen Chunk verarbeiten (0-basierter Index)
         */
        function processChunk(chunkIndex) {
            if (chunkingScanState.cancelled) {
                console.log('🛑 FAHRPLANPORTAL: Chunked Scanning abgebrochen');
                return;
            }
            
            // Prüfen ob fertig
            if (chunkIndex >= chunkingScanState.totalChunks) {
                console.log('✅ FAHRPLANPORTAL: Alle Chunks verarbeitet');
                completeScan();
                return;
            }
            
            chunkingScanState.currentChunk = chunkIndex;
            
            console.log('📦 FAHRPLANPORTAL: Verarbeite Chunk', (chunkIndex + 1), 'von', chunkingScanState.totalChunks);
            
            updateScanActivity('📦 Verarbeite Chunk ' + (chunkIndex + 1) + '/' + chunkingScanState.totalChunks);
            
            FahrplanAdmin.ajaxCall('scan_chunk', {
                folder: chunkingScanState.folder,
                chunk_index: chunkIndex,
                chunk_size: FahrplanAdmin.config.scan_chunk_size || 10
            }, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Chunk', (chunkIndex + 1), 'erfolgreich verarbeitet:', response);
                    
                    // Stats aktualisieren
                    processChunkResult(response);
                    
                    // Nächster Chunk
                    if (!chunkingScanState.cancelled) {
                        setTimeout(function() {
                            processChunk(chunkIndex + 1);
                        }, 100);
                    }
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Fehler bei Chunk', (chunkIndex + 1), ':', error);
                    
                    // Fehler zählen aber weitermachen
                    chunkingScanState.totalStats.errors++;
                    addErrorToCollection(error.message || 'Chunk-Verarbeitungsfehler', 'Chunk ' + (chunkIndex + 1), 'Unbekannt');
                    
                    updateScanActivity('❌ Fehler bei Chunk ' + (chunkIndex + 1) + ': ' + (error.message || 'Unbekannter Fehler'));
                    
                    // Trotzdem weitermachen mit nächstem Chunk
                    if (!chunkingScanState.cancelled) {
                        setTimeout(function() {
                            processChunk(chunkIndex + 1);
                        }, 1000);
                    }
                }
            });
        }
        
        /**
         * Chunk-Ergebnis verarbeiten
         */
        function processChunkResult(result) {
            // Stats aus Response extrahieren (kann direkt oder in .stats sein)
            var stats = result.stats || result;
            
            // Stats zusammenzählen
            chunkingScanState.totalStats.imported += stats.imported || 0;
            chunkingScanState.totalStats.skipped += stats.skipped || 0;
            chunkingScanState.totalStats.errors += stats.errors || 0;
            chunkingScanState.totalStats.processed += stats.processed || (stats.imported || 0) + (stats.skipped || 0) + (stats.errors || 0);
            
            // Regionen-Stats
            var regionStats = result.region_stats || stats.region_stats || {};
            for (var region in regionStats) {
                if (!chunkingScanState.regionStats[region]) {
                    chunkingScanState.regionStats[region] = 0;
                }
                chunkingScanState.regionStats[region] += regionStats[region];
            }
            
            // Fehler sammeln
            var errorDetails = result.error_details || stats.error_details || [];
            if (errorDetails.length > 0) {
                errorDetails.forEach(function(errorDetail) {
                    addErrorToCollection(
                        errorDetail.error || 'Unbekannter Fehler',
                        errorDetail.file || 'Unbekannte Datei',
                        errorDetail.region || 'Unbekannte Region'
                    );
                });
            }
            
            // Progress aktualisieren
            updateScanProgress();
            
            // Activity Log
            var chunkNum = chunkingScanState.currentChunk + 1;
            var activityMsg = '✅ Chunk ' + chunkNum + ': ';
            activityMsg += (stats.imported || 0) + ' importiert, ';
            activityMsg += (stats.skipped || 0) + ' übersprungen';
            if ((stats.errors || 0) > 0) {
                activityMsg += ', ' + stats.errors + ' Fehler';
            }
            
            updateScanActivity(activityMsg);
        }
        
        // ========================================
        // PROGRESS BAR
        // ========================================
        
        /**
         * Progress Bar anzeigen
         */
        function showProgressBar() {
            console.log('🎯🎯🎯 FAHRPLANPORTAL: showProgressBar() AUFGERUFEN! 🎯🎯🎯');
            
            // ✅ FIX: Bestehendes PHP-Element nutzen statt neues zu erstellen
            var $existingContainer = $('#scan-progress-container');
            
            if ($existingContainer.length > 0) {
                console.log('✅ FAHRPLANPORTAL: Bestehendes Progress-Container gefunden, mache sichtbar');
                
                // Controls verstecken
                $('.fahrplan-controls').hide();
                
                // Bestehendes Element anzeigen
                $existingContainer.show();
                
                // Werte zurücksetzen
                $('#scan-progress-bar').css('width', '0%');
                $('#scan-progress-text').text('0% (0/0 PDFs)');
                $('#scan-imported').text('0');
                $('#scan-skipped').text('0');
                $('#scan-errors').text('0');
                $('#scan-current-chunk').text('0/0');
                $('#scan-current-file').text('Bereite vor...');
                $('#scan-time-remaining').text('Geschätzte Zeit: berechne...');
                $('#scan-region-activity').html('<div class="text-muted">Starte Scan...</div>');
                
                // Cancel Button Handler (falls noch nicht registriert)
                $('#scan-cancel').off('click').on('click', function() {
                    console.log('🔘 FAHRPLANPORTAL: Cancel Button geklickt');
                    cancelScanning();
                });
                
                console.log('✅ FAHRPLANPORTAL: Progress Bar angezeigt (bestehendes Element)');
                
            } else {
                console.log('⚠️ FAHRPLANPORTAL: Kein bestehendes Element gefunden, erstelle dynamisch');
                createDynamicProgressBar();
            }
        }
        
        /**
         * Dynamische Progress Bar erstellen (Fallback)
         */
        function createDynamicProgressBar() {
            // Dynamischen Container entfernen falls vorhanden
            $('#scan-progress-dynamic').remove();
            
            // Controls verstecken
            $('.fahrplan-controls').hide();
            
            var progressHtml = `
                <div id="scan-progress-dynamic" style="
                    display: block !important;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    border-radius: 12px;
                    padding: 25px;
                    margin: 20px 0;
                    color: white;
                    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0; font-size: 18px;">📂 Chunked PDF-Scan läuft...</h3>
                        <button id="scan-cancel-btn" class="button button-secondary" style="
                            background: rgba(255,255,255,0.2);
                            border: 1px solid rgba(255,255,255,0.3);
                            color: white;
                            padding: 8px 16px;
                            border-radius: 6px;
                            cursor: pointer;
                        ">
                            ❌ Abbrechen
                        </button>
                    </div>
                    
                    <div style="background: rgba(255,255,255,0.2); border-radius: 10px; height: 24px; overflow: hidden; margin-bottom: 15px;">
                        <div id="scan-progress-bar" style="
                            background: linear-gradient(90deg, #00b09b, #96c93d);
                            height: 100%;
                            width: 0%;
                            transition: width 0.3s ease;
                            border-radius: 10px;
                        " class="progress-bar-striped progress-bar-animated"></div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 15px;">
                        <div style="text-align: center;">
                            <div id="scan-progress-text" style="font-size: 20px; font-weight: bold;">0%</div>
                            <div style="font-size: 12px; opacity: 0.8;">Fortschritt</div>
                        </div>
                        <div style="text-align: center;">
                            <div id="scan-imported" style="font-size: 20px; font-weight: bold; color: #00ff88;">0</div>
                            <div style="font-size: 12px; opacity: 0.8;">Importiert</div>
                        </div>
                        <div style="text-align: center;">
                            <div id="scan-skipped" style="font-size: 20px; font-weight: bold; color: #ffd93d;">0</div>
                            <div style="font-size: 12px; opacity: 0.8;">Übersprungen</div>
                        </div>
                        <div style="text-align: center;">
                            <div id="scan-errors" style="font-size: 20px; font-weight: bold; color: #ff6b6b;">0</div>
                            <div style="font-size: 12px; opacity: 0.8;">Fehler</div>
                        </div>
                    </div>
                    
                    <div style="font-size: 12px; opacity: 0.8; text-align: center;">
                        <span id="scan-time-remaining">Berechne Restzeit...</span>
                    </div>
                    
                    <div id="scan-region-activity" style="
                        margin-top: 15px;
                        max-height: 100px;
                        overflow-y: auto;
                        font-size: 11px;
                        background: rgba(0,0,0,0.2);
                        border-radius: 6px;
                        padding: 10px;
                    "></div>
                </div>
                
                <style>
                @keyframes progress-bar-stripes {
                    from { background-position: 40px 0; }
                    to { background-position: 0 0; }
                }
                .progress-bar-striped {
                    background-image: linear-gradient(45deg, rgba(255,255,255,.15) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.15) 50%, rgba(255,255,255,.15) 75%, transparent 75%, transparent);
                    background-size: 40px 40px;
                }
                .progress-bar-animated {
                    animation: progress-bar-stripes 1s linear infinite;
                }
                </style>
            `;
            
            // ✅ ROBUSTER: Mehrere mögliche Ankerpunkte versuchen
            var $anchor = null;
            
            // Mögliche Ankerpunkte in Prioritätsreihenfolge
            var anchors = [
                '#scan-status',           // Original
                '.scan-box',              // Scan-Box Container
                '#folder-select',         // Ordner-Auswahl
                '.fahrplan-controls',     // Controls Container
                '#fahrplaene-table_wrapper', // DataTable Wrapper
                '.wrap h1'                // WordPress Admin Headline
            ];
            
            for (var i = 0; i < anchors.length; i++) {
                var $test = $(anchors[i]);
                if ($test.length > 0) {
                    $anchor = $test;
                    console.log('✅ FAHRPLANPORTAL: Progress Bar Ankerpunkt gefunden:', anchors[i]);
                    break;
                }
            }
            
            if ($anchor) {
                $anchor.after(progressHtml);
            } else {
                // Fallback: Am Anfang des .wrap Containers einfügen
                console.log('⚠️ FAHRPLANPORTAL: Kein Ankerpunkt gefunden, verwende .wrap');
                $('.wrap').prepend(progressHtml);
            }
            
            // Cancel Button Handler
            $('#scan-cancel-btn').on('click', function() {
                console.log('🔘 FAHRPLANPORTAL: Cancel Button in Progress Bar geklickt');
                cancelScanning();
            });
            
            console.log('✅ FAHRPLANPORTAL: Progress Bar angezeigt');
        }
        
        /**
         * Progress aktualisieren
         */
        function updateScanProgress() {
            var progress = (chunkingScanState.totalStats.processed / chunkingScanState.totalFiles) * 100;
            var progressPercent = Math.min(Math.round(progress), 100);
            
            $('#scan-progress-bar').css('width', progressPercent + '%');
            $('#scan-progress-text').text(progressPercent + '% (' + chunkingScanState.totalStats.processed + '/' + chunkingScanState.totalFiles + ' PDFs)');
            
            // Statistiken aktualisieren
            $('#scan-imported').text(chunkingScanState.totalStats.imported);
            $('#scan-skipped').text(chunkingScanState.totalStats.skipped);
            $('#scan-errors').text(chunkingScanState.totalStats.errors);
            
            // ✅ FIX: Chunk-Counter aktualisieren
            $('#scan-current-chunk').text((chunkingScanState.currentChunk + 1) + '/' + chunkingScanState.totalChunks);
            
            // Restzeit berechnen
            var elapsed = (Date.now() - chunkingScanState.startTime) / 1000;
            if (chunkingScanState.totalStats.processed > 0) {
                var avgTimePerFile = elapsed / chunkingScanState.totalStats.processed;
                var remaining = (chunkingScanState.totalFiles - chunkingScanState.totalStats.processed) * avgTimePerFile;
                $('#scan-time-remaining').text('Geschätzte Restzeit: ' + FahrplanAdmin.formatDuration(Math.round(remaining)));
            }
        }
        
        /**
         * Aktivität aktualisieren
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
         * Progress Bar verstecken
         */
        function hideProgressBar() {
            $('#scan-progress-container').hide();
            $('#scan-progress-dynamic').hide();
            $('.fahrplan-controls').show();
            resetScanButton();
        }
        
        // ========================================
        // SCAN ABSCHLIESSEN
        // ========================================
        
        /**
         * Scan abschließen
         */
        function completeScan() {
            var stats = chunkingScanState.totalStats;
            var duration = Math.round((Date.now() - chunkingScanState.startTime) / 1000);
            
            console.log('🎉 FAHRPLANPORTAL: Chunked Scanning abgeschlossen:', stats);
            
            // Progress Bar auf 100%
            $('#scan-progress-bar').css('width', '100%');
            $('#scan-progress-text').text('100% (' + stats.processed + '/' + chunkingScanState.totalFiles + ' PDFs)');
            $('#scan-time-remaining').text('Abgeschlossen in ' + FahrplanAdmin.formatDuration(duration));
            
            // Erfolgs-Meldung
            var successMessage = '🎉 Chunked Scanning abgeschlossen! ';
            if (chunkingScanState.databaseCleared) {
                successMessage += '(Datenbank bereinigt) ';
            }
            successMessage += 'Importiert: ' + stats.imported + ', ' +
                             'Übersprungen: ' + stats.skipped + ', ' +
                             'Fehler: ' + stats.errors + ' ' +
                             '(Dauer: ' + FahrplanAdmin.formatDuration(duration) + ')';
            
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
            
            // Fehlerprotokoll sammeln und anzeigen
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
            
            // Nur bei Erfolg ohne Fehler automatisch neu laden
            if (stats.imported > 0 && stats.errors === 0) {
                setTimeout(function() {
                    updateScanActivity('🔄 Seite wird in 5 Sekunden neu geladen...');
                    setTimeout(function() {
                        location.reload();
                    }, 5000);
                }, 3000);
            } else if (stats.errors > 0) {
                // Bei Fehlern NICHT automatisch neu laden
                updateScanActivity('⚠️ Import mit Fehlern abgeschlossen - Seite wird NICHT automatisch neu geladen');
                updateScanActivity('📋 Überprüfen Sie das Fehlerprotokoll und laden Sie die Seite manuell neu');
            }
        }
        
        /**
         * Scanning abbrechen
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
        
        // ========================================
        // FEHLERPROTOKOLL
        // ========================================
        
        /**
         * Fehlerprotokoll sammeln
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
         * Fehlerprotokoll-Dialog anzeigen
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
                    .map(function(entry) { return '<li><strong>' + entry[0] + ':</strong> ' + entry[1] + '</li>'; })
                    .join('');
            } else {
                errorTypesHtml = '<li>Keine Kategorisierung verfügbar</li>';
            }
            
            var errorRegionsHtml = '';
            if (Object.keys(errorSummary.errorsByRegion).length > 0) {
                errorRegionsHtml = Object.entries(errorSummary.errorsByRegion)
                    .map(function(entry) { return '<li><strong>' + entry[0] + ':</strong> ' + entry[1] + '</li>'; })
                    .join('');
            } else {
                errorRegionsHtml = '<li>Keine Regions-Zuordnung verfügbar</li>';
            }
            
            var errorDetailsHtml = '';
            if (errorSummary.errorDetails.length > 0) {
                errorDetailsHtml = errorSummary.errorDetails
                    .map(function(error, index) {
                        return '<div style="padding: 10px 15px; border-bottom: 1px solid #e9ecef; ' + (index % 2 === 0 ? 'background: white;' : 'background: #f8f9fa;') + '">' +
                            '<div style="margin-bottom: 5px;"><strong style="color: #dc3545;">📄 Datei:</strong> ' +
                            '<span style="font-family: monospace; background: #f1f3f4; padding: 2px 4px; border-radius: 3px;">' +
                            (error.file || 'Unbekannte Datei') + '</span></div>' +
                            '<div style="margin-bottom: 5px;"><strong style="color: #856404;">⚠️ Fehler:</strong> ' +
                            '<span style="color: #721c24;">' + (error.error || 'Unbekannter Fehler') + '</span></div>' +
                            (error.region ? '<div style="margin-bottom: 5px;"><strong style="color: #0c5460;">🗺️ Region:</strong> <span style="color: #155724;">' + error.region + '</span></div>' : '') +
                            (error.timestamp ? '<div style="font-size: 11px; color: #6c757d;">🕒 ' + new Date(error.timestamp).toLocaleString('de-DE') + '</div>' : '') +
                        '</div>';
                    })
                    .join('');
            } else {
                errorDetailsHtml = '<div style="padding: 20px; text-align: center; color: #6c757d;">Keine detaillierten Fehlerinformationen verfügbar</div>';
            }
            
            var errorDialogHtml = '<div id="error-protocol-dialog" style="' +
                'position: fixed; top: 0; left: 0; width: 100%; height: 100%;' +
                'background: rgba(0, 0, 0, 0.8); display: flex; align-items: center;' +
                'justify-content: center; z-index: 1000000;">' +
                '<div style="background: white; border-radius: 12px; box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);' +
                'max-width: 900px; width: 95%; max-height: 90vh; overflow: hidden; border: 3px solid #dc3545;">' +
                '<div style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 20px 25px; text-align: center;">' +
                '<div style="font-size: 42px; margin-bottom: 10px;">⚠️</div>' +
                '<h2 style="margin: 0; font-size: 20px; font-weight: 600;">Import-Fehlerprotokoll</h2>' +
                '<p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;">' + errorSummary.totalErrors + ' Fehler beim PDF-Import aufgetreten</p></div>' +
                '<div style="padding: 25px; max-height: 60vh; overflow-y: auto;">' +
                '<div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px;">' +
                '<h4 style="margin: 0 0 15px 0; color: #856404;">📊 Fehler-Übersicht:</h4>' +
                '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">' +
                '<div><strong style="color: #856404; display: block; margin-bottom: 8px;">Nach Fehlertyp:</strong>' +
                '<ul style="margin: 0; padding-left: 20px; color: #856404; line-height: 1.4;">' + errorTypesHtml + '</ul></div>' +
                '<div><strong style="color: #856404; display: block; margin-bottom: 8px;">Nach Region:</strong>' +
                '<ul style="margin: 0; padding-left: 20px; color: #856404; line-height: 1.4;">' + errorRegionsHtml + '</ul></div>' +
                '</div></div>' +
                '<div style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; max-height: 300px; overflow-y: auto;">' +
                '<div style="background: #343a40; color: white; padding: 10px 15px; font-weight: 600;">📋 Detaillierte Fehlerliste</div>' +
                errorDetailsHtml + '</div></div>' +
                '<div style="padding: 20px 25px; background: #f8f9fa; border-top: 1px solid #dee2e6; display: flex; justify-content: center; gap: 15px;">' +
                '<button id="close-error-protocol" style="background: #6c757d; color: white; border: none; padding: 12px 30px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">✓ Verstanden</button></div>' +
                '</div></div>';
            
            $('body').append(errorDialogHtml);
            
            // Close Button Handler
            $('#close-error-protocol').on('click', function() {
                $('#error-protocol-dialog').remove();
            });
            
            // ESC-Taste zum Schließen
            $(document).on('keydown.error-protocol', function(e) {
                if (e.key === 'Escape') {
                    $('#close-error-protocol').click();
                    $(document).off('keydown.error-protocol');
                }
            });
        }
        
        console.log('✅ FAHRPLANPORTAL: Scanning-Modul vollständig initialisiert');
    }
    
}); // Ende jQuery ready
