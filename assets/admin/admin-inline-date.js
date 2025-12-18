/**
 * FahrplanPortal Admin - Inline Datum Editor (Bootstrap)
 * Ermöglicht direkte Bearbeitung von "Gültig von" und "Gültig bis" Spalten
 */

(function($) {
    'use strict';
    
    console.log('📅 FAHRPLANPORTAL: Inline-Datum-Editor Modul geladen');
    
    $(document).ready(function() {
        
        // ✅ GEFIXT: Prüfen ob FahrplanAdmin bereits bereit ist
        if (typeof FahrplanAdmin !== 'undefined' && FahrplanAdmin.initialized) {
            // FahrplanAdmin ist bereits fertig initialisiert
            console.log('✅ FAHRPLANPORTAL: FahrplanAdmin bereits bereit, starte Inline-Datum-Editor sofort');
            initInlineDateEditing();
        } else {
            // Warten auf fahrplanAdmin:ready Event
            console.log('⏳ FAHRPLANPORTAL: Warte auf fahrplanAdmin:ready Event');
            $(document).on('fahrplanAdmin:ready', function() {
                console.log('✅ FAHRPLANPORTAL: fahrplanAdmin:ready Event empfangen, initialisiere Inline-Datum-Editor');
                initInlineDateEditing();
            });
        }
        
        /**
         * Inline-Datum-Editor initialisieren
         */
        function initInlineDateEditing() {
            
            $('#fahrplaene-table').on('click', 'td.editable-date:not(.editing)', function(e) {
                e.stopPropagation();
                
                var $cell = $(this);
                var $row = $cell.closest('tr');
                
                // ✅ GEFIXT: ID aus DOM-Attribut lesen statt aus DataTable
                var fahrplanId = $row.attr('data-id');
                
                // Prüfen ob es "Gültig von" oder "Gültig bis" ist
                var isGueltigVon = $cell.hasClass('col-gueltig-von');
                var fieldName = isGueltigVon ? 'gueltig_von' : 'gueltig_bis';
                var currentText = $cell.text().trim();
                
                console.log('🖱️ Datumszelle geklickt:', {
                    id: fahrplanId,
                    field: fieldName,
                    current: currentText
                });
                
                // Validierung: ID muss vorhanden sein
                if (!fahrplanId) {
                    console.error('❌ Keine ID gefunden für diese Zeile!');
                    alert('Fehler: Keine ID gefunden!');
                    return;
                }
                
                // In Edit-Modus wechseln
                $cell.addClass('editing');
                showInlineDateEditor($cell, fahrplanId, fieldName, currentText);
            });


            // ✅ NEU: Click-Handler für editierbare Text-Spalten
            $('#fahrplaene-table').on('click', 'td.editable-text:not(.editing)', function(e) {
                e.stopPropagation();
                
                var $cell = $(this);
                var $row = $cell.closest('tr');
                
                // ID aus DOM-Attribut lesen
                var fahrplanId = $row.attr('data-id');
                
                // Feldname ermitteln (aus CSS-Klasse)
                var fieldName = 'titel'; // Erstmal nur Titel, später erweiterbar
                if ($cell.hasClass('editable-orte')) {
                    fieldName = 'orte';
                }
                
                var currentText = $cell.text().trim();
                
                console.log('🖱️ Text-Zelle geklickt:', {
                    id: fahrplanId,
                    field: fieldName,
                    current: currentText
                });
                
                // Validierung: ID muss vorhanden sein
                if (!fahrplanId) {
                    console.error('❌ Keine ID gefunden für diese Zeile!');
                    alert('Fehler: Keine ID gefunden!');
                    return;
                }
                
                // In Edit-Modus wechseln
                $cell.addClass('editing');
                showInlineTextEditor($cell, fahrplanId, fieldName, currentText);
            });
            
        }
        
        /**
         * Bootstrap Inline-Editor anzeigen
         */
        function showInlineDateEditor($cell, fahrplanId, fieldName, currentValue) {
            // Deutsches Datum in ISO-Format konvertieren
            var isoDate = convertGermanToISO(currentValue);
            
            console.log('📝 Editor öffnen für:', fieldName, '| ISO:', isoDate);
            
            // Bootstrap Input-Group HTML erstellen
            var editorHtml = `
                <div class="input-group input-group-sm inline-date-group">
                    <input type="date" 
                           class="form-control form-control-sm inline-date-input" 
                           value="${isoDate}"
                           data-id="${fahrplanId}"
                           data-field="${fieldName}">
                    <button class="btn btn-success btn-sm save-date" type="button" title="Speichern">
                        <i class="dashicons dashicons-yes"></i>
                    </button>
                    <button class="btn btn-secondary btn-sm cancel-date" type="button" title="Abbrechen">
                        <i class="dashicons dashicons-no"></i>
                    </button>
                </div>
            `;
            
            // Zelle durch Editor ersetzen
            $cell.html(editorHtml);
            $cell.find('.inline-date-input').focus();
            
            // Event-Handler registrieren
            setupEditorEvents($cell, currentValue);
        }
        
        /**
         * Event-Handler für Editor einrichten
         */
        function setupEditorEvents($cell, originalValue) {
            var $input = $cell.find('.inline-date-input');
            var $saveBtn = $cell.find('.save-date');
            var $cancelBtn = $cell.find('.cancel-date');
            
            // Speichern-Button
            $saveBtn.on('click', function() {
                console.log('💾 Speichern-Button geklickt');
                saveInlineDate($cell, $input.data('id'), $input.data('field'), $input.val(), originalValue);
            });
            
            // Abbrechen-Button
            $cancelBtn.on('click', function() {
                console.log('❌ Abbrechen-Button geklickt');
                cancelInlineEdit($cell, originalValue);
            });
            
            // Enter-Taste = Speichern
            $input.on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    console.log('⌨️ Enter gedrückt - Speichern');
                    $saveBtn.click();
                }
            });
            
            // ESC-Taste = Abbrechen
            $input.on('keyup', function(e) {
                if (e.which === 27) {
                    console.log('⌨️ ESC gedrückt - Abbrechen');
                    $cancelBtn.click();
                }
            });
            
            // Klick außerhalb der Zelle = Speichern
            $(document).on('click.inlineDateEditor', function(e) {
                if (!$(e.target).closest('.inline-date-group').length && 
                    !$(e.target).closest('.editing').length) {
                    console.log('🖱️ Außerhalb geklickt - Speichern');
                    $saveBtn.click();
                    $(document).off('click.inlineDateEditor');
                }
            });
        }





        
        /**
         * Datum via AJAX speichern
         */
        function saveInlineDate($cell, fahrplanId, fieldName, newDate, originalValue) {
            
            // ✅ DEBUG: Parameter ausgeben
            console.log('💾 SAVE DEBUG:', {
                fahrplanId: fahrplanId,
                fieldName: fieldName,
                newDate: newDate,
                originalValue: originalValue
            });

            // Validierung
            if (!newDate || newDate === '') {
                console.warn('⚠️ Kein Datum eingegeben');
                alert('Bitte ein gültiges Datum eingeben!');
                cancelInlineEdit($cell, originalValue);
                return;
            }
            
            console.log('🔄 AJAX-Speicherung starten:', {
                id: fahrplanId,
                field: fieldName,
                newDate: newDate
            });
            
            // Loading-State anzeigen
            $cell.html(`
                <div class="d-flex align-items-center justify-content-center">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                        <span class="visually-hidden">Speichern...</span>
                    </div>
                    <span class="text-muted small">Speichern...</span>
                </div>
            `);
            
            // AJAX-Daten vorbereiten
            var updateData = {
                id: fahrplanId
            };
            updateData[fieldName] = newDate;
            
            // AJAX-Call über FahrplanAdmin Helper
            FahrplanAdmin.ajaxCall('update_fahrplan', updateData, {
                success: function(response) {
                    console.log('✅ Speichern erfolgreich:', response);
                    
                    var germanDate = convertISOToGerman(newDate);
                    
                    // Success-State mit Bootstrap Badge
                    $cell.html(`
                        <span class="badge bg-success me-2">✓</span>
                        <span>${germanDate}</span>
                    `);
                    
                    // Nach 1.5 Sekunden Badge entfernen
                    setTimeout(function() {
                        $cell.html(germanDate);
                        $cell.removeClass('editing');
                    }, 1500);
                    
                    // Optional: Toast-Benachrichtigung
                    showToast('Gespeichert', 'Datum erfolgreich aktualisiert', 'success');
                },
                error: function(error) {
                    console.error('❌ Speichern fehlgeschlagen:', error);
                    
                    // Fehler-State anzeigen
                    $cell.html(`
                        <span class="badge bg-danger me-2">✗</span>
                        <span class="text-danger small">Fehler</span>
                    `);
                    
                    // Nach 2 Sekunden Original-Wert wiederherstellen
                    setTimeout(function() {
                        $cell.html(originalValue);
                        $cell.removeClass('editing');
                    }, 2000);
                    
                    // Fehler anzeigen
                    alert('Speichern fehlgeschlagen: ' + (error.message || 'Unbekannter Fehler'));
                }
            });
        }
        
        /**
         * Bearbeitung abbrechen
         */
        function cancelInlineEdit($cell, originalValue) {
            console.log('↩️ Bearbeitung abgebrochen, Original-Wert wiederhergestellt');
            $cell.html(originalValue);
            $cell.removeClass('editing');
            $(document).off('click.inlineDateEditor');
        }
        
        /**
         * Toast-Benachrichtigung anzeigen (optional)
         */
        function showToast(title, message, type) {
            // Einfache Alert-Variante (kann später durch Bootstrap Toast ersetzt werden)
            console.log('📢 Toast:', type, title, message);
            // Für jetzt: Stilles Log, später können wir Bootstrap Toasts hinzufügen
        }
        
        /**
         * Helper: Deutsches Datum → ISO-Format
         * "14.12.2025" → "2025-12-14"
         */
        function convertGermanToISO(germanDate) {
            var parts = germanDate.split('.');
            if (parts.length === 3) {
                var day = parts[0].padStart(2, '0');
                var month = parts[1].padStart(2, '0');
                var year = parts[2];
                return year + '-' + month + '-' + day;
            }
            return '';
        }
        
        /**
         * Helper: ISO-Format → Deutsches Datum
         * "2025-12-14" → "14.12.2025"
         */
        function convertISOToGerman(isoDate) {
            var parts = isoDate.split('-');
            if (parts.length === 3) {
                var year = parts[0];
                var month = parts[1];
                var day = parts[2];
                return day + '.' + month + '.' + year;
            }
            return isoDate;
        }


        /**
 * ✅ NEU: Bootstrap Inline-Text-Editor anzeigen
 */
function showInlineTextEditor($cell, fahrplanId, fieldName, currentValue) {
    console.log('📝 Text-Editor öffnen für:', fieldName, '| Wert:', currentValue);
    
    // Bootstrap Input-Group HTML erstellen
    var editorHtml = `
        <div class="input-group input-group-sm inline-text-group">
            <input type="text" 
                   class="form-control form-control-sm inline-text-input" 
                   value="${escapeHtml(currentValue)}"
                   data-id="${fahrplanId}"
                   data-field="${fieldName}"
                   placeholder="${fieldName === 'titel' ? 'Titel eingeben' : 'Text eingeben'}">
            <button class="btn btn-success btn-sm save-text" type="button" title="Speichern">
                <i class="dashicons dashicons-yes"></i>
            </button>
            <button class="btn btn-secondary btn-sm cancel-text" type="button" title="Abbrechen">
                <i class="dashicons dashicons-no"></i>
            </button>
        </div>
    `;
    
    // Zelle durch Editor ersetzen
    $cell.html(editorHtml);
    var $input = $cell.find('.inline-text-input');
    $input.focus();
    
    // Text selektieren für schnelles Überschreiben
    $input[0].select();
    
    // Event-Handler registrieren
    setupTextEditorEvents($cell, currentValue);
}

/**
 * ✅ NEU: Event-Handler für Text-Editor einrichten
 */
function setupTextEditorEvents($cell, originalValue) {
    var $input = $cell.find('.inline-text-input');
    var $saveBtn = $cell.find('.save-text');
    var $cancelBtn = $cell.find('.cancel-text');
    
    // Speichern-Button
    $saveBtn.on('click', function() {
        console.log('💾 Text-Speichern-Button geklickt');
        saveInlineText($cell, $input.data('id'), $input.data('field'), $input.val(), originalValue);
    });
    
    // Abbrechen-Button
    $cancelBtn.on('click', function() {
        console.log('❌ Text-Abbrechen-Button geklickt');
        cancelInlineEdit($cell, originalValue);
    });
    
    // Enter-Taste = Speichern
    $input.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            console.log('⌨️ Enter gedrückt - Speichern');
            $saveBtn.click();
        }
    });
    
    // ESC-Taste = Abbrechen
    $input.on('keyup', function(e) {
        if (e.which === 27) {
            console.log('⌨️ ESC gedrückt - Abbrechen');
            $cancelBtn.click();
        }
    });
    
    // Klick außerhalb = Speichern
    $(document).on('click.inlineTextEditor', function(e) {
        if (!$(e.target).closest('.inline-text-group').length && 
            !$(e.target).closest('.editing').length) {
            console.log('🖱️ Außerhalb geklickt - Speichern');
            $saveBtn.click();
            $(document).off('click.inlineTextEditor');
        }
    });
}

