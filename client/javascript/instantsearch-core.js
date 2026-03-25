(function (window) {
    if (window.AppInstantSearch) {
        return;
    }

    const HIGHLIGHT_START_TOKEN = '__TS_HL_START__';
    const HIGHLIGHT_END_TOKEN = '__TS_HL_END__';

    function readConfig(container) {
        return {
            searchKey: container.getAttribute('data-typesense-search-key') ||
                document.documentElement.getAttribute('data-typesense-search-key') || '',
            keyRefreshUrl: container.getAttribute('data-typesense-key-refresh-url') ||
                document.documentElement.getAttribute('data-typesense-key-refresh-url') || '',
            serverUrl: container.getAttribute('data-typesense-server') ||
                document.documentElement.getAttribute('data-typesense-server') || ''
        };
    }

    function parseServerConfig(serverUrl) {
        const url = new URL(serverUrl);

        return {
            host: url.hostname,
            port: url.port || (url.protocol === 'https:' ? '443' : '80'),
            protocol: url.protocol.replace(':', ''),
            path: ''
        };
    }

    function withHighlightTags(searchParameters) {
        const params = Object.assign({}, searchParameters || {});
        if (!params.highlight_start_tag) {
            params.highlight_start_tag = HIGHLIGHT_START_TOKEN;
        }
        if (!params.highlight_end_tag) {
            params.highlight_end_tag = HIGHLIGHT_END_TOKEN;
        }
        return params;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeHighlightTokens(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/&lt;mark&gt;/gi, HIGHLIGHT_START_TOKEN)
            .replace(/&#x3c;mark&#x3e;/gi, HIGHLIGHT_START_TOKEN)
            .replace(/&#60;mark&#62;/gi, HIGHLIGHT_START_TOKEN)
            .replace(/&lt;\/mark&gt;/gi, HIGHLIGHT_END_TOKEN)
            .replace(/&#x3c;\/mark&#x3e;/gi, HIGHLIGHT_END_TOKEN)
            .replace(/&#60;\/mark&#62;/gi, HIGHLIGHT_END_TOKEN)
            .replace(/<mark>/gi, HIGHLIGHT_START_TOKEN)
            .replace(/<\/mark>/gi, HIGHLIGHT_END_TOKEN);
    }

    function renderHighlight(value) {
        const tokenizedValue = normalizeHighlightTokens(value);
        return escapeHtml(tokenizedValue)
            .split(HIGHLIGHT_START_TOKEN).join('<mark>')
            .split(HIGHLIGHT_END_TOKEN).join('</mark>');
    }

    function stripHtml(html) {
        if (!html) {
            return '';
        }

        const normalized = normalizeHighlightTokens(html)
            .split(HIGHLIGHT_START_TOKEN).join('<mark>')
            .split(HIGHLIGHT_END_TOKEN).join('</mark>');

        const decoded = new DOMParser().parseFromString(String(normalized), 'text/html').documentElement.textContent || '';
        const plain = new DOMParser().parseFromString(decoded, 'text/html');

        return plain.body.textContent || '';
    }

    function normalizeSearchLink(link) {
        if (link === null || link === undefined) {
            return '';
        }

        const raw = String(link).trim();
        if (raw === '') {
            return '';
        }

        try {
            const url = new URL(raw, window.location.origin);
            const keysToDelete = [];
            url.searchParams.forEach(function (_, key) {
                if (String(key).toLowerCase() === 'stage') {
                    keysToDelete.push(key);
                }
            });
            keysToDelete.forEach(function (key) {
                url.searchParams.delete(key);
            });

            if (/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(raw)) {
                return url.toString();
            }

            return `${url.pathname}${url.search}${url.hash}` || '/';
        } catch (_error) {
            return raw
                .replace(/([?&])stage=[^&#]*/ig, '$1')
                .replace(/\?&/g, '?')
                .replace(/[?&]$/, '');
        }
    }

    async function fetchScopedKey(refreshUrl) {
        if (!refreshUrl) {
            throw new Error('Missing key refresh URL');
        }

        const response = await fetch(refreshUrl, {
            method: 'GET',
            headers: {
                Accept: 'application/json'
            },
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`Scoped key refresh failed (${response.status})`);
        }

        const payload = await response.json();
        if (!payload || !payload.key) {
            throw new Error('Scoped key refresh payload missing key');
        }

        return payload.key;
    }

    function isAuthError(error) {
        const message = ((error && error.message) || String(error) || '').toLowerCase();

        return message.includes('401') ||
            message.includes('403') ||
            message.includes('unauthorized') ||
            message.includes('forbidden') ||
            message.includes('invalid api key') ||
            message.includes('forbidden key');
    }

    function createAdapter(apiKey, serverConfig, additionalSearchParameters) {
        return new TypesenseInstantSearchAdapter({
            server: {
                apiKey: apiKey,
                nodes: [{
                    host: serverConfig.host,
                    port: serverConfig.port,
                    protocol: serverConfig.protocol,
                    path: serverConfig.path
                }],
                cacheSearchResultsForSeconds: 2 * 60,
                connectionTimeoutSeconds: 2,
                sendApiKeyAsQueryParam: false
            },
            additionalSearchParameters: withHighlightTags(additionalSearchParameters)
        });
    }

    function createSearchClient(options) {
        const config = options || {};
        const serverConfig = parseServerConfig(config.serverUrl);
        let currentApiKey = config.apiKey;
        let adapter = createAdapter(currentApiKey, serverConfig, config.additionalSearchParameters);
        let activeClient = adapter.searchClient;
        let refreshPromise = null;

        async function refreshKey() {
            if (!config.keyRefreshUrl) {
                throw new Error('No key refresh URL configured');
            }

            if (!refreshPromise) {
                refreshPromise = fetchScopedKey(config.keyRefreshUrl)
                    .then(function (newKey) {
                        currentApiKey = newKey;
                        adapter = createAdapter(currentApiKey, serverConfig, config.additionalSearchParameters);
                        activeClient = adapter.searchClient;
                        return currentApiKey;
                    })
                    .finally(function () {
                        refreshPromise = null;
                    });
            }

            return refreshPromise;
        }

        async function callWithRetry(fn, allowRetry) {
            try {
                return await fn(activeClient);
            } catch (error) {
                if (!allowRetry || !isAuthError(error) || !config.keyRefreshUrl) {
                    throw error;
                }

                await refreshKey();
                return fn(activeClient);
            }
        }

        return {
            search: function (requests) {
                return callWithRetry(function (client) {
                    return client.search(requests);
                }, true);
            },
            searchForFacetValues: function (requests) {
                if (typeof activeClient.searchForFacetValues !== 'function') {
                    return Promise.resolve([]);
                }

                return callWithRetry(function (client) {
                    return client.searchForFacetValues(requests);
                }, true);
            }
        };
    }

    function createUnionSearchClient(options) {
        const config = options || {};
        const collections = Array.isArray(config.collections) ? config.collections.filter(Boolean) : [];
        const collectionSearchParameters = config.collectionSearchParameters || {};
        const additionalSearchParameters = withHighlightTags(config.additionalSearchParameters);
        let currentApiKey = config.apiKey;
        let refreshPromise = null;

        function emptyResult(query, page, hitsPerPage) {
            return {
                hits: [],
                nbHits: 0,
                page: page,
                nbPages: 0,
                hitsPerPage: hitsPerPage,
                processingTimeMS: 0,
                query: query || '',
                params: ''
            };
        }

        function buildSearchRequest(request) {
            const params = (request && request.params) || {};
            const query = (params.query || '').trim();
            const page = Number.isInteger(params.page) ? params.page : 0;
            const hitsPerPage = Number.isInteger(params.hitsPerPage)
                ? params.hitsPerPage
                : (Number.isInteger(additionalSearchParameters.per_page) ? additionalSearchParameters.per_page : 10);

            const searches = collections.map(function (collectionName) {
                const collectionParams = collectionSearchParameters[collectionName] || {};
                const mergedParams = withHighlightTags(Object.assign({}, additionalSearchParameters, collectionParams));
                const searchPayload = {
                    collection: collectionName,
                    q: query
                };

                Object.keys(mergedParams).forEach(function (key) {
                    const value = mergedParams[key];
                    if (value !== null && value !== undefined && value !== '') {
                        searchPayload[key] = value;
                    }
                });

                if (params.filters && !searchPayload.filter_by) {
                    searchPayload.filter_by = params.filters;
                }

                return searchPayload;
            });

            return {
                query: query,
                page: page,
                hitsPerPage: hitsPerPage,
                searches: searches
            };
        }

        function adaptHit(hit) {
            const documentData = Object.assign({}, hit.document || {});
            const highlightData = hit.highlight || {};
            const highlightResult = {};

            Object.keys(highlightData).forEach(function (field) {
                const fieldHighlight = highlightData[field] || {};
                highlightResult[field] = {
                    value: fieldHighlight.snippet || String(documentData[field] || ''),
                    matchLevel: (fieldHighlight.matched_tokens || []).length > 0 ? 'full' : 'none',
                    matchedWords: fieldHighlight.matched_tokens || []
                };
            });

            documentData.objectID = documentData.objectID || documentData.id || '';
            documentData._highlightResult = highlightResult;
            documentData._collection = hit.collection || '';

            return documentData;
        }

        function adaptResult(payload, query, page, hitsPerPage) {
            const hits = Array.isArray(payload && payload.hits)
                ? payload.hits.map(adaptHit)
                : [];
            const nbHits = Number.isInteger(payload && payload.found) ? payload.found : hits.length;
            const nbPages = hitsPerPage > 0 ? Math.ceil(nbHits / hitsPerPage) : 0;

            return {
                hits: hits,
                nbHits: nbHits,
                page: page,
                nbPages: nbPages,
                hitsPerPage: hitsPerPage,
                processingTimeMS: Number(payload && payload.search_time_ms) || 0,
                query: query || '',
                params: ''
            };
        }

        async function refreshKey() {
            if (!config.keyRefreshUrl) {
                throw new Error('No key refresh URL configured');
            }

            if (!refreshPromise) {
                refreshPromise = fetchScopedKey(config.keyRefreshUrl)
                    .then(function (newKey) {
                        currentApiKey = newKey;
                        return currentApiKey;
                    })
                    .finally(function () {
                        refreshPromise = null;
                    });
            }

            return refreshPromise;
        }

        async function callWithRetry(fn, allowRetry) {
            try {
                return await fn();
            } catch (error) {
                if (!allowRetry || !isAuthError(error) || !config.keyRefreshUrl) {
                    throw error;
                }

                await refreshKey();
                return fn();
            }
        }

        async function performUnionSearch(request) {
            const built = buildSearchRequest(request);
            if (built.query === '' || built.searches.length === 0) {
                return emptyResult(built.query, built.page, built.hitsPerPage);
            }

            const endpoint = `${String(config.serverUrl || '').replace(/\/+$/, '')}/multi_search?page=${built.page + 1}&per_page=${built.hitsPerPage}`;
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-TYPESENSE-API-KEY': currentApiKey
                },
                body: JSON.stringify({
                    union: true,
                    searches: built.searches
                })
            });

            if (!response.ok) {
                const message = await response.text();
                throw new Error(`Typesense multi_search failed (${response.status}): ${message}`);
            }

            const payload = await response.json();
            return adaptResult(payload, built.query, built.page, built.hitsPerPage);
        }

        return {
            search: function (requests) {
                return callWithRetry(async function () {
                    const requestList = Array.isArray(requests) ? requests : [];
                    const results = [];
                    for (const request of requestList) {
                        const result = await performUnionSearch(request);
                        results.push(result);
                    }

                    return { results: results };
                }, true);
            },
            searchForFacetValues: function () {
                return Promise.resolve([]);
            }
        };
    }

    window.AppInstantSearch = {
        readConfig: readConfig,
        parseServerConfig: parseServerConfig,
        createSearchClient: createSearchClient,
        createUnionSearchClient: createUnionSearchClient,
        renderHighlight: renderHighlight,
        restoreMarkTags: renderHighlight,
        highlightStartToken: HIGHLIGHT_START_TOKEN,
        highlightEndToken: HIGHLIGHT_END_TOKEN,
        normalizeSearchLink: normalizeSearchLink,
        stripHtml: stripHtml
    };
})(window);
