document.addEventListener('DOMContentLoaded', () => {
    const conditionsContainer = document.getElementById('conditionsContainer');
    const addConditionBtn = document.getElementById('addConditionBtn');
    const searchForm = document.getElementById('searchForm');

    const conditionTemplate = document.getElementById('conditionTemplate');
    const operatorTemplate = document.getElementById('operatorTemplate');

    const resultsSection = document.getElementById('resultsSection');
    const resultsList = document.getElementById('resultsList');
    const resultsCount = document.getElementById('resultsCount');
    const pagination = document.getElementById('pagination');

    let currentPage = 1;
    let matchedTerms = [];

    // Initialize with one condition
    addCondition();

    // Event Listeners
    addConditionBtn.addEventListener('click', () => addCondition());

    searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        currentPage = 1;
        performSearch();
    });

    // Delegated events for dynamic elements
    conditionsContainer.addEventListener('click', (e) => {
        if (e.target.closest('.btn-remove-condition')) {
            const rows = conditionsContainer.querySelectorAll('.condition-row');
            if (rows.length > 1) {
                const row = e.target.closest('.condition-row');

                // Remove the associated operator (before or after)
                const prevSibling = row.previousElementSibling;
                const nextSibling = row.nextElementSibling;

                if (prevSibling && prevSibling.classList.contains('condition-operator')) {
                    prevSibling.remove();
                } else if (nextSibling && nextSibling.classList.contains('condition-operator')) {
                    nextSibling.remove();
                }

                row.remove();
            } else {
                showToast('يجب أن يكون هناك شرط بحث واحد على الأقل');
            }
        }
    });

    // Visual feedback for include/exclude mode change
    conditionsContainer.addEventListener('change', (e) => {
        if (e.target.classList.contains('condition-mode')) {
            const row = e.target.closest('.condition-row');
            if (e.target.value === 'exclude') {
                row.style.borderColor = '#e74c3c';
                row.style.background = '#fdf2f2';
            } else {
                row.style.borderColor = '';
                row.style.background = '';
            }
        }
    });

    function addCondition() {
        const rows = conditionsContainer.querySelectorAll('.condition-row');

        // Add operator before new condition if there are existing conditions
        if (rows.length > 0) {
            const operatorClone = operatorTemplate.content.cloneNode(true);
            conditionsContainer.appendChild(operatorClone);
        }

        const clone = conditionTemplate.content.cloneNode(true);
        conditionsContainer.appendChild(clone);
    }

    /**
     * Normalize root input: Remove spaces so "س م و" becomes "سمو"
     */
    function normalizeRoot(text) {
        return text.replace(/\s+/g, '');
    }

    /**
     * Build query groups from condition rows and operators.
     * "و" (AND) keeps conditions in the same group.
     * "أو" (OR) starts a new group.
     */
    function buildQueryGroups() {
        const queryGroups = [];
        let currentCriteria = [];

        const children = conditionsContainer.children;

        for (let i = 0; i < children.length; i++) {
            const child = children[i];

            if (child.classList.contains('condition-row')) {
                const mode = child.querySelector('.condition-mode').value;
                const type = child.querySelector('.search-type').value;
                let term = child.querySelector('.search-term').value.trim();
                const position = child.querySelector('.search-position').value;

                if (type === 'root') {
                    term = normalizeRoot(term);
                }

                if (term) {
                    currentCriteria.push({
                        type,
                        term,
                        position,
                        exclude: mode === 'exclude' ? 'true' : 'false'
                    });
                }
            } else if (child.classList.contains('condition-operator')) {
                const operatorValue = child.querySelector('.operator-select').value;

                if (operatorValue === 'OR') {
                    // Save current group and start a new one
                    if (currentCriteria.length > 0) {
                        queryGroups.push({ criteria: currentCriteria, operator: 'OR' });
                        currentCriteria = [];
                    }
                }
                // If AND, just continue adding to current group
            }
        }

        // Push the last group
        if (currentCriteria.length > 0) {
            queryGroups.push({ criteria: currentCriteria });
        }

        return queryGroups;
    }

    async function performSearch(page = 1) {
        const queryGroups = buildQueryGroups();

        if (queryGroups.length === 0) {
            showToast('أدخل على الأقل كلمة بحث واحدة');
            return;
        }

        // Show loading state
        resultsList.innerHTML = '<div class="loading-text">جاري البحث في كتاب الله...</div>';
        resultsSection.classList.remove('hidden');
        pagination.innerHTML = '';

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query_groups: queryGroups, page })
            });

            const result = await response.json();

            if (result.success) {
                matchedTerms = result.data.matched_terms || [];
                renderResults(result.data);
                renderPagination(result.data);
            } else {
                resultsList.innerHTML = `<div class="error-text">حدث خطأ: ${result.message}</div>`;
            }

        } catch (error) {
            console.error('Error:', error);
            resultsList.innerHTML = '<div class="error-text">فشل الاتصال بالخادم</div>';
        }
    }

    /**
     * Remove Arabic diacritics (tashkeel) and special Uthmani marks for matching
     */
    function stripDiacritics(text) {
        return text.replace(/[\u0610-\u061A\u064B-\u065F\u0670\u06D6-\u06DC\u06DF-\u06E4\u06E7\u06E8\u06EA-\u06ED\u0617\u0618\u0619\u08D4-\u08E1\u08E3-\u08FF\uFE70-\uFE7F]/g, '').replace(/[أإآٱ]/g, 'ا');
    }

    /**
     * Highlight matched terms in Uthmani text, aware of Arabic diacritics
     */
    function highlightText(originalText, terms) {
        if (!terms || terms.length === 0) return escapeHtml(originalText);

        const strippedChars = [];
        let stripped = '';
        let i = 0;
        while (i < originalText.length) {
            const ch = originalText[i];
            const strippedCh = stripDiacritics(ch);
            if (strippedCh.length > 0) {
                let origStart = i;
                i++;
                while (i < originalText.length && stripDiacritics(originalText[i]).length === 0) {
                    i++;
                }
                strippedChars.push({ char: strippedCh, origStart, origEnd: i });
                stripped += strippedCh;
            } else {
                i++;
            }
        }

        const sortedTerms = [...terms]
            .map(t => stripDiacritics(t))
            .filter(t => t.length > 0)
            .sort((a, b) => b.length - a.length);

        const matchRanges = [];

        for (const term of sortedTerms) {
            const escapedTerm = escapeRegex(term);
            const regex = new RegExp(escapedTerm, 'g');
            let match;
            while ((match = regex.exec(stripped)) !== null) {
                const mStart = match.index;
                const mEnd = match.index + match[0].length;
                const overlaps = matchRanges.some(r => mStart < r.end && mEnd > r.start);
                if (!overlaps) {
                    matchRanges.push({ start: mStart, end: mEnd });
                }
            }
        }

        if (matchRanges.length === 0) return escapeHtml(originalText);

        matchRanges.sort((a, b) => a.start - b.start);

        let result = '';
        let lastOrigPos = 0;

        for (const range of matchRanges) {
            const origMatchStart = strippedChars[range.start].origStart;
            const origMatchEnd = strippedChars[range.end - 1].origEnd;

            if (origMatchStart > lastOrigPos) {
                result += escapeHtml(originalText.substring(lastOrigPos, origMatchStart));
            }

            result += '<span class="highlight">' + escapeHtml(originalText.substring(origMatchStart, origMatchEnd)) + '</span>';
            lastOrigPos = origMatchEnd;
        }

        if (lastOrigPos < originalText.length) {
            result += escapeHtml(originalText.substring(lastOrigPos));
        }

        return result;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function renderResults(data) {
        resultsCount.textContent = data.total;
        resultsList.innerHTML = '';

        if (data.results.length === 0) {
            resultsList.innerHTML = '<div class="no-results">لا توجد نتائج مطابقة لمعايير البحث</div>';
            return;
        }

        data.results.forEach((ayah, index) => {
            const card = document.createElement('div');
            card.className = 'result-card';
            card.style.animationDelay = `${index * 0.05}s`;

            const highlightedText = highlightText(ayah.text_othmani, matchedTerms);

            card.innerHTML = `
                <div class="ayah-meta">
                    <span class="surah-name">${escapeHtml(ayah.surah_name)}</span>
                    <span class="ayah-number">الآية ${ayah.ayah_num}</span>
                </div>
                <div class="ayah-text">${highlightedText}</div>
                ${ayah.tafseer ? `<div class="ayah-tafseer">${escapeHtml(ayah.tafseer)}</div>` : ''}
            `;
            resultsList.appendChild(card);
        });
    }

    function renderPagination(data) {
        pagination.innerHTML = '';
        const totalPages = data.total_pages;
        const current = data.page;

        if (totalPages <= 1) return;

        if (current > 1) {
            pagination.appendChild(createPageBtn('← السابق', current - 1));
        }

        let start = Math.max(1, current - 2);
        let end = Math.min(totalPages, start + 4);
        if (end - start < 4) start = Math.max(1, end - 4);

        if (start > 1) {
            pagination.appendChild(createPageBtn('1', 1));
            if (start > 2) {
                const dots = document.createElement('span');
                dots.className = 'page-btn';
                dots.textContent = '…';
                dots.style.cursor = 'default';
                pagination.appendChild(dots);
            }
        }

        for (let i = start; i <= end; i++) {
            const btn = createPageBtn(i, i);
            if (i === current) btn.classList.add('active');
            pagination.appendChild(btn);
        }

        if (end < totalPages) {
            if (end < totalPages - 1) {
                const dots = document.createElement('span');
                dots.className = 'page-btn';
                dots.textContent = '…';
                dots.style.cursor = 'default';
                pagination.appendChild(dots);
            }
            pagination.appendChild(createPageBtn(totalPages, totalPages));
        }

        if (current < totalPages) {
            pagination.appendChild(createPageBtn('التالي →', current + 1));
        }
    }

    function createPageBtn(label, pageNum) {
        const btn = document.createElement('button');
        btn.className = 'page-btn';
        btn.textContent = label;
        btn.onclick = () => {
            performSearch(pageNum);
            resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        return btn;
    }

    // Simple toast notification
    function showToast(message) {
        let toast = document.querySelector('.toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'toast';
            toast.style.cssText = `
                position: fixed;
                bottom: 30px;
                left: 50%;
                transform: translateX(-50%);
                background: #2c3e50;
                color: #fff;
                padding: 12px 24px;
                border-radius: 8px;
                font-family: 'Tajawal', sans-serif;
                font-size: 0.95rem;
                z-index: 9999;
                box-shadow: 0 4px 16px rgba(0,0,0,0.2);
                transition: opacity 0.3s;
            `;
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.style.opacity = '1';
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => {
            toast.style.opacity = '0';
        }, 2500);
    }
});
