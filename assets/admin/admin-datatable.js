/**
 * FahrplanPortal Admin - DataTable Module
 * 
 * Enthält:
 * - DataTables Initialisierung
 * - Region-Filter mit sessionStorage-Persistenz
 * - Tag-Tooltips
 * - Filter-Status-Anzeige
 * 
 * @version 2.0.0
 * @requires admin-core.js
 */

jQuery(document).ready(function($) {
    
    // Warte auf Core-Modul
    $(document).on('fahrplanAdmin:ready', function() {
        console.log('📊 FAHRPLANPORTAL: DataTable-Modul wird initialisiert...');
        initDataTableModule();
    });
    
    // Falls Core bereits initialisiert ist
    if (typeof FahrplanAdmin !== 'undefined' && FahrplanAdmin.initialized) {
        initDataTableModule();
    }
    
    function initDataTableModule() {
        
        var pdfParsingEnabled = FahrplanAdmin.pdfParsingEnabled;
        
        // ========================================
        // DATATABLES INITIALISIERUNG
        // ========================================
        
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
            var tagsColumnIndex = pdfParsingEnabled ? 11 : -1;
            var actionsColumnIndex = pdfParsingEnabled ? 12 : 11;
            
            console.log('FAHRPLANPORTAL: Admin DataTables - Tags-Index:', tagsColumnIndex, 'Aktionen-Index:', actionsColumnIndex);
            
            var datatableConfig = {
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/German.json"
                },
                "responsive": true,
                "lengthMenu": [ 10, 25, 50, 200, -1 ],
                "pageLength": 200,
                "order": [[ 8, "asc" ]],
                "scrollX": false,
                "columnDefs": [
                    { "orderable": false, "targets": [actionsColumnIndex] },
                    { "width": "50px", "targets": [0] },          // ID
                    { "width": "80px", "targets": [1, 2] },       // Linie Alt, Linie Neu
                    { "width": "auto", "targets": [3] },          // Titel
                    { 
                        "width": "90px", 
                        "targets": [4, 5],                        // Gültig von, Gültig bis
                        "type": "date-de"
                    },
                    { "width": "80px", "targets": [6] },          // Status (NEU)
                    { "width": "70px", "targets": [7] },          // Ordner
                    { "width": "100px", "targets": [8] },         // Region
                    { "width": "50px", "targets": [9] },          // PDF
                    { "width": "150px", "targets": [10] },        // Kurzbeschreibung
                    { "width": "120px", "targets": [actionsColumnIndex] } // Aktionen
                ],
                "initComplete": function() {
                    var table = this.api();  // ✅ Sichere Referenz zur DataTable
                    
                    populateRegionFilter();
                    
                    // ✅ NEU: Filter-Button initial verstecken
                    $('#clear-filter').hide();
                    
                    // ✅ FIX: Gespeicherten Region-Filter NACH populateRegionFilter wiederherstellen
                    var savedRegion = sessionStorage.getItem('fahrplan_region_filter');
                    if (savedRegion) {
                        $('#region-filter').val(savedRegion);
                        // DataTable neu zeichnen mit Filter
                        table.draw();
                        updateFilterStatus(savedRegion, table);
                        // ✅ NEU: Button anzeigen wenn Filter aktiv
                        updateClearFilterButton(savedRegion);
                        console.log('✅ FAHRPLANPORTAL: Region-Filter wiederhergestellt:', savedRegion);
                    }
                    
                    if (pdfParsingEnabled) {
                        addTagTooltips();
                    }
                    
                    console.log('✅ FAHRPLANPORTAL: Admin DataTables initialisiert');
                },
                "drawCallback": function() {
                    if (pdfParsingEnabled) {
                        addTagTooltips();
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
            
            // Im Namespace speichern für andere Module
            FahrplanAdmin.dataTable = fahrplaeneTable;
            
            // Region-Filter Setup
            setupRegionFilter(fahrplaeneTable);
            
            // ✅ HINWEIS: sessionStorage-Wiederherstellung passiert in initComplete
            
        } catch (error) {
            console.error('FAHRPLANPORTAL: Admin DataTables Fehler:', error);
        }
        
        // ========================================
        // TAG TOOLTIPS
        // ========================================
        
        function addTagTooltips() {
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
        
        // ========================================
        // REGION-FILTER
        // ========================================
        
        function populateRegionFilter() {
            var regions = new Set();
            var regionFilter = $('#region-filter');
            
            $('#fahrplaene-table tbody tr').each(function() {
                var regionCell = $(this).find('td:nth-child(9)');
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
        
        function setupRegionFilter(fahrplaeneTable) {
            // DataTables Filter-Funktion registrieren
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var selectedRegion = $('#region-filter').val();
                var regionColumn = data[8];
                
                if (!selectedRegion || selectedRegion === '') {
                    return true;
                }
                
                return regionColumn === selectedRegion;
            });
            
            // Filter-Änderung Handler
            $('#region-filter').on('change', function() {
                var selectedRegion = $(this).val();
                console.log('FAHRPLANPORTAL: Admin Region-Filter geändert zu:', selectedRegion);
                
                // ✅ NEU: In sessionStorage speichern für Persistenz nach Reload
                if (selectedRegion) {
                    sessionStorage.setItem('fahrplan_region_filter', selectedRegion);
                } else {
                    sessionStorage.removeItem('fahrplan_region_filter');
                }
                
                // ✅ NEU: Button ein-/ausblenden
                updateClearFilterButton(selectedRegion);
                
                if (fahrplaeneTable) {
                    fahrplaeneTable.draw();
                }
                
                updateFilterStatus(selectedRegion, fahrplaeneTable);
            });
            
            // Clear-Button Handler
            $('#clear-filter').on('click', function() {
                $('#region-filter').val('');
                
                // ✅ NEU: Aus sessionStorage entfernen
                sessionStorage.removeItem('fahrplan_region_filter');
                
                // ✅ NEU: Button verstecken
                updateClearFilterButton('');
                
                if (fahrplaeneTable) {
                    fahrplaeneTable.draw();
                }
                updateFilterStatus('', fahrplaeneTable);
                console.log('FAHRPLANPORTAL: Admin Region-Filter zurückgesetzt');
            });
        }
        
        /**
         * ✅ NEU: Clear-Filter-Button ein-/ausblenden
         */
        function updateClearFilterButton(selectedRegion) {
            var $btn = $('#clear-filter');
            
            if (selectedRegion && selectedRegion !== '') {
                // Filter aktiv: Button anzeigen in Rot
                $btn.show().css({
                    'background-color': '#dc3545',
                    'border-color': '#dc3545',
                    'color': 'white'
                });
            } else {
                // Kein Filter: Button verstecken
                $btn.hide();
            }
        }
        
        function updateFilterStatus(selectedRegion, fahrplaeneTable) {
            var statusText = '';
            if (selectedRegion) {
                var filteredCount = fahrplaeneTable ? fahrplaeneTable.rows({search: 'applied'}).count() : 0;
                statusText = ' (gefiltert nach: ' + selectedRegion + ' - ' + filteredCount + ' Einträge)';
            }
            
            $('#filter-status').remove();
            $('.dataTables_info').append('<span id="filter-status">' + statusText + '</span>');
        }
        
        // ========================================
        // HINWEIS: Scan-Buttons werden im Scanning-Modul behandelt
        // ========================================
        
        console.log('✅ FAHRPLANPORTAL: DataTable-Modul vollständig initialisiert');
    }
    
}); // Ende jQuery ready
