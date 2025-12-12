/**
 * FahrplanPortal Admin - Sync Module
 * 
 * Enthält:
 * - Tabellen-Synchronisation mit Ordnern
 * - Fehlende PDFs verwalten
 * - Einzel-PDF-Import
 * - Status-Updates
 * 
 * @version 2.0.0
 * @requires admin-core.js
 */

jQuery(document).ready(function($) {
    
    // Warte auf Core-Modul
    $(document).on('fahrplanAdmin:ready', function() {
        console.log('🔄 FAHRPLANPORTAL: Sync-Modul wird initialisiert...');
        initSyncModule();
    });
    
    // Falls Core bereits initialisiert ist
    if (typeof FahrplanAdmin !== 'undefined' && FahrplanAdmin.initialized) {
        initSyncModule();
    }
    
    function initSyncModule() {
        
        // ========================================
        // TABELLEN-SYNCHRONISATION
        // ========================================
        
        // Button Handler für "Tabelle aktualisieren"
        $('#update-table-status').on('click', function() {
            syncTableWithFolders();
        });
        
        /**
         * Tabelle mit physikalischen Ordnern synchronisieren
         */
        function syncTableWithFolders() {
            var $btn = $('#update-table-status');
            var $status = $('#status-update-info');
            
            if ($btn.prop('disabled')) {
                return;
            }
            
            // Button deaktivieren und Status anzeigen
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite; vertical-align: middle; margin-right: 5px;"></span>Synchronisiere...');
            $status.html('<span style="color: orange;">🔄 Überprüfe DB gegen physikalische Ordner...</span>');
            
            console.log('🔄 FAHRPLANPORTAL: Starte zweistufige Tabellen-Synchronisation');
            
            FahrplanAdmin.ajaxCall('sync_table', {}, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Synchronisation erfolgreich:', response);
                    
                    // Button zurücksetzen
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Tabelle aktualisieren');
                    
                    // Erfolgs-Nachricht zusammenstellen
                    var message = '✅ Synchronisation abgeschlossen:<br>';
                    message += '📊 ' + response.stats.total_db_entries + ' DB-Einträge geprüft<br>';
                    message += '✅ ' + response.stats.status_ok + ' PDFs OK<br>';
                    
                    if (response.stats.marked_missing > 0) {
                        message += '❌ ' + response.stats.marked_missing + ' PDFs als fehlend markiert<br>';
                    }
                    
                    if (response.stats.already_missing > 0) {
                        message += '⚠️ ' + response.stats.already_missing + ' bereits als fehlend bekannt<br>';
                    }
                    
                    if (response.stats.marked_import > 0) {
                        message += '🆕 ' + response.stats.marked_import + ' neue PDFs gefunden<br>';
                    }
                    
                    if (response.stats.errors > 0) {
                        message += '❌ ' + response.stats.errors + ' Fehler<br>';
                    }
                    
                    // Persistent speichern
                    FahrplanAdmin.showPersistentSyncMessage(message, 'success');
                    
                    // Status in Tabelle aktualisieren (ohne Page-Reload!)
                    updateTableStatusAfterSync(response.stats);
                    
                    // Buttons anzeigen/verstecken und Details automatisch laden
                    var totalMissing = response.stats.status_missing || 0;
                    if (totalMissing > 0) {
                        $('#delete-missing-pdfs').show();
                        $('#show-missing-details').hide(); // Details-Button verstecken
                        $('#delete-missing-pdfs').html('<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>Fehlende PDFs löschen (' + totalMissing + ')');
                        
                        // Details automatisch laden und anzeigen
                        loadMissingPdfsDetailsAutomatic();
                    } else {
                        $('#delete-missing-pdfs').hide();
                        $('#show-missing-details').hide();
                        $('#missing-pdfs-details').hide();
                    }
                    
                    // Bei neuen PDFs separate Import-Liste anzeigen
                    if (response.stats.marked_import > 0) {
                        displayPendingImportsList(response.stats.new_files);
                    }
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Synchronisation-Fehler:', error);
                    
                    // Button zurücksetzen
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Tabelle aktualisieren');
                    
                    var errorMessage = '❌ Synchronisation fehlgeschlagen: ' + error.message;
                    FahrplanAdmin.showPersistentSyncMessage(errorMessage, 'error');
                }
            });
        }
        
        /**
         * Status in Tabelle nach Sync aktualisieren
         */
        function updateTableStatusAfterSync(stats) {
            console.log('🔄 FAHRPLANPORTAL: Aktualisiere Status in Tabelle nach Sync', stats);
            
            // Status-Daten laden
            FahrplanAdmin.ajaxCall('get_all_status_updates', {}, {
                success: function(statusData) {
                    console.log('✅ FAHRPLANPORTAL: Status-Daten erhalten:', statusData);
                    
                    // Alle Zeilen durchgehen und Status aktualisieren
                    $('#fahrplaene-table tbody tr').each(function() {
                        var $row = $(this);
                        var rowId = $row.data('id');
                        var $statusCell = $row.find('[id^="status-"]');
                        
                        // SKIP neue PDF-Zeilen (haben "new-" IDs)
                        if (rowId && rowId.toString().startsWith('new-')) {
                            console.log('FAHRPLANPORTAL: Überspringe neue PDF-Zeile:', rowId);
                            return; // Skip this row
                        }
                        
                        if (rowId && $statusCell.length > 0 && statusData.status_data) {
                            var fahrplanStatus = statusData.status_data[rowId];
                            
                            if (fahrplanStatus === 'MISSING') {
                                $statusCell.html('<span class="status-missing">❌ Fehlt</span>');
                                $row.addClass('missing-pdf-row');
                                $row.attr('data-pdf-status', 'MISSING');
                            } else if (fahrplanStatus === 'IMPORT') {
                                $statusCell.html('<span class="status-import" data-pdf-path="' + $row.data('pdf-path') + '">🆕 Import</span>');
                                $row.attr('data-pdf-status', 'IMPORT');
                            } else {
                                $statusCell.html('<span class="status-ok">✅ OK</span>');
                                $row.removeClass('missing-pdf-row');
                                $row.attr('data-pdf-status', 'OK');
                            }
                        }
                    });
                    
                    console.log('✅ FAHRPLANPORTAL: Status-Aktualisierung in Tabelle abgeschlossen');
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Fehler beim Laden der Status-Daten:', error);
                }
            });
        }
        
        // ========================================
        // FEHLENDE PDFs VERWALTEN
        // ========================================
        
        // Button Handler für "Fehlende PDFs löschen"
        $('#delete-missing-pdfs').on('click', function() {
            if (!confirm('Wirklich alle als fehlend markierten PDFs aus der Datenbank löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden!')) {
                return;
            }
            
            deleteMissingPdfs();
        });
        
        // Button Handler für "Details anzeigen"
        $('#show-missing-details').on('click', function() {
            var $detailsContainer = $('#missing-pdfs-details');
            
            if ($detailsContainer.is(':visible')) {
                // Details verstecken
                $detailsContainer.slideUp();
                $(this).html('<span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 5px;"></span>Details anzeigen');
            } else {
                // Details anzeigen
                loadMissingPdfsDetails();
            }
        });
        
        /**
         * Fehlende PDFs endgültig löschen
         */
        function deleteMissingPdfs() {
            var $btn = $('#delete-missing-pdfs');
            var $status = $('#status-update-info');
            
            if ($btn.prop('disabled')) {
                return;
            }
            
            // Button deaktivieren
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite; vertical-align: middle; margin-right: 5px;"></span>Lösche...');
            $status.html('<span style="color: orange;">🗑️ Lösche fehlende PDFs aus Datenbank...</span>');
            
            console.log('🗑️ FAHRPLANPORTAL: Starte Löschung fehlender PDFs');
            
            FahrplanAdmin.ajaxCall('delete_missing_pdfs', {}, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Fehlende PDFs gelöscht:', response);
                    
                    // Button zurücksetzen und verstecken
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>Fehlende PDFs löschen');
                    $btn.hide();
                    $('#show-missing-details').hide();
                    $('#missing-pdfs-details').hide();
                    
                    // Erfolgs-Nachricht
                    var message = '✅ Löschung abgeschlossen:<br>';
                    message += '🗑️ ' + response.deleted_count + ' fehlende PDFs gelöscht<br>';
                    
                    $status.html('<span style="color: green;">' + message + '</span>');
                    
                    // Fehlende Zeilen aus Tabelle entfernen
                    $('#fahrplaene-table tbody tr').each(function() {
                        var $row = $(this);
                        if ($row.data('pdf-status') === 'MISSING') {
                            $row.fadeOut(500, function() {
                                $row.remove();
                            });
                        }
                    });
                    
                    // Nach 3 Sekunden Seite neu laden
                    setTimeout(function() {
                        if (confirm('Fehlende PDFs erfolgreich gelöscht!\n\nSeite neu laden um die Änderungen vollständig zu sehen?')) {
                            location.reload();
                        }
                    }, 3000);
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Lösch-Fehler:', error);
                    
                    // Button zurücksetzen
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>Fehlende PDFs löschen');
                    
                    $status.html('<span style="color: red;">❌ Löschung fehlgeschlagen: ' + error.message + '</span>');
                }
            });
        }
        
        /**
         * Details der fehlenden PDFs laden und anzeigen
         */
        function loadMissingPdfsDetails() {
            var $detailsContainer = $('#missing-pdfs-details');
            var $listContainer = $('#missing-pdfs-list');
            var $btn = $('#show-missing-details');
            
            // Loading-Zustand
            $btn.prop('disabled', true);
            $listContainer.html('<div style="text-align: center; padding: 10px;">⏳ Lade Details...</div>');
            $detailsContainer.slideDown();
            
            console.log('📋 FAHRPLANPORTAL: Lade Details fehlender PDFs');
            
            FahrplanAdmin.ajaxCall('get_missing_pdfs', {}, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Fehlende PDFs Details geladen:', response);
                    
                    var html = '';
                    
                    if (response.missing_pdfs && response.missing_pdfs.length > 0) {
                        response.missing_pdfs.forEach(function(pdf) {
                            html += '<div style="margin-bottom: 5px; padding: 5px; background: rgba(255,255,255,0.5); border-radius: 3px;">';
                            html += '<strong>' + pdf.jahr + '/' + pdf.region + ':</strong> ';
                            html += pdf.titel + ' (' + pdf.dateiname + ')';
                            html += '<br><small style="color: #666;">Pfad: ' + pdf.pdf_pfad + '</small>';
                            html += '</div>';
                        });
                    } else {
                        html = '<div style="text-align: center; color: #666;">Keine fehlenden PDFs gefunden.</div>';
                    }
                    
                    $listContainer.html(html);
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-hidden" style="vertical-align: middle; margin-right: 5px;"></span>Details verstecken');
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Details-Fehler:', error);
                    
                    $listContainer.html('<div style="color: red; text-align: center;">❌ Fehler beim Laden: ' + error.message + '</div>');
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 5px;"></span>Details anzeigen');
                }
            });
        }
        
        /**
         * Automatische Details-Anzeige ohne Button
         */
        function loadMissingPdfsDetailsAutomatic() {
            var $detailsContainer = $('#missing-pdfs-details');
            var $listContainer = $('#missing-pdfs-list');
            
            // Container sofort anzeigen mit Loading
            $listContainer.html('<div style="text-align: center; padding: 10px;">⏳ Lade Details...</div>');
            $detailsContainer.show();
            
            console.log('📋 FAHRPLANPORTAL: Lade Details fehlender PDFs (automatisch)');
            
            FahrplanAdmin.ajaxCall('get_missing_pdfs', {}, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Fehlende PDFs Details geladen (automatisch):', response);
                    
                    var html = '';
                    
                    if (response.missing_pdfs && response.missing_pdfs.length > 0) {
                        response.missing_pdfs.forEach(function(pdf) {
                            html += '<div style="margin-bottom: 5px; padding: 5px; background: rgba(255,255,255,0.5); border-radius: 3px;">';
                            html += '<strong>' + pdf.jahr + '/' + pdf.region + ':</strong> ';
                            html += pdf.titel + ' (' + pdf.dateiname + ')';
                            html += '<br><small style="color: #666;">Pfad: ' + pdf.pdf_pfad + '</small>';
                            html += '</div>';
                        });
                    } else {
                        html = '<div style="text-align: center; color: #666;">Keine fehlenden PDFs gefunden.</div>';
                    }
                    
                    $listContainer.html(html);
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Details-Fehler (automatisch):', error);
                    $listContainer.html('<div style="color: red; text-align: center;">❌ Fehler beim Laden: ' + error.message + '</div>');
                }
            });
        }
        
        // ========================================
        // EINZEL-PDF-IMPORT
        // ========================================
        
        // Click-Handler für "import"-Status (einzelnes PDF importieren)
        $(document).on('click', '.status-import', function(e) {
            e.preventDefault();
            var $importBtn = $(this);
            var $row = $importBtn.closest('tr');
            var pdfPath = $importBtn.data('pdf-path');
            
            if (!pdfPath) {
                alert('PDF-Pfad nicht gefunden');
                return;
            }
            
            if (!confirm('PDF "' + pdfPath + '" jetzt importieren?')) {
                return;
            }
            
            // Import-Button deaktivieren
            $importBtn.removeClass('status-import').addClass('status-importing');
            $importBtn.html('<span style="color: orange;">⏳ Importiere...</span>');
            $importBtn.css('cursor', 'default');
            
            console.log('🔄 FAHRPLANPORTAL: Starte Einzel-PDF-Import:', pdfPath);
            
            FahrplanAdmin.ajaxCall('import_single_pdf', {
                pdf_path: pdfPath
            }, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: PDF erfolgreich importiert:', response);
                    
                    // Status auf OK setzen
                    $importBtn.removeClass('status-importing');
                    $importBtn.html('<span style="color: green; font-weight: bold;">✅ OK</span>');
                    
                    // Nach 2 Sekunden Seite neu laden
                    setTimeout(function() {
                        if (confirm('PDF erfolgreich importiert!\n\nSeite neu laden um die neuen Daten zu sehen?')) {
                            location.reload();
                        }
                    }, 2000);
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: PDF-Import-Fehler:', error);
                    
                    // Status auf Fehler setzen
                    $importBtn.removeClass('status-importing');
                    $importBtn.html('<span style="color: red;">❌ Fehler</span>');
                    $importBtn.css('cursor', 'default');
                    
                    alert('Import fehlgeschlagen: ' + error.message);
                }
            });
        });
        
        // ========================================
        // PENDING IMPORTS LISTE
        // ========================================
        
        /**
         * Separate Liste für zu importierende PDFs anzeigen
         */
        function displayPendingImportsList(newFiles) {
            if (!newFiles || newFiles.length === 0) return;
            
            console.log('📋 FAHRPLANPORTAL: Zeige ' + newFiles.length + ' PDFs in Import-Liste');
            
            // Container direkt nach dem "Tabelle aktualisieren" Button positionieren
            var $importContainer = $('#pending-imports-container');
            if ($importContainer.length === 0) {
                $importContainer = $('<div id="pending-imports-container" style="margin-top: 15px; margin-bottom: 15px;"></div>');
                $('#update-table-status').after($importContainer);
            }
            
            // Liste-HTML erstellen
            var listHtml = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px;">';
            listHtml += '<h4 style="margin: 0 0 10px 0; color: #856404;">📥 Neue PDFs gefunden - Einzelimport verfügbar:</h4>';
            
            newFiles.forEach(function(pdf, index) {
                var uniqueId = 'import-' + Date.now() + '-' + index;
                
                listHtml += '<div id="' + uniqueId + '" style="background: white; border: 1px solid #ddd; border-radius: 3px; padding: 10px; margin: 5px 0; display: flex; justify-content: space-between; align-items: center;">';
                
                // PDF Info
                listHtml += '<div style="flex-grow: 1;">';
                listHtml += '<strong>PDF ' + (index + 1) + ':</strong> ' + FahrplanAdmin.escapeHtml(pdf.filename);
                listHtml += '<br><small style="color: #666;">📁 ' + FahrplanAdmin.escapeHtml(pdf.folder);
                if (pdf.region) {
                    listHtml += ' / ' + FahrplanAdmin.escapeHtml(pdf.region);
                }
                listHtml += '</small>';
                listHtml += '</div>';
                
                // Import Button
                listHtml += '<div style="margin-left: 15px;">';
                listHtml += '<button type="button" class="button button-primary import-single-pdf-btn" ';
                listHtml += 'data-pdf-path="' + FahrplanAdmin.escapeHtml(pdf.relative_path) + '" ';
                listHtml += 'data-container-id="' + uniqueId + '">';
                listHtml += '🔄 Import</button>';
                listHtml += '</div>';
                
                listHtml += '</div>';
            });
            
            listHtml += '</div>';
            
            $importContainer.html(listHtml);
            
            // Event Handler für Import-Buttons registrieren
            registerImportButtonHandlers();
        }
        
        /**
         * Event Handler für einzelne Import-Buttons
         */
        function registerImportButtonHandlers() {
            $(document).off('click', '.import-single-pdf-btn').on('click', '.import-single-pdf-btn', function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var pdfPath = $btn.data('pdf-path');
                var containerId = $btn.data('container-id');
                var $container = $('#' + containerId);
                
                if (!pdfPath) {
                    alert('Fehler: PDF-Pfad nicht gefunden');
                    return;
                }
                
                console.log('🔄 FAHRPLANPORTAL: Starte Import für:', pdfPath);
                
                // Button deaktivieren und Status anzeigen
                $btn.prop('disabled', true);
                $btn.html('⏳ Importiere...');
                
                // PDF importieren
                FahrplanAdmin.ajaxCall('import_single_pdf', {pdf_path: pdfPath}, {
                    success: function(response) {
                        console.log('✅ FAHRPLANPORTAL: PDF erfolgreich importiert:', response);
                        
                        // Container mit Erfolg-Status markieren aber NICHT entfernen
                        $container.css({
                            'background': '#d4edda',
                            'border-color': '#c3e6cb'
                        });
                        
                        // Button durch Erfolg-Status ersetzen
                        $btn.closest('div').html('<span style="color: #155724; font-weight: bold;">✅ Importiert</span>');
                        
                        // Nach 3 Sekunden diesen Eintrag ausblenden
                        setTimeout(function() {
                            $container.fadeOut(500, function() {
                                $container.remove();
                                
                                // Prüfen ob alle PDFs importiert wurden
                                checkIfAllImportsComplete();
                            });
                        }, 3000);
                    },
                    error: function(error) {
                        console.error('❌ FAHRPLANPORTAL: Import-Fehler:', error);
                        
                        // Button wieder aktivieren
                        $btn.prop('disabled', false);
                        $btn.html('🔄 Import');
                        
                        // Fehler anzeigen
                        $container.css({
                            'background': '#f8d7da',
                            'border-color': '#f5c6cb'
                        });
                        
                        var errorDiv = '<div style="color: #721c24; margin-top: 5px;"><small>❌ Fehler: ' + FahrplanAdmin.escapeHtml(error.message) + '</small></div>';
                        $container.append(errorDiv);
                    }
                });
            });
        }
        
        /**
         * Prüfen ob alle Imports abgeschlossen sind
         */
        function checkIfAllImportsComplete() {
            var remainingImports = $('.import-single-pdf-btn:not(:disabled)').length;
            
            console.log('🔍 FAHRPLANPORTAL: Verbleibende Imports:', remainingImports);
            
            if (remainingImports === 0) {
                console.log('✅ FAHRPLANPORTAL: Alle PDFs importiert - entferne Container in 2 Sekunden');
                
                setTimeout(function() {
                    $('#pending-imports-container').fadeOut(500, function() {
                        $(this).remove();
                        
                        // JETZT erst DataTables neu laden
                        reloadDataTables();
                    });
                }, 2000);
            }
        }
        
        /**
         * DataTables neu laden
         */
        function reloadDataTables() {
            if (FahrplanAdmin.dataTable) {
                console.log('🔄 FAHRPLANPORTAL: Lade DataTables neu...');
                
                try {
                    FahrplanAdmin.dataTable.ajax.reload(null, false); // false = Position beibehalten
                    console.log('✅ FAHRPLANPORTAL: DataTables erfolgreich neu geladen');
                } catch (e) {
                    console.warn('⚠️ FAHRPLANPORTAL: DataTables Reload Fehler:', e);
                    
                    // Fallback: Seite neu laden
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                }
            } else {
                console.log('ℹ️ FAHRPLANPORTAL: Keine DataTables gefunden - lade Seite neu');
                setTimeout(function() {
                    location.reload();
                }, 1000);
            }
        }
        
        console.log('✅ FAHRPLANPORTAL: Sync-Modul vollständig initialisiert');
    }
    
}); // Ende jQuery ready
