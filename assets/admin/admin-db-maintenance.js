/**
 * FahrplanPortal Admin - DB Maintenance Module
 * 
 * Enthält:
 * - Exklusionsliste verwalten
 * - Linien-Mapping verwalten
 * - DB-Wartung (recreate, clear)
 * - Tag-Cleanup
 * 
 * @version 2.0.0
 * @requires admin-core.js
 */

jQuery(document).ready(function($) {
    
    // Warte auf Core-Modul
    $(document).on('fahrplanAdmin:ready', function() {
        console.log('🔧 FAHRPLANPORTAL: DB-Maintenance-Modul wird initialisiert...');
        initDbMaintenanceModule();
    });
    
    // Falls Core bereits initialisiert ist
    if (typeof FahrplanAdmin !== 'undefined' && FahrplanAdmin.initialized) {
        initDbMaintenanceModule();
    }
    
    function initDbMaintenanceModule() {
        
        // ========================================
        // STANDARD-WERTE
        // ========================================
        
        // Standard-Exklusionsliste
        var defaultExclusions = 'aber alle allem allen aller alles also auch auf aus bei bin bis bist dass den der des die dies doch dort durch ein eine einem einen einer eines für hab hat hier ich ihr ihre ihrem ihren ihrer ihres ist mit nach nicht noch nur oder sich sie sind über und uns von war wird wir zu zum zur wie was wo wer wann warum welche welcher welches wenn schon noch sehr mehr weniger groß klein gut schlecht neu alt lang kurz hoch niedrig\n\nfahrplan fahrpläne fahrt fahrten zug züge bus busse bahn bahnen haltestelle haltestellen bahnhof bahnhöfe station stationen linie linien route routen verkehr abfahrt abfahrten ankunft ankünfte uhrzeit uhrzeiten\n\nmontag dienstag mittwoch donnerstag freitag samstag sonntag wochentag wochentage wochenende\n\nheute gestern morgen übermorgen vorgestern täglich wöchentlich monatlich jährlich\n\njahr jahre jahren zeit zeiten mal male heute gestern morgen immer nie oft selten manchmal immer wieder\n\nauto autos wagen fahrzeug fahrzeuge transport transporte reise reisen weg wege straße straßen';
        
        // Beispiel-Mapping
        var exampleMapping = '// Beispiel Linien-Mapping: linie_alt:linie_neu\n// Kärnten Linien Beispiele:\n5000:100\n5001:101\n5002:102\n5003:103\n5004:104\n5005:105\n5006:106\n5007:107\n5008:108\n5009:109\n5010:110\n\n// Regionale Linien:\n5020:120\n5021:121\n5022:122\n5025:125\n\n// Stadtlinien:\n5100:200\n5101:201\n5102:202\n\n// Weitere Zuordnungen:\n5402:502\n5403:503\n5404:504';
        
        // ========================================
        // EXKLUSIONSLISTE
        // ========================================
        
        // Speichern
        $('#save-exclusion-words').on('click', function() {
            var $btn = $(this);
            var $status = $('#exclusion-status');
            var exclusionText = $('#exclusion-words').val();
            
            $btn.prop('disabled', true);
            $status.html('<span style="color: orange;">Admin speichert...</span>');
            
            FahrplanAdmin.ajaxCall('save_exclusion_words', {exclusion_words: exclusionText}, {
                success: function(response) {
                    $status.html('<span style="color: green;">✓ Admin gespeichert (' + response.word_count + ' Wörter)</span>');
                    setTimeout(function() {
                        $status.html('');
                    }, 3000);
                    $btn.prop('disabled', false);
                },
                error: function(error) {
                    $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
                    $btn.prop('disabled', false);
                }
            });
        });
        
        // Laden
        $('#load-exclusion-words').on('click', function() {
            var $btn = $(this);
            var $status = $('#exclusion-status');
            
            $btn.prop('disabled', true);
            $status.html('<span style="color: orange;">Admin lädt...</span>');
            
            FahrplanAdmin.ajaxCall('load_exclusion_words', {}, {
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
        
        // ========================================
        // LINIEN-MAPPING
        // ========================================
        
        // Speichern
        $('#save-line-mapping').on('click', function() {
            var $btn = $(this);
            var $status = $('#mapping-status');
            var mappingText = $('#line-mapping').val();
            
            $btn.prop('disabled', true);
            $status.html('<span style="color: orange;">Admin speichert...</span>');
            
            FahrplanAdmin.ajaxCall('save_line_mapping', {line_mapping: mappingText}, {
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
        
        // Mapping mit DB aktualisieren
        $('#update-mapping-in-db').on('click', function() {
            var $btn = $(this);
            var $status = $('#mapping-status');
            
            // Bestätigung anfordern
            if (!confirm('Mapping-Tabelle mit Datenbank abgleichen?\n\nDies aktualisiert alle bestehenden Fahrpläne mit den neuen Mapping-Zuordnungen.')) {
                return;
            }
            
            // Button deaktivieren und Status anzeigen
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite; vertical-align: middle; margin-right: 5px;"></span>Gleiche ab...');
            $status.html('<span style="color: orange;">🔄 Prüfe alle Fahrpläne gegen aktuelle Mapping-Konfiguration...</span>');
            
            console.log('🔄 FAHRPLANPORTAL: Starte Mapping-DB-Abgleich');
            
            FahrplanAdmin.ajaxCall('update_mapping_in_db', {}, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Mapping-DB-Abgleich erfolgreich:', response);
                    
                    // Button zurücksetzen
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Mapping Tabelle mit dB aktualisieren');
                    
                    // Erfolgs-Nachricht mit Details
                    var message = '✅ DB-Abgleich abgeschlossen:<br>';
                    message += '📊 ' + response.total_fahrplaene + ' Fahrpläne geprüft<br>';
                    message += '✏️ ' + response.updates_performed + ' Aktualisierungen durchgeführt<br>';
                    message += '✔️ ' + response.already_correct + ' bereits korrekt<br>';
                    
                    if (response.no_mapping_found > 0) {
                        message += '⚠️ ' + response.no_mapping_found + ' ohne Mapping<br>';
                    }
                    
                    if (response.updates_failed > 0) {
                        message += '❌ ' + response.updates_failed + ' fehlgeschlagen<br>';
                    }
                    
                    // Change-Details anzeigen falls vorhanden
                    if (response.change_details && response.change_details.length > 0) {
                        message += '<br><strong>Beispiel-Änderungen:</strong><br>';
                        response.change_details.slice(0, 3).forEach(function(change) {
                            message += '• ' + change.linie_neu + ': "' + change.old_linie_alt + '" → "' + change.new_linie_alt + '"<br>';
                        });
                        
                        if (response.change_details.length > 3) {
                            message += '• ... und ' + (response.change_details.length - 3) + ' weitere<br>';
                        }
                    }
                    
                    $status.html('<span style="color: green;">' + message + '</span>');
                    
                    // Bei Änderungen automatisch Seite neu laden nach 3 Sekunden
                    if (response.updates_performed > 0) {
                        setTimeout(function() {
                            if (confirm('Es wurden ' + response.updates_performed + ' Fahrpläne aktualisiert.\n\nSeite neu laden um Änderungen zu sehen?')) {
                                location.reload();
                            }
                        }, 3000);
                    } else {
                        // Status nach 5 Sekunden ausblenden
                        setTimeout(function() {
                            $status.html('');
                        }, 5000);
                    }
                },
                error: function(error) {
                    console.error('❌ FAHRPLANPORTAL: Mapping-DB-Abgleich fehlgeschlagen:', error);
                    
                    // Button zurücksetzen
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Mapping Tabelle mit dB aktualisieren');
                    
                    // Fehler anzeigen
                    $status.html('<span style="color: red;">❌ Fehler beim DB-Abgleich: ' + (error.message || 'Unbekannter Fehler') + '</span>');
                    
                    // Fehler nach 8 Sekunden ausblenden
                    setTimeout(function() {
                        $status.html('');
                    }, 8000);
                }
            });
        });
        
        // Laden
        $('#load-line-mapping').on('click', function() {
            var $btn = $(this);
            var $status = $('#mapping-status');
            
            $btn.prop('disabled', true);
            $status.html('<span style="color: orange;">Admin lädt...</span>');
            
            FahrplanAdmin.ajaxCall('load_line_mapping', {}, {
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
        
        // ========================================
        // DB-WARTUNG
        // ========================================
        
        // DB neu erstellen
        $('#recreate-db').on('click', function() {
            if (!confirm('Admin: Wirklich die komplette Datenbank neu erstellen? Alle Daten gehen verloren!')) {
                return;
            }
            
            FahrplanAdmin.ajaxCall('recreate_db', {}, {
                success: function(response) {
                    alert('Admin: Datenbank erfolgreich neu erstellt');
                    location.reload();
                },
                error: function(error) {
                    alert('Admin Fehler: ' + error.message);
                }
            });
        });
        
        // DB leeren
        $('#clear-db').on('click', function() {
            if (!confirm('Admin: Wirklich alle Fahrpläne löschen?')) {
                return;
            }
            
            FahrplanAdmin.ajaxCall('clear_db', {}, {
                success: function(response) {
                    alert('Admin: Alle Fahrpläne erfolgreich gelöscht');
                    location.reload();
                },
                error: function(error) {
                    alert('Admin Fehler: ' + error.message);
                }
            });
        });
        
        // ========================================
        // TAG-CLEANUP
        // ========================================
        
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
            FahrplanAdmin.ajaxCall('cleanup_existing_tags', {}, {
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
        
        // Hilfs-Tooltip für Tag-Cleanup Button
        $('#cleanup-existing-tags').on('mouseenter', function() {
            $(this).attr('title', 
                'Entfernt alle Exklusionswörter aus bereits gespeicherten Tags in der Datenbank. ' +
                'Nützlich nach Änderungen an der Exklusionsliste.'
            );
        });
        
        console.log('✅ FAHRPLANPORTAL: DB-Maintenance-Modul vollständig initialisiert');
    }
    
}); // Ende jQuery ready
