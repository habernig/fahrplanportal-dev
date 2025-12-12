/**
 * FahrplanPortal Admin - Tags Module
 * 
 * Enthält:
 * - Tag-Analyse
 * - Tag-Ergebnisse anzeigen
 * - Tag-Copy-Funktionalität
 * 
 * @version 2.0.0
 * @requires admin-core.js
 */

jQuery(document).ready(function($) {
    
    // Warte auf Core-Modul
    $(document).on('fahrplanAdmin:ready', function() {
        console.log('🏷️ FAHRPLANPORTAL: Tags-Modul wird initialisiert...');
        initTagsModule();
    });
    
    // Falls Core bereits initialisiert ist
    if (typeof FahrplanAdmin !== 'undefined' && FahrplanAdmin.initialized) {
        initTagsModule();
    }
    
    function initTagsModule() {
        
        // ========================================
        // TAG-ANALYSE EVENT-HANDLER
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
            
            FahrplanAdmin.ajaxCall('analyze_all_tags', {}, {
                success: function(response) {
                    console.log('✅ FAHRPLANPORTAL: Tag-Analyse erfolgreich:', response);
                    
                    // Button zurücksetzen
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-search" style="vertical-align: middle; margin-right: 5px;"></span>' +
                        'Alle Tags analysieren'
                    );
                    
                    // Response validieren
                    if (!response || (!response.statistics && !response.analysis)) {
                        console.error('❌ FAHRPLANPORTAL: Ungültige Response-Struktur:', response);
                        $status.html('<span style="color: red;">❌ Fehler: Ungültige Datenstruktur</span>');
                        return;
                    }
                    
                    // Erfolgs-Status anzeigen
                    var totalTags = (response.statistics && response.statistics.total_unique_tags) || 0;
                    $status.html('<span style="color: green;">✅ Analyse abgeschlossen (' + totalTags + ' eindeutige Tags)</span>');
                    
                    // Ergebnisse anzeigen
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
        // TAG-ANALYSE ERGEBNISSE ANZEIGEN
        // ========================================
        
        /**
         * Tag-Analyse Ergebnisse anzeigen
         */
        function displayTagAnalysisResults(response) {
            console.log('📊 FAHRPLANPORTAL: Zeige Tag-Analyse Ergebnisse an:', response);
            
            // Datenstruktur prüfen
            if (!response || !response.data) {
                console.error('❌ FAHRPLANPORTAL: Ungültige Response-Struktur:', response);
                return;
            }
            
            var data = response.data;
            
            // Sichere Navigation zur statistics und analysis
            var stats = data.statistics || {};
            var analysis = data.analysis || {};
            
            console.log('📊 FAHRPLANPORTAL: Extrahierte Stats:', stats);
            console.log('📊 FAHRPLANPORTAL: Extrahierte Analysis:', analysis);
            
            // Container anzeigen
            $('#tag-analysis-results').show();
            
            // Statistiken füllen (mit Fallback-Werten)
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
            
            // Bereits ausgeschlossene Tags (GRÜN)
            var excludedHtml = '';
            var excludedTags = analysis.excluded_tags || [];
            var excludedTagsTotal = analysis.excluded_tags_total || excludedTags.length;
            
            if (excludedTags && excludedTags.length > 0) {
                // Einfache kommagetrennte Liste
                excludedHtml = '<div style="padding: 5px; line-height: 1.6; word-wrap: break-word;">' + 
                    excludedTags.map(function(tag) {
                        return FahrplanAdmin.escapeHtml(tag);
                    }).join(', ') + '</div>';
            } else if (excludedTagsTotal === 0) {
                excludedHtml = '<div style="text-align: center; color: #666; font-style: italic;">Keine Tags in der Exklusionsliste gefunden</div>';
            }
            $('#excluded-tags-list').html(excludedHtml);
            $('#excluded-tags-count').text(excludedTagsTotal);
            
            // Noch nicht ausgeschlossene Tags (ROT)
            var notExcludedHtml = '';
            var notExcludedTags = analysis.not_excluded_tags || [];
            var notExcludedTagsTotal = analysis.not_excluded_tags_total || notExcludedTags.length;
            
            if (notExcludedTags && notExcludedTags.length > 0) {
                // Einfache kommagetrennte Liste
                notExcludedHtml = '<div style="padding: 5px; line-height: 1.6; word-wrap: break-word;">' + 
                    notExcludedTags.map(function(tag) {
                        return FahrplanAdmin.escapeHtml(tag);
                    }).join(', ') + '</div>';
            } else if (notExcludedTagsTotal === 0) {
                notExcludedHtml = '<div style="text-align: center; color: #666; font-style: italic;">Alle Tags sind bereits ausgeschlossen! 🎉</div>';
            }
            $('#not-excluded-tags-list').html(notExcludedHtml);
            $('#not-excluded-tags-count').text(notExcludedTagsTotal);
            
            // Variablen für Event-Handler verfügbar machen
            var _notExcludedTags = notExcludedTags;
            var _notExcludedTagsTotal = notExcludedTagsTotal;
            
            // Zusätzliche Analysen vorbereiten
            if (analysis && analysis.top_frequent_tags) {
                // Top häufige Tags
                var frequentHtml = '';
                var counter = 1;
                for (var tag in analysis.top_frequent_tags) {
                    var count = analysis.top_frequent_tags[tag];
                    frequentHtml += `<div>${counter}. <strong>${FahrplanAdmin.escapeHtml(tag)}</strong> (${count}x)</div>`;
                    counter++;
                }
                $('#frequent-tags-list').html(frequentHtml || '<div>Keine häufigen Tags gefunden</div>');
                
                // Kurze Tags
                var shortTags = analysis.short_tags || [];
                var shortHtml = shortTags.length > 0 ? 
                    shortTags.map(tag => '<code>' + FahrplanAdmin.escapeHtml(tag) + '</code>').join(', ') : 
                    'Keine kurzen Tags gefunden';
                $('#short-tags-list').html(shortHtml);
                
                // Lange Tags  
                var longTags = analysis.long_tags || [];
                var longHtml = longTags.length > 0 ? 
                    longTags.map(tag => '<code>' + FahrplanAdmin.escapeHtml(tag) + '</code>').join(', ') : 
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
            
            // Event-Handler für Aktions-Buttons
            $('#copy-red-tags').off('click').on('click', function() {
                // Nutze ALLE angezeigten Tags für die Kopier-Funktion
                if (_notExcludedTags && _notExcludedTags.length > 0) {
                    // Für Kopieren nutzen wir Komma als Trenner
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
        
        /**
         * Fallback für Kopieren in Zwischenablage
         */
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
        
        console.log('✅ FAHRPLANPORTAL: Tags-Modul vollständig initialisiert');
    }
    
}); // Ende jQuery ready
