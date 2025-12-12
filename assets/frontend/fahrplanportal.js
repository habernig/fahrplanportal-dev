/**
 * Fahrplanportal Frontend JavaScript
 * ✅ VERBESSERT: Unterstützt Unified Ajax System UND Direct AJAX
 * ✅ NEU: hideResultsUntilSearch-Setting für bedingte Anzeige des Results-Containers
 */

window.fahrplanportalInit = function(uniqueId) {
    jQuery(document).ready(function($) {
        var config = window.fahrplanportalConfigs[uniqueId];
        
        if (!config) {
            console.error("❌ Keine Konfiguration für Fahrplanportal gefunden: " + uniqueId);
            return;
        }
        
        console.log("🚀 Fahrplanportal Init:", uniqueId, config);
        
        var maxResults = config.maxResults || 100;
        var predefinedRegion = config.predefinedRegion || false;
        var hideResultsUntilSearch = config.hideResultsUntilSearch || false;  // ✅ NEU
        
        var performSearch = null;
        var performAutocomplete = null;
        
        // Unified System Check
        if (typeof window.UnifiedAjaxAPI !== 'undefined' && 
            typeof window.UnifiedAjaxAPI.fahrplanportal_frontend !== 'undefined') {
            console.log("✅ Unified System verfügbar - nutze UnifiedAjaxAPI");
            initUnified();
        } else if (typeof fahrplanportal_direct !== 'undefined') {
            console.log("⚠️ Unified System nicht verfügbar - nutze Direct AJAX");
            initDirect();
        } else {
            console.error("❌ Kein AJAX-System verfügbar!");
            showError();
        }
        
        function initUnified() {
            var $container = $("#" + uniqueId);
            var $regionFilter = $container.find('.fahrplanportal-region-filter');
            var $textSearch = $container.find('.fahrplanportal-text-search');
            var $resetBtn = $container.find('.fahrplanportal-reset');
            var $autocompleteDropdown = $container.find('.autocomplete-dropdown');
            
            var $resultsWrapper = $container.find('.fahrplanportal-results');  // ✅ NEU: Results-Wrapper
            var $emptyState = $container.find('.fahrplanportal-empty-state');
            var $loading = $container.find('.fahrplanportal-loading');
            var $noResults = $container.find('.fahrplanportal-no-results');
            var $resultsList = $container.find('.fahrplanportal-results-list');
            var $resultsContainer = $container.find('.results-container');
            var $count = $container.find('.fahrplanportal-count');
            
            // Funktionen in äußerem Scope verfügbar machen
            performSearch = function() {
                var region = $regionFilter.val().trim();
                var searchText = $textSearch.val().trim();
                
                console.log('🔍 Unified Suche:', {region: region, searchText: searchText});
                
                if (!region && !searchText) {
                    // ✅ NEU: Bei Reset - wenn hideResultsUntilSearch aktiv, Container wieder verstecken
                    if (hideResultsUntilSearch && !predefinedRegion) {
                        $resultsWrapper.hide();
                    } else {
                        showEmptyState();
                    }
                    return;
                }
                
                // ✅ NEU: Results-Container einblenden bei erster Suche
                if (hideResultsUntilSearch && !predefinedRegion) {
                    $resultsWrapper.show();
                }
                
                showLoading();
                
                UnifiedAjaxAPI.fahrplanportal_frontend.search({
                    region: region,
                    search_text: searchText,
                    max_results: maxResults
                }, {
                    success: function(response) {
                        console.log('✅ Unified Search erfolgreich:', response);
                        if (response.count > 0) {
                            showResults(response.html, response.count);
                        } else {
                            showNoResults();
                        }
                    },
                    error: function(error) {
                        console.error('❌ Unified Search fehlgeschlagen:', error);
                        showNoResults();
                    }
                });
            };
            
            performAutocomplete = function(searchTerm) {
                if (searchTerm.length < 2) {
                    hideAutocomplete();
                    return;
                }
                
                $autocompleteDropdown.html('<div class="autocomplete-loading">Suche...</div>').addClass('show');
                
                UnifiedAjaxAPI.fahrplanportal_frontend.autocomplete({
                    search_term: searchTerm
                }, {
                    success: function(response) {
                        console.log('✅ Unified Autocomplete erfolgreich:', response);
                        if (response.suggestions && response.suggestions.length > 0) {
                            showAutocomplete(response.suggestions);
                        } else {
                            hideAutocomplete();
                        }
                    },
                    error: function(error) {
                        console.error('❌ Unified Autocomplete fehlgeschlagen:', error);
                        hideAutocomplete();
                    }
                });
            };
            
            setupUIAndEvents();
        }
        
        function initDirect() {
            var $container = $("#" + uniqueId);
            var $regionFilter = $container.find('.fahrplanportal-region-filter');
            var $textSearch = $container.find('.fahrplanportal-text-search');
            var $resetBtn = $container.find('.fahrplanportal-reset');
            var $autocompleteDropdown = $container.find('.autocomplete-dropdown');
            
            var $resultsWrapper = $container.find('.fahrplanportal-results');  // ✅ NEU: Results-Wrapper
            var $emptyState = $container.find('.fahrplanportal-empty-state');
            var $loading = $container.find('.fahrplanportal-loading');
            var $noResults = $container.find('.fahrplanportal-no-results');
            var $resultsList = $container.find('.fahrplanportal-results-list');
            var $resultsContainer = $container.find('.results-container');
            var $count = $container.find('.fahrplanportal-count');
            
            // Funktionen in äußerem Scope verfügbar machen
            performSearch = function() {
                var region = $regionFilter.val().trim();
                var searchText = $textSearch.val().trim();
                
                console.log('🔍 Direct Suche:', {region: region, searchText: searchText});
                
                if (!region && !searchText) {
                    // ✅ NEU: Bei Reset - wenn hideResultsUntilSearch aktiv, Container wieder verstecken
                    if (hideResultsUntilSearch && !predefinedRegion) {
                        $resultsWrapper.hide();
                    } else {
                        showEmptyState();
                    }
                    return;
                }
                
                // ✅ NEU: Results-Container einblenden bei erster Suche
                if (hideResultsUntilSearch && !predefinedRegion) {
                    $resultsWrapper.show();
                }
                
                showLoading();
                
                $.post(fahrplanportal_direct.ajax_url, {
                    action: fahrplanportal_direct.search_action,
                    nonce: fahrplanportal_direct.nonce,
                    region: region,
                    search_text: searchText,
                    max_results: maxResults
                }).done(function(response) {
                    console.log('✅ Direct Search erfolgreich:', response);
                    if (response.success && response.data.count > 0) {
                        showResults(response.data.html, response.data.count);
                    } else {
                        showNoResults();
                    }
                }).fail(function(error) {
                    console.error('❌ Direct Search fehlgeschlagen:', error);
                    showNoResults();
                });
            };
            
            performAutocomplete = function(searchTerm) {
                if (searchTerm.length < 2) {
                    hideAutocomplete();
                    return;
                }
                
                $autocompleteDropdown.html('<div class="autocomplete-loading">Suche...</div>').addClass('show');
                
                $.post(fahrplanportal_direct.ajax_url, {
                    action: fahrplanportal_direct.autocomplete_action,
                    nonce: fahrplanportal_direct.nonce,
                    search_term: searchTerm
                }).done(function(response) {
                    console.log('✅ Direct Autocomplete erfolgreich:', response);
                    if (response.success && response.data.suggestions && response.data.suggestions.length > 0) {
                        showAutocomplete(response.data.suggestions);
                    } else {
                        hideAutocomplete();
                    }
                }).fail(function(error) {
                    console.error('❌ Direct Autocomplete fehlgeschlagen:', error);
                    hideAutocomplete();
                });
            };
            
            setupUIAndEvents();
        }
        
        function setupUIAndEvents() {
            var $container = $("#" + uniqueId);
            var $regionFilter = $container.find('.fahrplanportal-region-filter');
            var $textSearch = $container.find('.fahrplanportal-text-search');
            var $resetBtn = $container.find('.fahrplanportal-reset');
            var $autocompleteDropdown = $container.find('.autocomplete-dropdown');
            var $resultsWrapper = $container.find('.fahrplanportal-results');  // ✅ NEU
            
            var searchTimeout = null;
            var autocompleteTimeout = null;
            
            $regionFilter.on('change', function() {
                console.log('📍 Region geändert:', $(this).val());
                performSearch();
            });
            
            $textSearch.on('input', function() {
                var searchTerm = $(this).val().trim();
                
                clearTimeout(autocompleteTimeout);
                autocompleteTimeout = setTimeout(function() {
                    performAutocomplete(searchTerm);
                }, 200);
                
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    if (searchTerm.length >= 2 || searchTerm.length === 0) {
                        performSearch();
                    }
                }, 400);
            });
            
            $textSearch.on('keypress', function(e) {
                if (e.which === 13) {
                    clearTimeout(searchTimeout);
                    hideAutocomplete();
                    performSearch();
                }
            });
            
            $autocompleteDropdown.on('click', '.autocomplete-item', function() {
                var selectedText = $(this).data('text');
                $textSearch.val(selectedText);
                hideAutocomplete();
                performSearch();
            });
            
            $(document).on('click', function(e) {
                if (!$container.find(e.target).length) {
                    hideAutocomplete();
                }
            });
            
            $resetBtn.on('click', function() {
                console.log('🔄 Reset geklickt');
                $regionFilter.val('');
                $textSearch.val('');
                hideAutocomplete();
                
                // ✅ NEU: Bei Reset - wenn hideResultsUntilSearch aktiv, Container wieder verstecken
                if (hideResultsUntilSearch && !predefinedRegion) {
                    $resultsWrapper.hide();
                } else {
                    showEmptyState();
                }
            });
            
            console.log("✅ UI und Events initialisiert");
        }
        
        function showEmptyState() {
            var $container = $("#" + uniqueId);
            $container.find('.fahrplanportal-loading').addClass('d-none');
            $container.find('.fahrplanportal-no-results').addClass('d-none');
            $container.find('.fahrplanportal-results-list').addClass('d-none');
            $container.find('.fahrplanportal-empty-state').removeClass('d-none');
        }
        
        function showLoading() {
            var $container = $("#" + uniqueId);
            $container.find('.fahrplanportal-empty-state').addClass('d-none');
            $container.find('.fahrplanportal-no-results').addClass('d-none');
            $container.find('.fahrplanportal-results-list').addClass('d-none');
            $container.find('.fahrplanportal-loading').removeClass('d-none');
        }
        
        function showNoResults() {
            var $container = $("#" + uniqueId);
            $container.find('.fahrplanportal-empty-state').addClass('d-none');
            $container.find('.fahrplanportal-loading').addClass('d-none');
            $container.find('.fahrplanportal-results-list').addClass('d-none');
            $container.find('.fahrplanportal-no-results').removeClass('d-none');
        }
        
        function showResults(html, count) {
            var $container = $("#" + uniqueId);
            $container.find('.fahrplanportal-empty-state').addClass('d-none');
            $container.find('.fahrplanportal-loading').addClass('d-none');
            $container.find('.fahrplanportal-no-results').addClass('d-none');
            
            $container.find('.results-container').html(html);
            $container.find('.fahrplanportal-count').text(count);
            $container.find('.fahrplanportal-results-list').removeClass('d-none');
        }
        
        function showAutocomplete(suggestions) {
            var $autocompleteDropdown = $("#" + uniqueId + " .autocomplete-dropdown");
            var html = '';
            suggestions.forEach(function(suggestion, index) {
                html += '<div class="autocomplete-item" data-index="' + index + '" data-text="' + escapeHtml(suggestion.text) + '">';
                html += '<span class="autocomplete-text">' + escapeHtml(suggestion.text) + '</span>';
                html += '<span class="autocomplete-context">' + escapeHtml(suggestion.context) + '</span>';
                html += '</div>';
            });
            
            $autocompleteDropdown.html(html).addClass('show');
        }
        
        function hideAutocomplete() {
            var $autocompleteDropdown = $("#" + uniqueId + " .autocomplete-dropdown");
            $autocompleteDropdown.removeClass('show').empty();
        }
        
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showError() {
            var $container = $("#" + uniqueId + " .fahrplanportal-results");
            $container.html('<div class="alert alert-danger"><h4>Systemfehler</h4><p>Das Fahrplanportal konnte nicht geladen werden.</p></div>');
        }
    });
};

// Auto-Initialisierung für bereits vorhandene Konfigurationen
jQuery(document).ready(function($) {
    console.log("🚀 Fahrplanportal Auto-Init prüft vorhandene Konfigurationen");
    
    if (typeof window.fahrplanportalConfigs !== 'undefined') {
        Object.keys(window.fahrplanportalConfigs).forEach(function(uniqueId) {
            console.log("🔄 Auto-Init für:", uniqueId);
            window.fahrplanportalInit(uniqueId);
        });
    }
});