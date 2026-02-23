<?php
// SearchEngine.php
require_once 'db.php';

class SearchEngine {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Unified Normalization for Arabic text:
     * 1. Normalize Alif forms (أ, إ, آ, ٱ) to bare Alif (ا)
     * 2. Normalize Taa Marbuta (ة) to Ha (ه)
     * 3. Normalize Alef Maqsura (ى) to Ya (ي)
     */
    private function normalizeText($text) {
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        return $text;
    }

    /**
     * SQL expression to normalize Arabic root text (strip spaces and diacritics)
     * Used to compare root_text from DB with normalized user input
     */
    private function rootNormalizeSql($column) {
        // Strip spaces, then strip common Arabic diacritics (tashkeel)
        // Fatha(َ), Damma(ُ), Kasra(ِ), Shadda(ّ), Sukun(ْ), 
        // Tanwin: Fathatan(ً), Dammatan(ٌ), Kasratan(ٍ), 
        // Superscript Alef(ٰ), Small High Seen(ۜ)
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(" .
               "$column, ' ', ''), " .
               "'َ', ''), " .    // Fatha U+064E
               "'ُ', ''), " .    // Damma U+064F
               "'ِ', ''), " .    // Kasra U+0650
               "'ّ', ''), " .    // Shadda U+0651
               "'ْ', ''), " .    // Sukun U+0652
               "'ً', ''), " .    // Fathatan U+064B
               "'ٌ', ''), " .    // Dammatan U+064C
               "'ٍ', ''), " .    // Kasratan U+064D
               "'ٰ', '')";       // Superscript Alef U+0670
    }

    /**
     * SQL expression to normalize text_clean for search
     * Converts:
     * - Alif forms to bare Alif (ا)
     * - Taa Marbuta to Ha (ه)
     * - Alef Maqsura to Ya (ي)
     */
    private function textNormalizeSql($column) {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($column, 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا'), 'ٱ', 'ا'), 'ة', 'ه'), 'ى', 'ي')";
    }

    /**
     * Normalize root text in PHP: remove spaces and diacritics
     * so "س م و" becomes "سمو" and "رَحِمَ" becomes "رحم"
     */
    private function normalizeRoot($text) {
        // Remove spaces
        $text = str_replace(' ', '', $text);
        // Remove Arabic diacritics (tashkeel)
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E4}\x{06E7}\x{06E8}\x{06EA}-\x{06ED}]/u', '', $text);
        return trim($text);
    }