/**
 * ✅ NEU: Text via AJAX speichern
 */
function saveInlineText($cell, fahrplanId, fieldName, newText, originalValue) {
    // Validierung: Text sollte nicht leer sein (außer explizit gewollt)
    if (newText === '') {
        var confirmed = confirm('Möchten Sie den Text wirklich leeren?');
        if (!confirmed) {
            cancelInlineEdit($cell, originalValue);
            return;
        }
    }
    
    // Keine Änderung? Abbrechen
    if (newText === originalValue) {
        console.log('ℹ️ Keine Änderung, Editor schließen');
        cancelInlineEdit($cell, originalValue);
        return;
    }
    
    console.log('🔄 AJAX-Speicherung starten:', {
        id: fahrplanId,
        field: fieldName,
        oldText: originalValue,
        newText: newText
    });
    
    // Loading-State anzeigen
    $cell.html(`
        <div class="d-flex align-items-center justify-content-center">
            <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                <span class="visually-hidden">Speichern...</span>
            </div>
            <span class="text-muted small">Speichern...</span>
        </div>
    `);
    
    // AJAX-Daten vorbereiten
    var updateData = {
        id: fahrplanId
    };
    updateData[fieldName] = newText;
    
    // AJAX-Call über FahrplanAdmin Helper
    FahrplanAdmin.ajaxCall('update_fahrplan', updateData, {
        success: function(response) {
            console.log('✅ Text-Speichern erfolgreich:', response);
            
            // Success-State mit Bootstrap Badge
            $cell.html(`
                <span class="badge bg-success me-2">✓</span>
                <span>${escapeHtml(newText)}</span>
            `);
            
            // Nach 1.5 Sekunden Badge entfernen
            setTimeout(function() {
                $cell.html(escapeHtml(newText));
                $cell.removeClass('editing');
            }, 1500);
        },
        error: function(error) {
            console.error('❌ Text-Speichern fehlgeschlagen:', error);
            
            // Fehler-State anzeigen
            $cell.html(`
                <span class="badge bg-danger me-2">✗</span>
                <span class="text-danger small">Fehler</span>
            `);
            
            // Nach 2 Sekunden Original-Wert wiederherstellen
            setTimeout(function() {
                $cell.html(escapeHtml(originalValue));
                $cell.removeClass('editing');
            }, 2000);
            
            // Fehler anzeigen
            alert('Speichern fehlgeschlagen: ' + (error.message || 'Unbekannter Fehler'));
        }
    });
}

/**
 * Helper: HTML escapen für sichere Anzeige
 */
function escapeHtml(text) {
    var map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}
        
        
    });
    
})(jQuery);