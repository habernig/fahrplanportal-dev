/**
 * FahrplanPortal Admin - Modal Module
 * 
 * Enthält:
 * - Edit-Modal öffnen/schließen
 * - Fahrplan-Daten laden/speichern
 * - Tabellen-Klick-Handler
 * - Löschen-Funktionalität
 * 
 * @version 2.0.0
 * @requires admin-core.js
 */

jQuery(document).ready(function($) {
    
    // Warte auf Core-Modul
    $(document).on('fahrplanAdmin:ready', function() {
        console.log('📝 FAHRPLANPORTAL: Modal-Modul wird initialisiert...');
        initModalModule();
    });
    
    // Falls Core bereits initialisiert ist
    if (typeof FahrplanAdmin !== 'undefined' && FahrplanAdmin.initialized) {
        initModalModule();
    }
    
    function initModalModule() {
        
        var pdfParsingEnabled = FahrplanAdmin.pdfParsingEnabled;
        
        // ========================================
        // MODAL FUNKTIONEN
        // ========================================
        
        /**
         * Edit-Modal öffnen
         */
        function openEditModal(fahrplanId) {
            console.log('FAHRPLANPORTAL: Öffne Admin Modal für ID:', fahrplanId);
            
            $('#fahrplan-edit-modal').fadeIn(300);
            
            FahrplanAdmin.ajaxCall('get_fahrplan', {id: fahrplanId}, {
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
                    closeEditModal();
                }
            });
        }
        
        /**
         * Edit-Modal schließen
         */
        function closeEditModal() {
            $('#fahrplan-edit-modal').fadeOut(300);
            $('#fahrplan-edit-form')[0].reset();
            $('#edit-id').val('');
            console.log('FAHRPLANPORTAL: Admin Modal geschlossen');
        }
        
        /**
         * Änderungen speichern
         */
        function saveModalChanges() {
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
            
            FahrplanAdmin.ajaxCall('update_fahrplan', formData, {
                success: function(response) {
                    console.log('FAHRPLANPORTAL: Admin Speichern erfolgreich');
                    
                    saveButton.text('✓ Gespeichert').addClass('success');
                    
                    setTimeout(function() {
                        closeEditModal();
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
        
        // Im Namespace verfügbar machen
        FahrplanAdmin.openEditModal = openEditModal;
        FahrplanAdmin.closeEditModal = closeEditModal;
        
        // ========================================
        // EVENT-HANDLER
        // ========================================
        
        // Bearbeiten-Button in Admin-Tabelle
        $('#fahrplaene-table').on('click', '.edit-fahrplan', function() {
            var fahrplanId = $(this).data('id');
            openEditModal(fahrplanId);
        });
        
        // ✅ Klickbare Linie NEU (Spalte 3) statt Linie ALT (Spalte 2) in Admin
        $('#fahrplaene-table').on('click', 'td:nth-child(3)', function() {
            var lineText = $(this).text().trim();
            if (lineText && lineText !== '') {
                var fahrplanId = $(this).closest('tr').data('id');
                
                if (fahrplanId) {
                    console.log('FAHRPLANPORTAL: Admin Linie NEU geklickt - ID:', fahrplanId, 'Linie:', lineText);
                    openEditModal(fahrplanId);
                } else {
                    console.warn('FAHRPLANPORTAL: Admin - Keine ID in Tabellenzeile gefunden');
                }
            }
        });
        
        // ✅ Hover-Effekt für Linie NEU (Spalte 3)
        $('#fahrplaene-table').on('mouseenter', 'td:nth-child(3)', function() {
            var lineText = $(this).text().trim();
            if (lineText && lineText !== '') {
                $(this).attr('title', 'Klicken um Fahrplan zu bearbeiten');
                $(this).css('cursor', 'pointer');
            }
        });
        
        // ✅ Linie ALT (Spalte 2) nicht mehr klickbar
        $('#fahrplaene-table').on('mouseenter', 'td:nth-child(2)', function() {
            $(this).css('cursor', 'default');
            $(this).removeAttr('title');
        });
        
        // Modal-Buttons
        $('#close-modal-btn').on('click', function() {
            closeEditModal();
        });
        
        $('#cancel-edit-btn').on('click', function() {
            closeEditModal();
        });
        
        $('#save-edit-btn').on('click', function() {
            saveModalChanges();
        });
        
        // Klick außerhalb des Modals schließt es
        $('#fahrplan-edit-modal').on('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        // ESC-Taste
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                if ($('#fahrplan-edit-modal').is(':visible')) {
                    closeEditModal();
                }
            }
        });
        
        // ========================================
        // FAHRPLAN LÖSCHEN
        // ========================================
        
        $('#fahrplaene-table').on('click', '.delete-fahrplan', function() {
            if (!confirm('Admin: Fahrplan wirklich löschen?')) {
                return;
            }
            
            var row = $(this).closest('tr');
            var id = $(this).data('id');
            
            FahrplanAdmin.ajaxCall('delete_fahrplan', {id: id}, {
                success: function(response) {
                    // DataTable-Zeile entfernen
                    if (FahrplanAdmin.dataTable) {
                        FahrplanAdmin.dataTable.row(row).remove().draw();
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
        
        console.log('✅ FAHRPLANPORTAL: Modal-Modul vollständig initialisiert');
    }
    
}); // Ende jQuery ready