    /**
     * Find root IDs that match a normalized root term
     * This is more reliable than SQL normalization for complex cases
     */
    private function findMatchingRootIds($normalizedTerm) {
        $stmt = $this->pdo->query("SELECT id, root_text FROM roots");
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dbNormalized = $this->normalizeRoot($row['root_text']);
            if ($dbNormalized === $normalizedTerm) {
                $ids[] = $row['id'];
            }
        }
        return $ids;
    }

    /**
     * Enhanced search function supporting query groups with OR/AND operators
     */
    public function searchGroups($query_groups, $page = 1, $perPage = 20) {
        if (empty($query_groups)) {
            return ['results' => [], 'total' => 0, 'matched_terms' => []];
        }

        // Build WHERE clause by combining groups with their operators
        $group_clauses = [];
        $params = [];
        $all_search_terms = []; // For highlighting
        $root_id_map = []; // Cache resolved root IDs
        
        foreach ($query_groups as $group_idx => $group) {
            $criteria_list = $group['criteria'] ?? [];
            if (empty($criteria_list)) continue;

            $criteria_clauses = [];
            
            foreach ($criteria_list as $crit_idx => $criteria) {
                $term = trim($criteria['term']);
                if (empty($term)) continue;

                $type = $criteria['type'];
                $position = $criteria['position'] ?? 'any';
                $is_exclude = !empty($criteria['exclude']) && $criteria['exclude'] == 'true';

                // Normalize root input
                if ($type === 'root') {
                    $term = $this->normalizeRoot($term);
                }

                // Collect terms for highlighting
                if (!$is_exclude) {
                    $all_search_terms[] = [
                        'term' => $term,
                        'type' => $type
                    ];
                }

                $param_key = "term_g{$group_idx}_c{$crit_idx}";
                
                if ($type === 'root') {
                    // For root search: find matching root IDs first, then use them
                    if (!isset($root_id_map[$term])) {
                        $root_id_map[$term] = $this->findMatchingRootIds($term);
                    }
                    $root_ids = $root_id_map[$term];
                    
                    if (empty($root_ids)) {
                        // No matching roots found
                        if ($is_exclude) {
                            // Excluding something that doesn't exist = always true, skip
                            continue;
                        } else {
                            // Requiring something that doesn't exist = always false
                            $criteria_clauses[] = "1=0";
                            continue;
                        }
                    }
                    
                    // Build IN clause with root IDs
                    $id_placeholders = [];
                    foreach ($root_ids as $idx => $rid) {
                        $pk = ":{$param_key}_rid{$idx}";
                        $id_placeholders[] = $pk;
                        $params[$pk] = $rid;
                    }
                    $in_clause = implode(',', $id_placeholders);
                    
                    $subquery = "SELECT 1 FROM words w WHERE w.ayah_id = a.id AND w.root_id IN ($in_clause)";
                    
                    // Apply position filtering for root search
                    if ($position === 'start' || $position === 'end') {
                        $wordCol = $this->textNormalizeSql('w.word_clean');
                        $textCol = $this->textNormalizeSql('a.text_clean');
                        if ($position === 'start') {
                            $subquery .= " AND $textCol REGEXP CONCAT('^', $wordCol, '([[:space:]]|$)')";
                        } else {
                            $subquery .= " AND $textCol REGEXP CONCAT('(^|[[:space:]])', $wordCol, '$')";
                        }
                    }
                    
                    if ($is_exclude) {
                        $criteria_clauses[] = "NOT EXISTS ($subquery)";
                    } else {
                        $criteria_clauses[] = "EXISTS ($subquery)";
                    }
                    
                } else {
                    // word or part search
                    // Normalize the search term (Unified normalization)
                    $term = $this->normalizeText($term);
                    
                    $subquery = $this->buildSubquery($type, $position, $param_key);
                    
                    if (!empty($subquery)) {
                        if ($type === 'part') {
                            if ($position === 'start') {
                                $params[":$param_key"] = "$term%";
                            } elseif ($position === 'end') {
                                $params[":$param_key"] = "%$term";
                            } else {
                                $params[":$param_key"] = "%$term%";
                            }
                        } else {
                            // word search: pass the term directly to REGEXP
                            $params[":$param_key"] = $term;
                        }

                        if ($is_exclude) {
                            $criteria_clauses[] = "NOT EXISTS ($subquery)";
                        } else {
                            $criteria_clauses[] = "EXISTS ($subquery)";
                        }
                    }
                }
            }
            
            if (!empty($criteria_clauses)) {
                $group_clauses[] = '(' . implode(' AND ', $criteria_clauses) . ')';
            }
        }
        
        if (empty($group_clauses)) {
            return ['results' => [], 'total' => 0, 'matched_terms' => []];
        }

        // Combine groups with their operators (OR or AND)
        $where_sql = $group_clauses[0];
        for ($i = 1; $i < count($group_clauses); $i++) {
            $prev_group = $query_groups[$i - 1];
            $operator = strtoupper($prev_group['operator'] ?? 'OR');
            $where_sql .= " $operator " . $group_clauses[$i];
        }

        // Count Total
        $count_sql = "SELECT COUNT(*) FROM ayahs a WHERE $where_sql";
        $stmt = $this->pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Get Results
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT a.*, s.name_ar as surah_name 
                FROM ayahs a 
                JOIN surahs s ON a.sura_id = s.id 
                WHERE $where_sql 
                ORDER BY a.id ASC 
                LIMIT $perPage OFFSET $offset";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Expand terms to actual words for highlighting
        $highlight_terms = $this->expandTermsForHighlighting($all_search_terms, array_column($results, 'id'), $root_id_map);

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'matched_terms' => $highlight_terms
        ];
    }



    /**
     * Build subquery for word and part searches
     * Uses text_clean column directly but applies SQL normalization for reliability
     */
    private function buildSubquery($type, $position, $param_key) {
        $subquery = "";
        
        if ($type === 'part') {
            $col = $this->textNormalizeSql('a.text_clean');
            $subquery = "SELECT 1 WHERE $col LIKE :$param_key";
        } elseif ($type === 'word') {
            // Search for exact word by applying REGEXP boundary matching on normalized text_clean
            $col = $this->textNormalizeSql("a.text_clean");
            
            if ($position === 'start') {
                $subquery = "SELECT 1 WHERE $col REGEXP CONCAT('^', :$param_key, '([[:space:]]|$)')";
            } elseif ($position === 'end') {
                $subquery = "SELECT 1 WHERE $col REGEXP CONCAT('(^|[[:space:]])', :$param_key, '$')";
            } else {
                $subquery = "SELECT 1 WHERE $col REGEXP CONCAT('(^|[[:space:]])', :$param_key, '([[:space:]]|$)')";
            }
        }
        
        return $subquery;
    }

    /**
     * Expand search terms to actual words for highlighting (both Uthmani and clean forms)
     */
    private function expandTermsForHighlighting($search_terms, $ayah_ids, $root_id_map = []) {
        if (empty($ayah_ids)) return [];

        $highlight_words = [];
        $placeholders = implode(',', array_fill(0, count($ayah_ids), '?'));
        
        foreach ($search_terms as $term_info) {
            $type = $term_info['type'];
            $term = $term_info['term'];

            if ($type === 'root') {
                // Use pre-resolved root IDs if available
                $root_ids = $root_id_map[$term] ?? $this->findMatchingRootIds($term);
                
                if (!empty($root_ids)) {
                    $rid_placeholders = implode(',', array_fill(0, count($root_ids), '?'));
                    $sql = "SELECT DISTINCT w.word_text, w.word_clean 
                            FROM words w 
                            WHERE w.root_id IN ($rid_placeholders) 
                            AND w.ayah_id IN ($placeholders)";
                    
                    $params = array_merge($root_ids, $ayah_ids);
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);
                    
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $highlight_words[] = $row['word_text'];
                        $highlight_words[] = $row['word_clean'];
                    }
                }
            } elseif ($type === 'word') {
                // Normalize term for comparison
                $normalizedTerm = $this->normalizeText($term);
                
                // Find all words in these ayahs that normalize to this term
                // We need to normalize the word_text column in SQL to match
                $wordCleanNorm = $this->textNormalizeSql($this->rootNormalizeSql('w.word_text'));
                
                $sql = "SELECT DISTINCT w.word_text, w.word_clean
                        FROM words w
                        WHERE w.ayah_id IN ($placeholders)
                        AND $wordCleanNorm = ?";
                
                // Add the normalized term to params
                $params = array_merge($ayah_ids, [$normalizedTerm]);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $highlight_words[] = $row['word_text'];
                    $highlight_words[] = $row['word_clean'];
                }

                // Also add the original term just in case
                $highlight_words[] = $term;

            } else {
                // For 'part' type
                $highlight_words[] = $term;
            }
        }
        
        return array_values(array_unique(array_filter($highlight_words)));
    }

    /**
     * Legacy search function for backward compatibility
     */
    public function search($criteria_list, $page = 1, $perPage = 20) {
        $query_groups = [
            [
                'criteria' => $criteria_list,
                'operator' => 'AND'
            ]
        ];
        
        return $this->searchGroups($query_groups, $page, $perPage);
    }
}
?>
